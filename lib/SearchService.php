<?php

namespace Mtai\OpenSearch;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Search\ResultItem;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

/**
 * Поиск по OpenSearch и приведение результатов к контракту
 * crm.api.entity.search (items[] из Bitrix\Main\Search\ResultItem).
 *
 * CRM-сущности форматируются штатными адаптерами CRM
 * (Crm\Search\Result\Factory::createResultAdapter) — ссылки, подзаголовки
 * и атрибуты (телефоны/email) получаются идентичными штатному поиску.
 * Пользователи и элементы инфоблоков форматируются модулем.
 */
final class SearchService
{
	private IndexManager $indexManager;

	public function __construct(?IndexManager $indexManager = null)
	{
		$this->indexManager = $indexManager ?? new IndexManager();
	}

	/**
	 * @param array{
	 *     user_id?: int,
	 *     crm_type_ids?: int[]|null,
	 *     users?: bool|null,
	 *     iblock_ids?: int[]|null,
	 *     category_id?: int|null
	 * } $context
	 *
	 * @return ResultItem[]
	 */
	public function search(string $query, array $context = []): array
	{
		$query = trim($query);
		if ($query === '')
		{
			return [];
		}

		$userId = (int)($context['user_id'] ?? 0);
		$typeIds = $context['crm_type_ids'] ?? Config::allowedCrmEntityTypeIds();
		$withUsers = $context['users'] ?? Config::searchUsersEnabled();
		$iblockIds = $context['iblock_ids'] ?? Config::iblockIds();
		$categoryId = isset($context['category_id']) ? (int)$context['category_id'] : null;

		$entityTypes = [];
		foreach ((array)$typeIds as $typeId)
		{
			$entityTypes[] = DocumentBuilder::crmEntityTypeCode((int)$typeId);
		}
		if ($withUsers)
		{
			$entityTypes[] = IndexManager::TYPE_USER;
		}
		if ((array)$iblockIds !== [])
		{
			$entityTypes[] = IndexManager::TYPE_IBLOCK;
			if (Config::anyIblockSectionsEnabled())
			{
				$entityTypes[] = IndexManager::TYPE_IBLOCK_SECTION;
			}
		}
		$entityTypes = array_values(array_unique($entityTypes));
		if ($entityTypes === [])
		{
			return [];
		}

		$body = $this->buildQuery($query, $entityTypes, (array)$iblockIds, $categoryId);
		$response = $this->indexManager->client()->search(IndexManager::indexName(), $body);

		$hits = (array)($response['hits']['hits'] ?? []);
		if ($hits === [])
		{
			return [];
		}

		// Группировка кандидатов по виду сущности в порядке релевантности.
		$candidates = [];
		foreach ($hits as $hit)
		{
			$source = (array)($hit['_source'] ?? []);
			$type = (string)($source['entity_type'] ?? '');
			if ($type === '')
			{
				continue;
			}
			$candidates[$type][] = $source;
		}

		$perTypeLimit = Config::perTypeLimit();
		$totalLimit = Config::searchLimit();
		$items = [];

		foreach ($candidates as $type => $sources)
		{
			$free = $totalLimit - count($items);
			if ($free <= 0)
			{
				break;
			}
			$sources = array_slice($sources, 0, min($perTypeLimit, $free));

			if ($type === IndexManager::TYPE_USER)
			{
				foreach ($sources as $source)
				{
					$items[] = $this->formatUserItem($source);
				}
				continue;
			}

			if ($type === IndexManager::TYPE_IBLOCK)
			{
				foreach ($sources as $source)
				{
					$item = $this->formatIblockItem($source, $userId, 'element_read');
					if ($item !== null)
					{
						$items[] = $item;
					}
				}
				continue;
			}

			if ($type === IndexManager::TYPE_IBLOCK_SECTION)
			{
				foreach ($sources as $source)
				{
					$item = $this->formatIblockItem($source, $userId, 'section_read');
					if ($item !== null)
					{
						$items[] = $item;
					}
				}
				continue;
			}

			foreach ($this->formatCrmItems($type, $sources, $userId) as $item)
			{
				$items[] = $item;
			}
		}

		return array_slice($items, 0, $totalLimit);
	}

