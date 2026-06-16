<?php declare(strict_types = 1);

namespace Modules\DiscoveryNeighbors\Services;

use API;

class MacroResolverService {

	private string $hostid;
	private \Closure $log_callback;

	/**
	 * @param string $hostid ID do host Zabbix contexto
	 * @param \Closure $log_callback Callback de log de depuração
	 */
	public function __construct(string $hostid, \Closure $log_callback) {
		$this->hostid = $hostid;
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
	 * Resolve uma macro de usuário genérica ({$MACRO_NAME}).
	 *
	 * @param string $macro_name Ex: '{$SNMP_COMMUNITY}'
	 * @param string $default Valor retornado em caso de falha de resolução
	 * @return string
	 */
	public function resolve(string $macro_name, string $default): string {
		if (strpos($macro_name, '{$') === false) {
			return $macro_name;
		}

		try {
			// Tenta utilizar o resolvedor nativo do Zabbix
			$resolved = \CMacrosResolverHelper::resolve([
				'config' => 'hostInterfaceDetailsCommunity',
				'data' => [
					$this->hostid => [
						0 => $macro_name
					]
				]
			]);
			if (isset($resolved[$this->hostid][0]) && $resolved[$this->hostid][0] !== $macro_name) {
				return $resolved[$this->hostid][0];
			}
		} catch (\Throwable $e) {}

		// Fallback manual 1: Busca macros específicas do Host
		try {
			$host_macros = API::UserMacro()->get([
				'output' => ['macro', 'value'],
				'hostids' => $this->hostid
			]);
			foreach ($host_macros as $macro) {
				if ($macro['macro'] === $macro_name) {
					return $macro['value'];
				}
			}
		} catch (\Throwable $e) {
			$this->log("MacroResolverService: Host macro API call failed: " . $e->getMessage());
		}

		// Fallback manual 2: Busca macros globais do Zabbix
		try {
			$global_macros = API::UserMacro()->get([
				'output' => ['macro', 'value'],
				'globalmacro' => true
			]);
			foreach ($global_macros as $macro) {
				if ($macro['macro'] === $macro_name) {
					return $macro['value'];
				}
			}
		} catch (\Throwable $e) {
			$this->log("MacroResolverService: Global macro API call failed: " . $e->getMessage());
		}

		return $default;
	}
}
