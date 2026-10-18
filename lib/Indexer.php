<?php

namespace Mtai\OpenSearch;

use Bitrix\Main\Loader;

/**
 * Индексация сущностей в OpenSearch.
 *
 * Три режима:
 *  - точечный (по событиям изменения сущностей);
 *  - батчевый (полный переиндекс по шагам с прогрессом);
 *  - инкрементальный (агент: всё изменённое после метки времени).
 */
final class Indexer
{
	private IndexManager $indexManager;
	private DocumentBuilder $builder;

	public function __construct(?IndexManager $indexManager = null, ?DocumentBuilder $builder = null)
	{
		$this->indexManager = $indexManager ?? new IndexManager();
		$this->builder = $builder ?? new DocumentBuilder();
	}

	private function indexDocument(array $document): bool
	{
		$id = IndexManager::documentId(
			(string)$document['entity_type'],
			(int)$document['entity_id']
		);

		$this->indexManager->client()->indexDocument(
			IndexManager::indexName(),
			$id,
			$document
		);
		$this->notifyIndexed($id, $document);

		return true;
	}

	/**
	 * Событие для внешних модулей (mtai.aisearch строит по нему векторы).
	 * Ошибка обработчика не должна откатывать уже записанный документ.
	 */
	private function notifyIndexed(string $id, array $document): void
	{
		$this->notify('onAfterIndexDocument', $id, $document);
	}

	private function notifyDeleted(string $id): void
	{
		$this->notify('onAfterDeleteDocument', $id, null);
	}

	private function notify(string $eventName, string $id, ?array $document): void
	{
		try
		{
			$event = new \Bitrix\Main\Event(Config::MODULE_ID, $eventName, [
				'id' => $id,
				'document' => $document,
			]);
			$event->send();
		}
		catch (\Throwable $e)
		{
			AddMessage2Log(
				'mtai.opensearch ' . $eventName . ': ' . $e->getMessage(),
				Config::MODULE_ID
			);
		}
	}

	/**
	 * @param array[] $bulk
	 * @param array<int, array{0: string, 1: array}> $indexed
	 */
	private function pushBulk(array &$bulk, array &$indexed, string $index, array $document): void
	{
		$docId = IndexManager::documentId(
			(string)$document['entity_type'],
			(int)$document['entity_id']
		);
		$bulk[] = ['index' => ['_index' => $index, '_id' => $docId]];
		$bulk[] = $document;
		$indexed[] = [$docId, $document];
	}

	/**
	 * @param array[] $bulk
	 * @param array<int, array{0: string, 1: array}> $indexed
	 */
	private function flushBulk(\Mtai\OpenSearch\OpenSearchClient $client, array &$bulk, array &$indexed): void
	{
		if ($bulk === [])
		{
			return;
		}

		$client->bulk($bulk);
		foreach ($indexed as [$docId, $document])
		{
			$this->notifyIndexed($docId, $document);
		}
		$bulk = [];
		$indexed = [];
	}

	// ------------------------------------------------------------------
	// Точечная индексация (события)
	// ------------------------------------------------------------------

	public function indexCrmEntity(int $entityTypeId, int $entityId): bool
	{
		if (!in_array($entityTypeId, Config::allowedCrmEntityTypeIds(), true))
		{
			return false;
		}

		$document = $this->builder->buildCrmDocument($entityTypeId, $entityId);
		if ($document === null)
		{
			// Сущность исчезла — убираем документ из индекса.
			return $this->deleteCrmEntity($entityTypeId, $entityId);
		}

		return $this->indexDocument($document);
	}

	public function deleteCrmEntity(int $entityTypeId, int $entityId): bool
	{
		$typeName = DocumentBuilder::crmEntityTypeCode($entityTypeId);

		return $this->deleteById(IndexManager::documentId($typeName, $entityId));
	}

	public function indexUser(int $userId): bool
	{
		$document = $this->builder->buildUserDocument($userId);
		if ($document === null)
		{
			return $this->deleteUser($userId);
		}

		return $this->indexDocument($document);
	}

	public function deleteUser(int $userId): bool
	{
		return $this->deleteById(IndexManager::documentId(IndexManager::TYPE_USER, $userId));
	}

	public function indexIblockElement(int $elementId): bool
	{
		$document = $this->builder->buildIblockElementDocument($elementId);
		if ($document === null)
		{
			return $this->deleteIblockElement($elementId);
		}

		if (!in_array((int)$document['iblock_id'], Config::iblockIds(), true))
		{
			// Элемент переехал в инфоблок, который не индексируется.
			return $this->deleteIblockElement($elementId);
		}

		return $this->indexDocument($document);
	}

