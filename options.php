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
			// rootInfo() не глотает исключение — в сообщении об ошибке
			// остаётся настоящая причина (DNS/таймаут/схема).
			$info = $client->rootInfo();
			$version = (string)($info['version']['number'] ?? '');
			if ($version === '')
			{
				throw new \RuntimeException('кластер ответил без номера версии');
			}

			$note = str_replace('#VERSION#', $version, Loc::getMessage('MTAI_OPENSEARCH_TEST_OK'));
		}
		catch (\Throwable $e)
		{
			$error = str_replace('#ERROR#', $e->getMessage(), Loc::getMessage('MTAI_OPENSEARCH_TEST_FAIL'));
		}
	}
		elseif (
			// Кнопка может называться по-разному: Update (классические
			// settings-страницы) либо save/apply (кнопки CAdminTabControl).
			$request->getPost('Update') !== null
			|| $request->getPost('save') !== null
			|| $request->getPost('apply') !== null
		)
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
 * Select multiple полей + счётчик выбора + ссылки «выбрать все/снять все».
 *
 * @param array<string, string> $options code => label
 * @param string[] $selected
 */
$fieldSelect = static function (string $selectName, array $options, array $selected, int $size = 7): string {
	$selectedMap = array_map('strval', $selected);
	$html = '<div class="mtai-os-fields">'
		. '<select class="mtai-os-select" name="' . $selectName . '[]" multiple size="' . $size . '">';
	foreach ($options as $code => $label)
	{
		$html .= '<option value="' . htmlspecialcharsbx($code) . '"'
			. (in_array((string)$code, $selectedMap, true) ? ' selected' : '') . '>'
			. htmlspecialcharsbx($label) . '</option>';
	}
	$html .= '</select>'
		. '<div class="mtai-os-actions">'
		. '<span class="mtai-os-badge" data-badge-for="' . $selectName . '" data-pattern="'
		. htmlspecialcharsbx(Loc::getMessage('MTAI_OPENSEARCH_SELECTED_N')) . '"></span>'
		. '<a class="mtai-os-link" data-check-all="' . $selectName . '">'
		. htmlspecialcharsbx(Loc::getMessage('MTAI_OPENSEARCH_SELECT_ALL')) . '</a>'
		. '<a class="mtai-os-link" data-uncheck-all="' . $selectName . '">'
		. htmlspecialcharsbx(Loc::getMessage('MTAI_OPENSEARCH_UNSELECT_ALL')) . '</a>'
		. '</div>'
		. '</div>';

	return $html;
};

/**
 * Карточка скоупа: заголовок (вкл/выкл) + содержимое.
 */
