<?php

// require_once ASSET_PATH . 'vendor/autoload.php';

class MongoDB
{

    private $collection = null;
    private $DBName = null;
    private $collectionName = null;
    private $DBNameForOffline = null;
    private $client = null;
    public $offlineSync = false;
    public $orgIDForOffline = null;
    public $sessionTime = null;
    public $currentTime = null;

    
    public function __construct($collection, $DBName = null)
    {

        global $GLOBALS;

        $config = CONFIG['MongoDB'];

        $host = $config['host'];
        $username = $config['username'] ?? false;
        $password = $config['password'] ?? false;

       /* $host = MONGO_HOST;
        $username = MONGO_USERNAME;
        $password = MONGO_PASSWORD;*/
		
		
		

        $this->DBNameForOffline = $DBName = $DBName ? $DBName : $config['DefaultDB'];

        if (!isset($GLOBALS['mongoConnection'])) {
            $GLOBALS['mongoConnection'] = ($username && $password)
            ? new \MongoDB\Client('mongodb://' . $host, array('username' => $username, 'password' => $password))
            : new \MongoDB\Client('mongodb://' . $host);
        }

        $this->client = $GLOBALS['mongoConnection'];

        $this->DBName = $this->client->$DBName;
        $this->collectionName = $collection;
        $this->collection = $this->DBName->$collection;

        $this->sessionTime = $this->currentTime = $GLOBALS['currentTime'] ?? date(DATETIME_FORMAT);

        if (!$this->client) {
            die("Connection failed: ");
        }
    }

    public function find($searchCondition = [])
    {
        return json_decode(json_encode($this->collection->findOne($searchCondition)), true);
    }
    public function findAll($searchCondition = [], $additionalCondition = [])
    {
        return json_decode(json_encode($this->iterator_to_array_deep($this->collection->find($searchCondition, $additionalCondition))), true);
        // return json_decode(json_encode($this->deserialize($this->collection->find($searchCondition, $additionalCondition))), true);
    }

    public function findInsert($searchCondition = [], $insertData)
    {

        $data['val'] = $this->find($searchCondition);

        if (isset($data['val']['_id'])) {
            $data['msg'] = "Already exists in " . $this->collection . "\n";
            $data['errorCode'] = 1;
        } else {

            if (!isset($insertData['_createdAt'])) {
                $insertData['_createdAt'] = $this->currentTime;
            }

            if (!isset($insertData['_updatedAt'])) {
                $insertData['_updatedAt'] = $this->currentTime;
            }

            $data['value'] = $insertResult = $this->collection->insertOne($insertData);

            if ($this->offlineSync && OFFLINE_SYNC) {
                $this->insertForSync($insertResult);
            }

            if (isset($data)) {
                $data['msg'] = "inserted successfully in " . $this->collection . "\n";
                $data['errorCode'] = 2;
            } else {
                $data['msg'] = "Sorry couldn't not insert in " . $this->collection . "\n";
                $data['errorCode'] = 3;
            }
        }
        return $data;
    }

    public function insert($data)
    {
        // create json structure for offline schools

        if (!isset($data['_createdAt'])) {
            $data['_createdAt'] = $this->currentTime;
        }

        if (!isset($data['_updatedAt'])) {
            $data['_updatedAt'] = $this->currentTime;
        }

        $insertResult = $this->collection->insertOne($data);

        if ($this->offlineSync && OFFLINE_SYNC) {
            $this->insertForSync($insertResult);
        }

        return $insertResult;
    }
    public function insertMany($data)
    {
        return $this->collection->insertMany($data);
    }

    public function update($where, $what)
    {
        // create json structure for offline schools
        if (!isset($what['_updatedAt'])) {
            $what['_updatedAt'] = $this->currentTime;
        }
        $updatedData = $this->collection->findOneAndUpdate($where, array('$set' => $what));

        if ($this->offlineSync && OFFLINE_SYNC) {
            $this->insertForSync($updatedData, true);
        }
        return $updatedData;
    }

    public function count($where)
    {
        return $this->collection->count($where);
    }

    public function remove($where)
    {
        return $this->collection->delete($where);
    }

    public function removeMany($where)
    {
        return $this->collection->deleteMany($where);
    }

