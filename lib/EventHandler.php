<?php

namespace Mtai\OpenSearch;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;

Loc::loadMessages(__FILE__);

/**
 * Обработчики событий: поддерживают индекс актуальным при изменении
 * сущностей CRM, пользователей и элементов инфоблоков.
 *
 * Ошибки OpenSearch глотаются с записью в лог: недоступность поиска
 * не должна ломать сохранение сущностей.
 */
final class EventHandler
{
	/** События CRM и соответствующие им типы сущностей. */
	private const CRM_EVENTS = [
		'Lead' => \CCrmOwnerType::Lead,
		'Deal' => \CCrmOwnerType::Deal,
		'Contact' => \CCrmOwnerType::Contact,
		'Company' => \CCrmOwnerType::Company,
		'Quote' => \CCrmOwnerType::Quote,
	];

	/**
	 * Регистрирует все обработчики и агента (вызывается установщиком).
	 */
	public static function register(): void
	{
		$manager = \Bitrix\Main\EventManager::getInstance();
		$module = Config::MODULE_ID;
		$class = static::class;

		// Константы CRM_EVENTS ссылаются на CCrmOwnerType — нужен модуль CRM.
		if (Loader::includeModule('crm'))
		{
			foreach (static::CRM_EVENTS as $entity => $typeId)
			{
				foreach (['Add', 'Update', 'Delete'] as $action)
				{
					$manager->registerEventHandlerCompatible(
						'crm',
						'OnAfterCrm' . $entity . $action,
						$module,
						$class,
						'onCrm' . $entity . $action
					);
				}
			}
		}

		foreach (['Add', 'Update', 'Delete'] as $action)
		{
			$manager->registerEventHandlerCompatible(
				'main', 'OnAfterUser' . $action, $module, $class, 'onUser' . $action
			);
			$manager->registerEventHandlerCompatible(
				'iblock', 'OnAfterIBlockElement' . $action, $module, $class, 'onIblockElement' . $action
			);
		}

		// Подключение JS-патча на страницах портала.
		$manager->registerEventHandlerCompatible(
			'main', 'OnEndBufferContent', $module, $class, 'onEndBufferContent'
		);

		// Пункт в админ-меню: страница обслуживания индекса.
		$manager->registerEventHandlerCompatible(
			'main', 'OnBuildGlobalMenu', $module, $class, 'onBuildGlobalMenu'
		);

		// Агент инкрементальной синхронизации (страховка от пропущенных событий).
		if (\CAgent::AddAgent(
			'\\Mtai\\OpenSearch\\Agent::syncRecent();',
			$module,
			'N',
			300
		) === false)
		{
			// агент с таким именем уже есть
		}
	}

	public static function unregister(): void
	{
		$manager = \Bitrix\Main\EventManager::getInstance();
		$module = Config::MODULE_ID;
		$class = static::class;

		if (Loader::includeModule('crm'))
		{
			foreach (static::CRM_EVENTS as $entity => $typeId)
			{
				foreach (['Add', 'Update', 'Delete'] as $action)
				{
					$manager->unRegisterEventHandler(
						'crm', 'OnAfterCrm' . $entity . $action, $module, $class, 'onCrm' . $entity . $action
					);
				}
			}
		}

		foreach (['Add', 'Update', 'Delete'] as $action)
		{
			$manager->unRegisterEventHandler(
				'main', 'OnAfterUser' . $action, $module, $class, 'onUser' . $action
			);
			$manager->unRegisterEventHandler(
				'iblock', 'OnAfterIBlockElement' . $action, $module, $class, 'onIblockElement' . $action
			);
		}

		$manager->unRegisterEventHandler(
			'main', 'OnEndBufferContent', $module, $class, 'onEndBufferContent'
		);
		$manager->unRegisterEventHandler(
			'main', 'OnBuildGlobalMenu', $module, $class, 'onBuildGlobalMenu'
		);

		\CAgent::RemoveModuleAgents($module);
	}

	// ------------------------------------------------------------------
	// CRM: генерировать явные методы нельзя — перечисляем явно,
	// чтобы тип сущности не вычислять по стеку вызова.
	// ------------------------------------------------------------------

	public static function onCrmLeadAdd(&$f): void { self::crmIndex(\CCrmOwnerType::Lead, $f); }
	public static function onCrmLeadUpdate(&$f): void { self::crmIndex(\CCrmOwnerType::Lead, $f); }
	public static function onCrmLeadDelete(&$f): void { self::crmDelete(\CCrmOwnerType::Lead, $f); }

	public static function onCrmDealAdd(&$f): void { self::crmIndex(\CCrmOwnerType::Deal, $f); }
	public static function onCrmDealUpdate(&$f): void { self::crmIndex(\CCrmOwnerType::Deal, $f); }
	public static function onCrmDealDelete(&$f): void { self::crmDelete(\CCrmOwnerType::Deal, $f); }

	public static function onCrmContactAdd(&$f): void { self::crmIndex(\CCrmOwnerType::Contact, $f); }
	public static function onCrmContactUpdate(&$f): void { self::crmIndex(\CCrmOwnerType::Contact, $f); }
	public static function onCrmContactDelete(&$f): void { self::crmDelete(\CCrmOwnerType::Contact, $f); }