$card = static function (string $cardId, string $title, string $inner, bool $dim, string $checkbox = ''): string {
	return '<div class="mtai-os-card' . ($dim ? ' mtai-os-card--off' : '') . '" id="' . $cardId . '">'
		. '<div class="mtai-os-card-head">'
		. $checkbox
		. '<span class="mtai-os-card-title">' . htmlspecialcharsbx($title) . '</span>'
		. '</div>'
		. $inner
		. '</div>';
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
	<style>
		.mtai-os-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 10px; margin: 4px 0 8px; }
		.mtai-os-card { border: 1px solid #dfe3e8; border-radius: 8px; padding: 10px 12px; background: #fbfcfd; transition: opacity .2s ease; }
		.mtai-os-card--off { opacity: .5; }
		.mtai-os-card--off .mtai-os-fields, .mtai-os-dim .mtai-os-fields { pointer-events: none; }
		.mtai-os-card-head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
		.mtai-os-card-title { font-weight: 600; font-size: 13px; }
		.mtai-os-fields select { width: 100%; min-height: 140px; box-sizing: border-box; }
		.mtai-os-actions { display: flex; gap: 12px; margin-top: 6px; align-items: center; flex-wrap: wrap; }
		.mtai-os-link { color: #2f76c9; cursor: pointer; font-size: 12px; text-decoration: none; border-bottom: 1px dotted #9dbedd; }
		.mtai-os-link:hover { color: #1b53a0; }
		.mtai-os-badge { background: #e8eff8; color: #3f6ea8; border-radius: 10px; padding: 1px 9px; font-size: 11px; white-space: nowrap; }
		.mtai-os-badge:empty { display: none; }
		.mtai-os-dim { opacity: .55; }
		.mtai-os-subgroup { margin-top: 10px; padding-top: 8px; border-top: 1px dashed #e0e0e0; }
		.mtai-os-subgroup-title { color: #666; font-size: 12px; margin-bottom: 6px; }
		.mtai-os-selector { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 4px; flex-wrap: wrap; }
		.mtai-os-selector select { min-width: 320px; }
		.mtai-os-selector-label { font-weight: 600; padding-top: 4px; }
		.mtai-os-note { color: #888; font-size: 12px; margin: 2px 0 8px; }
	</style>

	<tr class="heading">
		<td colspan="2"><?= Loc::getMessage('MTAI_OPENSEARCH_HEADING_CRM') ?></td>
	</tr>
	<tr>
		<td colspan="2">
			<div class="mtai-os-note"><?= Loc::getMessage('MTAI_OPENSEARCH_FIELDS_HINT') ?></div>
			<div class="mtai-os-cards">
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
					$checkbox = '<input type="checkbox" name="entity_' . $scope . '" id="entity_' . $scope
						. '" value="Y"' . ($enabled ? ' checked' : '') . ' data-dim-card="card-' . $scope . '">';
					echo $card(
						'card-' . $scope,
						Loc::getMessage($langKey),
						$fieldSelect('scope_fields_' . $scope, $scopeOptions($scope), $fields),
						!$enabled,
						$checkbox
					);
				endforeach;
				?>
			</div>
		</td>
	</tr>

	<tr class="heading">
		<td colspan="2"><?= Loc::getMessage('MTAI_OPENSEARCH_HEADING_SMART') ?></td>
	</tr>
	<tr>
		<td colspan="2">
			<div class="mtai-os-selector">
				<span class="mtai-os-selector-label"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_SMART_TYPES') ?>:</span>
				<select name="smart_types[]" id="smart_types" multiple size="5">
					<?php
					$smartSelected = array_keys(is_array($scopes['smart'] ?? null) ? $scopes['smart'] : []);
					$smartSelectedMap = array_map('strval', $smartSelected);
					foreach ($smartTypes as $typeId => $title):
						?>
						<option value="<?= (int)$typeId ?>"<?= in_array((string)$typeId, $smartSelectedMap, true) ? ' selected' : '' ?>>
							<?= htmlspecialcharsbx($title) ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="mtai-os-note"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_SMART_TYPES_HINT') ?></div>
			<div class="mtai-os-cards" id="mtai-os-smart-cards">
				<?php
				foreach ($smartTypes as $typeId => $title):
					$smartCfg = is_array($scopes['smart'][$typeId] ?? null) ? $scopes['smart'][$typeId] : [];
					$smartFields = is_array($smartCfg['fields'] ?? null) ? $smartCfg['fields'] : [];
					?>
					<div data-smart-block="<?= (int)$typeId ?>" style="display: none;">
						<?= $card(
							'card-smart-' . (int)$typeId,
							(string)$title,
							$fieldSelect('scope_fields_smart_' . (int)$typeId, $scopeOptions('smart', (int)$typeId), $smartFields, 6),
							false
						) ?>
					</div>
				<?php endforeach; ?>
			</div>
		</td>
	</tr>

	<tr class="heading">
		<td colspan="2"><?= Loc::getMessage('MTAI_OPENSEARCH_HEADING_USERS') ?></td>
	</tr>
	<tr>
		<td colspan="2">
			<?php
			$userCfg = $scopeCfg('user');
			$userEnabled = (bool)($userCfg['enabled'] ?? false);
			$userFields = is_array($userCfg['fields'] ?? null) ? $userCfg['fields'] : [];
			$checkbox = '<input type="checkbox" name="search_users" id="search_users" value="Y"'
				. ($userEnabled ? ' checked' : '') . ' data-dim-card="card-user">';
			echo $card(
				'card-user',
				Loc::getMessage('MTAI_OPENSEARCH_HEADING_USERS_ONE'),
				$fieldSelect('scope_fields_user', $scopeOptions('user'), $userFields),
				!$userEnabled,
				$checkbox
			);
			?>
		</td>
	</tr>

	<tr class="heading">
		<td colspan="2"><?= Loc::getMessage('MTAI_OPENSEARCH_HEADING_IBLOCKS') ?></td>
	</tr>
	<tr>
		<td colspan="2">
			<div class="mtai-os-selector">
				<span class="mtai-os-selector-label"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_IBLOCKS') ?>:</span>
				<select name="iblocks[]" id="iblocks" multiple size="8">
					<?php
					$selectedIblocks = array_keys(is_array($scopes['iblock'] ?? null) ? $scopes['iblock'] : []);
					$selectedIblocksMap = array_map('strval', $selectedIblocks);
					foreach ($iblockTree as $typeTitle => $iblocksOfType):
						?>
						<optgroup label="<?= htmlspecialcharsbx($typeTitle) ?>">
							<?php foreach ($iblocksOfType as $iblockId => $title): ?>
								<option value="<?= (int)$iblockId ?>"<?= in_array((string)$iblockId, $selectedIblocksMap, true) ? ' selected' : '' ?>>
									<?= htmlspecialcharsbx($title) ?>
								</option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="mtai-os-note"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_IBLOCKS_HINT') ?></div>
			<div class="mtai-os-cards" id="mtai-os-iblock-cards">
				<?php
				foreach ($iblockTree as $typeTitle => $iblocksOfType):
					foreach ($iblocksOfType as $iblockId => $title):
						$iblockId = (int)$iblockId;
						$cfg = $iblockScopeCfg($iblockId);
						$sectionsCfg = is_array($cfg['sections'] ?? null) ? $cfg['sections'] : [];
						$sectionsEnabled = (bool)($sectionsCfg['enabled'] ?? false);
						$sectionFields = is_array($sectionsCfg['fields'] ?? null) ? $sectionsCfg['fields'] : [];
						$elementFields = is_array($cfg['fields'] ?? null) ? $cfg['fields'] : [];
						// Свойства хранятся отдельным ключом, но в форме
						// живут в том же select, что и поля элемента, —
						// помечаем выбранными их объединение, иначе любое
						// сохранение молча обнуляет свойства.
						$properties = is_array($cfg['properties'] ?? null) ? $cfg['properties'] : [];
						$elementSelected = array_merge($elementFields, $properties);

						$elementOptions = $scopeOptions('iblock_element');
						foreach (\Mtai\OpenSearch\FieldRegistry::iblockProperties($iblockId) as $code => $label)
						{
							$elementOptions[$code] = $label;
						}

						$inner = '<div class="mtai-os-subgroup-title">'
							. Loc::getMessage('MTAI_OPENSEARCH_FIELDS_FOR', ['#ENTITY#' => Loc::getMessage('MTAI_OPENSEARCH_FIELDS_IBLOCK_ONE')])
							. '</div>'
							. $fieldSelect('scope_fields_iblock_' . $iblockId, $elementOptions, $elementSelected)
							. '<div class="mtai-os-subgroup">'
							. '<label><input type="checkbox" name="scope_sections_iblock_' . $iblockId . '" value="Y"'
							. ($sectionsEnabled ? ' checked' : '')
							. ' data-dim-target="mtai-os-secfields-' . $iblockId . '"> '
							. Loc::getMessage('MTAI_OPENSEARCH_OPT_SEARCH_SECTIONS') . '</label>'
							. '<div id="mtai-os-secfields-' . $iblockId . '" class="' . ($sectionsEnabled ? '' : 'mtai-os-dim') . '">'
							. '<div class="mtai-os-subgroup-title">'
							. Loc::getMessage('MTAI_OPENSEARCH_FIELDS_FOR', ['#ENTITY#' => Loc::getMessage('MTAI_OPENSEARCH_FIELDS_SECTION_ONE')])
							. '</div>'
							. $fieldSelect('scope_section_fields_iblock_' . $iblockId, $scopeOptions('iblock_section', $iblockId), $sectionFields, 5)
							. '</div>'
							. '</div>';
						?>
						<div data-iblock-block="<?= $iblockId ?>" style="display: none;">
							<?= $card('card-iblock-' . $iblockId, (string)$title, $inner, false) ?>
						</div>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</div>
		</td>
	</tr>

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

		// --- Счётчики выбранных полей ---
		function updateBadge(name) {
			var badge = document.querySelector('[data-badge-for="' + name + '"]');
			var select = document.querySelector('select[name="' + name + '[]"]');
			if (!badge || !select) {
				return;
			}
			var count = 0;
			for (var i = 0; i < select.options.length; i++) {
				if (select.options[i].selected) {
					count++;
				}
			}
			badge.textContent = (badge.getAttribute('data-pattern') || '#N#').replace('#N#', String(count));
		}

		function setAll(name, check) {
			var select = document.querySelector('select[name="' + name + '[]"]');
			if (!select) {
				return;
			}
			for (var i = 0; i < select.options.length; i++) {
				select.options[i].selected = check;
			}
			updateBadge(name);
		}

		// --- Видимость карточек смартов/инфоблоков ---
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

		// --- Затемнение карточки выключенной сущности ---
		function syncDimCard(checkbox) {
			var card = document.getElementById(checkbox.getAttribute('data-dim-card'));
			if (card) {
				card.classList[checkbox.checked ? 'remove' : 'add']('mtai-os-card--off');
			}
		}

		function syncDimTarget(checkbox) {
			var target = document.getElementById(checkbox.getAttribute('data-dim-target'));
			if (target) {
				target.classList[checkbox.checked ? 'remove' : 'add']('mtai-os-dim');
			}
		}

		// --- Делегирование событий ---
		document.addEventListener('click', function (e) {
			var el = e.target.closest ? e.target.closest('[data-check-all],[data-uncheck-all]') : null;
			if (!el) {
				return;
			}
			e.preventDefault();
			setAll(el.getAttribute(el.hasAttribute('data-check-all') ? 'data-check-all' : 'data-uncheck-all'),
				el.hasAttribute('data-check-all'));
		});

		document.addEventListener('change', function (e) {
			var t = e.target;
			if (t.tagName === 'SELECT' && t.name && t.name.slice(-2) === '[]') {
				updateBadge(t.name.slice(0, -2));
			}
			if (t.getAttribute && t.getAttribute('data-dim-card')) {
				syncDimCard(t);
			}
			if (t.getAttribute && t.getAttribute('data-dim-target')) {
				syncDimTarget(t);
			}
			if (t.id === 'smart_types') {
				syncBlocks('smart_types', 'data-smart-block');
			}
			if (t.id === 'iblocks') {
				syncBlocks('iblocks', 'data-iblock-block');
			}
		});

		// --- Инициализация ---
		document.querySelectorAll('[data-badge-for]').forEach(function (b) {
			updateBadge(b.getAttribute('data-badge-for'));
		});
		document.querySelectorAll('[data-dim-card]').forEach(syncDimCard);
		document.querySelectorAll('[data-dim-target]').forEach(syncDimTarget);
		syncBlocks('smart_types', 'data-smart-block');
		syncBlocks('iblocks', 'data-iblock-block');
	})();
</script>
