<?php

namespace Mtai\OpenSearch;

use Bitrix\Main\Config\Option;

/**
 * Агенты модуля.
 */
final class Agent
{
	/**
	 * Инкрементальная синхронизация индекса: переиндексирует всё,
	 * что изменялось после предыдущего запуска (страховка от событий,
	 * отключённых при массовых операциях/импорте).
	 *
	 * Метка времени хранится в опции sync_watermark (время UTC+смещение БД).
	 */
	public static function syncRecent(): string
	{
		$module = Config::MODULE_ID;

		// Время берём из БД: агенты выполняются и на cron, и на хитах —
		// часы PHP и MySQL могут отличаться от времени записей DATE_MODIFY.
		global $DB;
		$now = (string)$DB->CurrentTimeFunction();
		// CurrentTimeFunction возвращает SQL-выражение — получаем значение отдельно.
		$row = $DB->Query('SELECT ' . $now . ' AS T')->Fetch();
		$nowValue = is_array($row) ? (string)$row['T'] : date('d.m.Y H:i:s');

		$watermark = Option::get($module, 'sync_watermark', '');
		if ($watermark === '')
		{
			// Первый запуск: следующая синхронизация стартует с текущего момента.
			Option::set($module, 'sync_watermark', $nowValue);

			return '\\Mtai\\OpenSearch\\Agent::syncRecent();';
		}

		try
		{
			(new Indexer())->syncModifiedSince($watermark);
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('mtai.opensearch agent: ' . $e->getMessage(), $module);
			// метку не двигаем — в следующий раз попробуем снова
			return '\\Mtai\\OpenSearch\\Agent::syncRecent();';
		}

		Option::set($module, 'sync_watermark', $nowValue);

		return '\\Mtai\\OpenSearch\\Agent::syncRecent();';
	}
}
