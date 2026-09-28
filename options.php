<?php
/**
 * Настройки модуля mtai.opensearch.
 *
 * Включается обёрткой /bitrix/admin/settings.php (mid=mtai.opensearch),
 * поэтому prolog/epilog не подключаются.
 *
 * Вкладки:
 *   Подключение   — адрес OpenSearch, имя индекса, включение замены поиска;
 *   Область поиска — CRM-сущности, смарт-процессы, пользователи, инфоблоки
 *                    (для каждого — свой набор полей, включая UF_ и свойства);
 *   Индексация    — размер батча + ссылка на страницу обслуживания.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

/** @global CMain $APPLICATION */
/** @global string $mid */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

Loc::loadMessages(__FILE__);

$moduleId = 'mtai.opensearch';

\Bitrix\Main\Loader::includeModule($moduleId);

$POST_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($POST_RIGHT < 'R')
{
	return;
}

$request = Application::getInstance()->getContext()->getRequest();

/** @var array<string, string> name => тип поля (string|int|checkbox) */
$editableOptions = [
	'host' => 'string',
	'port' => 'int',
	'scheme' => 'string',
	'index_name' => 'string',
	'replace_native_search' => 'checkbox',
	'search_limit' => 'int',
	'per_type_limit' => 'int',
	'candidate_factor' => 'int',
	'batch_size' => 'int',
];

$note = null;
$error = null;

if (
	$request->isPost()
	&& check_bitrix_sessid()
	&& $POST_RIGHT >= 'W'
)
{
	if ($request->getPost('test_connection') !== null)
	{
		try
		{
			$client = new \Mtai\OpenSearch\OpenSearchClient(
				rtrim((string)$request->getPost('scheme') ?: 'http', '://') . '://'
				. ((string)$request->getPost('host') ?: 'opensearch') . ':'
				. ((int)$request->getPost('port') ?: 9200)
			);
			$version = $client->version();
			if ($version === '')
			{
				throw new \RuntimeException('no version in response');
			}

			$note = str_replace('#VERSION#', $version, Loc::getMessage('MTAI_OPENSEARCH_TEST_OK'));
		}
		catch (\Throwable $e)
		{
			$error = str_replace('#ERROR#', $e->getMessage(), Loc::getMessage('MTAI_OPENSEARCH_TEST_FAIL'));
		}
	}
	elseif ($request->getPost('Update') !== null)
	{
		foreach ($editableOptions as $name => $type)
		{
			if ($type === 'checkbox')
			{
				Option::set($moduleId, $name, $request->getPost($name) !== null ? 'Y' : 'N');
			}
			else
			{
				$value = trim((string)$request->getPost($name));
				if ($value !== '')
				{
					$value = $type === 'int' ? (string)max(0, (int)$value) : $value;
				}
				Option::set($moduleId, $name, $value);
			}
		}

		Option::set($moduleId, 'scopes', Json::encode(
			\Mtai\OpenSearch\OptionsSaver::collectFromRequest($request),
			JSON_UNESCAPED_UNICODE
		));

		$note = Loc::getMessage('MTAI_OPENSEARCH_SAVED_FIELDS');
	}
}

/**
 * Дерево всех инфоблоков (тип → инфоблоки).
 */
$iblockTree = [];
if (\Bitrix\Main\Loader::includeModule('iblock'))
{
	$types = \CIBlockParameters::GetIBlockTypes();
	$iterator = \CIBlock::GetList(
		['SORT' => 'ASC', 'NAME' => 'ASC'],
		['CHECK_PERMISSIONS' => 'R']
	);
	while ($row = $iterator->Fetch())
	{
		$typeTitle = (string)($types[$row['IBLOCK_TYPE_ID']] ?? $row['IBLOCK_TYPE_ID']);
		$iblockTree[$typeTitle][$row['ID']] = '[' . $row['ID'] . '] ' . $row['NAME'];
	}
}

$scopes = \Mtai\OpenSearch\Config::scopes();
$smartTypes = \Mtai\OpenSearch\FieldRegistry::smartTypes();

/** Список select multiple из POST-подобного массива. */
$postArray = static fn ($value): array => is_array($value) ? $value : [];

/**
 * Рендер multiple select полей скоупа.
 *
 * @param array<string, string> $options code => label
 * @param string[] $selected
 */
$multiSelect = static function (string $name, array $options, array $selected, int $size = 8): string {
	$html = '<select name="' . $name . '[]" multiple size="' . $size . '" style="min-width: 360px;">';
	foreach ($options as $code => $label)
	{
		$html .= '<option value="' . htmlspecialcharsbx($code) . '"'
			. (in_array((string)$code, array_map('strval', $selected), true) ? ' selected' : '') . '>'
			. htmlspecialcharsbx($label) . '</option>';
	}

	return $html . '</select>';
};

