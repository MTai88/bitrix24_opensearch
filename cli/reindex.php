<?php
/**
 * CLI: полный переиндекс области поиска в OpenSearch.
 *
 * Запуск из PHP-контейнера стенда:
 *   docker compose exec php php /var/www/html/local/modules/mtai.opensearch/cli/reindex.php
 *
 * Или по шагам без пересоздания индекса (флаг --keep):
 *   ... reindex.php --keep
 */

if (PHP_SAPI !== 'cli')
{
	die('CLI only');
}

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 4);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('CHK_EVENT', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Mtai\OpenSearch\Config;
use Mtai\OpenSearch\IndexManager;
use Mtai\OpenSearch\Indexer;

try
{
	if (!\Bitrix\Main\Loader::includeModule('mtai.opensearch'))
	{
		fwrite(STDERR, "Module mtai.opensearch is not installed\n");
		exit(1);
	}

	$keepIndex = in_array('--keep', $argv, true);

	$manager = new IndexManager();
	if (!$manager->client()->ping())
	{
		fwrite(STDERR, "OpenSearch is not available at " . Config::baseUrl() . "\n");
		exit(1);
	}

	if (!$keepIndex)
	{
		echo 'Recreating index ' . IndexManager::indexName() . "...\n";
		$manager->recreateIndex();
	}
	else
	{
		$manager->ensureIndex();
	}

	$indexer = new Indexer();
	$steps = $indexer->reindexPlan();
	if ($steps === [])
	{
		echo "Nothing to index: empty search scope\n";
		exit(0);
	}

	$batchSize = Config::batchSize();
	foreach ($steps as $step)
	{
		$processed = 0;
		$lastId = 0;
		echo $step['title'] . ($step['total'] !== null ? ' (total ~' . $step['total'] . ')' : '') . ":\n";
		while (true)
		{
			$result = $indexer->runStep($step['code'], $lastId, $batchSize);
			$processed += $result['processed'];
			$lastId = $result['last_id'];
			echo "\r  " . $processed . ' elements';
			if ($result['done'])
			{
				break;
			}
		}
		echo "\n";
	}

	$manager->client()->refresh(\Mtai\OpenSearch\IndexManager::indexName());
	echo "Done. Total documents: " . $manager->client()->count(IndexManager::indexName()) . "\n";
}
catch (Throwable $e)
{
	fwrite(STDERR, 'Error: ' . get_class($e) . ': ' . $e->getMessage() . "\n"
		. $e->getTraceAsString() . "\n");
	exit(1);
}