	/**
	 * Тело поискового запроса: для каждого скоупа (лиды, смарт-процесс #N,
	 * инфоблок #M...) строится своя ветка bool со своим фильтром типов
	 * и ONLY выбранными полями этого скоупа — выбор полей одной сущности
	 * не открывает поиск по полям другой.
	 */
	private function buildQuery(string $query, array $entityTypes, array $iblockIds, ?int $categoryId): array
	{
		$filter = [
			['terms' => ['entity_type' => $entityTypes]],
		];
		if ($iblockIds !== [])
		{
			// Для инфоблоков — только выбранные в настройках.
			$filter[] = [
				'bool' => [
					'should' => [
						['bool' => ['must_not' => [['terms' => ['entity_type' => [IndexManager::TYPE_IBLOCK, IndexManager::TYPE_IBLOCK_SECTION]]]]]],
						['terms' => ['iblock_id' => $iblockIds]],
					],
					'minimum_should_match' => 1,
				],
			];
		}
		if ($categoryId !== null)
		{
			$filter[] = ['term' => ['category_id' => $categoryId]];
		}

		$scopeShoulds = [];
		foreach ($this->scopeSpecs() as $spec)
		{
			$scopeQuery = $this->buildScopeQuery($query, $spec['types'], $spec['fields']);
			if ($scopeQuery !== null)
			{
				$scopeShoulds[] = $scopeQuery;
			}
		}

		$bool = [
			'filter' => $filter,
		];
		if ($scopeShoulds !== [])
		{
			$bool['should'] = $scopeShoulds;
			$bool['minimum_should_match'] = 1;
		}
		else
		{
			// Все поля поиска сняты — не возвращаем всю выдачу.
			$bool['filter'][] = ['match_none' => new \stdClass()];
		}

		return [
			'size' => Config::searchLimit() * Config::candidateFactor(),
			'query' => ['bool' => $bool],
			// Свежие изменения важнее при прочих равных.
			'sort' => ['_score' => 'desc', 'date_update' => 'desc'],
		];
	}

	/**
	 * Спецификации скоупов текущего поиска: entity-коды индекса + поля.
	 *
	 * @return array<int, array{types: string[], fields: string[]}>
	 */
	private function scopeSpecs(): array
	{
		$specs = [];

		foreach (FieldRegistry::CRM_SCOPES as $scope)
		{
			if (Config::crmScopeEnabled($scope))
			{
				$typeIds = Config::allowedCrmEntityTypeIds();
				$map = [
					'crm_lead' => \CCrmOwnerType::Lead,
					'crm_deal' => \CCrmOwnerType::Deal,
					'crm_contact' => \CCrmOwnerType::Contact,
					'crm_company' => \CCrmOwnerType::Company,
				];
				$typeId = $map[$scope];
				if (in_array($typeId, $typeIds, true))
				{
					$specs[] = [
						'types' => [DocumentBuilder::crmEntityTypeCode($typeId)],
						'fields' => Config::crmScopeFields($scope),
					];
				}
			}
		}

		foreach (Config::smartScopes() as $typeId => $fields)
		{
			$specs[] = [
				'types' => [DocumentBuilder::crmEntityTypeCode((int)$typeId)],
				'fields' => $fields,
			];
		}

		if (Config::searchUsersEnabled())
		{
			$specs[] = [
				'types' => [IndexManager::TYPE_USER],
				'fields' => Config::userScopeFields(),
			];
		}

		foreach (Config::iblockScopes() as $iblockId => $config)
		{
			$specs[] = [
				'types' => [IndexManager::TYPE_IBLOCK],
				'fields' => array_values(array_merge($config['fields'], $config['properties'])),
			];
			if ($config['sections']['enabled'])
			{
				$specs[] = [
					'types' => [IndexManager::TYPE_IBLOCK_SECTION],
					'fields' => $config['sections']['fields'],
				];
			}
		}

		return $specs;
	}

