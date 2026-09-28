<?php

namespace Mtai\OpenSearch;

use Bitrix\Main\Loader;

/**
 * Строит документы для индекса OpenSearch из сущностей Bitrix24.
 *
 * Структура документа:
 *   title / subtitle / body — заголовок, подзаголовок и полный поисковый
 *     контент (для CRM — штатные билдеры Crm\Search\SearchContentBuilderFactory);
 *   fields.<code> — отдельные поля сущности (comments, post, preview, ...),
 *     по которым можно ограничить область поиска в настройках модуля;
 *   phones / emails — нормализованные контакты для точного поиска.
 */
final class DocumentBuilder
{
	/**
	 * Документ CRM-сущности. null — сущность не найдена/не поддерживается.
	 */
	public function buildCrmDocument(int $entityTypeId, int $entityId): ?array
	{
		if (!Loader::includeModule('crm') || $entityId <= 0)
		{
			return null;
		}

		$title = $this->crmTitle($entityTypeId, $entityId);
		if ($title === null)
		{
			return null;
		}

		$body = '';
		try
		{
			$builder = \Bitrix\Crm\Search\SearchContentBuilderFactory::create($entityTypeId);
			if ($builder !== null)
			{
				$body = (string)$builder->getSearchContent($entityId);
			}
		}
		catch (\Throwable)
		{
			// контент соберётся при следующем полном переиндексе
		}

		$row = $this->fetchCrmRow($entityTypeId, $entityId);

		$categoryId = 0;
		if ($entityTypeId === \CCrmOwnerType::Deal)
		{
			$categoryId = (int)($row['CATEGORY_ID'] ?? 0);
		}

		$contacts = $this->crmPhonesAndEmails($entityTypeId, $entityId);

		$fields = $this->crmFields($entityTypeId, $row);
		// UF-поля (индексируем все — область поиска режется запросом).
		foreach ($this->userFieldValues($this->crmUfEntityName($entityTypeId), $entityId) as $code => $value)
		{
			$fields[$code] = $value;
		}
		// Штатный поисковый контент кладём в fields.content, а не в общее
		// поле body: область поиска задаются по скоупам, и контент одного
		// типа не должен открывать поиск по контенту другого.
		if (trim($body) !== '')
		{
			$fields['content'] = $body;
		}

		return [
			'entity_type' => self::crmEntityTypeCode($entityTypeId),
			'crm_type_id' => $entityTypeId,
			'entity_id' => $entityId,
			'category_id' => $categoryId,
			'iblock_id' => 0,
			'title' => $title,
			'subtitle' => '',
			'fields' => $fields,
			'phones' => $contacts['phones'],
			'emails' => $contacts['emails'],
			'url' => '',
			'attrs' => [],
			'date_update' => date('c'),
		];
	}

	/**
	 * Имя UF-сущности CRM для чтения пользовательских полей.
	 */
	private function crmUfEntityName(int $entityTypeId): string
	{
		$map = [
			\CCrmOwnerType::Lead => 'CRM_LEAD',
			\CCrmOwnerType::Deal => 'CRM_DEAL',
			\CCrmOwnerType::Contact => 'CRM_CONTACT',
			\CCrmOwnerType::Company => 'CRM_COMPANY',
		];

		return $map[$entityTypeId] ?? ('CRM_' . $entityTypeId);
	}

	/**
	 * Значения всех UF-полей сущности в человекочитаемом виде:
	 * код поля => строка.
	 *
	 * @return array<string, string>
	 */
	private function userFieldValues(string $ufEntity, int $entityId): array
	{
		$result = [];
		try
		{
			$userFields = $GLOBALS['USER_FIELD_MANAGER']->GetUserFields($ufEntity, $entityId, LANGUAGE_ID);
			foreach ((array)$userFields as $code => $field)
			{
				if (!is_array($field) || ($field['VALUE'] ?? null) === null || (string)$field['VALUE'] === '')
				{
					continue;
				}
				$formatted = $this->formatUserFieldValue($field['VALUE'], $field);
				if ($formatted !== '')
				{
					$result[(string)$code] = $formatted;
				}
			}
		}
		catch (\Throwable)
		{
		}

		return $result;
	}

