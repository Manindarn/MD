<?php

class Mapping
{
    use HelperFunctions;

    private $UMMappingObject = null;
    private $tableMappings = null;
    private $mapingConfig = null;
    private $mappingResult = [];
    private $limit = 50;

    public function __construct()
    {
        $this->mapingConfig = $this->getConfig('mapping');
        $this->tableMappings = $this->mapingConfig['tableMappings'];

        $this->UMMappingObject = new MySqlDB($this->mapingConfig['mappingTableName'], $this->mapingConfig['mappingDBName']);
    }

    public function startMapping($orgID = null)
    {
        foreach ($this->tableMappings as $type => $mappings) {

            if (!in_array($type, ['organization', 'user'])) {
                continue;
            }

            if ($mappings[0] ?? false) {
                foreach ($mappings as $mapping) {
                    $this->generateMappingForThisType($type, $mapping, $orgID);
                }
            } else {
                $this->generateMappingForThisType($type, $mappings, $orgID);
            }

        }
        return $this->mappingResult;
    }

    public function generateMappingForThisType($type, $mapping, $orgID = null)
    {

        $mongoStructure = $mapping['mongo'];
        $mysqlStructure = $mapping['mysql'];

        $mongoTableName = $mongoStructure['table'];
        $mysqlTableName = $mysqlStructure['table'];

        $mysqlDBName = $mysqlStructure['DB'];
        $mysqlIDName = $mysqlStructure['IDName'];
        $mysqlLastUpdatedField = $mysqlStructure['lastUpdatedAtFieldName'];

        $mongoDBName = $mongoStructure['DB'];
        $mongoIDName = $mongoStructure['IDName'];
        $mongoLastUpdatedField = $mongoStructure['lastUpdatedAtFieldName'];
        $oldIDFieldName = $mongoStructure['oldIDFieldName'];

        $mongoTableObject = new MongoDB($mongoTableName, $mongoDBName);
        $mysqlTableObject = new MySqlDB($mysqlTableName, $mysqlDBName);

        $whereForMongoRecords = [$oldIDFieldName => ['$exists' => true, '$type' => 2, '$nin' => [null, '']]];

        if (!is_null($orgID) && in_array($type, ['organization', 'user'])) {
            $whereForMongoRecords['orgID'] = $orgID;
        }

        $totalMongoRecords = $mongoTableObject->count($whereForMongoRecords);

        if ($totalMongoRecords) {

            for ($j = 0; $j < $totalMongoRecords; $j = $j + $this->limit) {

                $mongoRecords = $mongoTableObject->findAll($whereForMongoRecords, ['limit' => $this->limit, 'skip' => $j]);

                $oldIDs = array_column($mongoRecords, $oldIDFieldName);
                $mongoRecords = array_combine($oldIDs, $mongoRecords);

                $whereForMysql = $mysqlIDName . ' IN (' . implode(', ', $oldIDs) . ')';

                $selectColumns = [$mysqlIDName, $mysqlLastUpdatedField];

                $totalRecords = $mysqlTableObject->getCount($whereForMysql);

                if ($totalRecords) {

                    for ($i = 0; $i < $totalRecords; $i = $i + $this->limit) {

                        $mysqlResult = $mysqlTableObject->getAll($whereForMysql, $selectColumns, ['limit' => $this->limit, 'offset' => $i]);

                        if (!empty($mysqlRecords = $mysqlResult['data'] ?? [])) {

                            $insertData = [];

                            foreach ($mysqlRecords as $value) {

                                $oldID = $value[$mysqlIDName];

                                $insertData[] = [
                                    'type' => $type,
                                    'oldDB' => $mysqlDBName,
                                    'oldTable' => $mysqlTableName,
                                    'oldIDName' => $mysqlIDName,
                                    'oldID' => $oldID,
                                    'oldToNew' => 'Y',
                                    'oldUpdatedAt' => $this->getFormatedDate($value[$mysqlLastUpdatedField], DATETIME_FORMAT),

                                    'newToOld' => 'Y',
                                    'newDB' => $mongoDBName,
                                    'newTable' => $mongoTableName,
                                    'newIDName' => $mongoIDName,
                                    'newID' => $mongoRecords[$oldID][$mongoIDName],
                                    'newTable' => $mongoTableName,
                                    'newUpdatedAt' => $this->getFormatedDate($mongoRecords[$oldID][$mongoLastUpdatedField], DATETIME_FORMAT),
                                ];
                            }

                            if (!empty($insertData)) {
                                $this->UMMappingObject->insertMany($insertData);
                                $this->mappingResult[$type] = ($this->mappingResult[$type] ?? 0) + count($insertData);
                            }
                        }

                    }

                }
            }
        }

    }

}
