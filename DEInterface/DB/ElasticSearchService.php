<?php

use Elasticsearch\ClientBuilder;
use Elasticsearch\Common\Exceptions\Conflict409Exception;

// Denying direct access to this file, have to call from index.php
//defined('INFRASTRUCTURE_PATH') or exit('No direct script access allowed');

class ElasticSearchService
{
    private $client;
    private $loopCount=0;
    private $sessionData = [];
    private $configData = [];

    public function __construct()
    {
        $configData = CONFIG['ElasticSearch'];
        $host = isset($configData['host']) ? $configData['host'] : 'localhost';
        $this->client = ClientBuilder::create()
                        ->setHosts([$host])
                        ->allowBadJSONSerialization() // Allow bad versions of json-ext
                        ->build();
        $this->configData = $configData;
    }

    public function ping($configData)
    {
        return $this->client->ping();
    }

    public function insert($dataSourceConfig, $jsonBody, $additionalInformation = [])
    {
        $params = [];
        $params['index'] = $dataSourceConfig["Database"];
        $params['type']  = $dataSourceConfig["Collection"];
        $offlineESFlag = $jsonBody['offlineElasticFlag'] ?? false;
        
        if(!$offlineESFlag){
            #Restrict multiple entries by upsert
            if( isset($jsonBody['contentAttemptId']) && in_array($dataSourceConfig['Database'], [ INDEX_USER_MODULE, INDEX_USER_ATTEMPT, INDEX_USER_ACTIVITY] )){
             $params['id']  = $jsonBody['contentAttemptId'];
            }else if ( isset($jsonBody['sessionId']) && $dataSourceConfig['Database'] == INDEX_USER_SESSION ) {
               $params['id']  = $jsonBody['sessionId'];
            }#else create ID by default    
        }
        

        if(isset($jsonBody['offlineElasticFlag']) ){
            unset($jsonBody['offlineElasticFlag']);
            $insertFlag     = true;
            $params['id']   = $jsonBody['docId'];

            try{
                $result = $this->client->get($params);
            }catch(\Elasticsearch\Common\Exceptions\Missing404Exception $e){
                // Data is not there
                $insertFlag=true;
            }catch(Exception $e){
                error_log('Error in Doc Fetch For Sync Query :---> '.json_encode($e.getMessage()));
            }
            if(isset($result['_id']) && !empty($result['_id'])){
                // unset($jsonBody['offlineElasticFlag']);
                $params['body']['doc']=$jsonBody;
                $this->client->update($params);
                $insertFlag=false;
                $returnData=$params['id'];
            }
            if($insertFlag){
                $params['body']  = $jsonBody;
                $returnData = json_encode(($this->client->index($params)));
            }

        }else{
            $params['body']  = $jsonBody;
            $returnData = json_encode(($this->client->index($params)));
        }
        return $returnData;
    }

    public function get($dataSourceConfig, $searchCondition)
    {
        // echo 'check';
        // print_r($searchCondition);
        $params = array();
        $params['index'] = $dataSourceConfig["Database"];
        $params['type']  = $dataSourceConfig["Collection"];
        $params['body'] = $searchCondition;

        try{
            return (json_encode([
                'elasticsearch' => true,
                'data' =>$this->client->search($params)
                ])
            );
        }catch(Exception $e){
            error_log('Error in Get query:---> '.json_encode($e.getMessage()));
        }
    }
    public function bulk($dataSourceConfig, $data)
    {
        $params = array();
        $params['index'] = $dataSourceConfig["Database"];
        $params['type']  = $dataSourceConfig["Collection"];
        $params['body'] = $data;

        try{
            return (json_encode([
                'elasticsearch' => true,
                'data' =>$this->client->bulk($params)
                ])
            );
        }catch(Exception $e){
            error_log('Error in bulk insert :---> '.json_encode($e.getMessage()));
        }
    }