/**
 * Строка настроек скоупа из конфига.
 */
$scopeCfg = static fn (string $key, array $default = []): array => is_array($scopes[$key] ?? null) ? $scopes[$key] : $default;

$aTabs = [
	[
		'DIV' => 'tab_connection',
		'TAB' => Loc::getMessage('MTAI_OPENSEARCH_TAB_CONNECTION'),
		'ICON' => 'main_settings',
		'TITLE' => Loc::getMessage('MTAI_OPENSEARCH_TAB_CONNECTION_TITLE'),
	],
	[
		'DIV' => 'tab_scope',
		'TAB' => Loc::getMessage('MTAI_OPENSEARCH_TAB_SCOPE'),
		'ICON' => 'main_settings',
		'TITLE' => Loc::getMessage('MTAI_OPENSEARCH_TAB_SCOPE_TITLE'),
	],
	[
		'DIV' => 'tab_index',
		'TAB' => Loc::getMessage('MTAI_OPENSEARCH_TAB_INDEX'),
		'ICON' => 'main_settings',
		'TITLE' => Loc::getMessage('MTAI_OPENSEARCH_TAB_INDEX_TITLE'),
	],
];
$tabControl = new CAdminTabControl('tabControl', $aTabs);

$optionValue = static function (string $name, string $default = '') use ($moduleId): string {
	return htmlspecialcharsbx(Option::get($moduleId, $name, $default));
};
$checkboxRow = static function (string $name, ?string $label, string $hint = '') use ($moduleId): string {
	$checked = Option::get($moduleId, $name) === 'Y' ? ' checked' : '';
	$html = '<tr><td width="40%">'
		. '<label for="' . $name . '">' . ($label ?? Loc::getMessage('MTAI_OPENSEARCH_OPT_' . mb_strtoupper($name))) . ':</label></td>'
		. '<td width="60%"><input type="checkbox" name="' . $name . '" id="' . $name . '" value="Y"' . $checked . '>';
	if ($hint !== '')
	{
		$html .= '<br><small>' . $hint . '</small>';
	}

	return $html . '</td></tr>';
};

/**
 * Подпись поля по lang-ключу с запасным текстом.
 */
$fieldLabel = static fn (array $field): string => Loc::getMessage($field[0]) ?: $field[1];

/**
 * Опции полей скоупа: системные + UF_/свойства.
 *
 * @return array<string, string>
 */
$scopeOptions = static function (string $kind, int $id = 0) use ($fieldLabel): array {
	$options = [];
	foreach (\Mtai\OpenSearch\FieldRegistry::systemFields($kind) as $code => $field)
	{
		$options[$code] = $fieldLabel($field);
	}
	if ($kind === 'smart')
	{
		foreach (\Mtai\OpenSearch\FieldRegistry::smartUserFields($id) as $code => $label)
		{
			$options[$code] = '[UF] ' . $label;
		}
	}
	elseif (str_starts_with($kind, 'crm_'))
	{
		foreach (\Mtai\OpenSearch\FieldRegistry::crmUserFields($kind) as $code => $label)
		{
			$options[$code] = '[UF] ' . $label;
		}
	}
	elseif ($kind === 'iblock_section')
	{
		foreach (\Mtai\OpenSearch\FieldRegistry::sectionUserFields($id) as $code => $label)
		{
			$options[$code] = '[UF] ' . $label;
		}
	}

	return $options;
};

$iblockScopeCfg = static fn (int $iblockId): array => is_array($scopes['iblock'][$iblockId] ?? null)
	? $scopes['iblock'][$iblockId]
	: ['fields' => [], 'properties' => [], 'sections' => ['enabled' => false, 'fields' => []]];
?>
<?php if ($note !== null): ?>
	<?= CAdminMessage::ShowNote($note) ?>
<?php endif; ?>
<?php if ($error !== null): ?>
	<?= CAdminMessage::ShowMessage($error) ?>
<?php endif; ?>

