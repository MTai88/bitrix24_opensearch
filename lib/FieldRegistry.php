<?php

namespace Mtai\OpenSearch;

use Bitrix\Main\Loader;

/**
 * Реестр полей, по которым можно искать, — единый источник для страницы
 * настроек, индексатора и поискового запроса.
 *
 * Структура настроек (опция scopes, JSON):
 * {
 *   "crm_lead":     {"enabled": true, "fields": ["title", "comments", "UF_..."]},
 *   "crm_deal":     {...}, "crm_contact": {...}, "crm_company": {...},
 *   "smart":        {"<typeId>": {"fields": [...]}},
 *   "user":         {"enabled": true, "fields": [...]},
 *   "iblock":       {"<iblockId>": {"fields": [...], "properties": [...],
 *                    "sections": {"enabled": true, "fields": [...]}}}
 * }
 *
 * Псевдокоды полей (одинаковы во всех скоупах):
 *   title   — заголовок сущности (индексное поле title);
 *   content — весь штатный поисковый контент сущности (fields.content);
 *   phone   — телефоны (массив phones);
 *   email   — email (массив emails);
 *   прочие  — fields.<code> (системные поля, UF_* и свойства инфоблоков).
 */
final class FieldRegistry
{
	public const CRM_SCOPES = ['crm_lead', 'crm_deal', 'crm_contact', 'crm_company'];

	// ------------------------------------------------------------------
	// Системные поля скоупов
	// ------------------------------------------------------------------

	/**
	 * Системные поля скоупа: code => [langKey, русский запасной текст].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function systemFields(string $scope): array
	{
		$maps = [
			'crm_lead' => [
				'title' => ['MTAI_OPENSEARCH_FIELD_TITLE', 'Заголовок лида'],
				'name' => ['MTAI_OPENSEARCH_FIELD_NAME', 'ФИО контакта лида'],
				'comments' => ['MTAI_OPENSEARCH_FIELD_COMMENTS', 'Комментарий'],
				'source' => ['MTAI_OPENSEARCH_FIELD_SOURCE', 'Описание источника'],
				'phone' => ['MTAI_OPENSEARCH_FIELD_PHONE', 'Телефоны'],
				'email' => ['MTAI_OPENSEARCH_FIELD_EMAIL', 'Email'],
				'content' => ['MTAI_OPENSEARCH_FIELD_CONTENT', 'Штатный поисковый контент (статусы, ответственные и т.п.)'],
			],
			'crm_deal' => [
				'title' => ['MTAI_OPENSEARCH_FIELD_TITLE', 'Название сделки'],
				'comments' => ['MTAI_OPENSEARCH_FIELD_COMMENTS', 'Комментарий'],
				'additional_info' => ['MTAI_OPENSEARCH_FIELD_ADDITIONAL_INFO', 'Дополнительная информация'],
				'content' => ['MTAI_OPENSEARCH_FIELD_CONTENT', 'Штатный поисковый контент'],
			],
			'crm_contact' => [
				'title' => ['MTAI_OPENSEARCH_FIELD_TITLE', 'ФИО контакта'],
				'post' => ['MTAI_OPENSEARCH_FIELD_POST', 'Должность'],
				'comments' => ['MTAI_OPENSEARCH_FIELD_COMMENTS', 'Комментарий'],
				'phone' => ['MTAI_OPENSEARCH_FIELD_PHONE', 'Телефоны'],
				'email' => ['MTAI_OPENSEARCH_FIELD_EMAIL', 'Email'],
				'content' => ['MTAI_OPENSEARCH_FIELD_CONTENT', 'Штатный поисковый контент'],
			],
			'crm_company' => [
				'title' => ['MTAI_OPENSEARCH_FIELD_TITLE', 'Название компании'],
				'comments' => ['MTAI_OPENSEARCH_FIELD_COMMENTS', 'Комментарий'],
				'phone' => ['MTAI_OPENSEARCH_FIELD_PHONE', 'Телефоны'],
				'email' => ['MTAI_OPENSEARCH_FIELD_EMAIL', 'Email'],
				'content' => ['MTAI_OPENSEARCH_FIELD_CONTENT', 'Штатный поисковый контент'],
			],
			'user' => [
				'title' => ['MTAI_OPENSEARCH_FIELD_USER_TITLE', 'ФИО сотрудника'],
				'email' => ['MTAI_OPENSEARCH_FIELD_EMAIL', 'Email'],
				'position' => ['MTAI_OPENSEARCH_FIELD_POST', 'Должность'],
				'department' => ['MTAI_OPENSEARCH_FIELD_DEPARTMENT', 'Отдел'],
				'phone' => ['MTAI_OPENSEARCH_FIELD_PHONE', 'Телефоны'],
			],
			'smart' => [
				'title' => ['MTAI_OPENSEARCH_FIELD_TITLE', 'Заголовок элемента'],
				'content' => ['MTAI_OPENSEARCH_FIELD_CONTENT', 'Штатный поисковый контент'],
			],
			'iblock_element' => [
				'title' => ['MTAI_OPENSEARCH_FIELD_IBLOCK_TITLE', 'Название элемента'],
				'preview' => ['MTAI_OPENSEARCH_FIELD_PREVIEW', 'Анонс (превью-текст)'],
				'detail' => ['MTAI_OPENSEARCH_FIELD_DETAIL', 'Подробное описание'],
				'tags' => ['MTAI_OPENSEARCH_FIELD_TAGS', 'Теги'],
			],
			'iblock_section' => [
				'title' => ['MTAI_OPENSEARCH_FIELD_SECTION_TITLE', 'Название раздела'],
				'description' => ['MTAI_OPENSEARCH_FIELD_SECTION_DESCRIPTION', 'Описание раздела'],
			],
		];

		return $maps[$scope] ?? [];
	}

	// ------------------------------------------------------------------
	// Пользовательские поля (UF_)
	// ------------------------------------------------------------------

	/**
	 * UF-поля сущности CRM: код => название.
	 *
	 * @return array<string, string>
	 */
	public static function crmUserFields(string $scope): array
	{
		$map = [
			'crm_lead' => 'CRM_LEAD',
			'crm_deal' => 'CRM_DEAL',
			'crm_contact' => 'CRM_CONTACT',
			'crm_company' => 'CRM_COMPANY',
		];
		$entity = $map[$scope] ?? null;

		return $entity !== null ? self::userFields($entity) : [];
	}

