<?php
/**
 * Страница обслуживания индекса OpenSearch (админка).
 *
 * Показывает статус кластера/индекса и запускает полный переиндекс
 * по шагам с прогрессом. Сама страница ничего не индексирует —
 * вся работа идёт в ajax-экшенах модуля mtai:opensearch.api.*.
 */

use Bitrix\Main\Localization\Loc;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

Loc::loadMessages(__FILE__);

$moduleId = 'mtai.opensearch';
$POST_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($POST_RIGHT < 'W')
{
	$APPLICATION->AuthForm(Loc::getMessage('MTAI_OPENSEARCH_ACCESS_DENIED'));
}

\Bitrix\Main\Loader::includeModule($moduleId);

$APPLICATION->SetTitle(Loc::getMessage('MTAI_OPENSEARCH_ADMIN_TITLE'));

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>
<div id="mtai-opensearch-admin" style="max-width: 900px;">
	<div class="adm-info-message-wrap">
		<div class="adm-info-message">
			<?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_INTRO') ?>
		</div>
	</div>

	<h3><?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_STATUS') ?></h3>
	<table class="internal" id="mtai-os-status" style="width: 100%;">
		<tbody>
			<tr class="heading">
				<th><?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_ROW_KEY') ?></th>
				<th><?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_ROW_VALUE') ?></th>
			</tr>
			<tr><td>OpenSearch</td><td data-f="ok">…</td></tr>
			<tr><td><?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_VERSION') ?></td><td data-f="version">…</td></tr>
			<tr><td><?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_INDEX') ?></td><td data-f="index">…</td></tr>
			<tr><td><?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_INDEX_EXISTS') ?></td><td data-f="index_exists">…</td></tr>
			<tr><td><?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_DOCS') ?></td><td data-f="total">…</td></tr>
		</tbody>
	</table>
	<table class="internal" id="mtai-os-by-type" style="width: 100%; margin-top: 8px;">
		<tbody>
			<tr class="heading">
				<th><?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_ENTITY_TYPE') ?></th>
				<th><?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_DOCS_COUNT') ?></th>
			</tr>
		</tbody>
	</table>
	<p>
		<input type="button" class="adm-btn" id="mtai-os-refresh" value="<?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_REFRESH') ?>">
		&nbsp;
		<a class="adm-btn" href="settings.php?mid=mtai.opensearch&lang=<?= LANGUAGE_ID ?>"><?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_SETTINGS') ?></a>
	</p>

	<h3><?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_REINDEX') ?></h3>
	<p>
		<input type="button" class="adm-btn adm-btn-save" id="mtai-os-reindex" value="<?= Loc::getMessage('MTAI_OPENSEARCH_ADMIN_BTN_REINDEX') ?>">
		<span id="mtai-os-reindex-state"></span>
	</p>
	<div id="mtai-os-progress-wrap" style="display: none;">
		<div class="adm-progress-bar-outer" style="width: 500px;">
			<div class="adm-progress-bar-inner" id="mtai-os-progress" style="width: 0%;"></div>
		</div>
		<span id="mtai-os-progress-text"></span>
	</div>
	<div id="mtai-os-log" style="margin-top: 12px; max-height: 260px; overflow: auto;"
		class="internal"></div>
</div>