    public function update($dataSourceConfig, $id, $jsonBody)
    {

        if($this->loopCount>2){
            error_log('EXIT Without Update due to version conflict:-->  '.json_encode($jsonBody));
            die();
        }

        //print_r([$dataSourceConfig,$id,$jsonBody]); die;
        $params             = array();
        $returnData         = [];
        $params['index']    = $dataSourceConfig["Database"];
        $params['type']     = $dataSourceConfig["Collection"];
        $params['id']       = isset($jsonBody['docId']) ? $jsonBody['docId']: $id['_id'];

        /* Remove id to match _source params */
        unset($jsonBody['docId']);
        /** Get object **/
        $params['refresh'] = true;          # Refresh Shard containing this doc before performing update

        
        try{
            $result = $this->client->get($params);
        }catch(\Elasticsearch\Common\Exceptions\Missing404Exception $e){
            // Data is not there
            $params['body']  = $jsonBody;
            $returnData = ($this->client->index($params));
            $returnData = (json_encode([
                'elasticsearch' => true,
                'data' => $returnData
                ])
            );
            $this->loopCount = 0;
            return $returnData;
            // echo "Catch 1";
        }

        /** Replace _source fields **/
        foreach ($jsonBody as $field => $value) {
            if(isset($value)){
                $result['_source'][$field] = $value;
            }
        }
        //$result['_source'] = $jsonBody;

        /* Now set version for concurrency control */
        $params['version'] = $result['_version'];
        $params['refresh'] = true;          # Refresh Shard containing this doc before performing update

        $params['body']['doc'] = $result['_source'];

        try{
            $returnData = $this->client->update($params);
            $returnData = (json_encode([
                'elasticsearch' => true,
                'data' => $returnData
                ])
            );
        }catch(Conflict409Exception $ex){
            //error_log('Update failed due to version conflict:---> '.json_encode($params));
            $this->loopCount++;
            $jsonBody['docId']=$params['id'];

            $returnData=$this->update($dataSourceConfig, $id, $jsonBody);
        }catch(\Elasticsearch\Common\Exceptions\Missing404Exception $e){
            // Data is not there
            $insertFlag=true;
            $returnData = ($this->client->index($params));
            $returnData = (json_encode([
                'elasticsearch' => true,
                'data' => $returnData
                ])
            );
        }catch(Exception $e){
            error_log('error in Update Query:---> '.json_encode($e.getMessage()));

        }
	       // error_log('database details for update' . json_encode($dataSourceConfig, true));
        $returnSync = $returnData;
        $this->loopCount = 0;
        return $returnData;
    }


    public function remove($dataSourceConfig, $id)
    {
        if (true) {
            // print_r($id);
            $params = array();
            $returnData = [];
            $params['index'] = $dataSourceConfig["Database"];
            $params['type']  = $dataSourceConfig["Collection"];
            $params['id']  = $id['_id'];
            /** Get object **/
            try{
                $result = $this->client->get($params);
            }catch(\Elasticsearch\Common\Exceptions\Missing404Exception $e){
                // Data is not there
                $params['body']  = $jsonBody;
                $returnData = ($this->client->index($params));
                $returnData = (json_encode([
                    'elasticsearch' => true,
                    'data' => $returnData
                    ])
                );
                $this->loopCount = 0;
                return $returnData;
                // echo "Catch 1";
            }

            //print_r($params);
            try{
                $returnData = $this->client->delete($params);
                $returnData = (json_encode([
                    'elasticsearch' => true,
                    'data' => $returnData
                    ])
                );
            }catch(Exception $e){
                error_log('error in Remove Query:---> '.json_encode($e.getMessage()));
            }
           return $returnData;
        }
    }

    public function getKey($dataSourceConfig)
    {
        return $dataSourceConfig["Key"];
    }

    public function ifDocExists($searchKey,$id,$domainObj)
    {

        $result = ['isExists' => true, 'data' => []];
        $params['index']        = $domainObj["Database"];
        $params['type']         = $domainObj["Collection"];


        if($searchKey == 'contentAttemptId'){
            #Search Query
            $searchCondition['query']  = ['bool' => ['must' => ['term' => ['contentAttemptId' => $id ] ] ] ];
            $searchCondition['size']   = 1;
            $params['body']     = $searchCondition;
            $res                = $this->client->search($params);
            if( isset($res['hits']['total']) AND $res['hits']['total'] >= 1 ){
                $result['data'] = $res['hits']['hits'][0]['_source'];
                $result['data']['docId'] = $res['_id'];
            }else{
                $result['isExists'] = false;
            }       
        }   
        elseif($searchKey == 'docId'){
            $params['id']       = $id;  
            
            try{
                $res = $this->client->get($params); 
                $result['data'] = $res['_source']?? [];
                $result['data']['docId'] = $id;
            }catch(\Elasticsearch\Common\Exceptions\Missing404Exception $e){ 
                $result['isExists'] = false;
            }
        }#else default
        return $result;
    }

    public function updateSync($dataSourceConfig, $searchCondition, $array, $additionalInformation = [])
    {
        
        try{
            $this->insert($dataSourceConfig, $array, $additionalInformation = []);
            return json_encode(array($array));
        }catch(Exception $e){
            error_log('error in UpdateSync Query:---> '.json_encode($e.getMessage()));
        }
    }

    // public function setInfrastructureManager($infrastructure){
    //     $this->infrastructure = $infrastructure;
    // }



}