    public function newMongoId()
    {
        return (string) new MongoDB\BSON\ObjectID();
    }
    public function mongoId($id)
    {
        return new MongoDB\BSON\ObjectID($id);
    }
    public function deserialize($data)
    {
        $returnData = [];

        foreach ($data as $key => $value) {
            $returnData[$key] = iterator_to_array($value);
            foreach ($returnData[$key] as $k => $v) {
                if (is_object($v)) {
                    $returnData[$key][$k] = iterator_to_array($v);
                    foreach ($returnData[$key][$k] as $k1 => $v1) {
                        if (is_object($v1)) {
                            $returnData[$key][$k][$k1] = iterator_to_array($v1);
                        }
                    }
                }
            }

        }
        return $returnData;
    }

    public function iterator_to_array_deep(\Traversable $iterator, $use_keys = true)
    {
        $array = array();
        foreach ($iterator as $key => $value) {
            if ($value instanceof \Iterator) {
                $value = $this->iterator_to_array_deep($value, $use_keys);
            }
            if ($use_keys) {
                $array[$key] = $value;
            } else {
                $array[] = $value;
            }
        }
        return $array;
    }

    public function aggregate($query)
    {
        return json_decode(json_encode($this->iterator_to_array_deep($this->collection->aggregate($query))), true);
    }

    private function insertForSync($object, $updateOperation = false)
    {

        $sessionsForSyncObject = new MongoDB('SessionsForSync', SYNC_SERVICE_DB);
        $dataForSyncObject = new MongoDB('DataForSync', SYNC_SERVICE_DB);

        $_id = $dataForSyncObject->newMongoId();
        $sessionID = 'UM_' . $this->orgIDForOffline . '_' . $this->sessionTime;

        $dataForSyncObject->offlineSync = false;
        $sessionsForSyncObject->offlineSync = false;

        $currentDateTime = date(DATETIME_FORMAT);

        $objectID = $updateOperation ? $object['_id'] : $object->getInsertedId();

        $foundObject = $this->find(['_id' => $objectID]);

        if ($docID = $foundObject['_id'] ?? false) {
            unset($foundObject['_id']);

            $lastSessionForSyncData = $sessionsForSyncObject->count([]);

            $sessionForSyncData = [
                "_id" => $_id,
                "srno" => intval(++$lastSessionForSyncData),
                "sessionID" => $sessionID,
                "destination" => $this->orgIDForOffline,
                "packageCreatedFlag" => 0,
                "portingFlag" => true,
                "_createdAt" => $currentDateTime,
                "_updatedAt" => $currentDateTime,
            ];
            $searchCond = ['sessionID' => $sessionID];

            $sessionsForSyncObject->findInsert($searchCond, $sessionForSyncData);

            $dataForSync = [
                "_id" => $_id,
                "docID" => $docID,
                "sessionID" => $sessionID,
                "table" => $this->collectionName,
                "DB" => $this->DBNameForOffline,
                "dataType" => SYNC_DATATYPE,
                "priority" => 1,
                "rawData" => $foundObject,
            ];

            $dataForSyncObject->collection->insertOne($dataForSync);
        }
    }

    public function insertPortLog($insertData = [], $UMPortingLogObject = null)
    {
        if (is_null($UMPortingLogObject)) {
            $UMPortingLogObject = new MongoDB('UMPortingLog', PORTING_DB);
        }

        $_id = $UMPortingLogObject->newMongoId();

        $insertData['_id'] = $_id;
        $insertData['createdAt'] = date(DATETIME_FORMAT);

        $UMPortingLogObject->collection->insertOne($insertData);
    }

    // @TODO : Rewrite the function (Prashanth)

    public function updateOneInArray($where, $what)
    {
        // create json structure for offline schools
        if (!isset($what['_updatedAt'])) {
            $what['_updatedAt'] = $this->currentTime;
        }
        $updatedData = $this->collection->findOneAndUpdate($where, array('$set' => $what));

        if ($this->offlineSync && OFFLINE_SYNC) {
            $this->insertForSync($updatedData, true);
        }
        return $updatedData;
    }
	
	public function listMongoDBs(){
       return $this->client->listDatabases();
    }
    
    public function listDBCollections($dbName = null){
        $db = $this->client->selectDatabase($dbName);
        $collections =  $db->listCollections();
        return $collections;
    }
}


