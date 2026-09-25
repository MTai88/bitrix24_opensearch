<?php

namespace Mtai\OpenSearch;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

/**
 * Тонкий REST-клиент OpenSearch поверх Bitrix\HttpClient.
 *
 * Зависимостей от composer нет. Любая транспортная ошибка или ответ
 * с HTTP-кодом >= 400 приводит к OpenSearchException — вызывающий код
 * решает, как реагировать (поиск откатывается на штатный механизм).
 */
class OpenSearchClient
{
	private string $baseUrl;

	/** @var array{connectTimeout?: int, socketTimeout?: int} */
	private array $timeouts;

	public function __construct(?string $baseUrl = null, array $timeouts = [])
	{
		$this->baseUrl = rtrim($baseUrl ?? Config::baseUrl(), '/');
		$this->timeouts = array_merge([
			'connectTimeout' => 2,
			'socketTimeout' => 10,
		], $timeouts);
	}

	private function request(string $method, string $path, ?array $body = null): array
	{
		$http = new HttpClient([
			'connectTimeout' => $this->timeouts['connectTimeout'],
			'socketTimeout' => $this->timeouts['socketTimeout'],
			'waitResponse' => true,
			'streamTimeout' => $this->timeouts['socketTimeout'],
		]);
		$http->setHeader('Content-Type', 'application/json');

		$url = $this->baseUrl . $path;
		$payload = $body !== null ? Json::encode($body, JSON_UNESCAPED_UNICODE) : null;

		$ok = $http->query($method, $url, $payload);
		if ($ok === false)
		{
			throw new OpenSearchException(
				'OpenSearch connection failed: ' . implode('; ', array_map(
					static fn ($e) => is_string($e) ? $e : json_encode($e),
					(array)($http->getError() ?: ['unknown'])
				))
			);
		}

		$status = (int)$http->getStatus();
		$raw = (string)$http->getResult();
		$data = $raw !== '' ? (Json::decode($raw) ?: []) : [];

		if ($status >= 400)
		{
			$reason = '';
			if (is_array($data))
			{
				$error = $data['error'] ?? null;
				if (is_array($error))
				{
					$reason = (string)($error['type'] ?? '') . ': ' . (string)($error['reason'] ?? '');
				}
				elseif (is_string($error))
				{
					$reason = $error;
				}
				$reason .= isset($data['status']) ? ' (status ' . $data['status'] . ')' : '';
			}

			throw new OpenSearchException('OpenSearch HTTP ' . $status . ' ' . $reason, $status);
		}

		return is_array($data) ? $data : [];
	}

	public function ping(): bool
	{
		try
		{
			$info = $this->request('GET', '/');

			return isset($info['version']['number']);
		}
		catch (OpenSearchException)
		{
			return false;
		}
	}

	public function version(): string
	{
		try
		{
			$info = $this->request('GET', '/');

			return (string)($info['version']['number'] ?? '');
		}
		catch (OpenSearchException)
		{
			return '';
		}
	}

	public function indexExists(string $name): bool
	{
		try
		{
			$this->request('HEAD', '/' . rawurlencode($name));

			return true;
		}
		catch (OpenSearchException $e)
		{
			if ($e->getCode() === 404)
			{
				return false;
			}

			throw $e;
		}
	}

	public function createIndex(string $name, array $body): array
	{
		return $this->request('PUT', '/' . rawurlencode($name), $body);
	}

	public function deleteIndex(string $name): array
	{
		return $this->request('DELETE', '/' . rawurlencode($name));
	}

	/**
	 * Индексирует/обновляет один документ.
	 */
	public function indexDocument(string $index, string $id, array $document): array
	{
		$path = '/' . rawurlencode($index) . '/_doc/' . rawurlencode($id);

		return $this->request('PUT', $path, $document);
	}

	public function deleteDocument(string $index, string $id): bool
	{
		try
		{
			$this->request('DELETE', '/' . rawurlencode($index) . '/_doc/' . rawurlencode($id));

			return true;
		}
		catch (OpenSearchException $e)
		{
			// 404 — документа уже нет, это не ошибка.
			return $e->getCode() === 404;
		}
	}

	/**
	 * Bulk-запрос. $lines — массив массивов, каждый кодируется в NDJSON.
	 */
	public function bulk(array $lines): array
	{
		$ndjson = '';
		foreach ($lines as $line)
		{
			$ndjson .= Json::encode($line, JSON_UNESCAPED_UNICODE) . "\n";
		}

		$http = new HttpClient([
			'connectTimeout' => $this->timeouts['connectTimeout'],
			'socketTimeout' => $this->timeouts['socketTimeout'],
			'streamTimeout' => $this->timeouts['socketTimeout'],
		]);
		$http->setHeader('Content-Type', 'application/x-ndjson');

		$ok = $http->query('POST', $this->baseUrl . '/_bulk', $ndjson);
		if ($ok === false)
		{
			throw new OpenSearchException('OpenSearch bulk request failed');
		}

		$status = (int)$http->getStatus();
		$data = Json::decode((string)$http->getResult()) ?: [];
		if ($status >= 400)
		{
			throw new OpenSearchException('OpenSearch bulk HTTP ' . $status, $status);
		}

		if (!empty($data['errors']))
		{
			$first = null;
			foreach ((array)($data['items'] ?? []) as $item)
			{
				foreach ($item as $op => $info)
				{
					if (is_array($info) && isset($info['error']))
					{
						$first = $info['error'];
						break 2;
					}
				}
			}
			throw new OpenSearchException(
				'OpenSearch bulk partial failure: ' . ($first ? json_encode($first, JSON_UNESCAPED_UNICODE) : 'n/a')
			);
		}

		return $data;
	}

	public function search(string $index, array $body): array
	{
		return $this->request('POST', '/' . rawurlencode($index) . '/_search', $body);
	}

	public function refresh(string $index): array
	{
		return $this->request('POST', '/' . rawurlencode($index) . '/_refresh');
	}

	public function count(string $index): int
	{
		$data = $this->request('POST', '/' . rawurlencode($index) . '/_count', [
			'query' => ['match_all' => new \stdClass()],
		]);

		return (int)($data['count'] ?? 0);
	}

	/**
	 * Количество документов по значениям поля entity_type (terms-агрегация).
	 *
	 * @return array<string, int>
	 */
	public function countsByType(string $index): array
	{
		$body = [
			'size' => 0,
			'aggs' => [
				'by_type' => [
					'terms' => ['field' => 'entity_type', 'size' => 100],
				],
			],
		];
		$data = $this->search($index, $body);

		$counts = [];
		foreach ((array)($data['aggregations']['by_type']['buckets'] ?? []) as $bucket)
		{
			$counts[(string)$bucket['key']] = (int)$bucket['doc_count'];
		}

		return $counts;
	}
}
