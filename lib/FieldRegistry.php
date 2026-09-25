<?php

namespace Mtai\OpenSearch;

/**
 * Реестр полей, по которым можно искать, — единый источник для страницы
 * настроек, индексатора и поискового запроса.
 *
 * Псевдокоды полей (одинаковы для всех скоупов):
 *   title   — заголовок сущности (индексное поле title);
 *   content — весь штатный поисковый контент сущности (поле body);
 *   phone   — телефоны (массив phones, нормализованные цифры);
 *   email   — email (массив emails, нижний регистр);
 *   прочие  — отдельные поля сущности (объект fields.<code>).
 *
 * Скоупы: crm_lead, crm_deal, crm_contact, crm_company, crm_quote,
 * crm_dynamic (смарт-счета и смарт-процессы), user, iblock.
 */
final class FieldRegistry
{
	public const SCOPE_CRM_LEAD = 'crm_lead';
	public const SCOPE_CRM_DEAL = 'crm_deal';
	public const SCOPE_CRM_CONTACT = 'crm_contact';
	public const SCOPE_CRM_COMPANY = 'crm_company';
	public const SCOPE_CRM_QUOTE = 'crm_quote';
	public const SCOPE_CRM_DYNAMIC = 'crm_dynamic';
	public const SCOPE_USER = 'user';
	public const SCOPE_IBLOCK = 'iblock';

	/**
	 * Поля скоупа: code => [langKey, русский запасной текст].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function fieldsForScope(string $scope): array
	{
		$maps = [
			self::SCOPE_CRM_LEAD => [
				'title' => ['MTAI_OPENSEARCH_FIELD_TITLE', 'Заголовок лида'],
				'name' => ['MTAI_OPENSEARCH_FIELD_NAME', 'ФИО контакта лида'],
				'comments' => ['MTAI_OPENSEARCH_FIELD_COMMENTS', 'Комментарий'],
				'source' => ['MTAI_OPENSEARCH_FIELD_SOURCE', 'Описание источника'],
				'phone' => ['MTAI_OPENSEARCH_FIELD_PHONE', 'Телефоны'],
				'email' => ['MTAI_OPENSEARCH_FIELD_EMAIL', 'Email'],
				'content' => ['MTAI_OPENSEARCH_FIELD_CONTENT', 'Штатный поисковый контент (статусы, ответственные и т.п.)'],
			],
			self::SCOPE_CRM_DEAL => [
				'title' => ['MTAI_OPENSEARCH_FIELD_TITLE', 'Название сделки'],
				'comments' => ['MTAI_OPENSEARCH_FIELD_COMMENTS', 'Комментарий'],
				'additional_info' => ['MTAI_OPENSEARCH_FIELD_ADDITIONAL_INFO', 'Дополнительная информация'],
				'content' => ['MTAI_OPENSEARCH_FIELD_CONTENT', 'Штатный поисковый контент'],
			],
			self::SCOPE_CRM_CONTACT => [
				'title' => ['MTAI_OPENSEARCH_FIELD_TITLE', 'ФИО контакта'],
				'post' => ['MTAI_OPENSEARCH_FIELD_POST', 'Должность'],
				'comments' => ['MTAI_OPENSEARCH_FIELD_COMMENTS', 'Комментарий'],
				'phone' => ['MTAI_OPENSEARCH_FIELD_PHONE', 'Телефоны'],
				'email' => ['MTAI_OPENSEARCH_FIELD_EMAIL', 'Email'],
				'content' => ['MTAI_OPENSEARCH_FIELD_CONTENT', 'Штатный поисковый контент'],
			],
			self::SCOPE_CRM_COMPANY => [
				'title' => ['MTAI_OPENSEARCH_FIELD_TITLE', 'Название компании'],
				'comments' => ['MTAI_OPENSEARCH_FIELD_COMMENTS', 'Комментарий'],
				'phone' => ['MTAI_OPENSEARCH_FIELD_PHONE', 'Телефоны'],
				'email' => ['MTAI_OPENSEARCH_FIELD_EMAIL', 'Email'],
				'content' => ['MTAI_OPENSEARCH_FIELD_CONTENT', 'Штатный поисковый контент'],
			],
			self::SCOPE_CRM_QUOTE => [
				'title' => ['MTAI_OPENSEARCH_FIELD_TITLE', 'Название квоты'],
				'content' => ['MTAI_OPENSEARCH_FIELD_CONTENT', 'Штатный поисковый контент'],
			],
			self::SCOPE_CRM_DYNAMIC => [
				'title' => ['MTAI_OPENSEARCH_FIELD_TITLE', 'Заголовок элемента'],
				'content' => ['MTAI_OPENSEARCH_FIELD_CONTENT', 'Штатный поисковый контент'],
			],
			self::SCOPE_USER => [
				'title' => ['MTAI_OPENSEARCH_FIELD_USER_TITLE', 'ФИО сотрудника'],
				'email' => ['MTAI_OPENSEARCH_FIELD_EMAIL', 'Email'],
				'position' => ['MTAI_OPENSEARCH_FIELD_POST', 'Должность'],
				'department' => ['MTAI_OPENSEARCH_FIELD_DEPARTMENT', 'Отдел'],
				'phone' => ['MTAI_OPENSEARCH_FIELD_PHONE', 'Телефоны'],
			],
			self::SCOPE_IBLOCK => [
				'title' => ['MTAI_OPENSEARCH_FIELD_IBLOCK_TITLE', 'Название элемента'],
				'preview' => ['MTAI_OPENSEARCH_FIELD_PREVIEW', 'Анонс (превью-текст)'],
				'detail' => ['MTAI_OPENSEARCH_FIELD_DETAIL', 'Подробное описание'],
				'tags' => ['MTAI_OPENSEARCH_FIELD_TAGS', 'Теги'],
			],
		];

		return $maps[$scope] ?? [];
	}

	/**
	 * Скоуп для типа CRM-сущности.
	 */
	public static function scopeForCrmType(int $entityTypeId): string
	{
		$map = [
			\CCrmOwnerType::Lead => self::SCOPE_CRM_LEAD,
			\CCrmOwnerType::Deal => self::SCOPE_CRM_DEAL,
			\CCrmOwnerType::Contact => self::SCOPE_CRM_CONTACT,
			\CCrmOwnerType::Company => self::SCOPE_CRM_COMPANY,
			\CCrmOwnerType::Quote => self::SCOPE_CRM_QUOTE,
		];

		return $map[$entityTypeId] ?? self::SCOPE_CRM_DYNAMIC;
	}

