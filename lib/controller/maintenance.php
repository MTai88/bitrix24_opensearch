<?php

namespace Mtai\OpenSearch\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Mtai\OpenSearch\IndexManager;
use Mtai\OpenSearch\Indexer;
use Mtai\OpenSearch\OpenSearchException;

/**
 * Служебные экшены страницы обслуживания индекса (только админы).
 *
 * URL: /bitrix/services/main/ajax.php?action=mtai:opensearch.api.maintenance.<action>
 */
class Maintenance extends Controller
{
	public function configureActions()
	{
		return [
			'status' => [
				'+prefilters' => [new CloseSession()],
			],
			'reindexPlan' => [],
			'reindexStart' => [],
			'reindexStep' => [],
		];
	}

	private function requireAdmin(): bool
	{
		if (!$this->getCurrentUser()->isAdmin())
		{
			$this->addError(new Error('Access denied', 'ACCESS_DENIED'));

			return false;
		}

		return true;
	}

	public function statusAction(): ?array
	{
		if (!$this->requireAdmin())
		{
			return null;
		}

		try
		{
			return (new IndexManager())->status();
		}
		catch (OpenSearchException $e)
		{
			$this->addError(new Error($e->getMessage(), 'OPENSEARCH_UNAVAILABLE'));

			return null;
		}
	}

	public function reindexPlanAction(): ?array
	{
		if (!$this->requireAdmin())
		{
			return null;
		}

		try
		{
			return ['steps' => (new Indexer())->reindexPlan()];
		}
		catch (OpenSearchException $e)
		{
			$this->addError(new Error($e->getMessage(), 'OPENSEARCH_UNAVAILABLE'));

			return null;
		}
	}

	/**
	 * Пересоздаёт индекс (полный переиндекс с нуля).
	 */
	public function reindexStartAction(): ?array
	{
		if (!$this->requireAdmin())
		{
			return null;
		}

		try
		{
			(new IndexManager())->recreateIndex();
		}
		catch (OpenSearchException $e)
		{
			$this->addError(new Error($e->getMessage(), 'OPENSEARCH_UNAVAILABLE'));

			return null;
		}

		return ['started' => true];
	}

	public function reindexStepAction(string $code, int $lastId = 0): ?array
	{
		if (!$this->requireAdmin())
		{
			return null;
		}

		try
		{
			return (new Indexer())->runStep($code, $lastId);
		}
		catch (OpenSearchException $e)
		{
			$this->addError(new Error($e->getMessage(), 'OPENSEARCH_UNAVAILABLE'));

			return null;
		}
	}
}
