<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 */

$json_flags = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

$lang = isset(\CWebUser::$data['lang']) ? \CWebUser::$data['lang'] : 'en_US';
$is_pt = (strpos($lang, 'pt_') === 0);

$t = function(string $pt, string $en) use ($is_pt): string {
	return $is_pt ? $pt : $en;
};

$table = new CTableInfo();
$table->setHeader([
	$t('Protocolo', 'Protocol'),
	$t('Porta Local', 'Local Port'),
	$t('Equipamento Vizinho', 'Neighbor Device'),
	$t('IP Vizinho', 'Neighbor IP'),
	$t('Porta Vizinha', 'Neighbor Port')
]);

if (!empty($data['neighbors'])) {
	foreach ($data['neighbors'] as $neighbor) {
		$full_name = $neighbor['neighbor_name'] !== '' ? $neighbor['neighbor_name'] : $t('(Desconhecido)', '(Unknown)');
		$display_name = mb_strlen($full_name) > 25 ? mb_substr($full_name, 0, 22) . '...' : $full_name;

		$neighbor_name_cell = $display_name;
		if (!empty($neighbor['neighbor_hostid'])) {
			$neighbor_name_cell = (new CLink($display_name, '#'))
				->addClass('navigate-host')
				->setAttribute('data-hostid', $neighbor['neighbor_hostid'])
				->setAttribute('title', $t('Navegar para ', 'Navigate to ') . $full_name);
		} elseif (!empty($neighbor['neighbor_ip']) && $neighbor['neighbor_ip'] !== '-' && !empty($neighbor['is_distribution'])) {
			$neighbor_name_cell = (new CLink($display_name, '#'))
				->addClass('navigate-host')
				->setAttribute('data-ip', $neighbor['neighbor_ip'])
				->setAttribute('data-community', isset($data['community']) ? $data['community'] : 'public')
				->setAttribute('data-version', isset($data['version']) ? $data['version'] : 2)
				->setAttribute('title', $t('Consultar vizinhos via IP ', 'Query neighbors via IP ') . $neighbor['neighbor_ip'] . ' (' . $full_name . ')');
		} else {
			$neighbor_name_cell = (new CSpan($display_name))->setAttribute('title', $full_name);
		}
		$table->addRow([
			$neighbor['protocol'],
			$neighbor['local_port'],
			$neighbor_name_cell,
			$neighbor['neighbor_ip'],
			$neighbor['neighbor_port']
		]);
	}
} else {
	// Exibe mensagem de erro amigável caso ocorra erro ou a tabela de vizinhos esteja vazia
	$no_data_msg = !empty($data['error']) ? $data['error'] : $t('Nenhum vizinho de rede detectado (LLDP/CDP/EDP).', 'No network neighbors detected (LLDP/CDP/EDP).');
	$table->setNoDataMessage($no_data_msg);
}

// Gera ID único para evitar conflitos se múltiplos popups forem abertos simultaneamente
$uniqid = uniqid('topo_');
$html = '';