	public function deleteIblockElement(int $elementId): bool
	{
		return $this->deleteById(IndexManager::documentId(IndexManager::TYPE_IBLOCK, $elementId));
	}

	public function indexIblockSection(int $sectionId): bool
	{
		$document = $this->builder->buildIblockSectionDocument($sectionId);
		if ($document === null)
		{
			return $this->deleteIblockSection($sectionId);
		}

		$iblockId = (int)$document['iblock_id'];
		$scopes = Config::iblockScopes();
		if (!isset($scopes[$iblockId]) || !$scopes[$iblockId]['sections']['enabled'])
		{
			// Разделы этого инфоблока не индексируются.
			return $this->deleteIblockSection($sectionId);
		}

		return $this->indexDocument($document);
	}

	public function deleteIblockSection(int $sectionId): bool
	{
		return $this->deleteById(
			IndexManager::documentId(IndexManager::TYPE_IBLOCK_SECTION, $sectionId)
		);
	}

	private function deleteById(string $id): bool
	{
		$ok = $this->indexManager->client()->deleteDocument(IndexManager::indexName(), $id);
		if ($ok)
		{
			$this->notifyDeleted($id);
		}

		return $ok;
	}

	// ------------------------------------------------------------------
	// Полный переиндекс по шагам
	// ------------------------------------------------------------------

