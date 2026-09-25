<?php

namespace Mtai\OpenSearch\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\PageNavigation;
use Mtai\OpenSearch\OpenSearchException;
use Mtai\OpenSearch\SearchService;

Loc::loadMessages(__FILE__);

/**
 * Поисковый экшен — полная замена crm.api.entity.search.
 *
 * URL: /bitrix/services/main/ajax.php?action=mtai:opensearch.api.entity.search
 *
 * Контракт ответа идентичен штатному: {items: [...], limits: []}, элементы —
 * Bitrix\Main\Search\ResultItem (title, type, module, links.show,
 * attributes), поэтому фронтенд (шапка портала, выбор клиента в карточках
 * CRM) работает без изменений. CRM-элементы форматируются штатными
 * адаптерами CRM, пользователи и инфоблоки — модулем.
 */
class Entity extends Controller
{
	public function configureActions()
	{
		return [
			'search' => [
				'+prefilters' => [new CloseSession()],
			],
		];
	}

	public function searchAction($searchQuery, ?array $options = null, ?PageNavigation $pageNavigation = null)
	{
		$query = trim((string)$searchQuery);
		if ($query === '')
		{
			return ['items' => [], 'limits' => []];
		}

		$options = is_array($options) ? $options : [];

		$context = [
			'user_id' => (int)$this->getCurrentUser()->getId(),
		];

		// Вызов может ограничить типы CRM (например, выбор клиента в карточке
		// лида ищет только контакты и компании) — уважаем это ограничение,
		// пересекая с разрешёнными в настройках модуля.
		$requestedTypes = [];
		if (isset($options['types']) && is_array($options['types']) && \Bitrix\Main\Loader::includeModule('crm'))
		{
			foreach ($options['types'] as $type)
			{
				$typeId = is_numeric($type) ? (int)$type : (int)\CCrmOwnerType::ResolveID((string)$type);
				if ($typeId > 0)
				{
					$requestedTypes[] = $typeId;
				}
			}
		}
		if ($requestedTypes !== [])
		{
			$context['crm_type_ids'] = array_values(array_intersect(
				$requestedTypes,
				\Mtai\OpenSearch\Config::allowedCrmEntityTypeIds()
			));
			// Типы ограничили — пользователи и инфоблоки в такой выдаче не нужны.
			$context['users'] = false;
			$context['iblock_ids'] = [];
		}

		if (isset($options['categoryId']))
		{
			$context['category_id'] = (int)$options['categoryId'];
		}

		try
		{
			$items = (new SearchService())->search($query, $context);
		}
		catch (OpenSearchException $e)
		{
			// Недоступность OpenSearch — сообщаем клиенту: JS-патч
			// откатится на штатный поиск.
			$this->addError(new Error($e->getMessage(), 'OPENSEARCH_UNAVAILABLE'));

			return null;
		}

		return ['items' => $items, 'limits' => []];
	}
}