	/**
	 * UF-поля смарт-процесса: код => название.
	 *
	 * @return array<string, string>
	 */
	public static function smartUserFields(int $typeId): array
	{
		return self::userFields('CRM_' . $typeId);
	}

	/**
	 * UF-поля раздела инфоблока: код => название.
	 *
	 * @return array<string, string>
	 */
	public static function sectionUserFields(int $iblockId): array
	{
		return self::userFields('IBLOCK_' . $iblockId . '_SECTION');
	}

	/**
	 * @return array<string, string>
	 */
	private static function userFields(string $entityId): array
	{
		$result = [];
		$rows = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId]);
		while ($row = $rows->Fetch())
		{
			$code = (string)$row['FIELD_NAME'];
			$title = trim((string)$row['EDIT_FORM_LABEL'] ?: (string)$row['FIELD_NAME']);
			$result[$code] = $title !== '' ? $title : $code;
		}

		return $result;
	}

	// ------------------------------------------------------------------
	// Смарт-процессы
	// ------------------------------------------------------------------

	/**
	 * Доступные смарт-процессы (включая смарт-счета, если они есть):
	 * typeId => название.
	 *
	 * @return array<int, string>
	 */
	public static function smartTypes(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$types = [];
		$typesMap = \Bitrix\Crm\Service\Container::getInstance()
			->getDynamicTypesMap()
			->load(['isLoadStages' => false, 'isLoadCategories' => false]);
		foreach ($typesMap->getTypes() as $type)
		{
			$types[(int)$type->getEntityTypeId()] = (string)$type->getTitle();
		}

		// Смарт-счета живут на фабричном подходе, но не в карте типов.
		if (!isset($types[\CCrmOwnerType::SmartInvoice])
			&& \CCrmOwnerType::isUseFactoryBasedApproach(\CCrmOwnerType::SmartInvoice)
		)
		{
			$title = (string)\Bitrix\Crm\Service\Container::getInstance()
				->getTypeByEntityTypeId(\CCrmOwnerType::SmartInvoice)?->getTitle();
			$types[\CCrmOwnerType::SmartInvoice] = $title !== '' ? $title : 'Смарт-счета';
		}

		asort($types, SORT_NATURAL | SORT_FLAG_CASE);

		return $types;
	}

	// ------------------------------------------------------------------
	// Инфоблоки
	// ------------------------------------------------------------------

	/**
	 * Свойства элементов инфоблока: код подполя (prop_<CODE>) => название.
	 *
	 * @return array<string, string>
	 */
	public static function iblockProperties(int $iblockId): array
	{
		$result = [];
		if (!Loader::includeModule('iblock'))
		{
			return $result;
		}

		$rows = \CIBlockProperty::GetList(['SORT' => 'ASC', 'NAME' => 'ASC'], ['IBLOCK_ID' => $iblockId]);
		while ($row = $rows->Fetch())
		{
			$code = (string)($row['CODE'] ?: $row['ID']);
			$result['prop_' . $code] = '[свойство] ' . (string)$row['NAME'];
		}

		return $result;
	}
}
