<?php declare(strict_types = 1);

namespace Modules\DiscoveryNeighbors;

use Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		spl_autoload_register(function ($class) {
			$prefix = 'Modules\\DiscoveryNeighbors\\';
			$len = strlen($prefix);
			if (strncmp($prefix, $class, $len) !== 0) {
				return;
			}

			$relative_class = substr($class, $len);
			$file = __DIR__ . '/' . str_replace('\\', '/', $relative_class) . '.php';

			if (file_exists($file)) {
				require_once $file;
				return;
			}

			// Fallback para caminhos de diretório em minúsculas no Linux (ex: Services -> services)
			$parts = explode('\\', $relative_class);
			if (count($parts) > 1) {
				$parts[0] = lcfirst($parts[0]);
				$lowercase_dir_file = __DIR__ . '/' . implode('/', $parts) . '.php';
				if (file_exists($lowercase_dir_file)) {
					require_once $lowercase_dir_file;
				}
			}
		});
	}

	public function onBeforeAction(\CController $action): void {
		if ($action->getAction() === 'discovery.popup') {
			return;
		}

		// Injeta o Javascript necessário para interceptar o clique no link do menu de contexto
		// e abrir como uma modal overlay nativa do Zabbix.
		$js = "
			jQuery(document).off('click', 'a[href*=\"action=discovery.popup\"]')
				.on('click', 'a[href*=\"action=discovery.popup\"]', function(e) {
					e.preventDefault();
					const href = jQuery(this).attr('href');
					const url = new Curl(href);
					const hostid = url.getArgument('hostid');
					jQuery(this).closest('.menu-popup').menuPopup('close', null);
					PopUp('discovery.popup', {hostid: hostid}, {dialogue_class: 'modal-popup-large', trigger_element: this});
				});
		";
		zbx_add_post_js($js);
	}
}
