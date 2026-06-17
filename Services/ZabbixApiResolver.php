<?php declare(strict_types = 1);

namespace Modules\DiscoveryNeighbors\Services;

use API;

class ZabbixApiResolver {

	private \Closure $log_callback;

	/**
	 * @param \Closure $log_callback Callback de log de depuração
	 */
	public function __construct(\Closure $log_callback) {
		$this->log_callback = $log_callback;
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
	 * Mapeia vizinhos resolvidos aos respectivos Hosts do Zabbix por Nome e por IP de Interface.
	 *
	 * @param array $neighbors
	 * @return array
	 */
	public function mapNeighborsToZabbixHosts(array $neighbors): array {
		$neighbor_names = [];
		$neighbor_ips = [];

		foreach ($neighbors as $neighbor) {
			$clean_name = trim(explode('(', $neighbor['neighbor_name'])[0]);
			if ($clean_name !== '' && $clean_name !== '(Desconhecido)') {
				$neighbor_names[$clean_name] = true;
			}
			if ($neighbor['neighbor_ip'] !== '-' && $neighbor['neighbor_ip'] !== '') {
				$neighbor_ips[$neighbor['neighbor_ip']] = true;
			}
		}

		$this->log("ZabbixApiResolver: Neighbor names to search in Zabbix: " . json_encode(array_keys($neighbor_names)));
		$this->log("ZabbixApiResolver: Neighbor IPs to search in Zabbix: " . json_encode(array_keys($neighbor_ips)));

		$hosts_map = [];

		// 1. Busca por Nome (Visível e Técnico)
		if (!empty($neighbor_names)) {
			$lower_neighbor_names = array_change_key_case($neighbor_names, CASE_LOWER);
			try {
				// 1.1 Busca por Nome Visível (case-insensitive usando search)
				$db_hosts = API::Host()->get([
					'output' => ['hostid', 'name', 'host'],
					'search' => ['name' => array_keys($neighbor_names)],
					'selectInterfaces' => ['ip', 'main', 'type', 'useip']
				]);
				$this->log("ZabbixApiResolver: Visible name search result: " . json_encode($db_hosts));

				foreach ($db_hosts as $db_host) {
					$db_name_lower = strtolower($db_host['name']);
					if (isset($lower_neighbor_names[$db_name_lower])) {
						$ip_val = '-';
						foreach ($db_host['interfaces'] as $iface) {
							if ($iface['main'] == 1 && $iface['ip'] !== '') {
								$ip_val = $iface['ip'];
								break;
							}
						}
						$host_data = [
							'hostid' => $db_host['hostid'],
							'name' => $db_host['name'],
							'host' => $db_host['host'],
							'ip' => $ip_val
						];
						$hosts_map[$db_name_lower] = $host_data;
						$hosts_map[strtolower($db_host['host'])] = $host_data;
					}
				}

				// 1.2 Busca por Nome Técnico para os que ainda restarem
				$remaining_names = [];
				foreach (array_keys($neighbor_names) as $name) {
					if (!isset($hosts_map[strtolower($name)])) {
						$remaining_names[] = $name;
					}
				}

				if (!empty($remaining_names)) {
					$this->log("ZabbixApiResolver: Technical name search remaining names: " . json_encode($remaining_names));
					$db_hosts_tech = API::Host()->get([
						'output' => ['hostid', 'name', 'host'],
						'search' => ['host' => $remaining_names],
						'selectInterfaces' => ['ip', 'main', 'type', 'useip']
					]);
					$this->log("ZabbixApiResolver: Technical name search result: " . json_encode($db_hosts_tech));
					
					$lower_remaining_names = array_change_key_case(array_flip($remaining_names), CASE_LOWER);
					foreach ($db_hosts_tech as $db_host) {
						$db_host_lower = strtolower($db_host['host']);
						if (isset($lower_remaining_names[$db_host_lower])) {
							$ip_val = '-';
							foreach ($db_host['interfaces'] as $iface) {
								if ($iface['main'] == 1 && $iface['ip'] !== '') {
									$ip_val = $iface['ip'];
									break;
								}
							}
							$host_data = [
								'hostid' => $db_host['hostid'],
								'name' => $db_host['name'],
								'host' => $db_host['host'],
								'ip' => $ip_val
							];
							$hosts_map[strtolower($db_host['name'])] = $host_data;
							$hosts_map[$db_host_lower] = $host_data;
						}
					}
				}
			} catch (\Throwable $e) {
				$this->log("ZabbixApiResolver: Host Map Name Exception: " . $e->getMessage());
			}
		}

		// 2. Busca por IP de Interface
		$hosts_by_ip = [];
		if (!empty($neighbor_ips)) {
			try {
				$db_interfaces = API::HostInterface()->get([
					'output' => ['hostid', 'ip'],
					'filter' => ['ip' => array_keys($neighbor_ips)]
				]);
				$this->log("ZabbixApiResolver: Zabbix interface IP search result: " . json_encode($db_interfaces));
				
				$ip_to_hostid = [];
				foreach ($db_interfaces as $iface) {
					$ip_to_hostid[$iface['ip']] = $iface['hostid'];
				}

				if (!empty($ip_to_hostid)) {
					$db_hosts_by_ip = API::Host()->get([
						'output' => ['hostid', 'name', 'host'],
						'hostids' => array_values($ip_to_hostid),
						'selectInterfaces' => ['ip', 'main', 'type', 'useip']
					]);
					$this->log("ZabbixApiResolver: Zabbix hosts for IP interfaces: " . json_encode($db_hosts_by_ip));
					foreach ($db_hosts_by_ip as $db_host) {
						$ip_val = '-';
						foreach ($db_host['interfaces'] as $iface) {
							if ($iface['main'] == 1 && $iface['ip'] !== '') {
								$ip_val = $iface['ip'];
								break;
							}
						}
						$host_data = [
							'hostid' => $db_host['hostid'],
							'name' => $db_host['name'],
							'host' => $db_host['host'],
							'ip' => $ip_val
						];
						foreach ($ip_to_hostid as $ip_addr => $hid) {
							if ($hid === $db_host['hostid']) {
								$hosts_by_ip[$ip_addr] = $host_data;
							}
						}
					}
				}
			} catch (\Throwable $e) {
				$this->log("ZabbixApiResolver: Host Map IP Exception: " . $e->getMessage());
			}
		}

		// 3. Aplica os mapeamentos na lista de vizinhos
		foreach ($neighbors as $idx => $neighbor) {
			$matched_host = null;

			// 3.1 Tenta mapeamento pelo nome (case-insensitive)
			$clean_name = trim(explode('(', $neighbor['neighbor_name'])[0]);
			$clean_name_lower = strtolower($clean_name);
			if ($clean_name !== '' && $clean_name !== '(Desconhecido)' && isset($hosts_map[$clean_name_lower])) {
				$matched_host = $hosts_map[$clean_name_lower];
				$this->log("ZabbixApiResolver: Matched neighbor {$neighbor['neighbor_name']} by name to hostid " . $matched_host['hostid']);
			}

			// 3.2 Tenta mapeamento pelo IP se o nome falhar
			if ($matched_host === null && $neighbor['neighbor_ip'] !== '-' && isset($hosts_by_ip[$neighbor['neighbor_ip']])) {
				$matched_host = $hosts_by_ip[$neighbor['neighbor_ip']];
				$this->log("ZabbixApiResolver: Matched neighbor {$neighbor['neighbor_name']} with IP {$neighbor['neighbor_ip']} by IP interface to hostid " . $matched_host['hostid']);
			}

			if ($matched_host !== null) {
				$neighbors[$idx]['neighbor_hostid'] = $matched_host['hostid'];
				$neighbors[$idx]['neighbor_name'] = $matched_host['name']; // Substitui pelo Nome Visível amigável
				if ($neighbor['neighbor_ip'] === '-' && $matched_host['ip'] !== '-') {
					$neighbors[$idx]['neighbor_ip'] = $matched_host['ip'];
				}
			} else {
				$neighbors[$idx]['neighbor_hostid'] = null;
				$this->log("ZabbixApiResolver: Neighbor {$neighbor['neighbor_name']} with IP {$neighbor['neighbor_ip']} had no Zabbix match.");
			}
		}

		return $neighbors;
	}
}