	/**
	 * Ветка запроса одного скоупа: фильтр по его типам + поиск по его
	 * выбранным полям (текст/телефон/email). null — полей нет.
	 *
	 * @param string[] $scopeTypes
	 * @param string[] $scopeFields
	 */
	private function buildScopeQuery(string $query, array $scopeTypes, array $scopeFields): ?array
	{
		$textFields = [];
		$withPhones = false;
		$withEmails = false;

		foreach ($scopeFields as $code)
		{
			switch ($code)
			{
				case 'title':
					$textFields[] = 'title^5';
					$textFields[] = 'title.en^5';
					break;
				case 'content':
					$textFields[] = 'fields.content^1.2';
					$textFields[] = 'fields.content.en^1.2';
					break;
				case 'phone':
					$withPhones = true;
					break;
				case 'email':
					$withEmails = true;
					break;
				default:
					$textFields[] = "fields.{$code}^2.5";
					$textFields[] = "fields.{$code}.en^2.5";
					break;
			}
		}
		$textFields = array_values(array_unique($textFields));

		$should = [];
		if ($textFields !== [])
		{
			$should[] = [
				'multi_match' => [
					'query' => $query,
					'type' => 'best_fields',
					'fields' => $textFields,
					// До 3 слов — должны совпасть все, дальше — 75%:
					// иначе запрос из заголовка новости матчит всё
					// с «отдел»/«корпоративн» по одному совпадению.
					'minimum_should_match' => '3<75%',
				],
			];
			$should[] = [
				'multi_match' => [
					'query' => $query,
					'type' => 'phrase',
					'fields' => $textFields,
					'slop' => 2,
					'boost' => 2,
				],
			];
		}

		// Поиск по телефону: запрос из цифр ищем как подстроку
		// по нормализованным номерам.
		$digits = preg_replace('/\D+/', '', $query) ?? '';
		if ($withPhones && mb_strlen($digits) >= 3)
		{
			$should[] = ['wildcard' => ['phones' => ['value' => '*' . $digits . '*', 'boost' => 6]]];
		}

		// Email — точное совпадение и префикс.
		if ($withEmails && filter_var($query, FILTER_VALIDATE_EMAIL))
		{
			$lower = mb_strtolower($query);
			$should[] = ['term' => ['emails' => ['value' => $lower, 'boost' => 7]]];
			$should[] = ['wildcard' => ['emails' => ['value' => $lower . '*', 'boost' => 4]]];
		}

		if ($should === [])
		{
			return null;
		}

		return [
			'bool' => [
				'filter' => [['terms' => ['entity_type' => $scopeTypes]]],
				'should' => $should,
				'minimum_should_match' => 1,
			],
		];
	}

	/**
	 * CRM-сущности: фильтр прав + форматирование штатными адаптерами CRM.
	 *
	 * @param array[] $sources
	 * @return ResultItem[]
	 */
	private function formatCrmItems(string $type, array $sources, int $userId): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$permissions = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($userId);

		$allowedIds = [];
		foreach ($sources as $source)
		{
			$typeId = (int)($source['crm_type_id'] ?? 0);
			$entityId = (int)($source['entity_id'] ?? 0);
			if ($typeId <= 0 || $entityId <= 0)
			{
				continue;
			}
			try
			{
				if ($permissions->item()->canRead($typeId, $entityId))
				{
					$allowedIds[] = $entityId;
				}
			}
			catch (\Throwable)
			{
				// при сбое проверки прав элемент не показываем
			}
		}

		if ($allowedIds === [])
		{
			return [];
		}

