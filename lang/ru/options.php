<?php

// Вкладки
$MESS['MTAI_OPENSEARCH_TAB_CONNECTION'] = 'Подключение';
$MESS['MTAI_OPENSEARCH_TAB_CONNECTION_TITLE'] = 'Параметры подключения к OpenSearch';
$MESS['MTAI_OPENSEARCH_TAB_SCOPE'] = 'Область поиска';
$MESS['MTAI_OPENSEARCH_TAB_SCOPE_TITLE'] = 'Где искать: CRM-сущности, пользователи, инфоблоки';
$MESS['MTAI_OPENSEARCH_TAB_INDEX'] = 'Индексация';
$MESS['MTAI_OPENSEARCH_TAB_INDEX_TITLE'] = 'Параметры индексации';

// Подключение
$MESS['MTAI_OPENSEARCH_OPT_HOST'] = 'Хост OpenSearch';
$MESS['MTAI_OPENSEARCH_OPT_HOST_HINT'] = 'Из docker-сети стенда: opensearch. Извне: localhost.';
$MESS['MTAI_OPENSEARCH_OPT_PORT'] = 'Порт';
$MESS['MTAI_OPENSEARCH_OPT_SCHEME'] = 'Протокол';
$MESS['MTAI_OPENSEARCH_OPT_INDEX_NAME'] = 'Имя индекса';
$MESS['MTAI_OPENSEARCH_HEADING_REPLACE'] = 'Замена штатного поиска';
$MESS['MTAI_OPENSEARCH_OPT_REPLACE_NATIVE_SEARCH'] = 'Заменять штатный поиск CRM';
$MESS['MTAI_OPENSEARCH_OPT_REPLACE_NATIVE_SEARCH_HINT'] = 'Подключает JS-патч: вызовы crm.api.entity.search (шапка портала, выбор клиента) уходят в OpenSearch. При недоступности OpenSearch автоматически выполняется штатный поиск.';
$MESS['MTAI_OPENSEARCH_HEADING_TEST'] = 'Проверка';
$MESS['MTAI_OPENSEARCH_BTN_TEST'] = 'Проверить соединение';
$MESS['MTAI_OPENSEARCH_BTN_TEST_HINT'] = 'Проверяет значения из формы (без сохранения).';
$MESS['MTAI_OPENSEARCH_TEST_OK'] = 'Соединение установлено, OpenSearch #VERSION#.';
$MESS['MTAI_OPENSEARCH_TEST_FAIL'] = 'Ошибка подключения: #ERROR#';
$MESS['MTAI_OPENSEARCH_SAVED'] = 'Настройки сохранены.';

// Область поиска
$MESS['MTAI_OPENSEARCH_HEADING_CRM'] = 'CRM-сущности';
$MESS['MTAI_OPENSEARCH_ENTITY_LEAD'] = 'Лиды';
$MESS['MTAI_OPENSEARCH_ENTITY_DEAL'] = 'Сделки';
$MESS['MTAI_OPENSEARCH_ENTITY_CONTACT'] = 'Контакты';
$MESS['MTAI_OPENSEARCH_ENTITY_COMPANY'] = 'Компании';
$MESS['MTAI_OPENSEARCH_ENTITY_QUOTE'] = 'Квоты';
$MESS['MTAI_OPENSEARCH_ENTITY_SMART_INVOICE'] = 'Смарт-счета';
$MESS['MTAI_OPENSEARCH_ENTITY_DYNAMIC'] = 'Смарт-процессы (динамические типы)';
$MESS['MTAI_OPENSEARCH_ENTITY_DYNAMIC_HINT'] = 'Индексируются все созданные смарт-процессы. Изменения подхватываются агентом синхронизации и полным переиндексом.';
$MESS['MTAI_OPENSEARCH_HEADING_USERS'] = 'Пользователи';
$MESS['MTAI_OPENSEARCH_OPT_SEARCH_USERS'] = 'Искать сотрудников портала';
$MESS['MTAI_OPENSEARCH_OPT_SEARCH_USERS_HINT'] = 'Блок «Сотрудники» в подсказке шапки заменяется на результаты морфологического поиска.';
$MESS['MTAI_OPENSEARCH_HEADING_IBLOCKS'] = 'Инфоблоки';
$MESS['MTAI_OPENSEARCH_OPT_IBLOCKS'] = 'Индексируемые инфоблоки';
$MESS['MTAI_OPENSEARCH_OPT_IBLOCKS_HINT'] = 'Множественный выбор (Ctrl/Shift). Найденные элементы выводятся в подсказке шапки отдельными блоками по инфоблокам.';
$MESS['MTAI_OPENSEARCH_HEADING_LIMITS'] = 'Лимиты';
$MESS['MTAI_OPENSEARCH_OPT_SEARCH_LIMIT'] = 'Всего результатов';
$MESS['MTAI_OPENSEARCH_OPT_PER_TYPE_LIMIT'] = 'Максимум на тип сущности';
$MESS['MTAI_OPENSEARCH_OPT_CANDIDATE_FACTOR'] = 'Запас кандидатов';
$MESS['MTAI_OPENSEARCH_OPT_CANDIDATE_FACTOR_HINT'] = 'Во сколько раз больше кандидатов запрашивать до проверки прав доступа.';

// Индексация
$MESS['MTAI_OPENSEARCH_OPT_BATCH_SIZE'] = 'Размер батча';
$MESS['MTAI_OPENSEARCH_OPT_BATCH_SIZE_HINT'] = 'Сколько элементов индексировать за один шаг переиндексации (5–500).';
$MESS['MTAI_OPENSEARCH_MAINTENANCE_HINT'] = 'Полный переиндекс и статус индекса — на странице <a href="mtai_opensearch.php?lang=' . LANGUAGE_ID . '">Обслуживание индекса OpenSearch</a>.';
