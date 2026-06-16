<?php declare(strict_types = 1);

namespace Modules\DiscoveryNeighbors\Actions;

use CController;
use CControllerResponseData;
use API;
use CRoleHelper;
use Modules\DiscoveryNeighbors\Services\MacroResolverService;
use Modules\DiscoveryNeighbors\Services\SnmpDiscoveryService;
use Modules\DiscoveryNeighbors\Services\ZabbixApiResolver;

class DiscoveryPopup extends CController {

	/**
	 * Log de depuração desativado para produção para evitar vazamento de credenciais.
	 *
	 * @param string $message
	 */
	private function logDebug(string $message): void {
		// Log desativado
	}


	protected function init() {
		$this->disableCsrfValidation();
		$this->logDebug("init: CSRF validation disabled");
	}

	protected function checkInput(): bool {
		$this->logDebug("checkInput: Validating input fields. Request URI: " . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'N/A'));
		$this->logDebug("checkInput: Request parameters: " . json_encode($_REQUEST));

		$fields = [
			'hostid' => 'db hosts.hostid',
			'ip' => 'db interface.ip',
			'community' => 'db interface_snmp.community',
			'version' => 'db interface_snmp.version'
		];

		$ret = $this->validateInput($fields);
		if ($ret && !$this->hasInput('hostid') && !$this->hasInput('ip')) {
			$this->logDebug("checkInput: Validation succeeded but neither 'hostid' nor 'ip' was provided");
			$ret = false;
		}

		$this->logDebug("checkInput: Result is " . ($ret ? 'true' : 'false'));

		if (!$ret) {
			$this->setResponse(new \CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		$this->logDebug("checkPermissions: Verifying user role permissions");
		if (!$this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS) && !$this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS)) {
			$this->logDebug("checkPermissions: Role helper check failed");
			return false;
		}

		if ($this->hasInput('hostid')) {
			$hosts = API::Host()->get([
				'output' => ['hostid'],
				'hostids' => $this->getInput('hostid')
			]);

			$has_access = (bool) $hosts;
			$this->logDebug("checkPermissions: Host read access check " . ($has_access ? 'passed' : 'failed'));
			return $has_access;
		}