<script>
(function () {
	'use strict';

	var stateBox = document.getElementById('mtai-os-reindex-state');
	var logBox = document.getElementById('mtai-os-log');
	var progressWrap = document.getElementById('mtai-os-progress-wrap');
	var progressBar = document.getElementById('mtai-os-progress');
	var progressText = document.getElementById('mtai-os-progress-text');

	function runAction(action, data) {
		return BX.ajax.runAction('mtai:opensearch.api.maintenance.' + action, {data: data || {}});
	}

	function log(text, isError) {
		var row = document.createElement('div');
		row.className = 'adm-list-table-row' + (isError ? ' mtai-os-error' : '');
		row.style.padding = '2px 8px';
		row.textContent = new Date().toLocaleTimeString() + ' — ' + text;
		logBox.appendChild(row);
		logBox.scrollTop = logBox.scrollHeight;
	}

	function refreshStatus() {
		runAction('status').then(function (response) {
			var d = response.data;
			var map = {
				ok: d.ok ? 'OK' : 'FAIL',
				version: d.version || '—',
				index: d.index,
				index_exists: d.index_exists ? 'Y' : 'N',
				total: String(d.total)
			};
			for (var f in map) {
				var cell = document.querySelector('#mtai-os-status [data-f="' + f + '"]');
				if (cell) {
					cell.textContent = map[f];
				}
			}

			var byType = document.querySelector('#mtai-os-by-type tbody');
			Array.prototype.slice.call(byType.querySelectorAll('tr:not(.heading)')).forEach(function (r) {
				r.parentNode.removeChild(r);
			});
			Object.keys(d.by_type || {}).forEach(function (type) {
				var tr = document.createElement('tr');
				var td1 = document.createElement('td');
				var td2 = document.createElement('td');
				td1.textContent = type;
				td2.textContent = String(d.by_type[type]);
				tr.appendChild(td1);
				tr.appendChild(td2);
				byType.appendChild(tr);
			});
		}, function (response) {
			log('status: ' + (response.errors[0] && response.errors[0].message), true);
		});
	}

	function setProgress(done, total) {
		progressWrap.style.display = total > 0 ? '' : 'none';
		var pct = total > 0 ? Math.round(done * 100 / total) : 0;
		progressBar.style.width = pct + '%';
		progressText.textContent = done + ' / ' + total + ' (' + pct + '%)';
	}

	function runStep(step, lastId, onDone) {
		runAction('reindexStep', {code: step.code, lastId: lastId}).then(function (response) {
			var d = response.data;
			step.processed += d.processed;
			stateBox.textContent = step.title + ': ' + step.processed
				+ (step.total ? ' / ' + step.total : '');
			setProgress(step.processed, step.total || 0);
			if (!d.done) {
				runStep(step, d.last_id, onDone);
			} else {
				log(step.title + ' — ' + (BX.message('MTAI_OPENSEARCH_ADMIN_STEP_DONE') || 'готово')
					+ ' (' + step.processed + ')');
				onDone();
			}
		}, function (response) {
			log(step.title + ': ' + (response.errors[0] && response.errors[0].message), true);
			onDone();
		});
	}

	function reindex() {
		document.getElementById('mtai-os-reindex').disabled = true;
		logBox.innerHTML = '';

		runAction('reindexStart').then(function () {
			log(BX.message('MTAI_OPENSEARCH_ADMIN_INDEX_RECREATED') || 'Индекс пересоздан');
			return runAction('reindexPlan');
		}, function (response) {
			log('reindexStart: ' + (response.errors[0] && response.errors[0].message), true);
		}).then(function (response) {
			if (!response || !response.data) {
				document.getElementById('mtai-os-reindex').disabled = false;
				return;
			}

			var steps = response.data.steps || [];
			if (steps.length === 0) {
				log(BX.message('MTAI_OPENSEARCH_ADMIN_NOTHING_TO_INDEX') || 'Нечего индексировать: область поиска пуста', true);
				document.getElementById('mtai-os-reindex').disabled = false;
				return;
			}

			var i = 0;
			var nextStep = function () {
				if (i >= steps.length) {
					log(BX.message('MTAI_OPENSEARCH_ADMIN_FINISHED') || 'Переиндексация завершена');
					stateBox.textContent = '';
					setProgress(0, 0);
					progressWrap.style.display = 'none';
					document.getElementById('mtai-os-reindex').disabled = false;
					refreshStatus();
					return;
				}
				var step = steps[i++];
				step.processed = 0;
				runStep(step, 0, nextStep);
			};
			nextStep();
		});
	}

	document.getElementById('mtai-os-refresh').addEventListener('click', refreshStatus);
	document.getElementById('mtai-os-reindex').addEventListener('click', reindex);

	BX.ready(refreshStatus);
})();
</script>

<style>
	#mtai-os-log .mtai-os-error { color: #d0021b; }
	#mtai-os-log div { border-bottom: 1px solid #f0f0f0; }
</style>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
