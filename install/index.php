<?php
/**
 * Установщик модуля mtai.opensearch.
 *
 * Модуль не создаёт собственных таблиц: поисковый индекс живёт во внешнем
 * OpenSearch. При установке регистрируется модуль, обработчики событий
 * (индексация при изменении сущностей), агент фоновой синхронизации и
 * копируется JS-патч, перехватывающий штатный ajax-экшен crm.api.entity.search.
 */

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

class mtai_opensearch extends CModule
{
	public $MODULE_ID = 'mtai.opensearch';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $MODULE_GROUP_RIGHTS = 'R';

	public function __construct()
	{
		$arModuleVersion = [];
		include __DIR__ . '/version.php';

		$this->MODULE_VERSION = (string)($arModuleVersion['VERSION'] ?? '');
		$this->MODULE_VERSION_DATE = (string)($arModuleVersion['VERSION_DATE'] ?? '');

		$this->MODULE_NAME = Loc::getMessage('MTAI_OPENSEARCH_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('MTAI_OPENSEARCH_MODULE_DESCRIPTION');
		$this->PARTNER_NAME = Loc::getMessage('MTAI_OPENSEARCH_PARTNER_NAME');
		$this->PARTNER_URI = Loc::getMessage('MTAI_OPENSEARCH_PARTNER_URI');
	}

	public function DoInstall(): void
	{
		global $DB, $APPLICATION;

		$this->InstallDB();
		$this->InstallEvents();
		$this->InstallFiles();

		$GLOBALS['errors'] = $this->errors;
	}

	public function DoUninstall(): void
	{
		$this->UnInstallFiles();
		$this->UnInstallEvents();
		$this->UnInstallDB();
	}

	public function InstallDB(): bool
	{
		ModuleManager::registerModule($this->MODULE_ID);

		return true;
	}

	public function UnInstallDB(): bool
	{
		// Индекс в OpenSearch не трогаем: его можно удалить вручную
		// (кнопка на странице обслуживания) или через DELETE /<index>.
		ModuleManager::unRegisterModule($this->MODULE_ID);

		return true;
	}

	public function InstallEvents(): bool
	{
		// Классы lib/ автозагружаются только после включения модуля.
		\Bitrix\Main\Loader::includeModule($this->MODULE_ID);
		\Mtai\OpenSearch\EventHandler::register();

		return true;
	}

	public function UnInstallEvents(): bool
	{
		if (\Bitrix\Main\Loader::includeModule($this->MODULE_ID))
		{
			\Mtai\OpenSearch\EventHandler::unregister();
		}

		return true;
	}

	public function InstallFiles(): bool
	{
		// JS-патч перехвата штатного поиска копируется в /bitrix/js/,
		// чтобы URL был стабильным и не зависел от пути к local/.
		CopyDirFiles(
			__DIR__ . '/js/mtai.opensearch',
			$_SERVER['DOCUMENT_ROOT'] . '/bitrix/js/mtai.opensearch',
			true,
			true
		);

		// Страница обслуживания индекса: в /bitrix/admin кладём заглушку,
		// которая подключает реальную страницу из каталога модуля —
		// так разрешение языковых файлов идёт по пути модуля
		// (lang/ru/install/admin/...), как у штатных модулей.
		$stubPath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/mtai_opensearch.php';
		CheckDirPath(dirname($stubPath) . '/');
		file_put_contents(
			$stubPath,
			'<?php require($_SERVER["DOCUMENT_ROOT"] . "/local/modules/mtai.opensearch/install/admin/mtai_opensearch.php");'
			. "\n"
		);

		return true;
	}

	public function UnInstallFiles(): bool
	{
		DeleteDirFilesEx('/bitrix/js/mtai.opensearch');
		if (is_file($_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/mtai_opensearch.php'))
		{
			unlink($_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/mtai_opensearch.php');
		}

		return true;
	}
}
