<?php
/**
 * Настройки модуля mtai.opensearch.
 *
 * Включается обёрткой /bitrix/admin/settings.php (mid=mtai.opensearch),
 * поэтому prolog/epilog не подключаются.
 *
 * Вкладки:
 *   Подключение   — адрес OpenSearch, имя индекса, включение замены поиска;
 *   Область поиска — CRM-сущности, пользователи, инфоблоки, лимиты;
 *   Индексация    — размер батча + ссылка на страницу обслуживания.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

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
	'entity_lead' => 'checkbox',
	'entity_deal' => 'checkbox',
	'entity_contact' => 'checkbox',
	'entity_company' => 'checkbox',
	'entity_quote' => 'checkbox',
	'entity_smart_invoice' => 'checkbox',
	'entity_dynamic' => 'checkbox',
	'search_users' => 'checkbox',
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

		// Инфоблоки: multiselect → CSV id.
		$iblocks = (array)$request->getPost('iblocks');
		$iblockIds = [];
		foreach ($iblocks as $iblockId)
		{
			if (is_numeric($iblockId))
			{
				$iblockIds[] = (int)$iblockId;
			}
		}
		Option::set($moduleId, 'iblocks', implode(',', array_unique($iblockIds)));

		$note = Loc::getMessage('MTAI_OPENSEARCH_SAVED');
	}
}

/**
 * Дерево инфоблоков (тип → инфоблоки) для multiselect.
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

$selectedIblocks = \Mtai\OpenSearch\Config::iblockIds();

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
	<?= $checkboxRow('entity_lead', Loc::getMessage('MTAI_OPENSEARCH_ENTITY_LEAD')) ?>
	<?= $checkboxRow('entity_deal', Loc::getMessage('MTAI_OPENSEARCH_ENTITY_DEAL')) ?>
	<?= $checkboxRow('entity_contact', Loc::getMessage('MTAI_OPENSEARCH_ENTITY_CONTACT')) ?>
	<?= $checkboxRow('entity_company', Loc::getMessage('MTAI_OPENSEARCH_ENTITY_COMPANY')) ?>
	<?= $checkboxRow('entity_quote', Loc::getMessage('MTAI_OPENSEARCH_ENTITY_QUOTE')) ?>
	<?= $checkboxRow('entity_smart_invoice', Loc::getMessage('MTAI_OPENSEARCH_ENTITY_SMART_INVOICE')) ?>
	<?= $checkboxRow(
		'entity_dynamic',
		Loc::getMessage('MTAI_OPENSEARCH_ENTITY_DYNAMIC'),
		Loc::getMessage('MTAI_OPENSEARCH_ENTITY_DYNAMIC_HINT')
	) ?>
	<tr class="heading">
		<td colspan="2"><?= Loc::getMessage('MTAI_OPENSEARCH_HEADING_USERS') ?></td>
	</tr>
	<?= $checkboxRow(
		'search_users',
		Loc::getMessage('MTAI_OPENSEARCH_OPT_SEARCH_USERS'),
		Loc::getMessage('MTAI_OPENSEARCH_OPT_SEARCH_USERS_HINT')
	) ?>
	<tr class="heading">
		<td colspan="2"><?= Loc::getMessage('MTAI_OPENSEARCH_HEADING_IBLOCKS') ?></td>
	</tr>
	<tr>
		<td width="40%"><label for="iblocks"><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_IBLOCKS') ?>:</label></td>
		<td width="60%">
			<select name="iblocks[]" id="iblocks" multiple size="12" style="min-width: 320px;">
				<?php foreach ($iblockTree as $typeTitle => $iblocksOfType): ?>
					<optgroup label="<?= htmlspecialcharsbx($typeTitle) ?>">
						<?php foreach ($iblocksOfType as $iblockId => $title): ?>
							<option value="<?= (int)$iblockId ?>"<?= in_array((int)$iblockId, $selectedIblocks, true) ? ' selected' : '' ?>>
								<?= htmlspecialcharsbx($title) ?>
							</option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
			<br><small><?= Loc::getMessage('MTAI_OPENSEARCH_OPT_IBLOCKS_HINT') ?></small>
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
