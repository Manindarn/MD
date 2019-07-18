<?php

interface ITypes
{
    public function getMysqlDetailsForSync($mappingOldID);
    public function createRecordInMongo($mappingData);
    public function createRecordInMysql($mappingData);
    public function getMongoDetailsForSync($mappingData);
    public function convertToMysqlFormat($mongoData, &$mysqlData);
    public function convertToMongoFormat($dataDiff, $mysqlData, $mongoData);
    public function updateFromMongoToMysql($mongoData, $mysqlData);
    public function updateFromMysqlToMongo($mongoData, $mysqlData);
}