		$this->logDebug("checkPermissions: IP-based ad-hoc query permitted");
		return true;
	}

	protected function doAction() {
		$neighbors = [];
		$error_message = '';
		$total_start = microtime(true);

		// 1. Verifica se a extensão php-snmp está instalada
		if (!extension_loaded('snmp')) {
			$this->logDebug("doAction: php-snmp extension is NOT loaded!");
			$this->setResponse(new CControllerResponseData([
				'neighbors' => [],
				'error' => $this->translate('extension_error')
			]));
			return;
		}

		try {
			$log_callback = function(string $message): void {
				$this->logDebug($message);
			};

			$snmpDiscovery = new SnmpDiscoveryService($log_callback);
			$zabbixApi = new ZabbixApiResolver($log_callback);

			$ip = '';
			$resolved_community = 'public';
			$version = 2;
			$host_name = '';

			if ($this->hasInput('hostid')) {
				$hostid = $this->getInput('hostid');
				$this->logDebug("doAction: Action execution started for hostid " . $hostid);

				// 2. Busca informações do host e suas interfaces SNMP
				$step_start = microtime(true);
				$hosts = API::Host()->get([
					'output' => ['hostid', 'name'],
					'hostids' => $hostid,
					'selectInterfaces' => ['interfaceid', 'main', 'type', 'useip', 'ip', 'dns', 'details']
				]);

				if (!$hosts) {
					$this->logDebug(sprintf("doAction: Host not found. Time taken: %.4fs", microtime(true) - $step_start));
					$this->setResponse(new CControllerResponseData([
						'neighbors' => [],
						'error' => $this->translate('host_not_found')
					]));
					return;
				}

				$host = $hosts[0];
				$host_name = $host['name'];
				$this->logDebug(sprintf("doAction: Found host %s. API Host time taken: %.4fs", $host['name'], microtime(true) - $step_start));
				$snmp_interface = null;

				foreach ($host['interfaces'] as $interface) {
					if ($interface['type'] == 2) { // INTERFACE_TYPE_SNMP
						if ($snmp_interface === null || $interface['main'] == 1) {
							$snmp_interface = $interface;
						}
					}
				}

				if (!$snmp_interface) {
					$this->logDebug("doAction: Host " . $host['name'] . " does not have any SNMP interfaces");
					$this->setResponse(new CControllerResponseData([
						'neighbors' => [],
						'error' => $this->translate('no_snmp_interface')
					]));
					return;
				}

				$ip = $snmp_interface['useip'] == 1 ? $snmp_interface['ip'] : $snmp_interface['dns'];
				$details = $snmp_interface['details'];
				$community_raw = isset($details['community']) ? $details['community'] : 'public';
				$version = isset($details['version']) ? (int)$details['version'] : 2; // Default para SNMPv2c

				// Resolve a comunidade SNMP via macros
				$macroResolver = new MacroResolverService($hostid, $log_callback);
				$step_start = microtime(true);
				$resolved_community = $macroResolver->resolve($community_raw, 'public');
				$this->logDebug(sprintf("doAction: SNMP Community resolved. Macro resolution time taken: %.4fs", microtime(true) - $step_start));
			} else {
				$ip = $this->getInput('ip');
				$resolved_community = $this->getInput('community', 'public');
				$version = (int)$this->getInput('version', 2);
				$host_name = $ip;
				$this->logDebug("doAction: Action execution started for ad-hoc IP: " . $ip);
			}

			// 4. Define configurações SNMP temporárias para formato numérico e simplificado
			if (function_exists('snmp_set_oid_numeric_print')) {
				try {
					@snmp_set_oid_numeric_print(defined('SNMP_OID_OUTPUT_NUMERIC') ? SNMP_OID_OUTPUT_NUMERIC : 4);
				} catch (\Throwable $t) {}
			}
			if (function_exists('snmp_set_quick_print')) {
				try {
					@snmp_set_quick_print(true);
				} catch (\Throwable $t) {}
			}

			// 5. Executa a leitura de ifName/ifDescr para mapear as portas locais
			$step_start = microtime(true);
			$this->logDebug("doAction: Querying interface names");
			$if_map = $snmpDiscovery->getInterfaceMap($ip, $resolved_community, $version);
			$this->logDebug(sprintf("doAction: Local interfaces mapped: %d. Time taken: %.4fs", count($if_map), microtime(true) - $step_start));

			// 6. Consultas e parsing para vizinhos LLDP/CDP/EDP
			$step_start = microtime(true);
			$neighbors = $snmpDiscovery->discoverNeighbors($ip, $resolved_community, $version, $if_map);
			$this->logDebug(sprintf("doAction: Done compiling SNMP neighbors. Count: %d. Time taken: %.4fs", count($neighbors), microtime(true) - $step_start));

			// Restaura configurações SNMP originais
			if (function_exists('snmp_set_oid_numeric_print')) {
				try {
					@snmp_set_oid_numeric_print(defined('SNMP_OID_OUTPUT_MODULE') ? SNMP_OID_OUTPUT_MODULE : 2);
				} catch (\Throwable $t) {}
			}
			if (function_exists('snmp_set_quick_print')) {
				try {
					@snmp_set_quick_print(false);
				} catch (\Throwable $t) {}
			}

			// Tratamento de falha caso nenhum dado tenha sido retornado
			if (empty($neighbors)) {
				$error_message = $this->translate('snmp_read_error');
				$this->logDebug("doAction: No network neighbors found.");
			} else {
				// Mapeia os vizinhos a hosts existentes no Zabbix por Nome/IP
				$neighbors = $zabbixApi->mapNeighborsToZabbixHosts($neighbors);
				
				$this->logDebug(sprintf("doAction: Done mapping neighbors. Total action execution time: %.4fs", microtime(true) - $total_start));
			}

			$csrf_token = class_exists('CCsrfTokenHelper') ? \CCsrfTokenHelper::get('discovery.popup') : '';
			$this->logDebug("doAction: Generated CSRF token: " . ($csrf_token ? 'SUCCESS' : 'EMPTY'));

			$this->setResponse(new CControllerResponseData([
				'neighbors' => $neighbors,
				'error' => $error_message,
				'host_name' => $host_name,
				'community' => $resolved_community,
				'version' => $version,
				'csrf_token' => $csrf_token
			]));

		} catch (\Throwable $e) {
			$this->logDebug(sprintf("doAction Exception: %s at %s:%d. Execution time up to crash: %.4fs", $e->getMessage(), $e->getFile(), $e->getLine(), microtime(true) - $total_start));
			$this->logDebug("doAction Exception Stack: " . $e->getTraceAsString());

			$csrf_token = class_exists('CCsrfTokenHelper') ? \CCsrfTokenHelper::get('discovery.popup') : '';
			$this->logDebug("doAction Catch: Generated CSRF token: " . ($csrf_token ? 'SUCCESS' : 'EMPTY'));

			$this->setResponse(new CControllerResponseData([
				'neighbors' => [],
				'error' => $this->translate('internal_error') . $e->getMessage(),
				'host_name' => $host_name,
				'csrf_token' => $csrf_token
			]));
		}
	}

	/**
	 * Retorna a string traduzida com base no idioma do usuário no Zabbix.
	 *
	 * @param string $key
	 * @return string
	 */
	private function translate(string $key): string {
		$lang = isset(\CWebUser::$data['lang']) ? \CWebUser::$data['lang'] : 'en_US';
		$is_pt = (strpos($lang, 'pt_') === 0);

		$translations = [
			'extension_error' => [
				'pt' => 'A extensão PHP SNMP não está instalada ou ativada no servidor web.',
				'en' => 'The PHP SNMP extension is not installed or enabled on the web server.'
			],
			'host_not_found' => [
				'pt' => 'Host não encontrado ou permissão negada.',
				'en' => 'Host not found or access denied.'
			],
			'no_snmp_interface' => [
				'pt' => 'O host não possui nenhuma interface SNMP configurada.',
				'en' => 'The host does not have any SNMP interfaces configured.'
			],
			'snmp_read_error' => [
				'pt' => 'Não foi possível ler dados SNMP do host. Verifique se o equipamento está online e se as credenciais de comunidade SNMP estão corretas.',
				'en' => 'Unable to read SNMP data from host. Verify if the device is online and if the SNMP community credentials are correct.'
			],
			'internal_error' => [
				'pt' => 'Ocorreu um erro interno durante a consulta: ',
				'en' => 'An internal error occurred during the query: '
			]
		];

		if (isset($translations[$key])) {
			return $is_pt ? $translations[$key]['pt'] : $translations[$key]['en'];
		}

		return $key;
	}
}
