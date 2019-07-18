<?php
require_once 'HelperFunctions.php';
require_once 'define.php';
require_once 'types/User.php';

require_once DB_PATH . 'MySqlDB.php';
require_once DB_PATH . 'MongoDB.php';
require_once DB_PATH . 'ElasticSearchService.php';

class DEInterface {
    public $error=[];
    use HelperFunctions;
    private $dashboardData = [];
    private $userUserDetailsObject = null;
    private $mongoOrgObject = null;
    private $portConfig = null;
    private $mongoBatchObject = null;
    private $mongoGroupsObject = null;
    private $mongoGroupContentsObject = null;
    private $mongoUserProductsObject = null;
    private $mongoUserDetailsObject = null;

    private $mongoSectionObject = null;
    private $mongoPSBObject = null;
    private $result = null;
    private $upgradeConfig = null;
    private $mongoOrderObject = null;
    private $mysqlOrderMasterObject = null;
    private $mongoUpdatedData = [];
    private $log = [];
    private $elasticObject = null;
    private $user_attempt_index = [];
    private $activity_details_index = [];
    private $user_module_progress_index = [];
    private $user_session_log_index = [];
    private $user_api_log_index = [];
    private $dataSourceConfig = null;
    private $dbAndCollectionArray = [];
    
    public function __construct()
    {
        $this->portConfig = $this->getConfig('porting');
        $this->upgradeConfig = $this->getConfig('class_upgrade');
        
        $this->defaultMathPID = $this->portConfig['defaultMathPID'];
        $this->defaultEnglighPID = $this->portConfig['defaultEnglighPID'];
        
        $this->mongoOrgObject = new MongoDB('Organizations');
        $this->mongoBatchObject = new MongoDB('Batch');
        $this->mongoGroupContentsObject = new MongoDB('GroupContents');
        $this->mongoOrderObject = new MongoDB('Order');
        $this->mongoPSBObject = new MongoDB('ProductSectionBatchMapping');
        $this->mongoSectionObject = new MongoDB('SectionDetails');
        $this->mongoUserDetailsObject = new MongoDB('UserDetails');
        $this->mongoUserProductsObject = new MongoDB('UserProducts');
        $this->elasticObject = new ElasticSearchService();

        $this->dataSourceConfig = $this->getConfig('elastic_search');
        $this->user_attempt_index =  $this->dataSourceConfig['user_attempt'];
        $this->user_module_progress_index =  $this->dataSourceConfig['user_module_progress'];
        $this->activity_details_index =  $this->dataSourceConfig['activity_details'];
        $this->user_session_log_index =  $this->dataSourceConfig['user_session_log'];
        $this->user_api_log_index =  $this->dataSourceConfig['user_api_log'];

        //var_dump($this->mongoUserProductsObject->listMongoDB());
    }  

    
   

    public function executeCurl($url, $data = [])
    {
      
        $headers = ['Auth:EISecret'];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1); //0 for a get request
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $curlResponse = curl_exec($ch);

