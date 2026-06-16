<?php declare(strict_types = 1);

namespace Modules\DiscoveryNeighbors\Actions;

use CControllerMenuPopup;
use CControllerResponseData;
use API;

class MenuPopupExtended extends CControllerMenuPopup {

	protected function doAction() {
		// Executa a ação original do Zabbix para obter o menu
		parent::doAction();

		$response = $this->getResponse();
		if ($response instanceof CControllerResponseData) {
			$data = $response->getData();
			if (isset($data['main_block'])) {
				$main_block_data = json_decode($data['main_block'], true);

				// Verifica se o menu popup é associado a um Host
				if (isset($main_block_data['data']['type']) && $main_block_data['data']['type'] === 'host') {
					$hostid = $main_block_data['data']['hostid'];

					// Busca o host e suas interfaces para validar se possui SNMP (tipo 2)
					$hosts = API::Host()->get([
						'output' => ['hostid'],
						'hostids' => $hostid,
						'selectInterfaces' => ['type']
					]);

					$has_snmp = false;
					if ($hosts) {
						foreach ($hosts[0]['interfaces'] as $interface) {
							if ($interface['type'] == 2) { // 2 = INTERFACE_TYPE_SNMP
								$has_snmp = true;
								break;
							}
						}
					}

					// Somente injeta o link se o host tiver interface SNMP configurada
					if ($has_snmp) {
						if (!isset($main_block_data['data']['urls'])) {
							$main_block_data['data']['urls'] = [];
						}

						// Injeta a opção no menu "Links" traduzida conforme o idioma do usuário
						$label = (isset(\CWebUser::$data['lang']) && strpos(\CWebUser::$data['lang'], 'pt_') === 0)
							? 'Ver Vizinhos (LLDP/CDP)'
							: 'View Neighbors (LLDP/CDP)';

						$main_block_data['data']['urls'][] = [
							'label' => $label,
							'url' => 'zabbix.php?action=discovery.popup&hostid=' . $hostid,
							'menu_path' => ''
						];

						$data['main_block'] = json_encode($main_block_data);

						// Atualiza a resposta do controller
						$this->setResponse(new CControllerResponseData($data));
					}
				}
			}
		}
	}
}
