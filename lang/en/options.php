<?php

// Tabs
$MESS['MTAI_OPENSEARCH_TAB_CONNECTION'] = 'Connection';
$MESS['MTAI_OPENSEARCH_TAB_CONNECTION_TITLE'] = 'OpenSearch connection parameters';
$MESS['MTAI_OPENSEARCH_TAB_SCOPE'] = 'Search scope';
$MESS['MTAI_OPENSEARCH_TAB_SCOPE_TITLE'] = 'What to search: CRM entities, users, iblocks';
$MESS['MTAI_OPENSEARCH_TAB_INDEX'] = 'Indexing';
$MESS['MTAI_OPENSEARCH_TAB_INDEX_TITLE'] = 'Indexing parameters';

// Connection
$MESS['MTAI_OPENSEARCH_OPT_HOST'] = 'OpenSearch host';
$MESS['MTAI_OPENSEARCH_OPT_HOST_HINT'] = 'From the docker network: opensearch. From outside: localhost.';
$MESS['MTAI_OPENSEARCH_OPT_PORT'] = 'Port';
$MESS['MTAI_OPENSEARCH_OPT_SCHEME'] = 'Protocol';
$MESS['MTAI_OPENSEARCH_OPT_INDEX_NAME'] = 'Index name';
$MESS['MTAI_OPENSEARCH_HEADING_REPLACE'] = 'Native search replacement';
$MESS['MTAI_OPENSEARCH_OPT_REPLACE_NATIVE_SEARCH'] = 'Replace native CRM search';
$MESS['MTAI_OPENSEARCH_OPT_REPLACE_NATIVE_SEARCH_HINT'] = 'Injects a JS patch: crm.api.entity.search calls (portal header, client picker) go to OpenSearch. If OpenSearch is down, the native search runs automatically.';
$MESS['MTAI_OPENSEARCH_HEADING_TEST'] = 'Test';
$MESS['MTAI_OPENSEARCH_BTN_TEST'] = 'Test connection';
$MESS['MTAI_OPENSEARCH_BTN_TEST_HINT'] = 'Tests the values from the form (without saving).';
$MESS['MTAI_OPENSEARCH_TEST_OK'] = 'Connection established, OpenSearch #VERSION#.';
$MESS['MTAI_OPENSEARCH_TEST_FAIL'] = 'Connection failed: #ERROR#';
$MESS['MTAI_OPENSEARCH_SAVED'] = 'Settings saved.';

// Scope
$MESS['MTAI_OPENSEARCH_HEADING_CRM'] = 'CRM entities';
$MESS['MTAI_OPENSEARCH_ENTITY_LEAD'] = 'Leads';
$MESS['MTAI_OPENSEARCH_ENTITY_DEAL'] = 'Deals';
$MESS['MTAI_OPENSEARCH_ENTITY_CONTACT'] = 'Contacts';
$MESS['MTAI_OPENSEARCH_ENTITY_COMPANY'] = 'Companies';
$MESS['MTAI_OPENSEARCH_ENTITY_QUOTE'] = 'Quotes';
$MESS['MTAI_OPENSEARCH_ENTITY_SMART_INVOICE'] = 'Smart invoices';
$MESS['MTAI_OPENSEARCH_ENTITY_DYNAMIC'] = 'Smart processes (dynamic types)';
$MESS['MTAI_OPENSEARCH_ENTITY_DYNAMIC_HINT'] = 'All created smart processes are indexed. Changes are picked up by the sync agent and full reindex.';

// Search fields
$MESS['MTAI_OPENSEARCH_FIELDS_FOR'] = 'Search fields: #ENTITY#';
$MESS['MTAI_OPENSEARCH_FIELDS_HINT'] = 'Only checked fields are searched. If none are checked, the entity is excluded from search. Changing the field set does not require reindexing.';
$MESS['MTAI_OPENSEARCH_FIELD_TITLE'] = 'Entity title';
$MESS['MTAI_OPENSEARCH_FIELD_USER_TITLE'] = 'Employee full name';
$MESS['MTAI_OPENSEARCH_FIELD_IBLOCK_TITLE'] = 'Element name';
$MESS['MTAI_OPENSEARCH_FIELD_NAME'] = 'Lead contact name';
$MESS['MTAI_OPENSEARCH_FIELD_COMMENTS'] = 'Comments';
$MESS['MTAI_OPENSEARCH_FIELD_SOURCE'] = 'Source description';
$MESS['MTAI_OPENSEARCH_FIELD_PHONE'] = 'Phones';
$MESS['MTAI_OPENSEARCH_FIELD_EMAIL'] = 'Email';
$MESS['MTAI_OPENSEARCH_FIELD_CONTENT'] = 'Native search content (statuses, assignees, etc.)';
$MESS['MTAI_OPENSEARCH_FIELD_ADDITIONAL_INFO'] = 'Additional information';
$MESS['MTAI_OPENSEARCH_FIELD_POST'] = 'Position';
$MESS['MTAI_OPENSEARCH_FIELD_DEPARTMENT'] = 'Department';
$MESS['MTAI_OPENSEARCH_FIELD_PREVIEW'] = 'Preview text';
$MESS['MTAI_OPENSEARCH_FIELD_DETAIL'] = 'Detail text';
$MESS['MTAI_OPENSEARCH_FIELD_TAGS'] = 'Tags';
$MESS['MTAI_OPENSEARCH_HEADING_USERS_ONE'] = 'Users';
$MESS['MTAI_OPENSEARCH_FIELDS_IBLOCK_ONE'] = 'Iblock elements';
$MESS['MTAI_OPENSEARCH_SAVED_FIELDS'] = 'Settings saved. Scope and field selection apply immediately, no reindexing needed.';
$MESS['MTAI_OPENSEARCH_HEADING_USERS'] = 'Users';
$MESS['MTAI_OPENSEARCH_OPT_SEARCH_USERS'] = 'Search portal employees';
$MESS['MTAI_OPENSEARCH_OPT_SEARCH_USERS_HINT'] = 'The "Employees" block in the header hint is replaced with morphological search results.';
$MESS['MTAI_OPENSEARCH_HEADING_IBLOCKS'] = 'Iblocks';
$MESS['MTAI_OPENSEARCH_OPT_IBLOCKS'] = 'Indexed iblocks';
$MESS['MTAI_OPENSEARCH_OPT_IBLOCKS_HINT'] = 'Multiple selection (Ctrl/Shift). Found elements appear in the header hint grouped by iblock.';
$MESS['MTAI_OPENSEARCH_HEADING_LIMITS'] = 'Limits';
$MESS['MTAI_OPENSEARCH_OPT_SEARCH_LIMIT'] = 'Total results';
$MESS['MTAI_OPENSEARCH_OPT_PER_TYPE_LIMIT'] = 'Max per entity type';
$MESS['MTAI_OPENSEARCH_OPT_CANDIDATE_FACTOR'] = 'Candidate factor';
$MESS['MTAI_OPENSEARCH_OPT_CANDIDATE_FACTOR_HINT'] = 'How many times more candidates to fetch before access checks.';

// Indexing
$MESS['MTAI_OPENSEARCH_OPT_BATCH_SIZE'] = 'Batch size';
$MESS['MTAI_OPENSEARCH_OPT_BATCH_SIZE_HINT'] = 'Elements per reindex step (5–500).';
$MESS['MTAI_OPENSEARCH_MAINTENANCE_HINT'] = 'Full reindex and index status are available on the <a href="mtai_opensearch.php?lang=' . LANGUAGE_ID . '">OpenSearch index maintenance</a> page.';