<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($moduleId) ?>&amp;lang=<?= LANGUAGE_ID ?>">
	<?= bitrix_sessid_post() ?>
	<?php $tabControl->Begin(); ?>

	<?php // ---------- Вкладка: подключение ---------- ?>
	<?php $tabControl->BeginNextTab(); ?>
	<tr>
		<td width="40%"><label for="host"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_HOST') ?>:</label></td>
		<td width="60%">
			<input type="text" size="30" name="host" id="host" value="<?= $optionValue('host', 'opensearch') ?>">
			<br><small><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_HOST_HINT') ?></small>
		</td>
	</tr>
	<tr>
		<td><label for="port"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_PORT') ?>:</label></td>
		<td><input type="text" size="7" name="port" id="port" value="<?= $optionValue('port', '9200') ?>"></td>
	</tr>
	<tr>
		<td><label for="scheme"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_SCHEME') ?>:</label></td>
		<td>
			<select name="scheme" id="scheme">
				<option value="http"<?= Option::get($moduleId, 'scheme', 'http') !== 'https' ? ' selected' : '' ?>>http</option>
				<option value="https"<?= Option::get($moduleId, 'scheme') === 'https' ? ' selected' : '' ?>>https</option>
			</select>
		</td>
	</tr>
	<tr>
		<td><label for="index_name"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_INDEX_NAME') ?>:</label></td>
		<td><input type="text" size="30" name="index_name" id="index_name" value="<?= $optionValue('index_name', 'bitrix24_morpho') ?>"></td>
	</tr>
	<tr class="heading">
		<td colspan="2"><?= Loc::getMessage('MTAI_OPENSEARCH_HEADING_REPLACE') ?></td>
	</tr>
	<?= $checkboxRow(
		'replace_native_search',
		Loc::getMessage('MTAI_OPENSEARCH_OPT_REPLACE_NATIVE_SEARCH'),
		Loc::getMessage('MTAI_OPENSEARCH_OPT_REPLACE_NATIVE_SEARCH_HINT')
	) ?>
	<tr class="heading">
		<td colspan="2"><?= Loc::getMessage('MTAI_OPENSEARCH_HEADING_TEST') ?></td>
	</tr>
	<tr>
		<td colspan="2" align="left">
			<input type="submit" name="test_connection" value="<?= Loc::getMessage('MTAI_OPENSEARCH_BTN_TEST') ?>">
			<br><small><?= Loc::getMessage('MTAI_OPENSEARCH_BTN_TEST_HINT') ?></small>
		</td>
	</tr>

	<?php // ---------- Вкладка: область поиска ---------- ?>
	<?php $tabControl->BeginNextTab(); ?>
	<tr class="heading">
		<td colspan="2"><?= Loc::getMessage('MTAI_OPENSEARCH_HEADING_CRM') ?></td>
	</tr>
	<?php
	$crmEntities = [
		'crm_lead' => 'MTAI_OPENSEARCH_ENTITY_LEAD',
		'crm_deal' => 'MTAI_OPENSEARCH_ENTITY_DEAL',
		'crm_contact' => 'MTAI_OPENSEARCH_ENTITY_CONTACT',
		'crm_company' => 'MTAI_OPENSEARCH_ENTITY_COMPANY',
	];
	foreach ($crmEntities as $scope => $langKey):
		$cfg = $scopeCfg($scope);
		$enabled = (bool)($cfg['enabled'] ?? false);
		$fields = is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];
		?>
		<tr>
			<td width="40%"><label for="entity_<?= $scope ?>"><?= Loc::getMessage($langKey) ?>:</label></td>
			<td width="60%">
				<input type="checkbox" name="entity_<?= $scope ?>" id="entity_<?= $scope ?>" value="Y"<?= $enabled ? ' checked' : '' ?>>
			</td>
		</tr>
		<tr>
			<td colspan="2" style="padding-left: 42px;">
				<div style="margin-bottom: 4px; color: #4a4a4a;"><?= Loc::getMessage('MTAI_OPENSEARCH_FIELDS_FOR', ['#ENTITY#' => Loc::getMessage($langKey)]) ?>:</div>
				<?= $multiSelect('scope_fields_' . $scope, $scopeOptions($scope), $fields) ?>
				<br><small><?= Loc::getMessage('MTAI_OPENSEARCH_FIELDS_HINT') ?></small>
			</td>
		</tr>
	<?php endforeach; ?>

	<tr class="heading">
		<td colspan="2"><?= Loc::getMessage('MTAI_OPENSEARCH_HEADING_SMART') ?></td>
	</tr>
	<tr>
		<td width="40%"><label for="smart_types"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_SMART_TYPES') ?>:</label></td>
		<td width="60%">
			<select name="smart_types[]" id="smart_types" multiple size="6" style="min-width: 360px;">
				<?php
				$smartSelected = array_keys(is_array($scopes['smart'] ?? null) ? $scopes['smart'] : []);
				foreach ($smartTypes as $typeId => $title):
					?>
					<option value="<?= (int)$typeId ?>"<?= in_array((string)$typeId, array_map('strval', $smartSelected), true) || in_array((int)$typeId, $smartSelected, true) ? ' selected' : '' ?>>
						<?= htmlspecialcharsbx($title) ?>
					</option>
				<?php endforeach; ?>
			</select>
			<br><small><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_SMART_TYPES_HINT') ?></small>
		</td>
	</tr>
	<?php foreach ($smartTypes as $typeId => $title):
		$smartCfg = is_array($scopes['smart'][$typeId] ?? null) ? $scopes['smart'][$typeId] : [];
		$smartFields = is_array($smartCfg['fields'] ?? null) ? $smartCfg['fields'] : [];
		?>
		<tr data-smart-block="<?= (int)$typeId ?>" style="display: none;">
			<td colspan="2" style="padding-left: 42px;">
				<div style="margin-bottom: 4px; color: #4a4a4a;"><?= Loc::getMessage('MTAI_OPENSEARCH_FIELDS_FOR', ['#ENTITY#' => htmlspecialcharsbx($title)]) ?>:</div>
				<?= $multiSelect('scope_fields_smart_' . (int)$typeId, $scopeOptions('smart', (int)$typeId), $smartFields, 6) ?>
			</td>
		</tr>
	<?php endforeach; ?>

	<tr class="heading">
		<td colspan="2"><?= Loc::getMessage('MTAI_OPENSEARCH_HEADING_USERS') ?></td>
	</tr>
	<?php
	$userCfg = $scopeCfg('user');
	$userEnabled = (bool)($userCfg['enabled'] ?? false);
	$userFields = is_array($userCfg['fields'] ?? null) ? $userCfg['fields'] : [];
	?>
	<tr>
		<td width="40%"><label for="search_users"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_SEARCH_USERS') ?>:</label></td>
		<td width="60%"><input type="checkbox" name="search_users" id="search_users" value="Y"<?= $userEnabled ? ' checked' : '' ?>></td>
	</tr>
	<tr>
		<td colspan="2" style="padding-left: 42px;">
			<div style="margin-bottom: 4px; color: #4a4a4a;"><?= Loc::getMessage('MTAI_OPENSEARCH_FIELDS_FOR', ['#ENTITY#' => Loc::getMessage('MTAI_OPENSEARCH_HEADING_USERS_ONE')]) ?>:</div>
			<?= $multiSelect('scope_fields_user', $scopeOptions('user'), $userFields) ?>
		</td>
	</tr>

	<tr class="heading">
		<td colspan="2"><?= Loc::getMessage('MTAI_OPENSEARCH_HEADING_IBLOCKS') ?></td>
	</tr>
	<tr>
		<td width="40%"><label for="iblocks"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_IBLOCKS') ?>:</label></td>
		<td width="60%">
			<select name="iblocks[]" id="iblocks" multiple size="10" style="min-width: 360px;">
				<?php
				$selectedIblocks = array_keys(is_array($scopes['iblock'] ?? null) ? $scopes['iblock'] : []);
				foreach ($iblockTree as $typeTitle => $iblocksOfType):
					?>
					<optgroup label="<?= htmlspecialcharsbx($typeTitle) ?>">
						<?php foreach ($iblocksOfType as $iblockId => $title): ?>
							<option value="<?= (int)$iblockId ?>"<?= in_array((string)$iblockId, array_map('strval', $selectedIblocks), true) || in_array((int)$iblockId, $selectedIblocks, true) ? ' selected' : '' ?>>
								<?= htmlspecialcharsbx($title) ?>
							</option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
			<br><small><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_IBLOCKS_HINT') ?></small>
		</td>
	</tr>
	<?php foreach ($iblockTree as $typeTitle => $iblocksOfType): ?>
		<?php foreach ($iblocksOfType as $iblockId => $title):
			$iblockId = (int)$iblockId;
			$cfg = $iblockScopeCfg($iblockId);
			$sectionsCfg = is_array($cfg['sections'] ?? null) ? $cfg['sections'] : [];
			$sectionsEnabled = (bool)($sectionsCfg['enabled'] ?? false);
			$sectionFields = is_array($sectionsCfg['fields'] ?? null) ? $sectionsCfg['fields'] : [];
			$elementFields = is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];
			$properties = is_array($cfg['properties'] ?? null) ? $cfg['properties'] : [];

			$elementOptions = $scopeOptions('iblock_element');
			foreach (\Mtai\OpenSearch\FieldRegistry::iblockProperties($iblockId) as $code => $label)
			{
				$elementOptions[$code] = $label;
			}
			?>
			<tr data-iblock-block="<?= $iblockId ?>" style="display: none;">
				<td colspan="2" style="padding-left: 42px; border-top: 1px solid #e0e0e0; padding-top: 10px;">
					<div style="margin-bottom: 6px; font-weight: bold;"><?= htmlspecialcharsbx($title) ?></div>

					<div style="margin-bottom: 4px; color: #4a4a4a;"><?= Loc::getMessage('MTAI_OPENSEARCH_FIELDS_FOR', ['#ENTITY#' => Loc::getMessage('MTAI_OPENSEARCH_FIELDS_IBLOCK_ONE')]) ?>:</div>
					<?= $multiSelect('scope_fields_iblock_' . $iblockId, $elementOptions, $elementFields) ?>

					<div style="margin: 8px 0 4px;">
						<label>
							<input type="checkbox" name="scope_sections_iblock_<?= $iblockId ?>" value="Y"<?= $sectionsEnabled ? ' checked' : '' ?>>
							<?= Loc::getMessage('MTAI_OPENSEARCH_OPT_SEARCH_SECTIONS') ?>
						</label>
					</div>
					<div style="margin-bottom: 4px; color: #4a4a4a;"><?= Loc::getMessage('MTAI_OPENSEARCH_FIELDS_FOR', ['#ENTITY#' => Loc::getMessage('MTAI_OPENSEARCH_FIELDS_SECTION_ONE')]) ?>:</div>
					<?= $multiSelect('scope_section_fields_iblock_' . $iblockId, $scopeOptions('iblock_section', $iblockId), $sectionFields, 6) ?>
				</td>
			</tr>
		<?php endforeach; ?>
	<?php endforeach; ?>

	<tr class="heading">
		<td colspan="2"><?= Loc::getMessage('MTAI_OPENSEARCH_HEADING_LIMITS') ?></td>
	</tr>
	<tr>
		<td><label for="search_limit"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_SEARCH_LIMIT') ?>:</label></td>
		<td><input type="text" size="5" name="search_limit" id="search_limit" value="<?= $optionValue('search_limit', '20') ?>"></td>
	</tr>
	<tr>
		<td><label for="per_type_limit"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_PER_TYPE_LIMIT') ?>:</label></td>
		<td><input type="text" size="5" name="per_type_limit" id="per_type_limit" value="<?= $optionValue('per_type_limit', '10') ?>"></td>
	</tr>
	<tr>
		<td><label for="candidate_factor"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_CANDIDATE_FACTOR') ?>:</label></td>
		<td>
			<input type="text" size="5" name="candidate_factor" id="candidate_factor" value="<?= $optionValue('candidate_factor', '5') ?>">
			<br><small><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_CANDIDATE_FACTOR_HINT') ?></small>
		</td>
	</tr>

	<?php // ---------- Вкладка: индексация ---------- ?>
	<?php $tabControl->BeginNextTab(); ?>
	<tr>
		<td width="40%"><label for="batch_size"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_BATCH_SIZE') ?>:</label></td>
		<td width="60%">
			<input type="text" size="5" name="batch_size" id="batch_size" value="<?= $optionValue('batch_size', '50') ?>">
			<br><small><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_BATCH_SIZE_HINT') ?></small>
		</td>
	</tr>
	<tr>
		<td colspan="2">
			<?= Loc::getMessage('MTAI_OPENSEARCH_MAINTENANCE_HINT') ?>
		</td>
	</tr>

	<?php $tabControl->Buttons(['btnSave' => true, 'btnApply' => false]); ?>
	<?php $tabControl->End(); ?>
</form>

<script>
	(function () {
		'use strict';

		function syncBlocks(selectId, blockAttr) {
			var select = document.getElementById(selectId);
			if (!select) {
				return;
			}
			var selected = {};
			for (var i = 0; i < select.options.length; i++) {
				if (select.options[i].selected) {
					selected[select.options[i].value] = true;
				}
			}
			var blocks = document.querySelectorAll('[' + blockAttr + ']');
			for (var j = 0; j < blocks.length; j++) {
				blocks[j].style.display = selected[blocks[j].getAttribute(blockAttr)] ? '' : 'none';
			}
		}

		var smart = document.getElementById('smart_types');
		if (smart) {
			smart.addEventListener('change', function () { syncBlocks('smart_types', 'data-smart-block'); });
			syncBlocks('smart_types', 'data-smart-block');
		}
		var iblocks = document.getElementById('iblocks');
		if (iblocks) {
			iblocks.addEventListener('change', function () { syncBlocks('iblocks', 'data-iblock-block'); });
			syncBlocks('iblocks', 'data-iblock-block');
		}
	})();
</script>
