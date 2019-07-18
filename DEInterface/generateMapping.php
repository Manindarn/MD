<?php
ini_set('max_execution_time', 10000);

require_once 'define.php';
require_once 'HelperFunctions.php';
require_once 'Mapping.php';

require_once DB_PATH . 'MySqlDB.php';
require_once DB_PATH . 'MongoDB.php';

$mappingObject = new Mapping();

$mappingResult = $mappingObject->startMapping();

echo json_encode($mappingResult);
