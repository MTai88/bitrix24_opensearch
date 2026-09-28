<?php

namespace Mtai\OpenSearch;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Типизированный доступ к опциям модуля с кешированием на запрос.
 *
 * Область поиска хранится опцией scopes (JSON), см. FieldRegistry.
 */
final class Config
{
	public const MODULE_ID = 'mtai.opensearch';

	private static ?array $options = null;
	private static ?array $scopes = null;

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

	// ------------------------------------------------------------------
	// Область поиска (опция scopes, JSON)
	// ------------------------------------------------------------------

	/**
	 * Конфигурация скоупов поиска. Формат — см. FieldRegistry.
	 * При отсутствии опции — значения по умолчанию: классические
	 * CRM-сущности и пользователи со всеми системными полями.
	 */
	public static function scopes(): array
	{
		if (self::$scopes !== null)
		{
			return self::$scopes;
		}

		$raw = self::get('scopes');
		$scopes = [];
		if (trim($raw) !== '')
		{
			try
			{
				$decoded = \Bitrix\Main\Web\Json::decode($raw);
				if (is_array($decoded))
				{
					$scopes = $decoded;
				}
			}
			catch (\Throwable)
			{
			}
		}

		if ($scopes === [])
		{
			foreach (FieldRegistry::CRM_SCOPES as $scope)
			{
				$scopes[$scope] = [
					'enabled' => true,
					'fields' => array_keys(FieldRegistry::systemFields($scope)),
				];
			}
			$scopes['smart'] = [];
			$scopes['user'] = [
				'enabled' => true,
				'fields' => array_keys(FieldRegistry::systemFields('user')),
			];
			$scopes['iblock'] = [];
		}

		return self::$scopes = $scopes;
	}

	private static function scopeConfig(string $scope): array
	{
		$scopes = self::scopes();

		return is_array($scopes[$scope] ?? null) ? $scopes[$scope] : [];
	}

	private static function scopeFields(string $scope): array
	{
		$fields = self::scopeConfig($scope)['fields'] ?? [];

		return is_array($fields) ? array_values($fields) : [];
	}

	/**
	 * CRM-классика включена?
	 */
	public static function crmScopeEnabled(string $scope): bool
	{
		return (bool)(self::scopeConfig($scope)['enabled'] ?? false);
	}

	/**
	 * Выбранные поля CRM-классики (системные + UF_).
	 *
	 * @return string[]
	 */
	public static function crmScopeFields(string $scope): array
	{
		return self::scopeFields($scope);
	}

	/**
	 * Выбранные смарт-процессы: typeId => поля.
	 *
	 * @return array<int, string[]>
	 */
	public static function smartScopes(): array
	{
		$smart = self::scopes()['smart'] ?? [];
		$result = [];
		if (is_array($smart))
		{
			foreach ($smart as $typeId => $config)
			{
				$fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];
				$result[(int)$typeId] = array_values($fields);
			}
		}

		return $result;
	}

	/**
	 * Пользователи включены?
	 */
	public static function searchUsersEnabled(): bool
	{
		return (bool)(self::scopeConfig('user')['enabled'] ?? false);
	}

	/**
	 * Выбранные поля пользователей.
	 *
	 * @return string[]
	 */
	public static function userScopeFields(): array
	{
		return self::scopeFields('user');
	}

	/**
	 * Настройки инфоблоков: iblockId => {
	 *   fields: string[], properties: string[],
	 *   sections: {enabled: bool, fields: string[]}
	 * }.
	 *
	 * @return array<int, array>
	 */
	public static function iblockScopes(): array
	{
		$iblocks = self::scopes()['iblock'] ?? [];
		$result = [];
		if (is_array($iblocks))
		{
			foreach ($iblocks as $iblockId => $config)
			{
				if (!is_array($config))
				{
					continue;
				}
				$sections = is_array($config['sections'] ?? null) ? $config['sections'] : [];
				$result[(int)$iblockId] = [
					'fields' => is_array($config['fields'] ?? null) ? array_values($config['fields']) : [],
					'properties' => is_array($config['properties'] ?? null) ? array_values($config['properties']) : [],
					'sections' => [
						'enabled' => (bool)($sections['enabled'] ?? false),
						'fields' => is_array($sections['fields'] ?? null) ? array_values($sections['fields']) : [],
					],
				];
			}
		}

		return $result;
	}

	/**
	 * ID инфоблоков, участвующих в поиске.
	 *
	 * @return int[]
	 */
	public static function iblockIds(): array
	{
		return array_keys(self::iblockScopes());
	}

	/**
	 * У выбранных инфоблоков включён поиск по разделам?
	 */
	public static function anyIblockSectionsEnabled(): bool
	{
		foreach (self::iblockScopes() as $config)
		{
			if ($config['sections']['enabled'])
			{
				return true;
			}
		}

		return false;
	}

	// ------------------------------------------------------------------
	// Сводные данные для индексатора и поиска
	// ------------------------------------------------------------------

	/**
	 * Разрешённые для индексации ID типов CRM (классика + смарты).
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
			'crm_lead' => \CCrmOwnerType::Lead,
			'crm_deal' => \CCrmOwnerType::Deal,
			'crm_contact' => \CCrmOwnerType::Contact,
			'crm_company' => \CCrmOwnerType::Company,
		];

		$typeIds = [];
		foreach ($map as $scope => $typeId)
		{
			if (self::crmScopeEnabled($scope))
			{
				$typeIds[] = $typeId;
			}
		}

		foreach (array_keys(self::smartScopes()) as $typeId)
		{
			$typeIds[] = (int)$typeId;
		}

		return array_values(array_unique($typeIds));
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