	/**
	 * Приводит значение UF-поля (в т.ч. множественное) к поисковой строке.
	 */
	private function formatUserFieldValue($value, array $field): string
	{
		$values = (($field['MULTIPLE'] ?? 'N') === 'Y' && is_array($value)) ? $value : [$value];

		$parts = [];
		foreach ($values as $single)
		{
			$formatted = $this->formatSingleUserFieldValue($single, $field);
			if ($formatted !== '')
			{
				$parts[] = $formatted;
			}
		}

		return implode(', ', $parts);
	}

	private function formatSingleUserFieldValue($value, array $field): string
	{
		if (!is_scalar($value) && $value !== null)
		{
			return '';
		}

		switch ((string)($field['USER_TYPE_ID'] ?? ''))
		{
			case 'boolean':
				return $value ? 'Да' : 'Нет';

			case 'enumeration':
				$enum = $this->enumValues((int)($field['ID'] ?? 0));

				return (string)($enum[(int)$value] ?? (string)$value);

			case 'employee':
				$user = \CUser::GetByID((int)$value)->Fetch();
				if ($user)
				{
					return trim(\CUser::FormatName(\CSite::GetNameFormat(false), $user, true));
				}

				return '';

			case 'crm':
				// Формат значений: "<буква типа>_<ID>" (L_12, D_3, C_7, CO_1...).
				[$prefix, $id] = array_pad(explode('_', (string)$value, 2), 2, null);
				$typeId = $this->resolveCrmUfPrefix((string)$prefix);
				if ($typeId > 0 && (int)$id > 0)
				{
					try
					{
						return (string)\CCrmOwnerType::GetTitle($typeId, (int)$id);
					}
					catch (\Throwable)
					{
						return (string)$value;
					}
				}

				return (string)$value;

			case 'iblock_element':
				if (Loader::includeModule('iblock'))
				{
					$element = \CIBlockElement::GetByID((int)$value)->Fetch();

					return $element ? (string)$element['NAME'] : '';
				}

				return '';

			case 'iblock_section':
				if (Loader::includeModule('iblock'))
				{
					$section = \CIBlockSection::GetByID((int)$value)->Fetch();

					return $section ? (string)$section['NAME'] : '';
				}

				return '';

			case 'disk_file':
			case 'url':
			default:
				return trim((string)$value);
		}
	}

	/** Кэш значений списков UF-полей: fieldId => [valueId => текст]. */
	private array $enumCache = [];

