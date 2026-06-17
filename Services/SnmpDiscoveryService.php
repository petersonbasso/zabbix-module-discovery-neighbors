<?php declare(strict_types = 1);

namespace Modules\DiscoveryNeighbors\Services;

class SnmpDiscoveryService {

	private \Closure $log_callback;
	private float $last_walk_duration = 0.0;
	private bool $last_walk_success = false;

	/**
	 * @param \Closure $log_callback Callback de log de depuração do módulo
	 */
	public function __construct(\Closure $log_callback) {
		$this->log_callback = $log_callback;
	}

	/**
	 * Retorna a duração do último walk SNMP.
	 *
	 * @return float
	 */
	public function getLastWalkDuration(): float {
		return $this->last_walk_duration;
	}

	/**
	 * Retorna se o último walk SNMP foi bem sucedido.
	 *
	 * @return bool
	 */
	public function getLastWalkSuccess(): bool {
		return $this->last_walk_success;
	}

	/**
	 * Dispara uma mensagem no log de depuração do módulo.
	 *
	 * @param string $message
	 */
	private function log(string $message): void {
		($this->log_callback)($message);
	}

	/**
	 * Determina se o vizinho é um equipamento de distribuição (switch/roteador)
	 * ou se é um endpoint (câmera, hotspot, PC).
	 *
	 * @param string $name Nome do vizinho
	 * @param string $port Nome da porta remota
	 * @return bool
	 */
	public function isDistributionDevice(string $name, string $port): bool {
		$name_lower = strtolower($name);
		$port_lower = strtolower($port);

		// 1. Se o nome do vizinho ou a porta contiver um MAC address, é muito provável que seja um endpoint
		if (preg_match('/([0-9a-fA-F]{2}[:-]){5}[0-9a-fA-F]{2}/', $port) || preg_match('/^[0-9a-fA-F]{12}$/', preg_replace('/[^a-fA-F0-9]/', '', $port))) {
			return false;
		}

		// 2. Palavras-chave no nome do equipamento (indica switch/roteador/gateway)
		if (preg_match('/\b(sw\d*|switch|router|rtr|gw|route|hub)\b/', $name_lower) || preg_match('/-sw\d*/', $name_lower)) {
			return true;
		}

		// 3. Formato padrão de portas de switch (ex: slot/portas como GigabitEthernet1/0/24, ge-0/0/1, etc.)
		if (preg_match('/(gigabit|fastethernet|ethernet|tgigabit|ten-gigabit|xe-|ge-|fa\d+\/\d+|gi\d+\/\d+|slot|unit\s*\d+)/', $port_lower)) {
			// Exclui interfaces de rede locais genéricas de computadores ou hotspots (ex: eth0, wlan0, lan, wan)
			if (!preg_match('/(eth0|wlan0|lan|wan|port\s*1\b|port1\b)/', $port_lower)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Realiza a consulta SNMP Walk de forma segura suprimindo warnings do PHP.
	 *
	 * @param string $ip
	 * @param string $community
	 * @param string $oid
	 * @param int $version
	 * @return array
	 */
	public function snmpWalk(string $ip, string $community, string $oid, int $version): array {
		$timeout = 2000000; // 2 segundos
		$retries = 0;       // 0 retentativas para evitar bloqueios longos
		$walk_data = false;
		$walk_start = microtime(true);

		try {
			if ($version == 1) {
				if (function_exists('snmprealwalk')) {
					$walk_data = @snmprealwalk($ip, $community, $oid, $timeout, $retries);
				}
			} else {
				if (function_exists('snmp2_real_walk')) {
					$walk_data = @snmp2_real_walk($ip, $community, $oid, $timeout, $retries);
				} elseif (function_exists('snmprealwalk')) {
					$walk_data = @snmprealwalk($ip, $community, $oid, $timeout, $retries);
				}
			}
		} catch (\Throwable $e) {
			$this->last_walk_duration = microtime(true) - $walk_start;
			$this->last_walk_success = false;
			$this->log(sprintf("  snmpWalk: Exception on OID (%s) after %.4fs. Error: %s", $oid, microtime(true) - $walk_start, $e->getMessage()));
			return [];
		}

		$duration = microtime(true) - $walk_start;
		$this->last_walk_duration = $duration;

		if ($walk_data === false) {
			$this->last_walk_success = false;
			$this->log(sprintf("  snmpWalk: Walk FAILED for OID (%s) on IP (%s). Duration: %.4fs", $oid, $ip, $duration));
			return [];
		}

		$this->last_walk_success = true;
		$this->log(sprintf("  snmpWalk: Walk SUCCESS for OID (%s) on IP (%s). Lines: %d. Duration: %.4fs", $oid, $ip, count($walk_data), $duration));

		$results = [];
		try {
			foreach ($walk_data as $key => $val) {
				$normalized_key = ltrim($key, '.');
				$val = trim($val, '" ');
				
				// Decodifica strings hexadecimais retornadas pelo SNMP (como nomes ou portas com null-bytes)
				$val = $this->decodeHexSnmpString($val);
				
				if (function_exists('mb_convert_encoding')) {
					$val = mb_convert_encoding($val, 'UTF-8', 'UTF-8, ISO-8859-1, ASCII');
				}
				$results[$normalized_key] = $val;
			}
		} catch (\Throwable $e) {
			$this->log("  snmpWalk: Exception parsing results: " . $e->getMessage());
		}

		return $results;
	}

	/**
	 * Decodifica uma string que foi retornada em formato hexadecimal separada por espaços ou colada.
	 * Ex: "53 4D 45 44 2D 53 57 30 31 00 00..." -> "SMED-SW01"
	 *
	 * @param string $val
	 * @return string
	 */
	public function decodeHexSnmpString(string $val): string {
		$val = trim($val, '" ');
		
		// Verifica se o valor original era do tipo Hex-STRING
		$is_hex_prefixed = (bool)preg_match('/^(Hex-STRING|Hex-String|HEX-STRING):\s*/i', $val);

		// Remove prefixo de tipo se existir
		$val = preg_replace('/^(Hex-STRING|Hex-String|HEX-STRING|STRING):\s*/i', '', $val);
		$val = trim($val);

		// Se não tem prefixo Hex-STRING, mas o padrão do valor indica fortemente bytes hexadecimais (ex: grupos de 2 digitos separados por espaço/hífen/dois-pontos)
		$is_hex = $is_hex_prefixed || (bool)preg_match('/^([a-fA-F0-9]{2}[ \s:-]+)+[a-fA-F0-9]{2}$/', $val);

		if (!$is_hex) {
			return $val;
		}

		// Limpa a string de separadores comuns para obter a cadeia hex pura
		$clean_hex = preg_replace('/[^a-fA-F0-9]/', '', $val);
		$clean_hex_len = strlen($clean_hex);

		if ($clean_hex_len > 0 && $clean_hex_len % 2 === 0 && preg_match('/^[a-fA-F0-9\s:-]+$/', $val)) {
			$binary = @pack('H*', $clean_hex);
			if ($binary !== false) {
				// Remove bytes nulos (\x00) e espaços das pontas
				$decoded = trim($binary, "\x00\r\n\t ");
				$len = strlen($decoded);
				
				if ($len > 0) {
					// Padrão de caracteres ASCII seguros para hostnames e portas
					// (letras, números, hífen, underline, ponto, barra, parênteses, dois-pontos e espaços)
					$is_safe_ascii = (bool)preg_match('/^[a-zA-Z0-9\-_.\/\(\)\s\:]+$/', $decoded);

					if ($is_safe_ascii) {
						return $decoded;
					}

					// Se tem exatamente 12 caracteres hex (6 bytes), tratamos como endereço MAC
					if ($clean_hex_len === 12) {
						return strtoupper(implode(':', str_split($clean_hex, 2)));
					}

					// Se tem até 8 caracteres hex (1 a 4 bytes) e não é ASCII seguro, tratamos como inteiro/índice
					if ($clean_hex_len <= 8) {
						return (string)hexdec($clean_hex);
					}

					// Caso contrário, valida se pelo menos 75% dos caracteres decodificados são ASCII imprimíveis genéricos
					// (removemos a faixa \xC0-\xFF para evitar falsos positivos com dados binários)
					$printable = preg_match_all('/[\x20-\x7E\x0A\x0D]/', $decoded);
					if ($printable / $len > 0.75) {
						return $decoded;
					}
				}
			}
		}

		return $val;
	}

	/**
	 * Executa a leitura das interfaces do switch para obter o mapeamento de portas locais.
	 *
	 * @param string $ip
	 * @param string $community
	 * @param int $version
	 * @return array
	 */
	public function getInterfaceMap(string $ip, string $community, int $version): array {
		$if_names_walk = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.31.1.1.1.1', $version);
		if (empty($if_names_walk)) {
			$this->log("snmpWalk: ifName walk returned empty, trying fallback to ifDescr");
			$if_names_walk = $this->snmpWalk($ip, $community, '1.3.6.1.2.1.2.2.1.2', $version);
		}

		$if_map = [];
		foreach ($if_names_walk as $key => $val) {
			$parts = explode('.', $key);
			$if_index = end($parts);
			$if_map[$if_index] = $val;
		}
		return $if_map;
	}

	/**
	 * Descobre vizinhos de rede usando LLDP, CDP e EDP.
	 *
	 * @param string $ip
	 * @param string $community
	 * @param int $version
	 * @param array $if_map Mapeamento local de ifIndex => ifName
	 * @return array
	 */
	public function discoverNeighbors(string $ip, string $community, int $version, array $if_map): array {
		// 1. Tenta por LLDP primeiro
		$neighbors = $this->discoverLldp($ip, $community, $version, $if_map);

		// 2. Tenta por CDP se nenhum vizinho LLDP for encontrado
		if (empty($neighbors)) {
			$neighbors = $this->discoverCdp($ip, $community, $version, $if_map);
		} else {
			$this->log("discoverNeighbors: Skipping CDP OIDs because LLDP neighbors were found.");
		}

		// 3. Tenta por EDP se nenhum vizinho LLDP ou CDP for encontrado
		if (empty($neighbors)) {
			$neighbors = $this->discoverEdp($ip, $community, $version, $if_map);
		} else {
			$this->log("discoverNeighbors: Skipping EDP OIDs because LLDP or CDP neighbors were found.");
		}

		return $neighbors;
	}

	private function discoverLldp(string $ip, string $community, int $version, array $if_map): array {
		$neighbors = [];
		$this->log("discoverLldp: Walking LLDP OIDs");
		
		$lldp_ports = $this->snmpWalk($ip, $community, '1.0.8802.1.1.2.1.4.1.1.7', $version);
		if (empty($lldp_ports)) {
			$this->log("discoverLldp: No LLDP neighbors found. Skipping other LLDP walks.");
			return [];
		}

		$lldp_names = $this->snmpWalk($ip, $community, '1.0.8802.1.1.2.1.4.1.1.9', $version);
		$lldp_chassis = $this->snmpWalk($ip, $community, '1.0.8802.1.1.2.1.4.1.1.5', $version);
		$lldp_descs = $this->snmpWalk($ip, $community, '1.0.8802.1.1.2.1.4.1.1.8', $version);
		$lldp_ips = $this->snmpWalk($ip, $community, '1.0.8802.1.1.2.1.4.2.1.3', $version);

		// Mapeamento de lldpLocPortNum para ifIndex (importante para Extreme Networks/ExtremeXOS)
		$lldp_loc_ports = $this->snmpWalk($ip, $community, '1.0.8802.1.1.2.1.3.7.1.3', $version);
		$lldp_loc_port_to_ifindex = [];
		foreach ($lldp_loc_ports as $key => $val) {
			$parts = explode('.', $key);
			$lldp_loc_port_num = end($parts);
			$lldp_loc_port_to_ifindex[$lldp_loc_port_num] = $val;
		}

		// Indexa tabelas por sufixo normalizado (LocalPortNum.RemIndex)
		$lldp_ports_indexed = [];
		foreach ($lldp_ports as $key => $val) {
			$suffix = $this->getLLDPSuffix($key);
			if ($suffix !== '') {
				$parts = explode('.', $suffix);
				$norm = implode('.', array_slice($parts, -2));
				$lldp_ports_indexed[$norm] = $val;
			}
		}

		$lldp_names_indexed = [];
		foreach ($lldp_names as $key => $val) {
			$suffix = $this->getLLDPSuffix($key);
			if ($suffix !== '') {
				$parts = explode('.', $suffix);
				$norm = implode('.', array_slice($parts, -2));
				$lldp_names_indexed[$norm] = $val;
			}
		}

		$lldp_chassis_indexed = [];
		foreach ($lldp_chassis as $key => $val) {
			$suffix = $this->getLLDPSuffix($key);
			if ($suffix !== '') {
				$parts = explode('.', $suffix);
				$norm = implode('.', array_slice($parts, -2));
				$lldp_chassis_indexed[$norm] = $val;
			}
		}

		$lldp_descs_indexed = [];
		foreach ($lldp_descs as $key => $val) {
			$suffix = $this->getLLDPSuffix($key);
			if ($suffix !== '') {
				$parts = explode('.', $suffix);
				$norm = implode('.', array_slice($parts, -2));
				$lldp_descs_indexed[$norm] = $val;
			}
		}

		$lldp_ips_indexed = [];
		foreach ($lldp_ips as $key => $val) {
			if (preg_match('/\.8802\.1\.1\.2\.1\.4\.2\.1\.3\.(.+)$/', $key, $matches)) {
				$parts = explode('.', $matches[1]);
				$count = count($parts);
				if ($count >= 6) {
					$subtype = (int)$parts[$count - 6];
					$addr_len = (int)$parts[$count - 5];
					if ($subtype === 1 && $addr_len === 4) {
						$ip_octets = array_slice($parts, -4);
						$neighbor_ip = implode('.', $ip_octets);
						$neighbor_suffix_parts = array_slice($parts, 0, $count - 6);
						$norm = implode('.', array_slice($neighbor_suffix_parts, -2));
						$lldp_ips_indexed[$norm] = $neighbor_ip;
					}
				}
			}
		}

		// Agrupa sufixos lógicos únicos encontrados nas tabelas principais do LLDP
		$norms = [];
		foreach ($lldp_ports as $key => $val) {
			$suffix = $this->getLLDPSuffix($key);
			if ($suffix !== '') {
				$parts = explode('.', $suffix);
				$norm = implode('.', array_slice($parts, -2));
				$norms[$norm] = $parts;
			}
		}
		foreach ($lldp_names as $key => $val) {
			$suffix = $this->getLLDPSuffix($key);
			if ($suffix !== '') {
				$parts = explode('.', $suffix);
				$norm = implode('.', array_slice($parts, -2));
				if (!isset($norms[$norm])) {
					$norms[$norm] = $parts;
				}
			}
		}
		foreach ($lldp_chassis as $key => $val) {
			$suffix = $this->getLLDPSuffix($key);
			if ($suffix !== '') {
				$parts = explode('.', $suffix);
				$norm = implode('.', array_slice($parts, -2));
				if (!isset($norms[$norm])) {
					$norms[$norm] = $parts;
				}
			}
		}

		foreach ($norms as $norm => $parts) {
			$local_port_index = (count($parts) >= 3) ? $parts[1] : $parts[0];
			$neighbor_name = isset($lldp_names_indexed[$norm]) ? $lldp_names_indexed[$norm] : '';
			$remote_port = isset($lldp_ports_indexed[$norm]) ? $lldp_ports_indexed[$norm] : '';
			$remote_desc = isset($lldp_descs_indexed[$norm]) ? $lldp_descs_indexed[$norm] : '';
			$neighbor_ip = isset($lldp_ips_indexed[$norm]) ? $lldp_ips_indexed[$norm] : '-';
			$chassis_id = isset($lldp_chassis_indexed[$norm]) ? $lldp_chassis_indexed[$norm] : '';

			$mac = null;
			if ($mac === null && $chassis_id !== '') {
				$mac = $this->extractMacAddress($chassis_id);
			}
			if ($mac === null && $remote_port !== '') {
				$mac = $this->extractMacAddress($remote_port);
			}
			if ($mac === null && $neighbor_name !== '') {
				$mac = $this->extractMacAddress($neighbor_name);
			}

			$neighbor_port = $remote_port;
			if ($remote_desc !== '' && $remote_desc !== $remote_port) {
				$neighbor_port .= ' (' . $remote_desc . ')';
			}

			// Tenta converter o índice de porta local do LLDP para ifIndex usando o mapeamento do LLDP-MIB
			$if_index = isset($lldp_loc_port_to_ifindex[$local_port_index]) 
				? $lldp_loc_port_to_ifindex[$local_port_index] 
				: $local_port_index;

			$local_port_name = isset($if_map[$if_index]) ? $if_map[$if_index] : $local_port_index;
			$local_port_display = ($local_port_name != $local_port_index) ? $local_port_name . ' (' . $local_port_index . ')' : $local_port_index;

			$display_name = $neighbor_name;
			if ($display_name === '') {
				$display_name = $chassis_id;
			}
			if ($display_name === '') {
				$display_name = '(Desconhecido)';
			}

			$neighbors[] = [
				'protocol' => 'LLDP',
				'local_port' => $local_port_display,
				'neighbor_name' => $display_name,
				'neighbor_ip' => $neighbor_ip,
				'neighbor_port' => $neighbor_port ?: '(Desconhecida)',
				'mac' => $mac,
				'is_distribution' => $this->isDistributionDevice($display_name, $remote_port)
			];
		}

		return $neighbors;
	}

	private function discoverCdp(string $ip, string $community, int $version, array $if_map): array {
		$neighbors = [];
		$this->log("discoverCdp: Walking CDP OIDs");
		$cdp_names = $this->snmpWalk($ip, $community, '1.3.6.1.4.1.9.9.23.1.2.1.1.6', $version);
		if (empty($cdp_names)) {
			$this->log("discoverCdp: No CDP neighbors found. Skipping other CDP walks.");
			return [];
		}

		$cdp_ports = $this->snmpWalk($ip, $community, '1.3.6.1.4.1.9.9.23.1.2.1.1.7', $version);
		$cdp_ips = $this->snmpWalk($ip, $community, '1.3.6.1.4.1.9.9.23.1.2.1.1.4', $version);

		$cdp_ports_indexed = [];
		foreach ($cdp_ports as $key => $val) {
			$suffix = $this->getCDPSuffix($key);
			if ($suffix !== '') {
				$cdp_ports_indexed[$suffix] = $val;
			}
		}

		$cdp_ips_indexed = [];
		foreach ($cdp_ips as $key => $val) {
			$suffix = $this->getCDPSuffix($key);
			if ($suffix !== '') {
				$cdp_ips_indexed[$suffix] = $val;
			}
		}

		foreach ($cdp_names as $key => $neighbor_name) {
			$suffix = $this->getCDPSuffix($key);
			if ($suffix !== '') {
				$suffix_parts = explode('.', $suffix);
				$local_port_index = $suffix_parts[0];

				$neighbor_port = isset($cdp_ports_indexed[$suffix]) ? $cdp_ports_indexed[$suffix] : '';
				$neighbor_ip = isset($cdp_ips_indexed[$suffix]) ? $this->parseSnmpIp($cdp_ips_indexed[$suffix]) : '-';

				$local_port_name = isset($if_map[$local_port_index]) ? $if_map[$local_port_index] : $local_port_index;
				$local_port_display = ($local_port_name != $local_port_index) ? $local_port_name . ' (' . $local_port_index . ')' : $local_port_index;

				$mac = null;
				if ($neighbor_port !== '') {
					$mac = $this->extractMacAddress($neighbor_port);
				}
				if ($mac === null && $neighbor_name !== '') {
					$mac = $this->extractMacAddress($neighbor_name);
				}

				$neighbors[] = [
					'protocol' => 'CDP',
					'local_port' => $local_port_display,
					'neighbor_name' => $neighbor_name ?: '(Desconhecido)',
					'neighbor_ip' => $neighbor_ip,
					'neighbor_port' => $neighbor_port ?: '(Desconhecida)',
					'mac' => $mac,
					'is_distribution' => $this->isDistributionDevice($neighbor_name ?: '', $neighbor_port)
				];
			}
		}

		return $neighbors;
	}

	private function discoverEdp(string $ip, string $community, int $version, array $if_map): array {
		$neighbors = [];
		$this->log("discoverEdp: Walking EDP OIDs");
		$edp_names = $this->snmpWalk($ip, $community, '1.3.6.1.4.1.1916.1.13.2.1.2', $version);
		if (empty($edp_names)) {
			$this->log("discoverEdp: No EDP neighbors found. Skipping other EDP walks.");
			return [];
		}

		$edp_ports = $this->snmpWalk($ip, $community, '1.3.6.1.4.1.1916.1.13.2.1.6', $version);
		$edp_ips = $this->snmpWalk($ip, $community, '1.3.6.1.4.1.1916.1.13.2.1.3', $version);

		$edp_ports_indexed = [];
		foreach ($edp_ports as $key => $val) {
			$suffix = $this->getEDPSuffix($key);
			if ($suffix !== '') {
				$edp_ports_indexed[$suffix] = $val;
			}
		}

		$edp_ips_indexed = [];
		foreach ($edp_ips as $key => $val) {
			$suffix = $this->getEDPSuffix($key);
			if ($suffix !== '') {
				$edp_ips_indexed[$suffix] = $val;
			}
		}

		foreach ($edp_names as $key => $neighbor_name) {
			$suffix = $this->getEDPSuffix($key);
			if ($suffix !== '') {
				$suffix_parts = explode('.', $suffix);
				$local_port_index = $suffix_parts[0];

				$neighbor_port = isset($edp_ports_indexed[$suffix]) ? $edp_ports_indexed[$suffix] : '';
				$neighbor_ip = isset($edp_ips_indexed[$suffix]) ? $this->parseSnmpIp($edp_ips_indexed[$suffix]) : '-';

				$local_port_name = isset($if_map[$local_port_index]) ? $if_map[$local_port_index] : $local_port_index;
				$local_port_display = ($local_port_name != $local_port_index) ? $local_port_name . ' (' . $local_port_index . ')' : $local_port_index;

				$mac = null;
				if ($neighbor_port !== '') {
					$mac = $this->extractMacAddress($neighbor_port);
				}
				if ($mac === null && $neighbor_name !== '') {
					$mac = $this->extractMacAddress($neighbor_name);
				}

				$neighbors[] = [
					'protocol' => 'EDP',
					'local_port' => $local_port_display,
					'neighbor_name' => $neighbor_name ?: '(Desconhecido)',
					'neighbor_ip' => $neighbor_ip,
					'neighbor_port' => $neighbor_port ?: '(Desconhecida)',
					'mac' => $mac,
					'is_distribution' => $this->isDistributionDevice($neighbor_name ?: '', $neighbor_port)
				];
			}
		}

		return $neighbors;
	}

	private function getLLDPSuffix(string $key): string {
		if (preg_match('/\.8802\.1\.1\.2\.1\.4\.1\.1\.[5789]\.(.+)$/', $key, $matches)) {
			return $matches[1];
		}
		if (preg_match('/lldpRem(?:SysName|PortId|PortDesc|ChassisId)\.(.+)$/', $key, $matches)) {
			return $matches[1];
		}
		$parts = explode('.', $key);
		return (count($parts) >= 2) ? implode('.', array_slice($parts, -2)) : '';
	}

	private function getCDPSuffix(string $key): string {
		if (preg_match('/\.9\.9\.23\.1\.2\.1\.1\.[67]\.(.+)$/', $key, $matches)) {
			return $matches[1];
		}
		if (preg_match('/cdpCache(?:DeviceId|DevicePort)\.(.+)$/', $key, $matches)) {
			return $matches[1];
		}
		$parts = explode('.', $key);
		return (count($parts) >= 2) ? implode('.', array_slice($parts, -2)) : '';
	}

	private function getEDPSuffix(string $key): string {
		if (preg_match('/\.1916\.1\.13\.2\.1\.[26]\.(.+)$/', $key, $matches)) {
			return $matches[1];
		}
		if (preg_match('/extremeEdpNeighbor(?:Name|Port)\.(.+)$/', $key, $matches)) {
			return $matches[1];
		}
		$parts = explode('.', $key);
		return (count($parts) >= 2) ? implode('.', array_slice($parts, -2)) : '';
	}

	/**
	 * Decodifica endereços IP retornados por SNMP para o formato legível IPv4.
	 *
	 * @param string $val
	 * @return string
	 */
	public function parseSnmpIp(string $val): string {
		$val = trim($val, '" ');
		if (filter_var($val, FILTER_VALIDATE_IP)) {
			return $val;
		}
		$clean_hex = preg_replace('/[^a-fA-F0-9]/', '', $val);
		if (strlen($clean_hex) === 8) {
			$octets = [];
			for ($i = 0; $i < 8; $i += 2) {
				$octets[] = hexdec(substr($clean_hex, $i, 2));
			}
			$ip = implode('.', $octets);
			if (filter_var($ip, FILTER_VALIDATE_IP)) {
				return (string)$ip;
			}
		}
		return $val;
	}

	/**
	 * Extrai e normaliza um endereço MAC no padrão AA:BB:CC:DD:EE:FF.
	 *
	 * @param string $val
	 * @return string|null
	 */
	public function extractMacAddress(string $val): ?string {
		$val = trim($val, '" ');
		$val = preg_replace('/^(Hex-STRING|Hex-String|HEX-STRING|STRING|OID):\s*/i', '', $val);
		$val = trim($val, '" ');

		if (strlen($val) === 6) {
			$hex = bin2hex($val);
			$parts = str_split($hex, 2);
			return strtoupper(implode(':', $parts));
		}

		if (preg_match('/([0-9a-fA-F]{2}[:-]){5}[0-9a-fA-F]{2}/', $val, $matches)) {
			return strtoupper(str_replace('-', ':', $matches[0]));
		}

		$clean = preg_replace('/[^a-fA-F0-9]/', '', $val);
		if (strlen($clean) === 12) {
			$parts = str_split($clean, 2);
			return strtoupper(implode(':', $parts));
		}

		return null;
	}
}