	/**
	 * Все скоупы, участвующие в поиске при текущих настройках.
	 *
	 * @return string[]
	 */
	public static function activeScopes(): array
	{
		$scopes = [];
		foreach (Config::allowedCrmEntityTypeIds() as $typeId)
		{
			$scopes[] = self::scopeForCrmType($typeId);
		}
		if (Config::searchUsersEnabled())
		{
			$scopes[] = self::SCOPE_USER;
		}
		if (Config::iblockIds() !== [])
		{
			$scopes[] = self::SCOPE_IBLOCK;
		}

		return array_values(array_unique($scopes));
	}

	/**
	 * Ключ опции, в которой хранится CSV выбранных полей скоупа.
	 */
	public static function optionKey(string $scope): string
	{
		return 'fields_' . $scope;
	}

	/**
	 * Все известные скоупы (включая выключенные сущности — их наборы
	 * полей тоже сохраняются, чтобы выбор не терялся).
	 *
	 * @return string[]
	 */
	public static function allScopes(): array
	{
		return [
			self::SCOPE_CRM_LEAD,
			self::SCOPE_CRM_DEAL,
			self::SCOPE_CRM_CONTACT,
			self::SCOPE_CRM_COMPANY,
			self::SCOPE_CRM_QUOTE,
			self::SCOPE_CRM_DYNAMIC,
			self::SCOPE_USER,
			self::SCOPE_IBLOCK,
		];
	}
}
