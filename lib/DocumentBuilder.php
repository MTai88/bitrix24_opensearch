<?php

namespace Mtai\OpenSearch;

use Bitrix\Main\Loader;

/**
 * Строит документы для индекса OpenSearch из сущностей Bitrix24.
 *
 * Для CRM-сущностей тело документа (body) берётся из штатных билдеров
 * поискового контента (Crm\Search\SearchContentBuilderFactory) — туда
 * попадают заголовки, телефоны, email, статусы и связанные данные,
 * т.е. индексируется всё то же, что ищет штатная CRM.
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

		$categoryId = 0;
		if ($entityTypeId === \CCrmOwnerType::Deal)
		{
			$row = \CCrmDeal::GetListEx(
				[],
				['=ID' => $entityId, 'CHECK_PERMISSIONS' => 'N'],
				false,
				['nTopCount' => 1],
				['ID', 'CATEGORY_ID']
			)->Fetch();
			$categoryId = (int)($row['CATEGORY_ID'] ?? 0);
		}

		return [
			'entity_type' => $this->crmEntityTypeName($entityTypeId),
			'crm_type_id' => $entityTypeId,
			'entity_id' => $entityId,
			'category_id' => $categoryId,
			'iblock_id' => 0,
			'title' => $title,
			'subtitle' => '',
			'body' => $body,
			'phones' => [],
			'emails' => [],
			'url' => '',
			'attrs' => [],
			'date_update' => date('c'),
		];
	}

	/**
	 * Отображаемый заголовок CRM-сущности (как в поисковой выдаче CRM).
	 */
	private function crmTitle(int $entityTypeId, int $entityId): ?string
	{
		switch ($entityTypeId)
		{
			case \CCrmOwnerType::Lead:
				$row = \CCrmLead::GetListEx(
					[],
					['=ID' => $entityId, 'CHECK_PERMISSIONS' => 'N'],
					false,
					['nTopCount' => 1],
					['ID', 'TITLE', 'HONORIFIC', 'NAME', 'SECOND_NAME', 'LAST_NAME']
				)->Fetch();
				if (!$row)
				{
					return null;
				}

				return (string)($row['TITLE'] ?: trim(
					($row['NAME'] ?? '') . ' ' . ($row['SECOND_NAME'] ?? '') . ' ' . ($row['LAST_NAME'] ?? '')
				));

			case \CCrmOwnerType::Deal:
			case \CCrmOwnerType::Quote:
				$row = $this->fetchCrmRow($entityTypeId, $entityId, ['ID', 'TITLE']);

				return $row ? (string)$row['TITLE'] : null;

			case \CCrmOwnerType::Contact:
				$row = \CCrmContact::GetListEx(
					[],
					['=ID' => $entityId, 'CHECK_PERMISSIONS' => 'N'],
					false,
					['nTopCount' => 1],
					['ID', 'HONORIFIC', 'NAME', 'SECOND_NAME', 'LAST_NAME']
				)->Fetch();
				if (!$row)
				{
					return null;
				}

				return (string)\CCrmContact::PrepareFormattedName($row);

			case \CCrmOwnerType::Company:
				$row = \CCrmCompany::GetListEx(
					[],
					['=ID' => $entityId, 'CHECK_PERMISSIONS' => 'N'],
					false,
					['nTopCount' => 1],
					['ID', 'TITLE']
				)->Fetch();

				return $row ? (string)$row['TITLE'] : null;

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

					return $item ? (string)($item->getTitle() ?? '') : null;
				}

				return null;
		}
	}

	private function fetchCrmRow(int $entityTypeId, int $entityId, array $select): ?array
	{
		$map = [
			\CCrmOwnerType::Deal => '\CCrmDeal',
			\CCrmOwnerType::Quote => '\CCrmQuote',
		];
		$class = $map[$entityTypeId] ?? null;
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
				'WORK_POSITION', 'ACTIVE', 'PERSONAL_PHOTO', 'UF_DEPARTMENT',
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
		if ($departments !== [] && Loader::includeModule('intranet'))
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

		$bodyParts = array_filter(array_merge(
			[$title, (string)($row['EMAIL'] ?? ''), $position],
			$departmentNames
		), static fn ($p) => (string)$p !== '');

		return [
			'entity_type' => IndexManager::TYPE_USER,
			'crm_type_id' => 0,
			'entity_id' => (int)$userId,
			'category_id' => 0,
			'iblock_id' => 0,
			'title' => $title,
			'subtitle' => implode(', ', array_filter([$position, implode(', ', $departmentNames)])),
			'body' => implode(' | ', $bodyParts),
			'phones' => [],
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
		if ($fileId <= 0 || !function_exists('CFile::ResizeImageGet'))
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
				'ID', 'IBLOCK_ID', 'NAME', 'TAGS',
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

		$siteDir = '/';
		$site = \Bitrix\Main\SiteTable::getList([
			'select' => ['DIR'],
			'filter' => ['=LID' => $iblock['LID'] ?? SITE_ID],
			'limit' => 1,
		])->fetch();
		if ($site && (string)($site['DIR'] ?? '') !== '')
		{
			$siteDir = (string)$site['DIR'];
		}

		$bodyParts = array_filter([
			(string)$row['NAME'],
			strip_tags((string)($row['PREVIEW_TEXT'] ?? '')),
			strip_tags((string)($row['DETAIL_TEXT'] ?? '')),
			(string)($row['TAGS'] ?? ''),
		], static fn ($p) => $p !== '');

		return [
			'entity_type' => IndexManager::TYPE_IBLOCK,
			'crm_type_id' => 0,
			'entity_id' => $elementId,
			'category_id' => 0,
			'iblock_id' => $iblockId,
			'title' => (string)$row['NAME'],
			'subtitle' => (string)$iblock['NAME'],
			'body' => implode(' | ', $bodyParts),
			'phones' => [],
			'emails' => [],
			'url' => str_replace('#SITE_DIR#', $siteDir, (string)$row['DETAIL_PAGE_URL']),
			'attrs' => [
				'iblockId' => $iblockId,
				'iblockTitle' => (string)$iblock['NAME'],
				'iblockType' => (string)($row['IBLOCK_TYPE_ID'] ?? ''),
			],
			'date_update' => date('c'),
		];
	}
}