        return json_decode($curlResponse, true);
    }

    public function get_data_for_elastic($UPID){
        $data_from_user_attempt_index = [];
        $data_from_user_module_progress_index = [];
        $data_from_activity_details_index = [];
        $data_from_user_session_log_index = [];
        $data_from_user_api_log_index = [];

        $data_from_user_attempt_index = $this->get_from_user_attempt_index($UPID);
        $data_from_user_module_progress_index = $this->get_from_user_module_progress_index($UPID);
        $data_from_activity_details_index = $this->get_from_activity_details_index($UPID);
        $data_from_user_session_log_index = $this->get_from_user_session_log_index($UPID);
        $data_from_user_api_log_index = $this->get_from_user_api_log_index($UPID);
    }

    private function get_from_user_attempt_index($UPID){
        $searchCondition = $this->getQueryForUserIdData($UPID);
        $data = $this->elasticObject->get($this->user_attempt_index,$searchCondition);
    }

    private function get_from_user_module_progress_index($UPID){
        $searchCondition = $this->getQueryForUserIdData($UPID);
        $data = $this->elasticObject->get($this->user_module_progress_index,$searchCondition);
    }

    private function get_from_activity_details_index($UPID){
        $searchCondition = $this->getQueryForUserIdData($UPID);
        $data = $this->elasticObject->get($this->activity_details_index,$searchCondition);
    }

    private function get_from_user_session_log_index($UPID){
        $searchCondition = $this->getQueryForUserIdData($UPID);
        $data = $this->elasticObject->get($this->user_session_log_index,$searchCondition);
    }

    private function get_from_user_api_log_index($UPID){
        $searchCondition = $this->getQueryForUserIdData($UPID);
        $data = $this->elasticObject->get($this->user_api_log_index,$searchCondition);
    }
    
    private function getQueryForUserIdData($UPID){
        $query =
        [
            "size" => "999",
            "query"=> [
                "bool" => [
                    "must" => [
                        [
                            "terms" => [
                                "userId" => $UPID,
                            ],
                        ]
                    ]
                ]
            ]
        ];
        return $query;
    }

    private function get_from_usermanagement_batch($orgID){
        foreach($orgID as $data){
            $searchCondition = ['orgID' => $data, 'status' =>'A'];
            $data[$data][] = $this->mongoBatchObject->findAll($searchCondition);
        }
        return $data;        
    }

    //To be called after the groupID data is present
    private function get_from_usermanagement_group_contents($groupID){
        foreach($groupID as $data){
            $searchCondition = ['groupID' => $data];
            $data[$data][] = $this->mongoGroupContentsObject->findAll($searchCondition);
        }
        return $data;        
    }
    //To be called after the groupID data is present
    private function get_from_usermanagement_groups($groupID){
        //print_r($groupID);die();
        foreach($groupID as $data){
            $searchCondition = ['groupID' => $data];
            $data[$data][] = $this->mongoGroupsObject->findAll($searchCondition);
            //$datas = $this->mongoGroupsObject->findAll(['groupID' => $data); 

        }
        return $datas;        
    }

    private function get_from_usermanagement_order($orgID){
        foreach($orgID as $data){
            $searchCondition = ['orgID' => $data, 'status' =>'A'];
            $data[$data][] = $this->mongoOrderObject->findAll($searchCondition);
        }
        return $data;        
    }

    private function get_from_usermanagement_organizations($orgID){
        foreach($orgID as $data){
            $searchCondition = ['orgID' => $data, 'status' =>'A'];
            $data[$data][] = $this->mongoOrgObject->findAll($searchCondition);
        }
        return $data;        
    }

    private function get_from_usermanagement_product_section_batch_mapping($orgID){
        foreach($orgID as $data){
            $searchCondition = ['orgID' => $data, 'status' =>'A'];
            $data[$data][] = $this->mongoPSBObject->findAll($searchCondition);
        }
        return $data;        
    }

    private function get_from_usermanagement_section_details($orgID){
        foreach($orgID as $data){
            $searchCondition = ['orgID' => $data, 'status' =>'A'];
            $data[$data][] = $this->mongoSectionObject->findAll($searchCondition);
        }
        return $data;        
    }

    //Can be called only after obtaining UID
    private function get_from_usermanagement_user_details($UID){
        foreach($UID as $data){
            $searchCondition = ['UID' => $data, 'status' =>'A'];
            $data[$data][] = $this->mongoUserDetailsObject->findAll($searchCondition);
        }
        return $data;        
    }

    private function get_from_usermanagement_user_products($UPID){
        foreach($UPID as $data){
            $searchCondition = ['UPID' => $data, 'status' =>'A'];
            $data[$data][] = $this->mongoUserProductsObject->findAll($searchCondition);
        }
        return $data;        
    }

    public function login($data=[]){
		
        $apiResponse = $this->executeCurl(LOC_FRAMEWORK_BASE_URL . 'Mindspark/CommonLogin/ValidatePassword', $data);
        return $apiResponse;
    }
    public function logout($data=[]){
		
        $apiResponse = $this->executeCurl(LOC_FRAMEWORK_BASE_URL . 'Mindspark/CommonLogin/Logout', $data);
        return $apiResponse;
    }

    public function getLikeOrganizations($keyword = null){
		
        $apiResponse = $this->executeCurl(LOC_FRAMEWORK_BASE_URL . 'Mindspark/UserManagement/GetAllOrganizations');
        $this->dashboardData['orgList'] = isset($apiResponse['data']) ? $apiResponse['data'] : [];
        return $this->dashboardData;
    }

    public function getOrgBatches($orgIds = []){
       $orgBatchArray =  $orgSectionArray = $orgClassArray = array();
	   $i = $j = 0;
       if(count($orgIds) > 0){
            
            foreach($orgIds as $orgID){
                $orgBatchResult = $this->mongoBatchObject->findAll(["orgID" => $orgID]);
                foreach($orgBatchResult as $orgInfo){
                    $orgBatchArray[$i]['name']= $orgInfo['name'];
                    //$orgBatchArray[$i]['batchID']= $orgInfo['batchID'];
                    $i++;
                }
				
				$orgClassSectionResult = $this->mongoSectionObject->findAll(["orgID" => $orgID]);
                foreach($orgClassSectionResult as $classSectionInfo){
                    $orgSectionArray[]= $classSectionInfo['name'];
                    $orgClassArray[]= $classSectionInfo['grade'];
                    $j++;
                }
            }
			
        }
        
        
        $orgSectionArray = array_unique($orgSectionArray);
        $orgClassArray = array_unique($orgClassArray);
        
		$resultArray = array('orgBatchArray' => $orgBatchArray, 'orgClassArray' => $orgClassArray, 'orgSectionArray' => $orgSectionArray );
           
        return $resultArray;
    }

    public function getOrgStudents($orgIds = []){
        $resultArray = array();
        $i = $j = 0;
        if(count($orgIds) > 0){
          
            foreach($orgIds as $orgID){
                $studentResult = $this->mongoUserDetailsObject->findAll(["orgID" => $orgID]);
                foreach($studentResult as $studInfo){
					$resultArray[$i]['name']= $studInfo['name'];
                    $i++;
                }
            }

            
        }
            
        return $resultArray;
    }

       public function check_orgID_exit()
        {

        }
         public function fetch($orgID,$db_colle_name="",$result=array(),$error=array())
        {   
            foreach ($db_colle_name as $val) 
            {
                $dbAndCollectionArray = explode('Verticle', $val);
                $this->dbAndCollectionArray = new MongoDB($dbAndCollectionArray[1]);

                foreach($orgID as  $data)
                {

                    // $unit_result = $this->dbAndCollectionArray->findAll(['orgID' => $data]);
                    $unit_result = $this->dbAndCollectionArray->findAll(array('$or' => array(
                                                                            array('orgID' => $data),
                                                                            array('groupID' => $data),
                                                                            array('UID' => $data), // 5a4b384f421aa9686b4dhayal
                                                                            array('UPID' => $data),
                                                                            array('batchID' => $data) //5a4b384f421aa968a15d6d23
                                                                            )
                                                                         )); 

                   if (!empty($unit_result)) {
                        $result[] = $unit_result;
                    } else {
                        $error[] = $dbAndCollectionArray[1];
                    }
                 }     
            }
            $end_result = array($result,$error);

            return $end_result;   
        }
        

        
}
	
?>
