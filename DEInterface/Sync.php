<?php

require_once 'HelperFunctions.php';

require_once DB_PATH . 'MySqlDB.php';
require_once DB_PATH . 'MongoDB.php';

class Sync
{
    use HelperFunctions;

    private $UMMappingObject = null;
    private $tableMappings = null;
    private $mapingConfig = null;
    private $overAllStatus = [];

    public function __construct()
    {
        $this->mapingConfig = $this->getConfig('mapping');
        $this->tableMappings = $this->mapingConfig['tableMappings'];

        $this->UMMappingObject = new MySqlDB($this->mapingConfig['mappingTableName'], $this->mapingConfig['mappingDBName']);
    }

    public function getMappingData($mappingID)
    {
        return $this->UMMappingObject->get('id = ' . $mappingID)['data'] ?? [];
    }

    public function syncAll()
    {
        $returnData = ['status' => 'success', 'msg' => 'Nothing to sync'];

        $where = 'oldToNew = "N" OR newToOld = "N"';
        $allUnsyncedMappings = $this->UMMappingObject->getAll($where)['data'] ?? [];
        //echo "<pre>";print_r($allUnsyncedMappings);die;
        if (!empty($allUnsyncedMappings)) {

            $synced = [];
            $successCount = $errorCount = 0;
            foreach ($allUnsyncedMappings as $key => $mapping) {
                $this->syncThis($mapping);
            }
            $returnData['msg'] = 'Sync done';
            $returnData['data'] = $this->overAllStatus;
        }

        return $returnData;
    }

