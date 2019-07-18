<?php
ini_set('max_execution_time', 10000);

require_once 'define.php';

require_once DB_PATH . 'MySqlDB.php';
require_once DB_PATH . 'MongoDB.php';
require_once DB_PATH . 'ElasticSearchService.php';

$mongoObject = new MongoDB('SecretQuestions', USER_MANAGEMENT_DB);

$mysqlObject = new MySqlDB('common_user_details', 'educatio_educat');

$elasticObject = new ElasticSearchService();
$elasticTest = $elasticObject->ping(CONFIG['ElasticSearch']);

echo "<pre>";
print_r([$mongoObject, $mysqlObject,$elasticTest]);
die("Test");