	private function enumValues(int $fieldId): array
	{
		if ($fieldId <= 0)
		{
			return [];
		}
		if (!isset($this->enumCache[$fieldId]))
		{
			$values = [];
			$rows = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $fieldId]);
			while ($row = $rows->GetNext())
			{
				$values[(int)$row['ID']] = (string)$row['VALUE'];
			}
			$this->enumCache[$fieldId] = $values;
		}

		return $this->enumCache[$fieldId];
	}

	private function resolveCrmUfPrefix(string $prefix): int
	{
		$map = [
			'L' => \CCrmOwnerType::Lead,
			'D' => \CCrmOwnerType::Deal,
			'C' => \CCrmOwnerType::Contact,
			'CO' => \CCrmOwnerType::Company,
			'Q' => \CCrmOwnerType::Quote,
			'I' => \CCrmOwnerType::Invoice,
			'SI' => \CCrmOwnerType::SmartInvoice,
			'TM' => \CCrmOwnerType::Activity,
		];

		return (int)($map[strtoupper($prefix)] ?? 0);
	}

	/**
	 * Отображаемый заголовок CRM-сущности (как в поисковой выдаче CRM).
	 */
	private function crmTitle(int $entityTypeId, int $entityId): ?string
	{
		$row = $this->fetchCrmRow($entityTypeId, $entityId);
		if ($row === null)
		{
			return null;
		}

		switch ($entityTypeId)
		{
			case \CCrmOwnerType::Lead:
				return (string)($row['TITLE'] ?: trim(
					($row['NAME'] ?? '') . ' ' . ($row['SECOND_NAME'] ?? '') . ' ' . ($row['LAST_NAME'] ?? '')
				));

			case \CCrmOwnerType::Deal:
			case \CCrmOwnerType::Quote:
				return (string)$row['TITLE'];

			case \CCrmOwnerType::Contact:
				return (string)\CCrmContact::PrepareFormattedName($row);

			case \CCrmOwnerType::Company:
				return (string)$row['TITLE'];

			default:
				// Фабричные сущности: смарт-счета и смарт-процессы.
				if (\CCrmOwnerType::isUseFactoryBasedApproach($entityTypeId))
				{
					$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
					if (!$factory)
					{
						return null;
					}
					$item = $factory->getItem($entityId);
					if (!$item)
					{
						return null;
					}
					try
					{
						return (string)($item->getTitle() ?? '');
					}
					catch (\Throwable)
					{
						return '#' . $entityId;
					}
				}

				return null;
		}
	}

	/**
	 * Колонки для выборки полей по типу сущности.
	 *
	 * @return string[]|null
	 */
	private function fetchCrmRow(int $entityTypeId, int $entityId): ?array
	{
		$selects = [
			\CCrmOwnerType::Lead => ['ID', 'TITLE', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'COMMENTS', 'SOURCE_DESCRIPTION', 'CATEGORY_ID'],
			\CCrmOwnerType::Deal => ['ID', 'TITLE', 'COMMENTS', 'ADDITIONAL_INFO', 'CATEGORY_ID'],
			\CCrmOwnerType::Contact => ['ID', 'HONORIFIC', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'POST', 'COMMENTS', 'CATEGORY_ID'],
			\CCrmOwnerType::Company => ['ID', 'TITLE', 'COMMENTS', 'CATEGORY_ID'],
			\CCrmOwnerType::Quote => ['ID', 'TITLE'],
		];

		$select = $selects[$entityTypeId] ?? null;

		// Фабричные сущности (смарт-счета, смарт-процессы).
		if ($select === null && \CCrmOwnerType::isUseFactoryBasedApproach($entityTypeId))
		{
			$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
			if (!$factory)
			{
				return null;
			}
			$item = $factory->getItem($entityId);
			if (!$item)
			{
				return null;
			}

			$row = ['ID' => $item->getId()];
			foreach (['TITLE', 'COMMENTS'] as $code)
			{
				try
				{
					$row[$code] = (string)($item->get($code) ?? '');
				}
				catch (\Throwable)
				{
					// не у всех смарт-процессов есть эти поля
					$row[$code] = '';
				}
			}

			return $row;
		}

		if ($select === null)
		{
			return null;
		}

		$classMap = [
			\CCrmOwnerType::Lead => '\CCrmLead',
			\CCrmOwnerType::Deal => '\CCrmDeal',
			\CCrmOwnerType::Contact => '\CCrmContact',
			\CCrmOwnerType::Company => '\CCrmCompany',
			\CCrmOwnerType::Quote => '\CCrmQuote',
		];
		$class = $classMap[$entityTypeId] ?? null;
		if (!$class || !class_exists($class))
		{
			return null;
		}

		$row = $class::GetListEx(
			[],
			['=ID' => $entityId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			['nTopCount' => 1],
			$select
		)->Fetch();

		return $row ?: null;
	}

	/**
	 * Отдельные поля сущности для объекта fields.<code>.
	 */
	private function crmFields(int $entityTypeId, ?array $row): array
	{
		if ($row === null)
		{
			return [];
		}

		$fields = [];
		switch ($entityTypeId)
		{
			case \CCrmOwnerType::Lead:
				$fields['name'] = trim(
					(string)($row['NAME'] ?? '') . ' ' . (string)($row['SECOND_NAME'] ?? '') . ' ' . (string)($row['LAST_NAME'] ?? '')
				);
				$fields['comments'] = (string)($row['COMMENTS'] ?? '');
				$fields['source'] = (string)($row['SOURCE_DESCRIPTION'] ?? '');
				break;

			case \CCrmOwnerType::Deal:
				$fields['comments'] = (string)($row['COMMENTS'] ?? '');
				$fields['additional_info'] = (string)($row['ADDITIONAL_INFO'] ?? '');
				break;

			case \CCrmOwnerType::Contact:
				$fields['post'] = (string)($row['POST'] ?? '');
				$fields['comments'] = (string)($row['COMMENTS'] ?? '');
				break;

			case \CCrmOwnerType::Company:
				$fields['comments'] = (string)($row['COMMENTS'] ?? '');
				break;
		}

		return array_filter($fields, static fn ($v) => trim((string)$v) !== '');
	}

	/**
	 * Телефоны и email CRM-сущности из множественных полей.
	 *
	 * @return array{phones: string[], emails: string[]}
	 */
	private function crmPhonesAndEmails(int $entityTypeId, int $entityId): array
	{
		$result = ['phones' => [], 'emails' => []];
		if (!in_array($entityTypeId, [\CCrmOwnerType::Lead, \CCrmOwnerType::Contact, \CCrmOwnerType::Company], true))
		{
			return $result;
		}

		try
		{
			$rows = \CCrmFieldMulti::GetListEx(
				[],
				[
					'=ENTITY_ID' => \CCrmOwnerType::ResolveName($entityTypeId),
					'=ELEMENT_ID' => $entityId,
					'@TYPE_ID' => ['PHONE', 'EMAIL'],
				],
				false,
				false,
				['TYPE_ID', 'VALUE']
			);
			while ($row = $rows->Fetch())
			{
				if ($row['TYPE_ID'] === 'PHONE')
				{
					$digits = preg_replace('/\D+/', '', (string)$row['VALUE']) ?? '';
					if (mb_strlen($digits) >= 5)
					{
						$result['phones'][] = $digits;
					}
				}
				else
				{
					$result['emails'][] = mb_strtolower((string)$row['VALUE']);
				}
			}
		}
		catch (\Throwable)
		{
		}

		return $result;
	}

	private function crmEntityTypeName(int $entityTypeId): string
	{
		return self::crmEntityTypeCode($entityTypeId);
	}

	/**
	 * Код вида сущности для индекса (crm_lead, crm_dynamic, ...).
	 */
	public static function crmEntityTypeCode(int $entityTypeId): string
	{
		// В новых версиях Битрикса фабричный подход используется для всех
		// сущностей, поэтому классику различаем явной картой.
		$classics = [
			\CCrmOwnerType::Lead => 'lead',
			\CCrmOwnerType::Deal => 'deal',
			\CCrmOwnerType::Contact => 'contact',
			\CCrmOwnerType::Company => 'company',
			\CCrmOwnerType::Quote => 'quote',
			\CCrmOwnerType::SmartInvoice => 'smart_invoice',
		];
		if (isset($classics[$entityTypeId]))
		{
			return IndexManager::TYPE_CRM_PREFIX . $classics[$entityTypeId];
		}

		if (\CCrmOwnerType::isUseDynamicTypeBasedApproach($entityTypeId))
		{
			return IndexManager::TYPE_CRM_PREFIX . 'dynamic';
		}

		return IndexManager::TYPE_CRM_PREFIX . mb_strtolower(\CCrmOwnerType::ResolveName($entityTypeId));
	}

	/**
	 * Документ пользователя портала.
	 */
	public function buildUserDocument(int $userId): ?array
	{
		if ($userId <= 0)
		{
			return null;
		}

		$row = \Bitrix\Main\UserTable::getList([
			'select' => [
				'ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'EMAIL', 'LOGIN',
				'WORK_POSITION', 'ACTIVE', 'PERSONAL_PHOTO', 'PERSONAL_PHONE',
				'PERSONAL_MOBILE', 'WORK_PHONE', 'UF_DEPARTMENT',
			],
			'filter' => ['=ID' => $userId],
			'limit' => 1,
		])->fetch();
		if (!$row)
		{
			return null;
		}

		if (($row['ACTIVE'] ?? 'N') !== 'Y')
		{
			return null;
		}

		$departments = (array)($row['UF_DEPARTMENT'] ?? []);
		// Внешних пользователей (без подразделений — extranet и пр.) не индексируем.
		if ($departments === [])
		{
			return null;
		}

		$title = trim(\CUser::FormatName(\CSite::GetNameFormat(false), $row, true));
		if ($title === '')
		{
			$title = (string)$row['EMAIL'];
		}

		$position = (string)($row['WORK_POSITION'] ?? '');
		$departmentNames = [];
		if (Loader::includeModule('intranet'))
		{
			$departmentsData = \CIntranetUtils::GetDepartmentsData($departments);
			if (is_array($departmentsData))
			{
				foreach ($departmentsData as $departmentName)
				{
					if (is_string($departmentName) && $departmentName !== '')
					{
						$departmentNames[] = $departmentName;
					}
				}
			}
		}

		$phones = [];
		foreach (['PERSONAL_PHONE', 'PERSONAL_MOBILE', 'WORK_PHONE'] as $column)
		{
			$digits = preg_replace('/\D+/', '', (string)($row[$column] ?? '')) ?? '';
			if (mb_strlen($digits) >= 5)
			{
				$phones[] = $digits;
			}
		}

		$fields = [
			'email' => (string)($row['EMAIL'] ?? ''),
			'position' => $position,
			'department' => implode(', ', $departmentNames),
		];
		$fields = array_filter($fields, static fn ($v) => trim((string)$v) !== '');

		return [
			'entity_type' => IndexManager::TYPE_USER,
			'crm_type_id' => 0,
			'entity_id' => (int)$userId,
			'category_id' => 0,
			'iblock_id' => 0,
			'title' => $title,
			'subtitle' => implode(', ', array_filter([$position, implode(', ', $departmentNames)])),
			'fields' => $fields,
			'phones' => array_values(array_unique($phones)),
			'emails' => array_values(array_filter([mb_strtolower((string)($row['EMAIL'] ?? ''))])),
			'url' => '/company/personal/user/' . (int)$userId . '/',
			'attrs' => [
				'email' => (string)($row['EMAIL'] ?? ''),
				'position' => $position,
				'photo' => $this->userPhotoUrl((int)($row['PERSONAL_PHOTO'] ?? 0)),
			],
			'date_update' => date('c'),
		];
	}

	private function userPhotoUrl(int $fileId): string
	{
		if ($fileId <= 0)
		{
			return '';
		}

		$resized = \CFile::ResizeImageGet(
			$fileId,
			['width' => 38, 'height' => 38],
			BX_RESIZE_IMAGE_EXACT
		);

		return is_array($resized) ? (string)$resized['src'] : '';
	}

	/**
	 * Документ элемента инфоблока.
	 */
	public function buildIblockElementDocument(int $elementId): ?array
	{
		if (!Loader::includeModule('iblock') || $elementId <= 0)
		{
			return null;
		}

		$iterator = \CIBlockElement::GetList(
			[],
			[
				'=ID' => $elementId,
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			['nTopCount' => 1],
			[
				'ID', 'IBLOCK_ID', 'NAME', 'TAGS', 'CODE', 'IBLOCK_SECTION_ID',
				'PREVIEW_TEXT', 'DETAIL_TEXT',
				'DETAIL_PAGE_URL', 'IBLOCK_TYPE_ID',
			]
		);
		$row = $iterator instanceof \CIBlockResult ? $iterator->Fetch() : null;
		if (!$row)
		{
			return null;
		}

		$iblockId = (int)$row['IBLOCK_ID'];
		$iblock = \CIBlock::GetArrayByID($iblockId);
		if (!$iblock)
		{
			return null;
		}

		$siteDir = $this->iblockSiteDir((string)($iblock['LID'] ?? SITE_ID));

		// DETAIL_PAGE_URL из GetList содержит неразвёрнутые плейсхолдеры
		// (#ELEMENT_ID#, #CODE#, ...) — подставляем штатным механизмом,
		// иначе ссылка из выдачи не работает.
		$url = \CIBlock::ReplaceDetailUrl((string)$row['DETAIL_PAGE_URL'], $row, false, 'E');
		$url = str_replace('#SITE_DIR#', $siteDir, $url);

		$preview = trim(strip_tags((string)($row['PREVIEW_TEXT'] ?? '')));
		$detail = trim(strip_tags((string)($row['DETAIL_TEXT'] ?? '')));
		$tags = trim((string)($row['TAGS'] ?? ''));

		$fields = [
			'preview' => $preview,
			'detail' => $detail,
			'tags' => str_replace(',', ' ', $tags),
		];
		// Свойства элемента: prop_<код свойства> => человекочитаемое значение.
		foreach ($this->iblockPropertyValues($iblockId, $elementId) as $code => $value)
		{
			$fields[$code] = $value;
		}
		$fields = array_filter($fields, static fn ($v) => trim((string)$v) !== '');

		return [
			'entity_type' => IndexManager::TYPE_IBLOCK,
			'crm_type_id' => 0,
			'entity_id' => $elementId,
			'category_id' => 0,
			'iblock_id' => $iblockId,
			'title' => (string)$row['NAME'],
			'subtitle' => (string)$iblock['NAME'],
			'fields' => $fields,
			'phones' => [],
			'emails' => [],
			'url' => $url,
			'attrs' => [
				'iblockId' => $iblockId,
				'iblockTitle' => (string)$iblock['NAME'],
				'iblockType' => (string)($row['IBLOCK_TYPE_ID'] ?? ''),
			],
			'date_update' => date('c'),
		];
	}

	/**
	 * Документ раздела инфоблока (включается чекбоксом у инфоблока).
	 */
	public function buildIblockSectionDocument(int $sectionId): ?array
	{
		if (!Loader::includeModule('iblock') || $sectionId <= 0)
		{
			return null;
		}

		// CHECK_PERMISSIONS=N: индексация идёт из CLI/агентов без авторизации.
		$section = \CIBlockSection::GetList(
			[],
			['=ID' => $sectionId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			['ID', 'IBLOCK_ID', 'NAME', 'DESCRIPTION']
		)->Fetch();
		if (!$section)
		{
			return null;
		}

		$iblockId = (int)$section['IBLOCK_ID'];
		$iblock = \CIBlock::GetArrayByID($iblockId);
		if (!$iblock)
		{
			return null;
		}

		$fields = [
			'description' => trim(strip_tags((string)($section['DESCRIPTION'] ?? ''))),
		];
		// UF-поля раздела.
		foreach ($this->userFieldValues('IBLOCK_' . $iblockId . '_SECTION', $sectionId) as $code => $value)
		{
			$fields[$code] = $value;
		}
		$fields = array_filter($fields, static fn ($v) => trim((string)$v) !== '');

		// Ссылка на раздел по шаблону SECTION_PAGE_URL инфоблока.
		$url = (string)($iblock['SECTION_PAGE_URL'] ?? '');
		$url = str_replace(
			['#SITE_DIR#', '#SECTION_ID#', '#ID#'],
			[$this->iblockSiteDir((string)($iblock['LID'] ?? SITE_ID)), $sectionId, $sectionId],
			$url
		);

		return [
			'entity_type' => IndexManager::TYPE_IBLOCK_SECTION,
			'crm_type_id' => 0,
			'entity_id' => $sectionId,
			'category_id' => 0,
			'iblock_id' => $iblockId,
			'title' => (string)$section['NAME'],
			'subtitle' => (string)$iblock['NAME'],
			'fields' => $fields,
			'phones' => [],
			'emails' => [],
			'url' => $url,
			'attrs' => [
				'iblockId' => $iblockId,
				'iblockTitle' => (string)$iblock['NAME'],
			],
			'date_update' => date('c'),
		];
	}

	private function iblockSiteDir(string $siteId): string
	{
		$dir = '/';
		try
		{
			$site = \Bitrix\Main\SiteTable::getList([
				'select' => ['DIR'],
				'filter' => ['=LID' => $siteId],
				'limit' => 1,
			])->fetch();
			if ($site && (string)($site['DIR'] ?? '') !== '')
			{
				$dir = (string)$site['DIR'];
			}
		}
		catch (\Throwable)
		{
		}

		return $dir;
	}

	/**
	 * Значения свойств элемента инфоблока: prop_<код> => строка.
	 *
	 * @return array<string, string>
	 */
	private function iblockPropertyValues(int $iblockId, int $elementId): array
	{
		$result = [];
		try
		{
			$rows = \CIBlockElement::GetProperty($iblockId, $elementId, [], []);
			$collected = [];
			while ($row = $rows->GetNext())
			{
				$code = (string)($row['CODE'] ?: $row['ID']);
				// Для списков человекочитаемое значение в DISPLAY_VALUE,
				// для прочих VALUE уже текст (привязки дают ID — ограничение v1).
				$value = (string)($row['DISPLAY_VALUE'] ?: $row['VALUE']);
				if (trim($value) === '' || $value === '[]')
				{
					continue;
				}
				$collected['prop_' . $code][] = $value;
			}
			foreach ($collected as $code => $values)
			{
				$result[$code] = implode(', ', array_values(array_unique($values)));
			}
		}
		catch (\Throwable)
		{
		}

		return $result;
	}
}