	public static function onCrmCompanyAdd(&$f): void { self::crmIndex(\CCrmOwnerType::Company, $f); }
	public static function onCrmCompanyUpdate(&$f): void { self::crmIndex(\CCrmOwnerType::Company, $f); }
	public static function onCrmCompanyDelete(&$f): void { self::crmDelete(\CCrmOwnerType::Company, $f); }

	public static function onCrmQuoteAdd(&$f): void { self::crmIndex(\CCrmOwnerType::Quote, $f); }
	public static function onCrmQuoteUpdate(&$f): void { self::crmIndex(\CCrmOwnerType::Quote, $f); }
	public static function onCrmQuoteDelete(&$f): void { self::crmDelete(\CCrmOwnerType::Quote, $f); }

	private static function crmIndex(int $typeId, $fields): void
	{
		$id = is_array($fields) ? (int)($fields['ID'] ?? 0) : (int)$fields;
		if ($id <= 0 || !in_array($typeId, Config::allowedCrmEntityTypeIds(), true))
		{
			return;
		}

		try
		{
			(new Indexer())->indexCrmEntity($typeId, $id);
		}
		catch (\Throwable $e)
		{
			self::log($e);
		}
	}

	private static function crmDelete(int $typeId, $fields): void
	{
		$id = is_array($fields) ? (int)($fields['ID'] ?? 0) : (int)$fields;
		if ($id <= 0)
		{
			return;
		}

		try
		{
			(new Indexer())->deleteCrmEntity($typeId, $id);
		}
		catch (\Throwable $e)
		{
			self::log($e);
		}
	}

	// ------------------------------------------------------------------
	// Пользователи
	// ------------------------------------------------------------------

	public static function onUserAdd(&$fields): void { self::userIndex($fields); }
	public static function onUserUpdate(&$fields): void { self::userIndex($fields); }

	public static function onUserDelete($userId): void
	{
		try
		{
			(new Indexer())->deleteUser((int)$userId);
		}
		catch (\Throwable $e)
		{
			self::log($e);
		}
	}

	private static function userIndex($fields): void
	{
		$id = is_array($fields) ? (int)($fields['ID'] ?? 0) : (int)$fields;
		if ($id <= 0 || !Config::searchUsersEnabled())
		{
			return;
		}

		try
		{
			(new Indexer())->indexUser($id);
		}
		catch (\Throwable $e)
		{
			self::log($e);
		}
	}

	// ------------------------------------------------------------------
	// Инфоблоки
	// ------------------------------------------------------------------

	public static function onIblockElementAdd(&$fields): void { self::iblockIndex($fields); }
	public static function onIblockElementUpdate(&$fields): void { self::iblockIndex($fields); }

	public static function onIblockElementDelete($elementId): void
	{
		try
		{
			(new Indexer())->deleteIblockElement((int)$elementId);
		}
		catch (\Throwable $e)
		{
			self::log($e);
		}
	}

	private static function iblockIndex($fields): void
	{
		$id = is_array($fields) ? (int)($fields['ID'] ?? 0) : (int)$fields;
		if ($id <= 0)
		{
			return;
		}

		try
		{
			(new Indexer())->indexIblockElement($id);
		}
		catch (\Throwable $e)
		{
			self::log($e);
		}
	}

	// ------------------------------------------------------------------
	// Встраивание JS-патча
	// ------------------------------------------------------------------

	/**
	 * Добавляет <script> патча перед </body> на HTML-страницах
	 * авторизованных пользователей. Не зависит от asset-конвейера шаблона.
	 */
	public static function onEndBufferContent(&$content): void
	{
		try
		{
			if (!Config::replaceNativeSearch())
			{
				return;
			}

			$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
			if ($request->get('ajax_call') === 'y')
			{
				return;
			}

			global $USER;
			if (!isset($USER) || !($USER instanceof \CUser) || !$USER->IsAuthorized())
			{
				return;
			}

			$pos = mb_strripos($content, '</body>');
			if ($pos === false)
			{
				return;
			}

			$script = '<script src="/bitrix/js/mtai.opensearch/patch.js" defer></script>';
			$content = mb_substr($content, 0, $pos) . $script . mb_substr($content, $pos);
		}
		catch (\Throwable)
		{
			// подключение патча не должно ломать вывод страниц
		}
	}

	// ------------------------------------------------------------------
	// Админ-меню
	// ------------------------------------------------------------------

	/**
	 * Пункт «Обслуживание индекса OpenSearch» в разделе настроек админки.
	 */
	public static function onBuildGlobalMenu(&$aGlobalMenu, &$aModuleMenu): void
	{
		$aModuleMenu[] = [
			'parent_menu' => 'global_menu_settings',
			'sort' => 3500,
			'url' => 'mtai_opensearch.php?lang=' . LANGUAGE_ID,
			'text' => 'OpenSearch: ' . Loc::getMessage('MTAI_OPENSESEARCH_ADMIN_TITLE_SHORT'),
			'title' => Loc::getMessage('MTAI_OPENSESEARCH_ADMIN_TITLE'),
			'icon' => 'sys_menu_icon',
			'page_icon' => 'sys_page_icon',
		];
	}

	private static function log(\Throwable $e): void
	{
		AddMessage2Log('mtai.opensearch: ' . $e->getMessage(), Config::MODULE_ID);
	}
}
