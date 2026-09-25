/**
 * mtai.opensearch — JS-патч, заменяющий штатный поиск CRM
 * (crm.api.entity.search) на морфологический поиск через OpenSearch.
 *
 * Как работает:
 *  1. Оборачивает BX.ajax.runAction: вызовы с action === 'crm.api.entity.search'
 *     перенаправляются на экшен модуля 'mtai:opensearch.api.search'
 *     с теми же параметрами и тем же контрактом ответа
 *     ({ data: { items: [...], limits: [...] } }).
 *  2. Если OpenSearch недоступен (экшен вернул ошибку), автоматически
 *     выполняется штатный запрос — поиск продолжает работать.
 *  3. Пользователи и элементы инфоблоков, найденные OpenSearch,
 *     дописываются в выпадающую подсказку шапки: блок «Сотрудники»
 *     заменяет штатный (LIKE-поиск), блоки инфоблоков добавляются.
 *
 * Файл подключается модулем перед </body> и не зависит от шаблона.
 */
;(function () {
	'use strict';

	var NATIVE_ACTION = 'crm.api.entity.search';
	var OUR_ACTION = 'mtai:opensearch.api.entity.search';

	var BLOCK_MARKER = 'mtai-opensearch-block';
	var TOOLS_ID = 'search-title-block-tools';

	function msg(code, fallback) {
		try {
			var v = window.BX && BX.message && BX.message(code);
			return (typeof v === 'string' && v.length > 0) ? v : fallback;
		} catch (e) {
			return fallback;
		}
	}

	// ------------------------------------------------------------------
	// Перехват BX.ajax.runAction
	// ------------------------------------------------------------------

	function patchRunAction() {
		if (!window.BX || !BX.ajax || typeof BX.ajax.runAction !== 'function') {
			return false;
		}
		if (BX.ajax.runAction.__mtaiOpenSearch) {
			return true;
		}

		var original = BX.ajax.runAction;

		var wrapped = function (action, config) {
			if (action !== NATIVE_ACTION) {
				return original.apply(BX.ajax, arguments);
			}

			var proxied = original.call(BX.ajax, OUR_ACTION, config);
			var fallback = function () {
				// OpenSearch недоступен — выполняем штатный поиск.
				return original.call(BX.ajax, NATIVE_ACTION, config);
			};

			var promise = proxied.then(
				function (response) {
					if (!response || response.status !== 'success') {
						return fallback();
					}
					try {
						renderExtraItems(response);
					} catch (e) {
						// ошибка отрисовки не должна ломать выдачу CRM
					}
					return response;
				},
				fallback
			);

			return promise;
		};
		wrapped.__mtaiOpenSearch = true;
		BX.ajax.runAction = wrapped;

		return true;
	}

	// BX может грузиться позже патча — ждём и патчим.
	function waitAndPatch(attempts) {
		attempts = attempts || 0;
		if (patchRunAction()) {
			return;
		}
		if (attempts > 200) {
			return;
		}
		setTimeout(function () {
			waitAndPatch(attempts + 1);
		}, 50);
	}

	// ------------------------------------------------------------------
	// Отрисовка пользователей и инфоблоков в подсказке шапки
	// ------------------------------------------------------------------

	function esc(text) {
		return window.BX && BX.util
			? BX.util.htmlspecialchars(String(text == null ? '' : text))
			: String(text == null ? '' : text)
				.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;');
	}

	function removeOldBlocks() {
		var nodes = document.querySelectorAll('.' + BLOCK_MARKER);
		for (var i = 0; i < nodes.length; i++) {
			if (nodes[i].parentNode) {
				nodes[i].parentNode.removeChild(nodes[i]);
			}
		}
	}

	function sectionHtml(blockClass, title, listHtml, blockId) {
		return '<div class="search-title-top-block ' + blockClass + ' ' + BLOCK_MARKER + '">'
			+ '<div class="search-title-top-subtitle">'
			+ '<div class="search-title-top-subtitle-text">' + esc(title) + '</div>'
			+ '</div>'
			+ '<div class="search-title-top-list-wrap">'
			+ '<div class="search-title-top-list search-title-top-list-js" bx-search-block-id="' + esc(blockId) + '">'
			+ listHtml
			+ '</div>'
			+ '</div>'
			+ '</div>';
	}

	function userItemHtml(item) {
		var url = item && item.links && item.links.show ? item.links.show : '#';
		var photo = item && item.attributes && item.attributes.photo ? item.attributes.photo : '';
		var iconStyle = photo ? ' style="background-image: url(\'' + esc(photo) + '\')"' : '';
		var iconClass = 'search-title-top-item-img'
			+ (photo ? '' : ' search-title-top-item-img-default-users');
		var id = parseInt(item && item.id, 10) || 0;

		var html = '<div class="search-title-top-item search-title-top-item-js" role="option" aria-selected="false"'
			+ ' bx-search-item-id="mtai_os_U' + id + '" title="' + esc(item.title) + '">'
			+ '<a class="search-title-top-item-link" href="' + esc(url) + '">'
			+ '<span class="' + iconClass + '"' + iconStyle + '></span>'
			+ '<span class="search-title-top-item-text"><span>' + esc(item.title) + '</span></span>'
			+ '</a>'
			+ '<span class="search-title-top-item-message" data-mtai-user="' + id + '" title="'
			+ esc(msg('MTAI_OPENSEARCH_WRITE_MESSAGE', 'Написать сообщение')) + '"></span>'
			+ '</div>';

		return html;
	}

	function linkItemHtml(item) {
		var url = item && item.links && item.links.show ? item.links.show : '#';

		return '<div class="search-title-top-item search-title-top-item-js" role="option" aria-selected="false"'
			+ ' bx-search-item-id="mtai_os_I' + esc(item.id) + '" title="' + esc(item.title) + '">'
			+ '<a class="search-title-top-item-link" href="' + esc(url) + '">'
			+ '<span class="search-title-top-item-text"><span>' + esc(item.title) + '</span></span>'
			+ '</a>'
			+ '</div>';
	}

	function bindItemEvents(root) {
		var items = root.querySelectorAll('.search-title-top-item-js');
		for (var i = 0; i < items.length; i++) {
			(function (node) {
				node.addEventListener('mouseover', function () {
					var sel = root.querySelectorAll('.search-title-top-item-selected');
					for (var j = 0; j < sel.length; j++) {
						sel[j].classList.remove('search-title-top-item-selected');
					}
					node.classList.add('search-title-top-item-selected');
				});
				node.addEventListener('mouseout', function () {
					node.classList.remove('search-title-top-item-selected');
				});
			})(items[i]);
		}

		var chatButtons = root.querySelectorAll('.search-title-top-item-message[data-mtai-user]');
		for (var k = 0; k < chatButtons.length; k++) {
			(function (button) {
				button.addEventListener('click', function (e) {
					e.preventDefault();
					e.stopPropagation();
					var userId = parseInt(button.getAttribute('data-mtai-user'), 10);
					if (window.BXIM && userId > 0) {
						BXIM.openMessenger(userId);
					}
				});
			})(chatButtons[k]);
		}
	}

	function insertBlocks(html) {
		var tools = document.getElementById(TOOLS_ID);
		if (!tools || !tools.parentNode) {
			return;
		}
		var container = tools.parentNode;
		var tmp = document.createElement('div');
		tmp.innerHTML = html;
		while (tmp.firstChild) {
			var block = tmp.firstChild;
			container.insertBefore(block, tools);
			bindItemEvents(block);
		}
	}

	function renderExtraItems(response) {
		var data = response && response.data;
		if (!data || !Array.isArray(data.items) || data.items.length === 0) {
			return;
		}

		var crmItems = [];
		var users = [];
		var iblocks = {}; // title -> items[]

		for (var i = 0; i < data.items.length; i++) {
			var item = data.items[i];
			if (item && item.type === 'USER') {
				users.push(item);
			} else if (item && item.type === 'IBLOCK_ELEMENT') {
				var title = (item.attributes && item.attributes.iblockTitle)
					? String(item.attributes.iblockTitle)
					: msg('MTAI_OPENSEARCH_IBLOCKS', 'Инфоблоки');
				if (!iblocks[title]) {
					iblocks[title] = [];
				}
				iblocks[title].push(item);
			} else {
				crmItems.push(item);
			}
		}

		// Из ответа для вызывающего кода оставляем только совместимые
		// CRM-элементы — штатный код не знает типов USER/IBLOCK_ELEMENT.
		data.items = crmItems;

		if (users.length === 0 && Object.keys(iblocks).length === 0) {
			removeOldBlocks();
			return;
		}

		// Выпадающая подсказка шапки существует только при поиске из шапки.
		if (!document.getElementById(TOOLS_ID)) {
			return;
		}

		removeOldBlocks();

		var html = '';
		if (users.length > 0) {
			// Заменяем штатный блок «Сотрудники» (LIKE-поиск) на результаты
			// морфологического поиска — чтобы не было дублей.
			var nativeUsers = document.querySelectorAll('.search-title-top-block-users');
			for (var n = 0; n < nativeUsers.length; n++) {
				if (nativeUsers[n].parentNode && nativeUsers[n].className.indexOf(BLOCK_MARKER) === -1) {
					nativeUsers[n].parentNode.removeChild(nativeUsers[n]);
				}
			}

			var usersHtml = '';
			for (var u = 0; u < users.length; u++) {
				usersHtml += userItemHtml(users[u]);
			}
			html += sectionHtml(
				'search-title-top-block-users',
				msg('MTAI_OPENSEARCH_USERS', 'Сотрудники'),
				usersHtml,
				'users'
			);
		}

		for (var blockTitle in iblocks) {
			if (!Object.prototype.hasOwnProperty.call(iblocks, blockTitle)) {
				continue;
			}
			var itemsHtml = '';
			var blockItems = iblocks[blockTitle];
			for (var b = 0; b < blockItems.length; b++) {
				itemsHtml += linkItemHtml(blockItems[b]);
			}
			html += sectionHtml(
				'search-title-top-block-section',
				msg('MTAI_OPENSEARCH_IBLOCK_PREFIX', 'Инфоблок') + ': ' + blockTitle,
				itemsHtml,
				'mtai_iblock'
			);
		}

		if (html !== '') {
			insertBlocks(html);
		}
	}

	waitAndPatch();
})();
