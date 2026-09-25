<?php

/**
 * Конфигурация ajax-контроллеров модуля.
 *
 * Благодаря алиасу 'api' экшен доступен как
 *   /bitrix/services/main/ajax.php?action=mtai:opensearch.api.search
 * и разрешается в класс \Mtai\OpenSearch\Controller\Api::searchAction.
 */

return [
	'controllers' => [
		'value' => [
			'defaultNamespace' => '\\Mtai\\OpenSearch\\Controller',
			'namespaces' => [
				'\\Mtai\\OpenSearch\\Controller' => 'api',
			],
		],
		'readonly' => true,
	],
];
