<?php

namespace Mtai\OpenSearch;

/**
 * Управление индексом OpenSearch: создание с маппингом, пересоздание, статус.
 *
 * Морфология обеспечивают встроенные анализаторы Lucene:
 *   russian — нижний регистр, стоп-слова, стеммер (Ивану → иван);
 *   english — то же для латиницы.
 * Поля поиска дублируются субполями .en, чтобы находились и латинские
 * названия в русском контексте.
 */
final class IndexManager
{
	public const TYPE_CRM_PREFIX = 'crm_';
	public const TYPE_USER = 'user';
	public const TYPE_IBLOCK = 'iblock';
	public const TYPE_IBLOCK_SECTION = 'iblock_section';

	private OpenSearchClient $client;

	public function __construct(?OpenSearchClient $client = null)
	{
		$this->client = $client ?? new OpenSearchClient();
	}

	public function client(): OpenSearchClient
	{
		return $this->client;
	}

	public static function indexName(): string
	{
		return Config::indexName();
	}

	/**
	 * Идентификатор документа в индексе.
	 */
	public static function documentId(string $entityType, int $entityId): string
	{
		return $entityType . '_' . $entityId;
	}

	public function buildIndexBody(): array
	{
		$textRu = fn (array $extraFields = []) => [
			'type' => 'text',
			'analyzer' => 'morpho_ru',
			'fields' => array_merge(
				['en' => ['type' => 'text', 'analyzer' => 'morpho_en']],
				$extraFields
			),
		];

		return [
			'settings' => [
				'analysis' => [
					'analyzer' => [
						'morpho_ru' => ['type' => 'russian'],
						'morpho_en' => ['type' => 'english'],
					],
					'normalizer' => [
						'lowercase' => ['type' => 'custom', 'filter' => ['lowercase']],
					],
				],
			],
			'mappings' => [
				// Отдельные поля сущностей (fields.<code>) создаются динамически;
				// шаблон даёт им морфологический анализатор и субполе .en.
				'dynamic_templates' => [
					[
						'morpho_text' => [
							'match_mapping_type' => 'string',
							'mapping' => [
								'type' => 'text',
								'analyzer' => 'morpho_ru',
								'fields' => [
									'en' => ['type' => 'text', 'analyzer' => 'morpho_en'],
								],
							],
						],
					],
				],
				'properties' => [
					// Вид сущности: crm_lead, crm_deal, crm_contact, crm_company,
					// crm_quote, crm_smart_invoice, crm_dynamic, user, iblock.
					'entity_type' => ['type' => 'keyword'],
					// CCrmOwnerType::* для CRM-сущностей, 0 для прочих.
					'crm_type_id' => ['type' => 'long'],
					// ID сущности (элемента CRM / пользователя / элемента инфоблока).
					'entity_id' => ['type' => 'long'],
					// Категория (воронки сделок, смарт-процессов).
					'category_id' => ['type' => 'long'],
					// ID инфоблока для entity_type = iblock.
					'iblock_id' => ['type' => 'long'],
					// Заголовок — основной вес при ранжировании.
					'title' => $textRu(['raw' => ['type' => 'keyword', 'normalizer' => 'lowercase']]),
					// Подзаголовок (должность, компания, раздел инфоблока).
					'subtitle' => $textRu(),
					// Отдельные поля сущностей — состав зависит от типа
					// (comments, post, position, department, preview, content, ...).
					'fields' => ['type' => 'object'],
					// Телефоны в нормализованном виде (только цифры).
					'phones' => ['type' => 'keyword'],
					// Email в нижнем регистре.
					'emails' => ['type' => 'keyword', 'normalizer' => 'lowercase'],
					// Ссылка на карточку сущности.
					'url' => ['type' => 'keyword', 'index' => false],
					// Данные для отрисовки (должность, email, фото и т.п.).
					'attrs' => ['type' => 'object', 'enabled' => false],
					'date_update' => ['type' => 'date'],
				],
			],
		];
	}

	/**
	 * Создаёт индекс, если его нет.
	 */
	public function ensureIndex(): bool
	{
		$name = self::indexName();
		if ($this->client->indexExists($name))
		{
			return false;
		}

		$this->client->createIndex($name, $this->buildIndexBody());

		return true;
	}

	/**
	 * Удаляет и создаёт индекс заново (полный переиндекс).
	 */
	public function recreateIndex(): void
	{
		$name = self::indexName();
		if ($this->client->indexExists($name))
		{
			$this->client->deleteIndex($name);
		}

		$this->client->createIndex($name, $this->buildIndexBody());
	}

	/**
	 * @return array{ok: bool, version: string, index: string, index_exists: bool, total: int, by_type: array}
	 */
	public function status(): array
	{
		$result = [
			'ok' => false,
			'version' => '',
			'index' => self::indexName(),
			'index_exists' => false,
			'total' => 0,
			'by_type' => [],
		];

		if (!$this->client->ping())
		{
			return $result;
		}

		$result['ok'] = true;
		$result['version'] = $this->client->version();
		$result['index_exists'] = $this->client->indexExists($result['index']);
		if ($result['index_exists'])
		{
			// Bulk-индексация видна в поиске не сразу — форсируем refresh,
			// чтобы статус показывал актуальное число документов.
			try
			{
				$this->client->refresh($result['index']);
			}
			catch (OpenSearchException)
			{
			}
			$result['total'] = $this->client->count($result['index']);
			$result['by_type'] = $this->client->countsByType($result['index']);
		}

		return $result;
	}
}
