<?php

/**
 * Значения опций модуля по умолчанию (см. options.php).
 */

return [
	// --- Подключение к OpenSearch ---
	// Из PHP-контейнера docker-стенда адрес http://opensearch:9200.
	'host' => 'opensearch',
	'port' => '9200',
	'scheme' => 'http',
	'index_name' => 'bitrix24_morpho',

	// --- Замена штатного поиска ---
	// Y — JS-патч подключается на страницах и перенаправляет
	// crm.api.entity.search на OpenSearch (с откатом при сбое).
	'replace_native_search' => 'Y',

	// --- CRM-сущности ---
	'entity_lead' => 'Y',
	'entity_deal' => 'Y',
	'entity_contact' => 'Y',
	'entity_company' => 'Y',
	'entity_quote' => 'N',
	'entity_smart_invoice' => 'N',
	// Все смарт-процессы (динамические типы CRM).
	'entity_dynamic' => 'N',

	// --- Пользователи и инфоблоки ---
	'search_users' => 'Y',
	// CSV id инфоблоков, элементы которых индексируются и ищутся.
	'iblocks' => '',

	// --- Поиск ---
	// Максимум итоговых элементов (как у штатного поиска CRM).
	'search_limit' => '20',
	// Во сколько раз больше кандидатов запрашивать из OpenSearch
	// до фильтра прав (часть элементов пользователь видеть не вправе).
	'candidate_factor' => '5',
	// Максимум элементов на один тип в выдаче (шапка режет по 10).
	'per_type_limit' => '10',

	// --- Индексация ---
	// Сколько элементов обрабатывать за один шаг переиндексации.
	'batch_size' => '50',
];