    public function syncThis($mappingData)
    {
        $returnData = ['status' => 'error', 'msg' => 'Something went, mapping node done'];

        if (!empty($mappingData)) {
            $mappingID = $mappingData['id'];

            $mappingStructure = $this->getConfig('mapping', 'tableMappings')[$mappingData['type']];

            $className = ucfirst($mappingData['type']);

            $classFilePath = __DIR__ . DIRECTORY_SEPARATOR . 'types' . DIRECTORY_SEPARATOR . $className . '.php';

            if (file_exists($classFilePath)) {
                require_once $classFilePath;

                if (class_exists($className)) {

                    $typeObject = new $className(); //user/organization/order

                    $mysqlData = $typeObject->getMysqlDetailsForSync($mappingData['oldID']);

                    if (!empty($mysqlData)) {

                        if (is_null($mappingData['newDB']) && is_null($mappingData['newTable'])) {
                            // create new record from mysql to mongo

                            if ($createResult = $typeObject->createRecordInMongo($mappingData)) {
                                $markDoneData = [
                                    'oldToNew' => 'Y',
                                    'newToOld' => 'Y',
                                    'newDB' => $mappingStructure['mongo']['DB'],
                                    'newTable' => $mappingStructure['mongo']['table'],
                                    'newIDName' => $mappingStructure['mongo']['IDName'],
                                    'newID' => $createResult,
                                    'newUpdatedAt' => $mappingData['oldUpdatedAt'],
                                ];

                                $returnData['status'] = 'success';
                                $returnData['msg'] = 'Sync done';

                                $this->overAllStatus['createdToMongo'][$mappingData['type']][] = $mappingData['oldID'];

                                $this->markAsDone($mappingID, $markDoneData);
                            }

                        } elseif (is_null($mappingData['oldDB']) && is_null($mappingData['oldTable'])) {
                            // not supported as of now
                            // create new record from mongo to mysql

                            // $createResult = $typeObject->createRecordInMysql($mappingData);

                            // $markDoneData = [
                            //     'newToOld' => 'Y',
                            //     'oldToNew' => 'Y',
                            //     'oldDB' => $mappingStructure['mysql']['DB'],
                            //     'oldTable' => $mappingStructure['mysql']['table'],
                            //     'oldIDName' => $mappingStructure['mysql']['IDName'],
                            //     'oldID' => $createResult,
                            //     'oldUpdatedAt' => $mappingData['newUpdatedAt'],
                            // ];

                            // $returnData['status'] = 'success';
                            // $returnData['msg'] = 'Sync done';

                            // $this->markAsDone($mappingID, $markDoneData);

                            // $this->overAllStatus['createdToMysql'][$mappingData['type']][] = $mappingData['newID'];

                        } else {

                            $mongoData = $typeObject->getMongoDetailsForSync($mappingData);

                            if (!empty($mongoData)) {
                                if ($orgID = $mongoData['orgID'] ?? false) {
                                    $typeObject->orgIDForOffline = $orgID;
                                }

                                if ($mappingData['newToOld'] == 'N' && $mappingData['oldToNew'] == 'N') {

                                    if (($mappingData['oldUpdatedAt'] == $mappingData['newUpdatedAt']) ||
                                        ($mappingData['newUpdatedAt'] > $mappingData['oldUpdatedAt'])) {

                                        // sync from mysql to mongo
                                        $typeObject->updateFromMysqlToMongo($mongoData, $mysqlData);

                                        // sync from mongo to mysql
                                        {

                                            $updatedMysqlData = $typeObject->getMysqlDetailsForSync($mappingData['oldID']);
                                            $updatedMongoData = $typeObject->getMongoDetailsForSync($mappingData);

                                            $typeObject->updateFromMongoToMysql($updatedMongoData, $updatedMysqlData);

                                        }

                                    }

                                    if ($mappingData['newUpdatedAt'] < $mappingData['oldUpdatedAt']) {

                                        // sync from mongo to mysql
                                        $typeObject->updateFromMongoToMysql($mongoData, $mysqlData);

                                        // sync from mysql to mongo
                                        {
                                            $updatedMysqlData = $typeObject->getMysqlDetailsForSync($mappingData['oldID']);
                                            $updatedMongoData = $typeObject->getMongoDetailsForSync($mappingData);

                                            $typeObject->updateFromMysqlToMongo($updatedMongoData, $updatedMysqlData);
                                        }

                                    }

                                    $returnData['status'] = 'success';
                                    $returnData['msg'] = 'Sync done';

                                    $this->overAllStatus['updatedToMysqlAndMongo'][$mappingData['type']][] = $mappingData['oldID'];

                                    $this->markAsDone($mappingID, ['oldToNew' => 'Y', 'newToOld' => 'Y', 'newUpdatedAt' => $mappingData['oldUpdatedAt'], 'oldUpdatedAt' => $mappingData['newUpdatedAt']]);

                                } elseif ($mappingData['newToOld'] == 'N') {
                                    // sync from mongo to mysql
                                    $typeObject->updateFromMongoToMysql($mongoData, $mysqlData);

                                    $returnData['status'] = 'success';
                                    $returnData['msg'] = 'Sync done';

                                    $this->overAllStatus['updatedToMysql'][$mappingData['type']][] = $mappingData['oldID'];

                                    $this->markAsDone($mappingID, ['newToOld' => 'Y', 'oldUpdatedAt' => $mappingData['newUpdatedAt']]);

                                } else if ($mappingData['oldToNew'] == 'N') {
                                    // sync from mysql to mongo
                                    $typeObject->updateFromMysqlToMongo($mongoData, $mysqlData);

                                    $returnData['status'] = 'success';
                                    $returnData['msg'] = 'Sync done';

                                    $this->overAllStatus['updatedToMongo'][$mappingData['type']][] = $mappingData['oldID'];

                                    $this->markAsDone($mappingID, ['oldToNew' => 'Y', 'newUpdatedAt' => $mappingData['oldUpdatedAt']]);
                                } else {
                                    $returnData['status'] = 'success';
                                    $returnData['msg'] = 'Nothing to sync';
                                }
                            } else {
                                $returnData['msg'] = 'No record found in mongo for this mappingID: ' . $mappingID;
                            }
                        }

                    } else {
                        $returnData['msg'] = 'No details found in mysql table for the given mappingID, may be condition not matched.';
                    }
                } else {
                    $returnData['msg'] = 'No sync script avaiable for: ' . $className;
                }
            } else {
                $returnData['msg'] = 'No sync script avaiable for: ' . $className;
            }
        } else {
            $returnData['msg'] = 'Mapping details not found for the given mappingID';
        }
        return $returnData;
    }

    private function markAsDone($mappingID, $updateData)
    {
        $this->UMMappingObject->update('id = ' . $mappingID, $updateData);
    }

}