		// Внутри одного вида сущности могут быть разные CCrmOwnerType
		// (динамика) — группируем и строим адаптер для каждого типа.
		$idsByTypeId = [];
		foreach ($sources as $source)
		{
			$typeId = (int)($source['crm_type_id'] ?? 0);
			$entityId = (int)($source['entity_id'] ?? 0);
			if (in_array($entityId, $allowedIds, true))
			{
				$idsByTypeId[$typeId][] = $entityId;
			}
		}

		$items = [];
		foreach ($idsByTypeId as $typeId => $ids)
		{
			try
			{
				$searchResult = new \Bitrix\Crm\Search\Result();
				$searchResult->addIds(array_values(array_unique($ids)));

				$adapter = \Bitrix\Crm\Search\Result\Factory::createResultAdapter($typeId);
				if ($adapter === null)
				{
					continue;
				}

				foreach ($adapter->adapt($searchResult) as $item)
				{
					$items[] = $item;
				}
			}
			catch (\Throwable)
			{
			}
		}

		return $items;
	}

	private function formatUserItem(array $source): ResultItem
	{
		$userId = (int)($source['entity_id'] ?? 0);
		$attrs = (array)($source['attrs'] ?? []);

		$item = new ResultItem(
			(string)($source['title'] ?? ''),
			new Uri((string)($source['url'] ?? '/'))
		);
		$item->setId($userId);
		$item->setType('USER');
		$item->setModule('intranet');
		$item->setSubTitle((string)($source['subtitle'] ?? ''));
		if ((string)($attrs['email'] ?? '') !== '')
		{
			$item->setAttribute('email', $attrs['email']);
		}
		if ((string)($attrs['position'] ?? '') !== '')
		{
			$item->setAttribute('position', $attrs['position']);
		}
		if ((string)($attrs['photo'] ?? '') !== '')
		{
			$item->setAttribute('photo', $attrs['photo']);
		}

		return $item;
	}

	private function formatIblockItem(array $source, int $userId, string $permission): ?ResultItem
	{
		$elementId = (int)($source['entity_id'] ?? 0);
		$iblockId = (int)($source['iblock_id'] ?? 0);
		$isSection = ($source['entity_type'] ?? '') === IndexManager::TYPE_IBLOCK_SECTION;
		if ($elementId <= 0 || $iblockId <= 0)
		{
			return null;
		}
		// У части инфоблоков (например, CRM-каталог) DETAIL_PAGE_URL пуст —
		// элемент всё равно показываем, ссылка-заглушка.
		$url = (string)($source['url'] ?? '');
		if ($url === '' || mb_strpos($url, '#') !== false)
		{
			$url = '#';
		}

		// Право чтения (элемент/раздел) проверяем по правам инфоблока.
		if (Loader::includeModule('iblock'))
		{
			global $USER;
			$isAdmin = isset($USER) && $USER instanceof \CUser && $USER->IsAdmin();
			if (!$isAdmin && !\CIBlockRights::UserHasRightTo($iblockId, $elementId, $permission))
			{
				return null;
			}
		}

		$attrs = (array)($source['attrs'] ?? []);

		$item = new ResultItem((string)($source['title'] ?? ''), new Uri($url));
		$item->setId($elementId);
		$item->setType($isSection ? 'IBLOCK_SECTION' : 'IBLOCK_ELEMENT');
		$item->setModule('iblock');
		$item->setSubTitle(($isSection ? Loc::getMessage('MTAI_OPENSEARCH_SECTION_SUBTITLE') . ': ' : '')
			. (string)($source['subtitle'] ?? ''));
		if ((string)($attrs['iblockId'] ?? '') !== '')
		{
			$item->setAttribute('iblockId', (int)$attrs['iblockId']);
		}
		if ((string)($attrs['iblockTitle'] ?? '') !== '')
		{
			$item->setAttribute('iblockTitle', $attrs['iblockTitle']);
		}

		return $item;
	}
}