if (!empty($data['neighbors'])) {
	$html .= '
	<style>
		.topology-wrapper {
			position: relative;
			width: 100%;
			height: 480px;
			background: var(--body-bg-color, #0d1117);
			border: 1px solid var(--ui-border-color, #30363d);
			border-radius: 6px;
			margin-bottom: 15px;
			overflow: visible; /* Permite que filhos sticky funcionem em relação ao container de scroll */
			box-shadow: inset 0 0 15px rgba(0, 0, 0, 0.15);
		}
		.topology-info-bar {
			position: -webkit-sticky;
			position: sticky;
			top: 8px;
			left: 8px;
			z-index: 10;
			background: var(--ui-bg-color, rgba(22, 27, 34, 0.95));
			border: 1px solid var(--ui-border-color, #30363d);
			border-radius: 4px;
			padding: 5px 10px;
			color: var(--font-color, #c9d1d9);
			font-size: 11px;
			font-family: monospace;
			pointer-events: none;
			transition: border-color 0.2s ease, background-color 0.2s ease, color 0.2s ease;
			
			/* Layout flutuante sobre o SVG */
			float: left;
			margin-left: 8px;
			margin-top: 8px;
			margin-bottom: -36px;
			
			/* Restrição de largura */
			width: max-content;
			max-width: calc(100% - 60px);
			box-sizing: border-box;
		}
		.topology-fullscreen-btn {
			position: absolute;
			top: 8px;
			right: 8px;
			background: var(--ui-bg-color, rgba(22, 27, 34, 0.9));
			border: 1px solid var(--ui-border-color, #30363d);
			border-radius: 4px;
			padding: 5px;
			color: var(--font-color, #c9d1d9);
			cursor: pointer;
			z-index: 10;
			display: flex;
			align-items: center;
			justify-content: center;
			transition: background-color 0.2s, border-color 0.2s;
		}
		.topology-fullscreen-btn:hover {
			background: var(--body-bg-color, #21262d);
			border-color: #8b949e;
		}
		.topology-svg {
			width: 100%;
			height: 100%;
			display: block;
		}
		.topo-node {
			cursor: default;
			transition: opacity 0.25s ease;
		}
		.topo-node.clickable {
			cursor: pointer;
		}
		.topo-node.clickable:hover circle {
			stroke: #60a5fa !important;
			filter: drop-shadow(0 0 4px #60a5fa);
		}
		.topo-node circle {
			stroke-width: 2.5px;
			transition: transform 0.2s ease, stroke-width 0.2s ease, fill 0.2s ease, stroke 0.2s ease;
		}
		.topo-node:hover circle, .topo-node.focused circle {
			transform: scale(1.08);
			stroke-width: 3.5px;
		}
		.topo-node:not(.center).focused circle {
			stroke: #14b8a6 !important;
		}
		.topo-node text {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			font-size: 10px;
			fill: var(--font-color, #8b949e);
			text-anchor: middle;
			pointer-events: none;
			user-select: none;
			font-weight: 600;
			transition: fill 0.2s ease;
		}
		.topo-node.center text {
			fill: var(--font-color, #f0f6fc);
			font-size: 11px;
		}
		.node-label-bg {
			fill: var(--ui-bg-color, rgba(13, 17, 23, 0.9));
			stroke: var(--ui-border-color, #30363d);
			stroke-width: 0.5px;
			rx: 3px;
			transition: fill 0.2s ease, stroke 0.2s ease;
		}
		
		.topo-link-group {
			cursor: pointer;
			transition: opacity 0.25s ease;
		}
		.topo-link {
			fill: none;
			transition: stroke-width 0.15s ease, stroke 0.15s ease;
		}
		.topo-link.bg-line {
			stroke-width: 6px;
			stroke: var(--ui-border-color, #30363d);
			opacity: 0.12;
		}
		.topo-link.fg-line {
			stroke-width: 2.2px;
			stroke-dasharray: 4, 4;
			animation: topoDash 35s linear infinite;
		}
		
		@keyframes topoDash {
			to {
				stroke-dashoffset: -1000;
			}
		}

		/* Focus Dimming / Highlighting */
		.topology-svg.has-focus .topo-link-group:not(.focused) {
			opacity: 0.08;
		}
		.topology-svg.has-focus .topo-node:not(.focused) {
			opacity: 0.25;
		}
		
		/* Highlighted State */
		.topo-link-group.focused .topo-link.bg-line {
			stroke: #14b8a6 !important;
			opacity: 0.25 !important;
			stroke-width: 8px !important;
		}
		.topo-link-group.focused .topo-link.fg-line {
			stroke: #14b8a6 !important;
			stroke-width: 3.5px !important;
		}

		/* Fullscreen styling overrides for the entire modal popup */
		.overlay-dialogue:fullscreen {
			width: 100vw !important;
			height: 100vh !important;
			max-width: 100vw !important;
			max-height: 100vh !important;
			top: 0 !important;
			left: 0 !important;
			margin: 0 !important;
			border-radius: 0 !important;
			box-sizing: border-box !important;
			display: flex !important;
			flex-direction: column !important;
			background: var(--body-bg-color, #0d1117) !important;
			overflow: hidden !important;
		}
		.overlay-dialogue:fullscreen .overlay-dialogue-body {
			flex: 1 1 auto !important;
			overflow-y: auto !important;
			display: flex !important;
			flex-direction: column !important;
		}
		.overlay-dialogue:fullscreen .topology-wrapper {
			height: 580px !important;
			flex-shrink: 0 !important;
			overflow: visible !important;
		}
		.overlay-dialogue:fullscreen .overlay-dialogue-footer,
		.overlay-dialogue:fullscreen .overlay-dialogue-controls,
		.overlay-dialogue:fullscreen footer {
			margin-top: auto !important;
			flex-shrink: 0 !important;
		}
		
		/* Webkit prefix fallback */
		.overlay-dialogue:-webkit-full-screen {
			width: 100vw !important;
			height: 100vh !important;
			max-width: 100vw !important;
			max-height: 100vh !important;
			top: 0 !important;
			left: 0 !important;
			margin: 0 !important;
			border-radius: 0 !important;
			display: flex !important;
			flex-direction: column !important;
			background: var(--body-bg-color, #0d1117) !important;
			overflow: hidden !important;
		}
		.overlay-dialogue:-webkit-full-screen .overlay-dialogue-body {
			flex: 1 1 auto !important;
			display: flex !important;
			flex-direction: column !important;
		}
		.overlay-dialogue:-webkit-full-screen .topology-wrapper {
			height: 580px !important;
			flex-shrink: 0 !important;
			overflow: visible !important;
		}
		.overlay-dialogue:-webkit-full-screen .overlay-dialogue-footer,
		.overlay-dialogue:-webkit-full-screen .overlay-dialogue-controls,
		.overlay-dialogue:-webkit-full-screen footer {
			margin-top: auto !important;
			flex-shrink: 0 !important;
		}
	</style>
	<div class="topology-wrapper" id="wrapper-' . $uniqid . '">
		<div class="topology-info-bar" id="info-' . $uniqid . '">' . $t('Passe o mouse nos elementos para ver os detalhes', 'Hover over elements to view details') . '</div>
		<svg class="topology-svg" id="svg-' . $uniqid . '"></svg>
	</div>';
}

$html .= (new CDiv($table))->toString();

if (!empty($data['neighbors'])) {
	$html .= '
	<script>
	(function() {
		const neighbors = ' . json_encode($data['neighbors'], $json_flags) . ';
		const hostName = ' . json_encode($data['host_name'] ?: 'Local Switch', $json_flags) . ';
		const queryCommunity = ' . json_encode(isset($data['community']) ? $data['community'] : 'public', $json_flags) . ';
		const queryVersion = ' . json_encode(isset($data['version']) ? $data['version'] : 2, $json_flags) . ';
		const csrfToken = ' . json_encode(isset($data['csrf_token']) ? $data['csrf_token'] : '', $json_flags) . ';
		const wrapper = document.getElementById("wrapper-' . $uniqid . '");
		const svg = document.getElementById("svg-' . $uniqid . '");
		const infoBar = document.getElementById("info-' . $uniqid . '");
		if (!svg || !infoBar || !wrapper) return;

		const t = {
			connectionActive: ' . json_encode($t('1 conexão ativa', '1 active connection'), $json_flags) . ',
			connectionsActive: ' . json_encode($t('conexões ativas', 'active connections'), $json_flags) . ',
			hoverDetails: ' . json_encode($t('Passe o mouse nos elementos para ver os detalhes', 'Hover over elements to view details'), $json_flags) . ',
			clickNavigate: ' . json_encode($t('Clique para navegar para ', 'Click to navigate to '), $json_flags) . ',
			queryIp: ' . json_encode($t('Consultar vizinhos via IP ', 'Query neighbors via IP '), $json_flags) . ',
			fullscreen: ' . json_encode($t('Tela Cheia', 'Fullscreen'), $json_flags) . ',
			exitFullscreen: ' . json_encode($t('Sair da Tela Cheia', 'Exit Fullscreen'), $json_flags) . '
		};

		console.log("DiscoveryNeighbors Popup Loaded:");
		console.log(" - csrfToken from PHP:", csrfToken ? "EXISTS" : "EMPTY");
		console.log(" - CCsrfTokenHelper in JS:", typeof CCsrfTokenHelper !== "undefined" ? "EXISTS" : "MISSING");

		function navigateToHost(element) {
			const hostid = element.getAttribute("data-hostid");
			const ip = element.getAttribute("data-ip");
			const community = element.getAttribute("data-community");
			const version = element.getAttribute("data-version");

			try {
				if (document.fullscreenElement) {
					sessionStorage.setItem("topo_fullscreen_navigate", "1");
				}
			} catch (e) {}

			let destroyed = false;
			try {
				if (typeof overlays_stack !== "undefined") {
					const active_overlay = overlays_stack.end();
					if (active_overlay && active_overlay.dialogueid) {
						overlayDialogueDestroy(active_overlay.dialogueid);
						destroyed = true;
					}
				}
			} catch (e) {}

			// Fallback: se a pilha do Zabbix não estiver acessível, tenta fechar pelo ancestral DOM mais próximo
			if (!destroyed) {
				try {
					const dialogue = jQuery(element).closest(".overlay-dialogue");
					if (dialogue.length) {
						overlayDialogueDestroy(dialogue.attr("id"));
					}
				} catch (e) {}
			}

			const params = {};
			let activeToken = csrfToken;
			if (typeof CCsrfTokenHelper !== "undefined") {
				try {
					activeToken = CCsrfTokenHelper.getToken();
				} catch (err) {
					console.error("Error calling CCsrfTokenHelper.getToken():", err);
				}
			}
			console.log("DiscoveryNeighbors: Navigating with CSRF token status:", activeToken ? "EXISTS" : "EMPTY");
			if (activeToken) {
				params._csrf_token = activeToken;
			}

			if (hostid) {
				params.hostid = hostid;
			} else if (ip) {
				params.ip = ip;
				params.community = community;
				params.version = version;
			}

			setTimeout(() => {
				PopUp("discovery.popup", params, {dialogue_class: "modal-popup-large"});
			}, 100);
		}

		// Navegação via clique na tabela
		document.querySelectorAll(".navigate-host").forEach(link => {
			link.addEventListener("click", (e) => {
				e.preventDefault();
				navigateToHost(link);
			});
		});

		// Botão de Tela Cheia
		const fullscreenBtn = document.createElement("button");
		fullscreenBtn.className = "topology-fullscreen-btn";
		fullscreenBtn.title = t.fullscreen;
		fullscreenBtn.innerHTML = `
			<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
			</svg>
		`;
		wrapper.appendChild(fullscreenBtn);

		fullscreenBtn.addEventListener("click", () => {
			const popupElement = wrapper.closest(".overlay-dialogue");
			if (!popupElement) return;

			if (!document.fullscreenElement) {
				popupElement.requestFullscreen().catch(err => {
					console.error("Error entering fullscreen:", err);
				});
			} else {
				document.exitFullscreen();
			}
		});

		// Cores com base no protocolo
		const colors = {
			"LLDP": "#a855f7", // Roxo
			"CDP": "#f97316",  // Laranja
			"EDP": "#ef4444",  // Vermelho
			"center": "#3b82f6" // Azul para o switch local
		};

		const ns = "http://www.w3.org/2000/svg";

		// Agrupa conexões por nome de vizinho
		const groups = {};
		neighbors.forEach(n => {
			const name = n.neighbor_name || "(Desconhecido)";
			if (!groups[name]) {
				groups[name] = [];
			}
			groups[name].push(n);
		});

		const uniqueNames = Object.keys(groups);
		const totalNeighbors = uniqueNames.length;

		// Determina o peso máximo de conexões para centralização dinâmica
		let maxLinks = 1;
		uniqueNames.forEach(name => {
			if (groups[name].length > maxLinks) {
				maxLinks = groups[name].length;
			}
		});

		function drawTopology() {
			svg.innerHTML = "";

			const width = svg.clientWidth || 600;
			const height = svg.clientHeight || 480;
			svg.setAttribute("viewBox", `0 0 ${width} ${height}`);

			const centerX = width / 2;
			const centerY = height / 2;

			const maxR = Math.min(width, height) * 0.38;
			const minR = Math.min(width, height) * 0.20;

			const nodeGroups = {};

			// 1. Cria o Nó Central (Switch Local) primeiro para termos a referência no hover dos links
			const centerG = document.createElementNS(ns, "g");
			centerG.setAttribute("class", "topo-node center");
			centerG.setAttribute("transform", `translate(${centerX}, ${centerY})`);

			const centerCircle = document.createElementNS(ns, "circle");
			centerCircle.setAttribute("r", "20");
			centerCircle.setAttribute("fill", colors["center"]);
			centerCircle.setAttribute("stroke", "#60a5fa");
			centerG.appendChild(centerCircle);

			// Desenha portas internas simplificadas (switchports)
			for (let i = -6; i <= 6; i += 6) {
				const rect = document.createElementNS(ns, "rect");
				rect.setAttribute("x", (i - 2).toString());
				rect.setAttribute("y", "-2");
				rect.setAttribute("width", "4");
				rect.setAttribute("height", "4");
				rect.setAttribute("fill", "#ffffff");
				centerG.appendChild(rect);
			}

			// Label do Switch Central
			const centerLabelG = document.createElementNS(ns, "g");
			centerLabelG.setAttribute("transform", "translate(0, 32)");
			
			const centerText = document.createElementNS(ns, "text");
			centerText.textContent = hostName.length > 25 ? hostName.substring(0, 22) + "..." : hostName;
			centerLabelG.appendChild(centerText);
			centerG.appendChild(centerLabelG);

			centerG.addEventListener("mouseover", () => {
				centerG.classList.add("focused");
				const connText = neighbors.length === 1 ? t.connectionActive : `${neighbors.length} ${t.connectionsActive}`;
				infoBar.textContent = `${hostName} | (${connText})`;
				infoBar.style.borderColor = "#60a5fa";
			});
			centerG.addEventListener("mouseout", () => {
				centerG.classList.remove("focused");
				infoBar.textContent = t.hoverDetails;
				infoBar.style.borderColor = "";
			});

			// 2. Pré-instancia os grupos de nós vizinhos para podermos vinculá-los no hover das conexões
			uniqueNames.forEach((name, index) => {
				const group = groups[name];
				const numLinks = group.length;

				const angle = (index * 2 * Math.PI) / totalNeighbors;
				// Mais conexões = raio menor (mais perto do centro)
				const r = maxLinks === 1 ? maxR : maxR - ((numLinks - 1) / (maxLinks - 1)) * (maxR - minR);

				const targetX = centerX + r * Math.cos(angle);
				const targetY = centerY + r * Math.sin(angle);

				const nodeG = document.createElementNS(ns, "g");
				nodeG.setAttribute("class", "topo-node");
				nodeG.setAttribute("transform", `translate(${targetX}, ${targetY})`);

				const nodeCircle = document.createElementNS(ns, "circle");
				nodeCircle.setAttribute("r", "10");
				
				const protocol = group[0].protocol;
				const nodeColor = colors[protocol] || "#64748b";
				nodeCircle.setAttribute("fill", "#1e293b"); // Fundo dark slate para legibilidade
				nodeCircle.setAttribute("stroke", nodeColor);
				nodeCircle.setAttribute("stroke-width", "3");
				nodeG.appendChild(nodeCircle);

				// Label do Vizinho
				const labelG = document.createElementNS(ns, "g");
				labelG.setAttribute("transform", "translate(0, 22)");
				
				const labelText = document.createElementNS(ns, "text");
				labelText.textContent = name.length > 25 ? name.substring(0, 22) + "..." : name;
				labelG.appendChild(labelText);
				nodeG.appendChild(labelG);

				// Configura clique e tooltip de navegação para hosts do Zabbix ou por IP ad-hoc (apenas para switches/roteadores)
				const hostidObj = group.find(link => link.neighbor_hostid);
				const hostid = hostidObj ? hostidObj.neighbor_hostid : null;
				const ipObj = group.find(link => link.neighbor_ip && link.neighbor_ip !== "-" && link.is_distribution);
				const ip = ipObj ? ipObj.neighbor_ip : null;

				if (hostid) {
					nodeG.classList.add("clickable");
					nodeG.setAttribute("data-hostid", hostid);
					
					const nodeTitle = document.createElementNS(ns, "title");
					nodeTitle.textContent = t.clickNavigate + name;
					nodeG.appendChild(nodeTitle);

					nodeG.addEventListener("click", (e) => {
						e.preventDefault();
						navigateToHost(nodeG);
					});
				} else if (ip) {
					nodeG.classList.add("clickable");
					nodeG.setAttribute("data-ip", ip);
					nodeG.setAttribute("data-community", queryCommunity);
					nodeG.setAttribute("data-version", queryVersion);
					
					const nodeTitle = document.createElementNS(ns, "title");
					nodeTitle.textContent = t.queryIp + ip;
					nodeG.appendChild(nodeTitle);

					nodeG.addEventListener("click", (e) => {
						e.preventDefault();
						navigateToHost(nodeG);
					});
				}

				nodeG.addEventListener("mouseover", () => {
					svg.classList.add("has-focus");
					nodeG.classList.add("focused");
					centerG.classList.add("focused");

					// Realça todas as conexões deste vizinho
					svg.querySelectorAll(`.topo-link-group[data-neighbor="${name}"]`).forEach(lg => {
						lg.classList.add("focused");
					});

					// Exibe portas de todas as conexões deste vizinho no label
					const connTextList = group.map(link => `${link.local_port} <> ${link.neighbor_port}`);
					const connText = connTextList.join(", ");
					const ipObj = group.find(link => link.neighbor_ip && link.neighbor_ip !== "-");
					const ipSuffix = ipObj ? ` (${ipObj.neighbor_ip})` : "";
					infoBar.textContent = `${hostName} | ${connText} | ${name}${ipSuffix}`;
					infoBar.style.borderColor = nodeColor;
				});

				nodeG.addEventListener("mouseout", () => {
					svg.classList.remove("has-focus");
					nodeG.classList.remove("focused");
					centerG.classList.remove("focused");

					svg.querySelectorAll(`.topo-link-group[data-neighbor="${name}"]`).forEach(lg => {
						lg.classList.remove("focused");
					});

					infoBar.textContent = t.hoverDetails;
					infoBar.style.borderColor = "";
				});

				nodeGroups[name] = nodeG;
			});

			// 3. Desenha as conexões (links) - Ficam no fundo
			uniqueNames.forEach((name, index) => {
				const group = groups[name];
				const numLinks = group.length;

				const angle = (index * 2 * Math.PI) / totalNeighbors;
				const r = maxLinks === 1 ? maxR : maxR - ((numLinks - 1) / (maxLinks - 1)) * (maxR - minR);

				const targetX = centerX + r * Math.cos(angle);
				const targetY = centerY + r * Math.sin(angle);

				group.forEach((link, linkIndex) => {
					const linkGroup = document.createElementNS(ns, "g");
					linkGroup.setAttribute("class", "topo-link-group");
					linkGroup.setAttribute("data-neighbor", name);

					// Desenha curvas Bezier paralelas se houver mais de uma conexão com o mesmo vizinho
					let d = "";
					if (numLinks === 1) {
						d = `M ${centerX} ${centerY} L ${targetX} ${targetY}`;
					} else {
						const dx = targetX - centerX;
						const dy = targetY - centerY;
						const dist = Math.sqrt(dx * dx + dy * dy);
						const mx = (centerX + targetX) / 2;
						const my = (centerY + targetY) / 2;

						// Vetor perpendicular
						const px = -dy / dist;
						const py = dx / dist;

						const spacing = 12;
						const offsetIndex = linkIndex - (numLinks - 1) / 2;
						const cx = mx + px * offsetIndex * spacing;
						const cy = my + py * offsetIndex * spacing;

						d = `M ${centerX} ${centerY} Q ${cx} ${cy} ${targetX} ${targetY}`;
					}

					const strokeColor = colors[link.protocol] || "#64748b";

					// Background line (Thick hover target)
					const bgPath = document.createElementNS(ns, "path");
					bgPath.setAttribute("class", "topo-link bg-line");
					bgPath.setAttribute("d", d);
					linkGroup.appendChild(bgPath);

					// Foreground line (Dashed animation)
					const fgPath = document.createElementNS(ns, "path");
					fgPath.setAttribute("class", "topo-link fg-line");
					fgPath.setAttribute("d", d);
					fgPath.setAttribute("stroke", strokeColor);
					linkGroup.appendChild(fgPath);

					// Tooltip nativo
					const title = document.createElementNS(ns, "title");
					const ipSuffix = (link.neighbor_ip && link.neighbor_ip !== "-") ? ` (${link.neighbor_ip})` : "";
					title.textContent = `${link.protocol}: ${link.local_port} ➔ ${link.neighbor_port}${ipSuffix}`;
					linkGroup.appendChild(title);

					// Eventos de Hover
					linkGroup.addEventListener("mouseover", () => {
						svg.classList.add("has-focus");
						linkGroup.classList.add("focused");
						centerG.classList.add("focused");
						if (nodeGroups[name]) {
							nodeGroups[name].classList.add("focused");
						}

						infoBar.textContent = `${hostName} | ${link.local_port} <> ${link.neighbor_port} | ${name}${ipSuffix}`;
						infoBar.style.borderColor = "#14b8a6";
					});

					linkGroup.addEventListener("mouseout", () => {
						svg.classList.remove("has-focus");
						linkGroup.classList.remove("focused");
						centerG.classList.remove("focused");
						if (nodeGroups[name]) {
							nodeGroups[name].classList.remove("focused");
						}

						infoBar.textContent = t.hoverDetails;
						infoBar.style.borderColor = "";
					});

					svg.appendChild(linkGroup);
				});
			});

			// 4. Adiciona o nó central e os nós vizinhos ao SVG (depois das conexões para ficarem no topo)
			svg.appendChild(centerG);
			uniqueNames.forEach(name => {
				if (nodeGroups[name]) {
					svg.appendChild(nodeGroups[name]);
				}
			});

			// 5. Cria fundos (background boxes) dinâmicos com fallback robusto
			svg.querySelectorAll(".topo-node text").forEach(text => {
				try {
					let bbox = { x: 0, y: 0, width: 0, height: 0 };
					try {
						bbox = text.getBBox();
					} catch (err) {}

					let w = bbox.width;
					let h = bbox.height;
					let x = bbox.x;
					let y = bbox.y;

					if (w === 0) {
						// Fallback caso o SVG ainda não esteja visível/renderizado
						w = text.textContent.length * 6.2 + 8;
						h = 14;
						x = -w / 2;
						y = -10;
					} else {
						// Padding de margem
						x -= 4;
						y -= 1;
						w += 8;
						h += 2;
					}

					const parent = text.parentNode;
					const rect = document.createElementNS(ns, "rect");
					rect.setAttribute("class", "node-label-bg");
					rect.setAttribute("x", x.toString());
					rect.setAttribute("y", y.toString());
					rect.setAttribute("width", w.toString());
					rect.setAttribute("height", h.toString());
					
					// Insere o rect antes do texto para que o texto fique por cima do fundo
					parent.insertBefore(rect, text);
				} catch (e) {}
			});
		}

		// Primeira renderização
		setTimeout(drawTopology, 100);

		// Recupera estado de tela cheia se vier de uma navegação
		if (sessionStorage.getItem("topo_fullscreen_navigate") === "1") {
			sessionStorage.removeItem("topo_fullscreen_navigate");
			setTimeout(() => {
				const popupElement = wrapper.closest(".overlay-dialogue");
				if (popupElement) {
					popupElement.requestFullscreen().catch(err => {
						console.error("Erro ao reentrar em tela cheia pós-navegação:", err);
					});
				}
			}, 250);
		}

		// Listener para mudança de tela cheia do popup como um todo
		const onFullscreenChange = () => {
			const popupElement = wrapper.closest(".overlay-dialogue");
			if (!document.body.contains(wrapper)) {
				document.removeEventListener("fullscreenchange", onFullscreenChange);
				return;
			}
			if (popupElement && document.fullscreenElement === popupElement) {
				fullscreenBtn.innerHTML = `
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M4 14h6v6m10-6h-6v6M4 10h6V4m10 6h-6V4"/>
					</svg>
				`;
				fullscreenBtn.title = t.exitFullscreen;
			} else {
				fullscreenBtn.innerHTML = `
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
					</svg>
				`;
				fullscreenBtn.title = t.fullscreen;
			}
			setTimeout(drawTopology, 50);
		};
		document.addEventListener("fullscreenchange", onFullscreenChange);

		// Listener para redimensionamento da janela
		let resizeTimeout;
		window.addEventListener("resize", () => {
			if (!document.body.contains(wrapper)) return;
			clearTimeout(resizeTimeout);
			resizeTimeout = setTimeout(drawTopology, 100);
		});
	})();
	</script>';
}

// Estrutura JSON requerida pelo Zabbix para renderização do popup Overlay (Modal)
$output = [
	'header' => $t('Vizinhos de Rede (Real-Time)', 'Network Neighbors (Real-Time)') . ' - ' . (!empty($data['host_name']) ? $data['host_name'] : ''),
	'body' => $html,
	'buttons' => [
		[
			'title' => $t('Fechar', 'Close'),
			'class' => 'btn-alt',
			'keepOpen' => false,
			'isSubmit' => false,
			'action' => 'overlayDialogueDestroy(this);'
		]
	]
];

echo json_encode($output, $json_flags);