	/**
	 * План переиндексации: последовательность шагов с числом элементов
	 * (там, где его можно дёшево посчитать).
	 *
	 * @return array<int, array{code: string, title: string, total: ?int}>
	 */
	public function reindexPlan(): array
	{
		$steps = [];

		$classics = [];
		if (Loader::includeModule('crm'))
		{
			$classics = [
				'crm_lead' => ['Лиды', '\Bitrix\Crm\LeadTable'],
				'crm_deal' => ['Сделки', '\Bitrix\Crm\DealTable'],
				'crm_contact' => ['Контакты', '\Bitrix\Crm\ContactTable'],
				'crm_company' => ['Компании', '\Bitrix\Crm\CompanyTable'],
			];
		}
		foreach ($classics as $code => [$title, $tableClass])
		{
			if (!Config::crmScopeEnabled($code))
			{
				continue;
			}
			$total = null;
			if (class_exists($tableClass))
			{
				try
				{
					$total = (int)$tableClass::getCount([]);
				}
				catch (\Throwable)
				{
				}
			}
			$steps[] = ['code' => $code, 'title' => 'CRM: ' . $title, 'total' => $total];
		}

		// Смарт-процессы и смарт-счета — только выбранные в настройках.
		$smartTitles = FieldRegistry::smartTypes();
		foreach (array_keys(Config::smartScopes()) as $typeId)
		{
			$title = $smartTitles[(int)$typeId] ?? ('#' . $typeId);
			$steps[] = [
				'code' => 'crm_smart_' . $typeId,
				'title' => 'CRM: смарт-процесс ' . $title,
				'total' => null,
			];
		}

		if (Config::searchUsersEnabled())
		{
			$total = null;
			try
			{
				$total = (int)\Bitrix\Main\UserTable::getCount(['=ACTIVE' => 'Y']);
			}
			catch (\Throwable)
			{
			}
			$steps[] = ['code' => 'user', 'title' => 'Пользователи', 'total' => $total];
		}

		if (Loader::includeModule('iblock'))
		{
			foreach (Config::iblockScopes() as $iblockId => $config)
			{
				$iblock = \CIBlock::GetArrayByID($iblockId);
				if (!$iblock)
				{
					continue;
				}
				$name = (string)($iblock['NAME'] ?? $iblockId);

				$total = null;
				try
				{
					$total = (int)\CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId], true);
				}
				catch (\Throwable)
				{
				}
				$steps[] = [
					'code' => 'iblock_' . $iblockId,
					'title' => 'Инфоблок: ' . $name,
					'total' => $total,
				];

				if ($config['sections']['enabled'])
				{
					$totalSections = null;
					try
					{
						$totalSections = (int)\CIBlockSection::GetList([], ['IBLOCK_ID' => $iblockId], true);
					}
					catch (\Throwable)
					{
					}
					$steps[] = [
						'code' => 'iblock_section_' . $iblockId,
						'title' => 'Разделы: ' . $name,
						'total' => $totalSections,
					];
				}
			}
		}

		return $steps;
	}

	/**
	 * Один шаг переиндексации.
	 *
	 * @return array{processed: int, last_id: int, done: bool}
	 */
	public function runStep(string $code, int $lastId, ?int $limit = null): array
	{
		$limit ??= Config::batchSize();
		$index = IndexManager::indexName();
		$client = $this->indexManager->client();

		$ids = $this->fetchStepIds($code, $lastId, $limit);
		if ($ids === [])
		{
			return ['processed' => 0, 'last_id' => $lastId, 'done' => true];
		}

		$bulk = [];
		$indexed = [];
		foreach ($ids as $id)
		{
			try
			{
				$document = $this->buildStepDocument($code, (int)$id);
			}
			catch (\Throwable $e)
			{
				// Элемент с нестандартной структурой не должен валить шаг —
				// пропускаем и пишем в лог.
				AddMessage2Log(
					'mtai.opensearch reindex: step ' . $code . ' item #' . $id . ' skipped: ' . $e->getMessage(),
					Config::MODULE_ID
				);
				continue;
			}
			if ($document !== null)
			{
				$this->pushBulk($bulk, $indexed, $index, $document);
			}
		}

		$this->flushBulk($client, $bulk, $indexed);

		return [
			'processed' => count($ids),
			'last_id' => max($ids),
			'done' => count($ids) < $limit,
		];
	}

	/**
	 * @return int[]
	 */
	private function fetchStepIds(string $code, int $lastId, int $limit): array
	{
		$order = ['ID' => 'ASC'];
		$filter = ['>ID' => $lastId];

		if ($code === 'user')
		{
			$filter['=ACTIVE'] = 'Y';
			$rows = \Bitrix\Main\UserTable::getList([
				'select' => ['ID'],
				'filter' => $filter,
				'order' => ['ID' => 'ASC'],
				'limit' => $limit,
			])->fetchAll();

			return array_map(static fn ($r) => (int)$r['ID'], $rows);
		}

		// Шаги инфоблоков: в ajax-контексте модуль iblock не загружен.
		if (mb_strpos($code, 'iblock_') === 0)
		{
			if (!Loader::includeModule('iblock'))
			{
				return [];
			}
		}

		if (mb_strpos($code, 'iblock_section_') === 0)
		{
			$iblockId = (int)mb_substr($code, 15);
			$ids = [];
			$result = \CIBlockSection::GetList(
				['ID' => 'ASC'],
				['>ID' => $lastId, '=IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
				false,
				['ID'],
				['nTopCount' => $limit]
			);
			while ($row = $result->Fetch())
			{
				$ids[] = (int)$row['ID'];
			}

			return $ids;
		}

		if (mb_strpos($code, 'iblock_') === 0)
		{
			$iblockId = (int)mb_substr($code, 7);
			$ids = [];
			$result = \CIBlockElement::GetList(
				['ID' => 'ASC'],
				['>ID' => $lastId, '=IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
				false,
				['nTopCount' => $limit],
				['ID']
			);
			while ($row = $result->Fetch())
			{
				$ids[] = (int)$row['ID'];
			}

			return $ids;
		}

		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		if (mb_strpos($code, 'crm_smart_') === 0)
		{
			$typeId = (int)mb_substr($code, 10);
			$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($typeId);
			if (!$factory)
			{
				return [];
			}
			$items = $factory->getItems([
				'select' => ['ID'],
				'filter' => $filter,
				'order' => $order,
				'limit' => $limit,
			]);

			return array_map(static fn ($item) => (int)$item->getId(), $items);
		}

		$map = [
			'crm_lead' => '\CCrmLead',
			'crm_deal' => '\CCrmDeal',
			'crm_contact' => '\CCrmContact',
			'crm_company' => '\CCrmCompany',
		];

		$class = $map[$code] ?? null;
		if (!$class || !class_exists($class))
		{
			return [];
		}

		$ids = [];
		$result = $class::GetListEx(
			['ID' => 'ASC'],
			$filter + ['CHECK_PERMISSIONS' => 'N'],
			false,
			['nTopCount' => $limit],
			['ID']
		);
		while ($row = $result->Fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	private function buildStepDocument(string $code, int $id): ?array
	{
		if ($code === 'user')
		{
			return $this->builder->buildUserDocument($id);
		}

		if (mb_strpos($code, 'iblock_section_') === 0)
		{
			return $this->builder->buildIblockSectionDocument($id);
		}

		if (mb_strpos($code, 'iblock_') === 0)
		{
			return $this->builder->buildIblockElementDocument($id);
		}

		if (mb_strpos($code, 'crm_smart_') === 0)
		{
			$typeId = (int)mb_substr($code, 10);

			return $this->builder->buildCrmDocument($typeId, $id);
		}

		$map = [
			'crm_lead' => \CCrmOwnerType::Lead,
			'crm_deal' => \CCrmOwnerType::Deal,
			'crm_contact' => \CCrmOwnerType::Contact,
			'crm_company' => \CCrmOwnerType::Company,
		];
		$typeId = $map[$code] ?? null;

		return $typeId !== null ? $this->builder->buildCrmDocument($typeId, $id) : null;
	}

	/**
	 * Инкрементальная синхронизация: переиндексировать сущности,
	 * изменённые после $since. Возвращает число обработанных.
	 */
	public function syncModifiedSince(string $since): int
	{
		$index = IndexManager::indexName();
		$client = $this->indexManager->client();
		$processed = 0;

		$crmMap = [
			\CCrmOwnerType::Lead => '\CCrmLead',
			\CCrmOwnerType::Deal => '\CCrmDeal',
			\CCrmOwnerType::Contact => '\CCrmContact',
			\CCrmOwnerType::Company => '\CCrmCompany',
		];
		$allowed = Config::allowedCrmEntityTypeIds();

		$bulk = [];
		$indexed = [];
		$flush = function () use ($client, &$bulk, &$indexed): void {
			$this->flushBulk($client, $bulk, $indexed);
		};

		foreach ($crmMap as $typeId => $class)
		{
			if (!in_array($typeId, $allowed, true))
			{
				continue;
			}
			try
			{
				$result = $class::GetListEx(
					['ID' => 'ASC'],
					[
						'>=DATE_MODIFY' => $since,
						'CHECK_PERMISSIONS' => 'N',
					],
					false,
					false,
					['ID']
				);
				$count = 0;
				while ($row = $result->Fetch())
				{
					$document = $this->builder->buildCrmDocument($typeId, (int)$row['ID']);
					if ($document !== null)
					{
						$this->pushBulk($bulk, $indexed, $index, $document);
					}
					$count++;
					$processed++;
					if (count($bulk) >= 200)
					{
						$flush();
					}
				}
			}
			catch (\Throwable)
			{
				// проблемы с конкретным типом не должны ронять синхронизацию
			}
		}

		if (Config::searchUsersEnabled())
		{
			try
			{
				$rows = \Bitrix\Main\UserTable::getList([
					'select' => ['ID'],
					'filter' => ['>=TIMESTAMP_X' => $since],
					'limit' => 5000,
				])->fetchAll();
				foreach ($rows as $row)
				{
					$document = $this->builder->buildUserDocument((int)$row['ID']);
					if ($document !== null)
					{
						$this->pushBulk($bulk, $indexed, $index, $document);
					}
					$processed++;
					if (count($bulk) >= 200)
					{
						$flush();
					}
				}
			}
			catch (\Throwable)
			{
			}
		}

		if (\Bitrix\Main\Loader::includeModule('iblock'))
		{
			foreach (Config::iblockScopes() as $iblockId => $config)
			{
				try
				{
					$result = \CIBlockElement::GetList(
						['ID' => 'ASC'],
						[
							'>=TIMESTAMP_X' => $since,
							'=IBLOCK_ID' => $iblockId,
							'CHECK_PERMISSIONS' => 'N',
						],
						false,
						false,
						['ID']
					);
					while ($row = $result->Fetch())
					{
						$document = $this->builder->buildIblockElementDocument((int)$row['ID']);
						if ($document !== null)
						{
							$this->pushBulk($bulk, $indexed, $index, $document);
						}
						$processed++;
						if (count($bulk) >= 200)
						{
							$flush();
						}
					}
				}
				catch (\Throwable)
				{
				}

				if ($config['sections']['enabled'])
				{
					try
					{
						$result = \CIBlockSection::GetList(
							['ID' => 'ASC'],
							[
								'>=TIMESTAMP_X' => $since,
								'=IBLOCK_ID' => $iblockId,
							],
							false,
							['ID']
						);
						while ($row = $result->Fetch())
						{
							$document = $this->builder->buildIblockSectionDocument((int)$row['ID']);
							if ($document !== null)
							{
								$this->pushBulk($bulk, $indexed, $index, $document);
							}
							$processed++;
							if (count($bulk) >= 200)
							{
								$flush();
							}
						}
					}
					catch (\Throwable)
					{
					}
				}
			}
		}

		$flush();

		return $processed;
	}
}
