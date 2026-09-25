<?php

namespace Mtai\OpenSearch;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Типизированный доступ к опциям модуля с кешированием на запрос.
 */
final class Config
{
	public const MODULE_ID = 'mtai.opensearch';

	private static ?array $options = null;

	private static function load(): array
	{
		if (self::$options === null)
		{
			$defaults = [];
			$defaultFile = dirname(__DIR__) . '/default_option.php';
			if (is_file($defaultFile))
			{
				$defaults = (array)(include $defaultFile);
			}

			$stored = [];
			try
			{
				$stored = Option::getForModule(self::MODULE_ID);
			}
			catch (\Throwable)
			{
				// модуль ещё не установлен — используются значения по умолчанию
			}

			self::$options = array_merge($defaults, $stored);
		}

		return self::$options;
	}

	public static function get(string $name, string $default = ''): string
	{
		$options = self::load();

		return (string)($options[$name] ?? $default);
	}

	public static function getInt(string $name, int $default = 0): int
	{
		$value = self::get($name);
		if ($value === '' || !is_numeric($value))
		{
			return $default;
		}

		return (int)$value;
	}

	public static function getBool(string $name): bool
	{
		return strtoupper(self::get($name)) === 'Y';
	}

	// --- Подключение к OpenSearch ---

	public static function baseUrl(): string
	{
		$scheme = self::get('scheme', 'http') ?: 'http';
		$host = self::get('host', 'opensearch') ?: 'opensearch';
		$port = self::get('port', '9200') ?: '9200';

		return rtrim($scheme . '://' . $host, '/') . ':' . $port;
	}

	public static function indexName(): string
	{
		$name = self::get('index_name', 'bitrix24_morpho');

		return $name !== '' ? $name : 'bitrix24_morpho';
	}

	// --- Область поиска ---

	/**
	 * Разрешённые для поиска/индексации ID типов CRM (CCrmOwnerType::*)
	 * согласно настройкам модуля.
	 *
	 * @return int[]
	 */
	public static function allowedCrmEntityTypeIds(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$map = [
			'entity_lead' => \CCrmOwnerType::Lead,
			'entity_deal' => \CCrmOwnerType::Deal,
			'entity_contact' => \CCrmOwnerType::Contact,
			'entity_company' => \CCrmOwnerType::Company,
			'entity_quote' => \CCrmOwnerType::Quote,
			'entity_smart_invoice' => \CCrmOwnerType::SmartInvoice,
		];

		$typeIds = [];
		foreach ($map as $option => $typeId)
		{
			if (self::getBool($option))
			{
				$typeIds[] = (int)$typeId;
			}
		}

		if (self::getBool('entity_dynamic'))
		{
			foreach (self::dynamicEntityTypeIds() as $typeId)
			{
				$typeIds[] = (int)$typeId;
			}
		}

		return array_values(array_unique($typeIds));
	}

	/**
	 * ID всех динамических типов CRM (смарт-процессы).
	 *
	 * @return int[]
	 */
	public static function dynamicEntityTypeIds(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$typeIds = [];
		$typesMap = \Bitrix\Crm\Service\Container::getInstance()
			->getDynamicTypesMap()
			->load(['isLoadStages' => false, 'isLoadCategories' => false]);

		foreach ($typesMap->getTypes() as $type)
		{
			$typeIds[] = (int)$type->getEntityTypeId();
		}

		return $typeIds;
	}

	public static function searchUsersEnabled(): bool
	{
		return self::getBool('search_users');
	}

	/**
	 * ID инфоблоков из настроек.
	 *
	 * @return int[]
	 */
	public static function iblockIds(): array
	{
		$raw = self::get('iblocks');
		$ids = [];
		foreach (preg_split('/[\s,;]+/u', $raw) ?: [] as $part)
		{
			if (is_numeric($part))
			{
				$ids[] = (int)$part;
			}
		}

		return array_values(array_unique($ids));
	}

	public static function searchLimit(): int
	{
		return max(1, self::getInt('search_limit', 20));
	}

	public static function perTypeLimit(): int
	{
		return max(1, self::getInt('per_type_limit', 10));
	}

	public static function candidateFactor(): int
	{
		return max(1, self::getInt('candidate_factor', 5));
	}

	public static function batchSize(): int
	{
		return min(500, max(5, self::getInt('batch_size', 50)));
	}

	public static function replaceNativeSearch(): bool
	{
		return self::getBool('replace_native_search');
	}
}
