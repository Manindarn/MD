<?php
require_once 'HelperFunctions.php';
require_once 'define.php';
require_once 'types/User.php';

require_once DB_PATH . 'MySqlDB.php';
require_once DB_PATH . 'MongoDB.php';

class ClassUpgradeHelper {
    public $error=[];
    use HelperFunctions;

    private $userUserDetailsObject = null;
    private $mongoOrgObject = null;
    private $portConfig = null;
    private $mongoBatchObject = null;
    private $mongoUserProductObject = null;
    private $mongoGroupsObject = null;
    private $mongoSectionObject = null;
    private $mongoPSBObject = null;
    private $result = null;
    private $upgradeConfig = null;
    private $mongoOrderObject = null;
    private $mysqlOrderMasterObject = null;
    private $mongoUpdatedData = [];
    private $log = [];
    
    public function __construct()
    {
        $this->portConfig = $this->getConfig('porting');
        $this->upgradeConfig = $this->getConfig('class_upgrade');
        $this->defaultMathPID = $this->portConfig['defaultMathPID'];
        $this->defaultEnglighPID = $this->portConfig['defaultEnglighPID'];
        $this->mongoOrgObject = new MongoDB('Organizations');
        $this->mongoUserDetailsObject = new MongoDB('UserDetails');    
        $this->mongoSectionObject = new MongoDB('SectionDetails');
        $this->mongoGroupsObject = new MongoDB('Groups');
        $this->mongoPSBObject = new MongoDB('ProductSectionBatchMapping');
        $this->mongoBatchObject = new MongoDB('Batch');
        $this->mongoUserProductObject = new MongoDB('UserProducts');   
        $this->mongoOrderObject = new MongoDB('Order');
        $this->mysqlOrderMasterObject = new MySqlDB('ms_orderMaster', 'educatio_educat');
    }

    //Same school, new class, no academic year change
    public function updateType1($data, $mongoUpdatedData, $mysqlData, $mongoData){
        $this->mapping = [];
        $this->UPID = [];
        $this->groupID = [];
        //convert UPID into UID
        $userObject = new User();

        isset($mysqlData['updated_by']) ? $updatedByField = ['updatedBy' => ($updatedBy = $mysqlData['updated_by']) ?? false ? $userObject->getFromGlobal($updatedBy, $updatedBy) : 'script'] : $updatedByField = ['updatedBy' => 'script'];

        if ($classAndSectionChange = $mongoUpdatedData['classAndSectionChange'] ?? false) {
            
            //UserManagement
            $orgRelatedIDs = $userObject->getOrgAndRelatedIDsByOrgID($mongoData['orgID']);
            if($mysqlData['category'] == 'TEACHER'){
                $role = $this->portConfig['role']['teacher'];
            } else if($mysqlData['category'] == 'ADMIN'){
                $role = $this->portConfig['role']['admin'];
            } else if($mysqlData['category'] == 'STUDENT'){
                $role = $this->portConfig['role']['student'];
            }
            
            $classAndSectionChange['schoolCode'] = $orgRelatedIDs['oldOrgID'];
            $key = array_search($this->defaultMathPID, array_column($mongoData['userProducts'], 'PID'));
            $pValue = $mongoData['userProducts'][$key];
            $userProductUpdateData = [];

            $this->UPID = $UPID = $pValue['UPID'];
            $activeGroups = $pValue['activeGroups'];
            $inactiveGroups = $pValue['inactiveGroups'];

            $sectionPSBAndGroupResult = $userObject->checkAndCreateSectionPSBAndGroup($classAndSectionChange, $pValue['PID']);

            $oldActiveBatch = $pValue['batch']['ActiveBatch'] ?? [];
            //checks if the new group is present
            if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false) {

                $groupMembers = $groupDetails['members'] ?? [];

                $this->groupID = $groupID = $groupDetails['groupID'];

                if ($roleAndMembers = $groupMembers[$role] ?? false) {
                    array_push($roleAndMembers, $UPID);
                    $groupMembers[$role] = $roleAndMembers;
                } else {
                    $groupMembers[$role][] = $UPID;
                }

                $groupMembers = $userObject->getUniqeMembers($groupMembers);

                $tmpGroupDetails = array_merge(['members' => $groupMembers], $updatedByField);
                $this->mongoGroupsObject->update(['groupID' => $groupID], $tmpGroupDetails);

                $activeBatch = [
                    "grade" => $classAndSectionChange['class'],
                    "section" => $classAndSectionChange['section'],
                    "groupID" => $groupID,
                ];

                $userProductUpdateData['batch']['ActiveBatch'] = array_merge($oldActiveBatch, $activeBatch);


                $userProductUpdateData['activeGroups'][] = ["groupID" => $groupID, "role" => $role, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"];
                // array_map(function (&$v) {$v['status'] = 'D';}, $activeGroups);

                // $userProductUpdateData['inactiveGroups'] = array_merge($inactiveGroups, $activeGroups);

                $userProductUpdateData = array_merge($userProductUpdateData, $updatedByField);

                $this->mongoUserProductObject->update(['UPID' => $UPID], $userProductUpdateData);

                $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                if (!empty($oldActiveGroupIDs)) {
                    foreach ($oldActiveGroupIDs as $oldGroupID) {
                        $userObject->removeUserFromGroup($oldGroupID, $UPID, $role);
                    }
                }

                $userObject->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $pValue['PID'], $groupID);
                $this->log[ $this->UPID ]['UserManagement'] = 'done';

            }

            //call all APIs to archive
            //UserAuthentication
            $paramsForUserAuthenticationAPI = array("classUpgradeType" => "TYPE1","UIDs[]"=>$data['UID'],"oldClass"=>$oldActiveBatch['grade'],"newClass"=>$classAndSectionChange['class']);
            $urlAPI = $this->upgradeConfig['UAClassUpgrade'];
            $userAuthenticationAPI = $this->callAPI($paramsForUserAuthenticationAPI,$urlAPI);
            $this->log[ $this->UPID ]['UserAuthentication'] = $userAuthenticationAPI['resultMessage'];
         

            //CommunicationService 
            $paramsForCommunicationServiceAPI = array("classUpgradeType" => "TYPE1","UPIDs[]"=>$this->UPID);
            $urlAPI = $this->upgradeConfig['CSClassUpgrade'];
            $communicationServiceAPI = $this->callAPI($paramsForCommunicationServiceAPI,$urlAPI);
            $this->log[ $this->UPID ]['CommunicationService'] = $communicationServiceAPI['resultMessage'];


            //NotificationService 
            $paramsForNotificationServiceAPI = array("classUpgradeType" => "TYPE1","UPIDs[]"=>$this->UPID);
            $urlAPI = $this->upgradeConfig['NSClassUpgrade'];            
            $notificationService = $this->callAPI($paramsForNotificationServiceAPI,$urlAPI);
            $this->log[ $this->UPID ]['NotificationService'] = $notificationService['resultMessage'];
           

            //ReportingService 
            $paramsForReportingServiceAPI = array("classUpgradeType" => "TYPE1","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "newClass" => $classAndSectionChange['class'] );
            $urlAPI = $this->upgradeConfig['RSClassUpgrade'];
            $reportingService = $this->callAPI($paramsForReportingServiceAPI,$urlAPI);
            $this->log[ $this->UPID ]['ReportingService'] = $reportingService['resultMessage']??"";
           

            //PedagogyService @TODO: Vini has to verify
            $paramsForPedagogyServiceAPI = array("classUpgradeType" => "TYPE1","UPIDs[]"=>$this->UPID, "newClass" => $classAndSectionChange['class'] );
            $urlAPI = $this->upgradeConfig['PSClassUpgrade'];
            $pedagogyService = $this->callAPI($paramsForPedagogyServiceAPI,$urlAPI);
            $this->log[ $this->UPID ]['PedagogyService'] = $pedagogyService['resultMessage'];
          

            //RewardEngine @TODO: Prasanth has to verify
            $paramsForRewardEngineAPI = array("classUpgradeType" => "TYPE1","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
            $urlAPI = $this->upgradeConfig['REClassUpgrade'];
            $rewardEngine = $this->callAPI($paramsForRewardEngineAPI,$urlAPI);
            $this->log[ $this->UPID ]['RewardEngine'] = $rewardEngine['resultMessage'];            
            
        }
        print_r( $this->log);
        
    }
    //Same school, new section but same class, no academic year change
    public function updateType2($data, $mongoUpdatedData, $mysqlData, $mongoData){
        $this->mapping = [];
        $this->UPID = [];
        $this->groupID = [];
        //convert UPID into UID
        $userObject = new User();
        isset($mysqlData['updated_by']) ? $updatedByField = ['updatedBy' => ($updatedBy = $mysqlData['updated_by']) ?? false ? $userObject->getFromGlobal($updatedBy, $updatedBy) : 'script'] : $updatedByField = ['updatedBy' => 'script'];
        //check only for section change 
        if ($classAndSectionChange = $mongoUpdatedData['classAndSectionChange'] ?? false) {
            $orgRelatedIDs = $userObject->getOrgAndRelatedIDsByOrgID($mongoData['orgID']);
            if($mysqlData['category'] == 'TEACHER'){
                $role = $this->portConfig['role']['teacher'];
            } else if($mysqlData['category'] == 'ADMIN'){
                $role = $this->portConfig['role']['admin'];
            } else if($mysqlData['category'] == 'STUDENT'){
                $role = $this->portConfig['role']['student'];
            }

            $classAndSectionChange['schoolCode'] = $orgRelatedIDs['oldOrgID'];
            $key = array_search($this->defaultMathPID, array_column($mongoData['userProducts'], 'PID'));
            $pValue = $mongoData['userProducts'][$key];
            $userProductUpdateData = [];

            $this->UPID = $UPID =  $pValue['UPID'];

            $activeGroups = $pValue['activeGroups'];
            $inactiveGroups = $pValue['inactiveGroups'];

            $sectionPSBAndGroupResult = $userObject->checkAndCreateSectionPSBAndGroup($classAndSectionChange, $pValue['PID']);

            $oldActiveBatch = $pValue['batch']['ActiveBatch'] ?? [];

            if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false){

                $groupMembers = $groupDetails['members'] ?? [];
                $this->groupID = $groupID = $groupDetails['groupID'];

                if ($roleAndMembers = $groupMembers[$role] ?? false) {
                    array_push($roleAndMembers, $UPID);
                    $groupMembers[$role] = $roleAndMembers;
                } else {
                    $groupMembers[$role][] = $UPID;
                }

                $groupMembers = $userObject->getUniqeMembers($groupMembers);

                $tmpGroupDetails = array_merge(['members' => $groupMembers], $updatedByField);

                $this->mongoGroupsObject->update(['groupID' => $groupID], $tmpGroupDetails);

                $activeBatch = [
                    "grade" => $classAndSectionChange['class'],
                    "section" => $classAndSectionChange['section'],
                    "groupID" => $groupID,
                ];

                $userProductUpdateData['batch']['ActiveBatch'] = array_merge($oldActiveBatch, $activeBatch);
                $userProductUpdateData['activeGroups'][] = ["groupID" => $groupID, "role" => $role, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"];

                // array_map(function (&$v) {$v['status'] = 'D';}, $activeGroups);

                // $userProductUpdateData['inactiveGroups'] = array_merge($inactiveGroups, $activeGroups);

                $userProductUpdateData = array_merge($userProductUpdateData, $updatedByField);

                $this->mongoUserProductObject->update(['UPID' => $UPID], $userProductUpdateData);

                $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                if (!empty($oldActiveGroupIDs)) {
                    foreach ($oldActiveGroupIDs as $oldGroupID) {
                        $userObject->removeUserFromGroup($oldGroupID, $UPID, $role);
                    }
                }

                $userObject->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $pValue['PID'], $groupID);
                $this->log[ $this->UPID ]['UserManagement'] = 'done';
            }

            //call all APIs to archive

            //UserAuthentication
            $paramsForUserAuthenticationAPI = array("classUpgradeType" => "TYPE2","UIDs[]"=>$data['UID'],"oldClass"=>$oldActiveBatch['grade'],"newClass"=>$classAndSectionChange['class']);
            $urlAPI = $this->upgradeConfig['UAClassUpgrade'];
            $userAuthenticationAPI = $this->callAPI($paramsForUserAuthenticationAPI,$urlAPI);
            $this->log[ $this->UPID ]['UserAuthentication'] = $userAuthenticationAPI['resultMessage'];
            

             //CommunicationService 
            $paramsForCommunicationServiceAPI = array("classUpgradeType" => "TYPE2","UPIDs[]"=>$this->UPID);
            $urlAPI = $this->upgradeConfig['CSClassUpgrade'];
            $communicationServiceAPI = $this->callAPI($paramsForCommunicationServiceAPI,$urlAPI);
            $this->log[ $this->UPID ]['UserAuthentication'] = $userAuthenticationAPI['resultMessage'];
           
            

            //NotificationService 
            $paramsForNotificationServiceAPI = array("classUpgradeType" => "TYPE2","UPIDs[]"=>$this->UPID);
            $urlAPI = $this->upgradeConfig['NSClassUpgrade'];
            $notificationService = $this->callAPI($paramsForNotificationServiceAPI,$urlAPI);
            $this->log[ $this->UPID ]['NotificationService'] = $notificationService['resultMessage'];
           

            //ReportingService 
            $paramsForReportingServiceAPI = array("classUpgradeType" => "TYPE2","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'], "oldGroupID" => $activeGroups[0]['groupID'] );
            $urlAPI = $this->upgradeConfig['RSClassUpgrade'];
            $reportingService = $this->callAPI($paramsForReportingServiceAPI,$urlAPI);
            $this->log[ $this->UPID ]['ReportingService'] = $reportingService['resultMessage']??"";
           

            //PedagogyService @TODO: Vini has to verify
            $paramsForPedagogyServiceAPI = array("classUpgradeType" => "TYPE2","UPIDs[]"=>$this->UPID, "newClass" => $classAndSectionChange['class'] );
            $urlAPI = $this->upgradeConfig['PSClassUpgrade'];
            $pedagogyService = $this->callAPI($paramsForPedagogyServiceAPI,$urlAPI);
            $this->log[ $this->UPID ]['PedagogyService'] = $pedagogyService['resultMessage'];
           

            //RewardEngine @TODO: Prasanth has to verify
            $paramsForRewardEngineAPI = array("classUpgradeType" => "TYPE2","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
            $urlAPI = $this->upgradeConfig['REClassUpgrade'];
            $rewardEngine = $this->callAPI($paramsForRewardEngineAPI,$urlAPI);
            $this->log[ $this->UPID ]['RewardEngine'] = $rewardEngine['resultMessage'];
           
        }

        print_r( $this->log);
    }
    //New school, same class (6)
    public function updateType3($data, $mongoUpdatedData, $mysqlData, $mongoData){

        $this->mapping = [];
        $this->UPID = [];
        $this->groupID = [];
        //convert UPID into UID
        $userObject = new User();
        isset($mysqlData['updated_by']) ? $updatedByField = ['updatedBy' => ($updatedBy = $mysqlData['updated_by']) ?? false ? $userObject->getFromGlobal($updatedBy, $updatedBy) : 'script'] : $updatedByField = ['updatedBy' => 'script'];
        if ($organizationChange = $mongoUpdatedData['organizationChange'] ?? false) {
            //do operations for organization change
            //echo "<pre>";print_r($mysqlData);die;
            //obtain orgID for the newOrg
            if ($orgDetails = $this->mongoOrgObject->find(["oldOrgID" => $organizationChange['newOrgID']]) ?? false) {                
                $orgID = $orgDetails['orgID'];
                $data = ["orgID" => $orgID, "UID" => $mongoData['UID'] ];
                //new orgID updated in user details
                $this->updateOrgID( $data );
                $orgRelatedIDs = $userObject->getOrgAndRelatedIDsByOrgID($orgID);
                if($mysqlData['category'] == 'TEACHER'){
                    $role = $this->portConfig['role']['teacher'];
                } else if($mysqlData['category'] == 'ADMIN'){
                    $role = $this->portConfig['role']['admin'];
                } else if($mysqlData['category'] == 'STUDENT'){
                    $role = $this->portConfig['role']['student'];
                }

                $organizationChange['schoolCode'] = $orgRelatedIDs['oldOrgID'];
                $organizationChange['class'] = $mysqlData['class'];
                $organizationChange['section'] = $mysqlData['section'];

                $key = array_search($this->defaultMathPID, array_column($mongoData['userProducts'], 'PID'));
                $pValue = $mongoData['userProducts'][$key];

                $userProductUpdateData = [];

                $this->UPID = $UPID = $pValue['UPID'];

                $activeGroups = $pValue['activeGroups'];
                $inactiveGroups = $pValue['inactiveGroups'];
                

                $sectionPSBAndGroupResult = $userObject->checkAndCreateSectionPSBAndGroup($organizationChange, $pValue['PID']);

                $oldActiveBatch = $pValue['batch']['ActiveBatch'] ?? [];

                if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false) {
                    $groupMembers = $groupDetails['members'] ?? [];
                    $this->groupID = $groupID = $groupDetails['groupID'];

                    if ($roleAndMembers = $groupMembers[$role] ?? false) {
                        array_push($roleAndMembers, $UPID);
                        $groupMembers[$role] = $roleAndMembers;
                    } else {
                        $groupMembers[$role][] = $UPID;
                    }

                    $groupMembers = $userObject->getUniqeMembers($groupMembers);

                    $tmpGroupDetails = array_merge(['members' => $groupMembers], $updatedByField);

                    $this->mongoGroupsObject->update(['groupID' => $groupID], $tmpGroupDetails);

                    $activeBatch = [
                        "grade" => $organizationChange['class'],
                        "section" => $organizationChange['section'],
                        "groupID" => $groupID,
                    ];

                    $userProductUpdateData['batch']['ActiveBatch'] = array_merge($oldActiveBatch, $activeBatch);
                    $userProductUpdateData['activeGroups'][] = ["groupID" => $groupID, "role" => $role, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"];

                    // array_map(function (&$v) {$v['status'] = 'D';}, $activeGroups);

                    // $userProductUpdateData['inactiveGroups'] = array_merge($inactiveGroups, $activeGroups);

                    $userProductUpdateData = array_merge($userProductUpdateData, $updatedByField);

                    $this->mongoUserProductObject->update(['UPID' => $UPID], $userProductUpdateData);

                    $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                    if (!empty($oldActiveGroupIDs)) {
                        foreach ($oldActiveGroupIDs as $oldGroupID) {
                            $userObject->removeUserFromGroup($oldGroupID, $UPID, $role);
                        }
                    }

                    $userObject->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $pValue['PID'], $groupID);
                    $this->log[ $this->UPID ]['UserManagement'] = 'done';

                }
                
                $returnData = true;

                // update User product orderID 
                $this->updateUserProductOrderID($UPID,$orgID);

                //call all APIs to archive

                //UserAuthentication 
                $paramsForUserAuthenticationAPI = array("classUpgradeType" => "TYPE3","UIDs[]"=>$data['UID'],"oldClass"=>$oldActiveBatch['grade'],"newClass"=>$organizationChange['class']);
                $urlAPI = $this->upgradeConfig['UAClassUpgrade'];
                $userAuthenticationAPI = $this->callAPI($paramsForUserAuthenticationAPI,$urlAPI);
                $this->log[ $this->UPID ]['UserAuthentication'] = $userAuthenticationAPI['resultMessage'];
                

                //CommunicationService 
                $paramsForCommunicationServiceAPI = array("classUpgradeType" => "TYPE3","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['CSClassUpgrade'];
                $communicationServiceAPI = $this->callAPI($paramsForCommunicationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['CommunicationService'] = $communicationServiceAPI['resultMessage'];
               

                //NotificationService 
                $paramsForNotificationServiceAPI = array("classUpgradeType" => "TYPE3","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['NSClassUpgrade'];
                $notificationService = $this->callAPI($paramsForNotificationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['NotificationService'] = $notificationService['resultMessage'];
              

                //ReportingService 
                $paramsForReportingServiceAPI = array("classUpgradeType" => "TYPE3","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $organizationChange['class'],"oldGroupID" => $activeGroups[0]['groupID'] );
                $urlAPI = $this->upgradeConfig['RSClassUpgrade'];
                $reportingService = $this->callAPI($paramsForReportingServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['ReportingService'] = $reportingService['resultMessage'];

                //PedagogyService @TODO: Vini has to verify
                $paramsForPedagogyServiceAPI = array("classUpgradeType" => "TYPE3","UPIDs[]"=>$this->UPID, "newClass" => $organizationChange['class'] );
                $urlAPI = $this->upgradeConfig['PSClassUpgrade'];
                $pedagogyService = $this->callAPI($paramsForPedagogyServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['PedagogyService'] = $pedagogyService['resultMessage'];

                //RewardEngine @TODO: Prasanth has to verify
                $paramsForRewardEngineAPI = array("classUpgradeType" => "TYPE3","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $organizationChange['class'] );
                $urlAPI = $this->upgradeConfig['REClassUpgrade'];
                $rewardEngine = $this->callAPI($paramsForRewardEngineAPI,$urlAPI);
                $this->log[ $this->UPID ]['RewardEngine'] = $rewardEngine['resultMessage'];

            } 
            print_r( $this->log);
        }

    }

    //New school, new class (9)
    public function updateType4($data, $mongoUpdatedData, $mysqlData, $mongoData){
        $this->UPID = [];
        $this->groupID = [];
        //convert UPID into UID
        $userObject = new User();
        isset($mysqlData['updated_by']) ? $updatedByField = ['updatedBy' => ($updatedBy = $mysqlData['updated_by']) ?? false ? $userObject->getFromGlobal($updatedBy, $updatedBy) : 'script'] : $updatedByField = ['updatedBy' => 'script'];
        if (($organizationChange = $mongoUpdatedData['organizationChange'] && $classAndSectionChange = $mongoUpdatedData['classAndSectionChange']) ?? false) {
            //do operations for organization change
            $organizationChange = $mongoUpdatedData['organizationChange'];
            $classAndSectionChange = $mongoUpdatedData['classAndSectionChange'];

            //check if the new ORGID is present in database
            if ($orgDetails = $this->mongoOrgObject->find(["oldOrgID" => $organizationChange['newOrgID']]) ?? false) {
                $orgID = $orgDetails['orgID'];
                $data = ["orgID" => $orgID, "UID" => $mongoData['UID'] ];
                //new orgID updated in user details
                $this->updateOrgID( $data );
                $orgRelatedIDs = $userObject->getOrgAndRelatedIDsByOrgID($orgID);
                $role = $this->portConfig['role']['student'];

                $classAndSectionChange['schoolCode'] = $orgRelatedIDs['oldOrgID'];

                $key = array_search($this->defaultMathPID, array_column($mongoData['userProducts'], 'PID'));
                $pValue = $mongoData['userProducts'][$key];
                $userProductUpdateData = [];

                $this->UPID = $UPID = $pValue['UPID'];

                $activeGroups = $pValue['activeGroups'];
                $inactiveGroups = $pValue['inactiveGroups'];
                

                $sectionPSBAndGroupResult = $userObject->checkAndCreateSectionPSBAndGroup($classAndSectionChange, $pValue['PID']);

                $oldActiveBatch = $pValue['batch']['ActiveBatch'] ?? [];

                if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false) {
                    $groupMembers = $groupDetails['members'] ?? [];
                    $this->groupID = $groupID = $groupDetails['groupID'];

                    if ($roleAndMembers = $groupMembers[$role] ?? false) {
                        array_push($roleAndMembers, $UPID);
                        $groupMembers[$role] = $roleAndMembers;
                    } else {
                        $groupMembers[$role][] = $UPID;
                    }

                    $groupMembers = $userObject->getUniqeMembers($groupMembers);

                    $tmpGroupDetails = array_merge(['members' => $groupMembers], $updatedByField);

                    $this->mongoGroupsObject->update(['groupID' => $groupID], $tmpGroupDetails);

                    $activeBatch = [
                        "grade" => $classAndSectionChange['class'],
                        "section" => $classAndSectionChange['section'],
                        "groupID" => $groupID,
                    ];

                    $userProductUpdateData['batch']['ActiveBatch'] = array_merge($oldActiveBatch, $activeBatch);
                    $userProductUpdateData['activeGroups'][] = ["groupID" => $groupID, "role" => $role, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"];

                    // array_map(function (&$v) {$v['status'] = 'D';}, $activeGroups);

                    // $userProductUpdateData['inactiveGroups'] = array_merge($inactiveGroups, $activeGroups);

                    $userProductUpdateData = array_merge($userProductUpdateData, $updatedByField);

                    $this->mongoUserProductObject->update(['UPID' => $UPID], $userProductUpdateData);

                    $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                    if (!empty($oldActiveGroupIDs)) {
                        foreach ($oldActiveGroupIDs as $oldGroupID) {
                            $userObject->removeUserFromGroup($oldGroupID, $UPID, $role);
                        }
                    }

                    $userObject->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $pValue['PID'], $groupID);
                    $this->log[ $this->UPID ]['UserManagement'] = 'done';

                }                
                $returnData = true;


                // update User product orderID 
                $this->updateUserProductOrderID($UPID,$orgID);

                //call all APIs to archive

                //UserAuthentication
                $paramsForUserAuthenticationAPI = array("classUpgradeType" => "TYPE4","UIDs[]"=>$data['UID'],"oldClass"=>$oldActiveBatch['grade'],"newClass"=>$classAndSectionChange['class']);
                $urlAPI = $this->upgradeConfig['UAClassUpgrade'];
                $userAuthenticationAPI = $this->callAPI($paramsForUserAuthenticationAPI,$urlAPI);
                $this->log[ $this->UPID ]['UserAuthentication'] = $userAuthenticationAPI['resultMessage'];

                //CommunicationService 
                $paramsForCommunicationServiceAPI = array("classUpgradeType" => "TYPE4","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['CSClassUpgrade'];
                $communicationServiceAPI = $this->callAPI($paramsForCommunicationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['CommunicationService'] = $communicationServiceAPI['resultMessage'];

                //NotificationService 
                $paramsForNotificationServiceAPI = array("classUpgradeType" => "TYPE4","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['NSClassUpgrade'];
                $notificationService = $this->callAPI($paramsForNotificationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['NotificationService'] = $notificationService['resultMessage'];

                //ReportingService 
                $paramsForReportingServiceAPI = array("classUpgradeType" => "TYPE4","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
                $urlAPI = $this->upgradeConfig['RSClassUpgrade'];
                $reportingService = $this->callAPI($paramsForReportingServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['ReportingService'] = $reportingService['resultMessage'];


                //PedagogyService @TODO: Vini has to verify
                $paramsForPedagogyServiceAPI = array("classUpgradeType" => "TYPE4","UPIDs[]"=>$this->UPID, "newClass" => $classAndSectionChange['class'] );
                $urlAPI = $this->upgradeConfig['PSClassUpgrade'];
                $pedagogyService = $this->callAPI($paramsForPedagogyServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['PedagogyService'] = $pedagogyService['resultMessage'];


                //RewardEngine @TODO: Prasanth has to verify
                $paramsForRewardEngineAPI = array("classUpgradeType" => "TYPE4","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
                $urlAPI = $this->upgradeConfig['REClassUpgrade'];
                $rewardEngine = $this->callAPI($paramsForRewardEngineAPI,$urlAPI);
                $this->log[ $this->UPID ]['RewardEngine'] = $rewardEngine['resultMessage'];
            } 
            print_r( $this->log);
        }

    }

    //Subscription mode changed school=>retail or retail=>school, same class (8)
    public function updateType5($data, $mongoUpdatedData, $mysqlData, $mongoData){
        $this->UPID = [];
        $this->groupID = [];
        //echo "<pre>";print_r($mongoData);die;

        //convert UPID into UID
        $userObject = new User();
        isset($mysqlData['updated_by']) ? $updatedByField = ['updatedBy' => ($updatedBy = $mysqlData['updated_by']) ?? false ? $userObject->getFromGlobal($updatedBy, $updatedBy) : 'script'] : $updatedByField = ['updatedBy' => 'script'];
        if (($mongoUpdatedData['subscriptionModeChange'] && $organizationChange = $mongoUpdatedData['organizationChange']) ?? false) {
            $organizationChange = $mongoUpdatedData['organizationChange'];
            if ($orgDetails = $this->mongoOrgObject->find(["oldOrgID" => $organizationChange['newOrgID']]) ?? false) {
                //obtain orgID for retail school in MS
                $orgID = $orgDetails['orgID'];
                $data = ["orgID" => $orgID, "UID" => $mongoData['UID'] ];
                //new orgID updated in user details
                $this->updateOrgID($data);
                $orgRelatedIDs = $userObject->getOrgAndRelatedIDsByOrgID($orgID);
                $role = $this->portConfig['role']['student'];

                $organizationChange['schoolCode'] = $orgRelatedIDs['oldOrgID'];
                $organizationChange['class'] = $mysqlData['class'];
                $organizationChange['section'] = $mysqlData['section'];

                $key = array_search($this->defaultMathPID, array_column($mongoData['userProducts'], 'PID'));
                $pValue = $mongoData['userProducts'][$key];
                $userProductUpdateData = [];

                $this->UPID =  $UPID = $pValue['UPID'];

                $activeGroups = $pValue['activeGroups'];
                $inactiveGroups = $pValue['inactiveGroups'];
                

                $sectionPSBAndGroupResult = $userObject->checkAndCreateSectionPSBAndGroup($organizationChange, $pValue['PID']);

                $oldActiveBatch = $pValue['batch']['ActiveBatch'] ?? [];

                if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false) {
                    $groupMembers = $groupDetails['members'] ?? [];
                    $this->groupID = $groupID = $groupDetails['groupID'];

                    if ($roleAndMembers = $groupMembers[$role] ?? false) {
                        array_push($roleAndMembers, $UPID);
                        $groupMembers[$role] = $roleAndMembers;
                    } else {
                        $groupMembers[$role][] = $UPID;
                    }

                    $groupMembers = $userObject->getUniqeMembers($groupMembers);

                    $tmpGroupDetails = array_merge(['members' => $groupMembers], $updatedByField);

                    $this->mongoGroupsObject->update(['groupID' => $groupID], $tmpGroupDetails);

                    $activeBatch = [
                        "grade" => $organizationChange['class'],
                        "section" => $organizationChange['section'],
                        "groupID" => $groupID,
                    ];

                    $userProductUpdateData['batch']['ActiveBatch'] = array_merge($oldActiveBatch, $activeBatch);
                    $userProductUpdateData['activeGroups'][] = ["groupID" => $groupID, "role" => $role, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"];

                    // array_map(function (&$v) {$v['status'] = 'D';}, $activeGroups);

                    // $userProductUpdateData['inactiveGroups'] = array_merge($inactiveGroups, $activeGroups);

                    $userProductUpdateData = array_merge($userProductUpdateData, $updatedByField);

                    $this->mongoUserProductObject->update(['UPID' => $UPID], $userProductUpdateData);

                    $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                    if (!empty($oldActiveGroupIDs)) {
                        foreach ($oldActiveGroupIDs as $oldGroupID) {
                            $userObject->removeUserFromGroup($oldGroupID, $UPID, $role);
                        }
                    }

                    $userObject->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $pValue['PID'], $groupID);

                }

                
                
                $this->log[ $this->UPID ]['UserManagement'] = 'done';

                // updateUserOrgTag
                $this->updateUserOrgTag($mongoUpdatedData,$UPID);

                // update User product orderID 
                $this->updateUserProductOrderID($UPID,$orgID);

                //call all APIs to archive

                //UserAuthentication 
                $paramsForUserAuthenticationAPI = array("classUpgradeType" => "TYPE5","UIDs[]"=>$data['UID'],"oldClass"=>$oldActiveBatch['grade'],"newClass"=>$organizationChange['class']);
                $urlAPI = $this->upgradeConfig['UAClassUpgrade'];
                $userAuthenticationAPI = $this->callAPI($paramsForUserAuthenticationAPI,$urlAPI);
                $this->log[ $this->UPID ]['UserAuthentication'] = $userAuthenticationAPI['resultMessage'];

                //CommunicationService 
                $paramsForCommunicationServiceAPI = array("classUpgradeType" => "TYPE5","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['CSClassUpgrade'];
                $communicationServiceAPI = $this->callAPI($paramsForCommunicationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['CommunicationService'] = $communicationServiceAPI['resultMessage'];

                //NotificationService 
                $paramsForNotificationServiceAPI = array("classUpgradeType" => "TYPE5","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['NSClassUpgrade'];
                $notificationService = $this->callAPI($paramsForNotificationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['NotificationService'] = $notificationService['resultMessage'];

                //ReportingService 
                $paramsForReportingServiceAPI = array("classUpgradeType" => "TYPE5","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $organizationChange['class'],"oldGroupID" => $activeGroups[0]['groupID'] );
                $urlAPI = $this->upgradeConfig['RSClassUpgrade'];
                $reportingService = $this->callAPI($paramsForReportingServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['ReportingService'] = $reportingService['resultMessage'];

                //PedagogyService @TODO: Vini has to verify
                $paramsForPedagogyServiceAPI = array("classUpgradeType" => "TYPE5","UPIDs[]"=>$this->UPID, "newClass" => $organizationChange['class'] );
                $urlAPI = $this->upgradeConfig['PSClassUpgrade'];
                $pedagogyService = $this->callAPI($paramsForPedagogyServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['PedagogyService'] = $pedagogyService['resultMessage'];

                //RewardEngine @TODO: Prasanth has to verify
                $paramsForRewardEngineAPI = array("classUpgradeType" => "TYPE5","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $organizationChange['class'] );
                $urlAPI = $this->upgradeConfig['REClassUpgrade'];
                $rewardEngine = $this->callAPI($paramsForRewardEngineAPI,$urlAPI);
                $this->log[ $this->UPID ]['RewardEngine'] = $rewardEngine['resultMessage'];
             }
             print_r( $this->log);
        }
    }
    //Subscription mode changed school=>retail or retail=>school, new class (10)
    public function updateType6($data, $mongoUpdatedData, $mysqlData, $mongoData){
        $this->UPID = [];
        $this->groupID = [];
        //convert UPID into UID
        $userObject = new User();
        isset($mysqlData['updated_by']) ? $updatedByField = ['updatedBy' => ($updatedBy = $mysqlData['updated_by']) ?? false ? $userObject->getFromGlobal($updatedBy, $updatedBy) : 'script'] : $updatedByField = ['updatedBy' => 'script'];
        if (($organizationChange = $mongoUpdatedData['organizationChange'] && $classAndSectionChange = $mongoUpdatedData['classAndSectionChange']) ?? false) {
            //do operations for organization change
            $mongoUpdatedData['organizationChange'] ? $organizationChange = $mongoUpdatedData['organizationChange'] : NULL ;
            $mongoUpdatedData['classAndSectionChange'] ? $classAndSectionChange = $mongoUpdatedData['classAndSectionChange'] : NULL;

            //check if the new ORGID is present in database
            if ($orgDetails = $this->mongoOrgObject->find(["oldOrgID" => $organizationChange['newOrgID']]) ?? false) {
                $orgID = $orgDetails['orgID'];
                $data = ["orgID" => $orgID, "UID" => $mongoData['UID'] ];
                //new orgID updated in user details
                $this->updateOrgID( $data );
                $orgRelatedIDs = $userObject->getOrgAndRelatedIDsByOrgID($orgID);
                if($mysqlData['category'] == 'TEACHER'){
                    $role = $this->portConfig['role']['teacher'];
                } else if($mysqlData['category'] == 'ADMIN'){
                    $role = $this->portConfig['role']['admin'];
                } else if($mysqlData['category'] == 'STUDENT'){
                    $role = $this->portConfig['role']['student'];
                }

                $classAndSectionChange['schoolCode'] = $orgRelatedIDs['oldOrgID'];

                $key = array_search($this->defaultMathPID, array_column($mongoData['userProducts'], 'PID'));
                $pValue = $mongoData['userProducts'][$key];
                $userProductUpdateData = [];

                $this->UPID = $UPID = $pValue['UPID'];

                $activeGroups = $pValue['activeGroups'];
                $inactiveGroups = $pValue['inactiveGroups'];
                

                $sectionPSBAndGroupResult = $userObject->checkAndCreateSectionPSBAndGroup($classAndSectionChange, $pValue['PID']);

                $oldActiveBatch = $pValue['batch']['ActiveBatch'] ?? [];

                if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false) {
                    $groupMembers = $groupDetails['members'] ?? [];
                    $this->groupID = $groupID = $groupDetails['groupID'];

                    if ($roleAndMembers = $groupMembers[$role] ?? false) {
                        array_push($roleAndMembers, $UPID);
                        $groupMembers[$role] = $roleAndMembers;
                    } else {
                        $groupMembers[$role][] = $UPID;
                    }

                    $groupMembers = $userObject->getUniqeMembers($groupMembers);

                    $tmpGroupDetails = array_merge(['members' => $groupMembers], $updatedByField);

                    $this->mongoGroupsObject->update(['groupID' => $groupID], $tmpGroupDetails);

                    $activeBatch = [
                        "grade" => $classAndSectionChange['class'],
                        "section" => $classAndSectionChange['section'],
                        "groupID" => $groupID,
                    ];

                    $userProductUpdateData['batch']['ActiveBatch'] = array_merge($oldActiveBatch, $activeBatch);
                    $userProductUpdateData['activeGroups'][] = ["groupID" => $groupID, "role" => $role, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"];

                    // array_map(function (&$v) {$v['status'] = 'D';}, $activeGroups);

                    // $userProductUpdateData['inactiveGroups'] = array_merge($inactiveGroups, $activeGroups);

                    $userProductUpdateData = array_merge($userProductUpdateData, $updatedByField);

                    $this->mongoUserProductObject->update(['UPID' => $UPID], $userProductUpdateData);

                    $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                    if (!empty($oldActiveGroupIDs)) {
                        foreach ($oldActiveGroupIDs as $oldGroupID) {
                            $userObject->removeUserFromGroup($oldGroupID, $UPID, $role);
                        }
                    }

                    $userObject->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $pValue['PID'], $groupID);

                }


                
                $this->log[ $this->UPID ]['UserManagement'] = 'done';

                $this->updateUserOrgTag($mongoUpdatedData,$UPID);
                //call all APIs to archive

                // updateUserOrgTag
                $this->updateUserOrgTag($mongoUpdatedData,$UPID);

                // update User product orderID 
                $this->updateUserProductOrderID($UPID,$orgID);

                 //call all APIs to archive
                 
                //UserAuthentication
                $paramsForUserAuthenticationAPI = array("classUpgradeType" => "TYPE6","UIDs[]"=>$data['UID'],"oldClass"=>$oldActiveBatch['grade'],"newClass"=>$classAndSectionChange['class']);
                $urlAPI = $this->upgradeConfig['UAClassUpgrade'];
                $userAuthenticationAPI = $this->callAPI($paramsForUserAuthenticationAPI,$urlAPI);
                $this->log[ $this->UPID ]['UserAuthentication'] = $userAuthenticationAPI['resultMessage'];


                //CommunicationService 
                $paramsForCommunicationServiceAPI = array("classUpgradeType" => "TYPE6","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['CSClassUpgrade'];
                $communicationServiceAPI = $this->callAPI($paramsForCommunicationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['CommunicationService'] = $communicationServiceAPI['resultMessage'];


                //NotificationService 
                $paramsForNotificationServiceAPI = array("classUpgradeType" => "TYPE6","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['NSClassUpgrade'];
                $notificationService = $this->callAPI($paramsForNotificationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['NotificationService'] = $notificationService['resultMessage'];


                //ReportingService 
                $paramsForReportingServiceAPI = array("classUpgradeType" => "TYPE6","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
                $urlAPI = $this->upgradeConfig['RSClassUpgrade'];
                $reportingService = $this->callAPI($paramsForReportingServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['ReportingService'] = $reportingService['resultMessage'];


                //PedagogyService @TODO: Vini has to verify
                $paramsForPedagogyServiceAPI = array("classUpgradeType" => "TYPE6","UPIDs[]"=>$this->UPID, "newClass" => $classAndSectionChange['class'] );
                $urlAPI = $this->upgradeConfig['PSClassUpgrade'];
                $pedagogyService = $this->callAPI($paramsForPedagogyServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['PedagogyService'] = $pedagogyService['resultMessage'];


                //RewardEngine @TODO: Prasanth has to verify
                $paramsForRewardEngineAPI = array("classUpgradeType" => "TYPE6","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
                $urlAPI = $this->upgradeConfig['REClassUpgrade'];
                $rewardEngine = $this->callAPI($paramsForRewardEngineAPI,$urlAPI);
                $this->log[ $this->UPID ]['RewardEngine'] = $rewardEngine['resultMessage'];
             
                
            } 
            print_r( $this->log);
        }
    }
    //Same school, new class, academic year changed
    public function updateType7($data, $mongoUpdatedData, $mysqlData, $mongoData){
        $this->UPID = [];
        $this->groupID = [];

        //convert UPID into UID
        $userObject = new User();
        isset($mysqlData['updated_by']) ? $updatedByField = ['updatedBy' => ($updatedBy = $mysqlData['updated_by']) ?? false ? $userObject->getFromGlobal($updatedBy, $updatedBy) : 'script'] : $updatedByField = ['updatedBy' => 'script'];
      //check if this is academic year change 
        if (($mongoUpdatedData['academicYearChange'] == 1 && $mongoUpdatedData['classAndSectionChange'] ) ?? false) {

            $classAndSectionChange = $mongoUpdatedData['classAndSectionChange'];
            //$this->checkAndSyncOrderDetails($mysqlData['schoolCode'],$mongoData['orgID']);
            $createBatchResult = $this->createBatch($mongoData['orgID']);
            $orgRelatedIDs = $userObject->getOrgAndRelatedIDsByOrgID($mongoData['orgID']);
            if($mysqlData['category'] == 'TEACHER'){
                $role = $this->portConfig['role']['teacher'];
            } else if($mysqlData['category'] == 'ADMIN'){
                $role = $this->portConfig['role']['admin'];
            } else if($mysqlData['category'] == 'STUDENT'){
                $role = $this->portConfig['role']['student'];
            }

            $classAndSectionChange['schoolCode'] = $orgRelatedIDs['oldOrgID'];
            $key = array_search($this->defaultMathPID, array_column($mongoData['userProducts'], 'PID'));
            $pValue = $mongoData['userProducts'][$key];
            $userProductUpdateData = [];

            $this->UPID =  $UPID= $pValue['UPID'];
            $activeGroups = $pValue['activeGroups'];
            $inactiveGroups = $pValue['inactiveGroups'];

            $sectionPSBAndGroupResult = $userObject->checkAndCreateSectionPSBAndGroup($classAndSectionChange, $pValue['PID']);

            $oldActiveBatch = $pValue['batch']['ActiveBatch'] ?? [];
            //checks if the new group is present
            if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false) {

                $groupMembers = $groupDetails['members'] ?? [];

                $this->groupID = $groupID = $groupDetails['groupID'];

                if ($roleAndMembers = $groupMembers[$role] ?? false) {
                    array_push($roleAndMembers, $UPID);
                    $groupMembers[$role] = $roleAndMembers;
                } else {
                    $groupMembers[$role][] = $UPID;
                }

                $groupMembers = $userObject->getUniqeMembers($groupMembers);

                $tmpGroupDetails = array_merge(['members' => $groupMembers], $updatedByField);
                $this->mongoGroupsObject->update(['groupID' => $groupID], $tmpGroupDetails);

                $activeBatch = [
                    "grade" => $classAndSectionChange['class'],
                    "section" => $classAndSectionChange['section'],
                    "groupID" => $groupID,
                ];

                $userProductUpdateData['batch']['ActiveBatch'] = array_merge($oldActiveBatch, $activeBatch);


                $userProductUpdateData['activeGroups'][] = ["groupID" => $groupID, "role" => $role, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"];
                // array_map(function (&$v) {$v['status'] = 'D';}, $activeGroups);

                // $userProductUpdateData['inactiveGroups'] = array_merge($inactiveGroups, $activeGroups);

                $userProductUpdateData = array_merge($userProductUpdateData, $updatedByField);

                $this->mongoUserProductObject->update(['UPID' => $UPID], $userProductUpdateData);

                $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                if (!empty($oldActiveGroupIDs)) {
                    foreach ($oldActiveGroupIDs as $oldGroupID) {
                        $userObject->removeUserFromGroup($oldGroupID, $UPID, $role);
                    }
                }

                $userObject->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $pValue['PID'], $groupID);
                $this->log[ $this->UPID ]['UserManagement'] = 'done';
            }
            $returnData = true;

           
            //call all APIs to archive

            //UserAuthentication
            $paramsForUserAuthenticationAPI = array("classUpgradeType" => "TYPE1","UIDs[]"=>$data['UID'],"oldClass"=>$oldActiveBatch['grade'],"newClass"=>$classAndSectionChange['class']);
            $urlAPI = $this->upgradeConfig['UAClassUpgrade'];
            $userAuthenticationAPI = $this->callAPI($paramsForUserAuthenticationAPI,$urlAPI);
            $this->log[ $this->UPID ]['UserAuthentication'] = $userAuthenticationAPI['resultMessage'];

                //CommunicationService 
            $paramsForCommunicationServiceAPI = array("classUpgradeType" => "TYPE1","UPIDs[]"=>$this->UPID);
            $urlAPI = $this->upgradeConfig['CSClassUpgrade'];
            $communicationServiceAPI = $this->callAPI($paramsForCommunicationServiceAPI,$urlAPI);
            $this->log[ $this->UPID ]['CommunicationService'] = $communicationServiceAPI['resultMessage'];

            //NotificationService 
            $paramsForNotificationServiceAPI = array("classUpgradeType" => "TYPE1","UPIDs[]"=>$this->UPID);
            $urlAPI = $this->upgradeConfig['NSClassUpgrade'];
            $notificationService = $this->callAPI($paramsForNotificationServiceAPI,$urlAPI);
            $this->log[ $this->UPID ]['NotificationService'] = $notificationService['resultMessage'];

            //ReportingService 
            $paramsForReportingServiceAPI = array("classUpgradeType" => "TYPE1","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
            $urlAPI = $this->upgradeConfig['RSClassUpgrade'];
            $reportingService = $this->callAPI($paramsForReportingServiceAPI,$urlAPI);
            $this->log[ $this->UPID ]['ReportingService'] = $reportingService['resultMessage'];

            //PedagogyService @TODO: Vini has to verify
            $paramsForPedagogyServiceAPI = array("classUpgradeType" => "TYPE1","UPIDs[]"=>$this->UPID, "newClass" => $classAndSectionChange['class'] );
            $urlAPI = $this->upgradeConfig['PSClassUpgrade'];
            $pedagogyService = $this->callAPI($paramsForPedagogyServiceAPI,$urlAPI);
            $this->log[ $this->UPID ]['PedagogyService'] = $pedagogyService['resultMessage'];

            //RewardEngine @TODO: Prasanth has to verify
            $paramsForRewardEngineAPI = array("classUpgradeType" => "TYPE1","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
            $urlAPI = $this->upgradeConfig['REClassUpgrade'];
            $rewardEngine = $this->callAPI($paramsForRewardEngineAPI,$urlAPI);
            $this->log[ $this->UPID ]['RewardEngine'] = $rewardEngine['resultMessage'];
        }
        print_r( $this->log);
    }
    //Same school, new section but same class, academic year changed
    public function updateType8($data, $mongoUpdatedData, $mysqlData, $mongoData){
        $this->UPID = [];
        $this->groupID = [];
        //echo "<pre>";print_r($mysqlData);die;

        //convert UPID into UID
        $userObject = new User();
        isset($mysqlData['updated_by']) ? $updatedByField = ['updatedBy' => ($updatedBy = $mysqlData['updated_by']) ?? false ? $userObject->getFromGlobal($updatedBy, $updatedBy) : 'script'] : $updatedByField = ['updatedBy' => 'script'];
      //check if this is academic year change 
        if (($mongoUpdatedData['academicYearChange'] == 1 && $mongoUpdatedData['classAndSectionChange'] ) ?? false) {

            $classAndSectionChange = $mongoUpdatedData['classAndSectionChange'];
            //$this->checkAndSyncOrderDetails($mysqlData['schoolCode'],$mongoData['orgID']);
             $createBatchResult = $this->createBatch($mongoData['orgID']);
             $orgRelatedIDs = $userObject->getOrgAndRelatedIDsByOrgID($mongoData['orgID']);
             if($mysqlData['category'] == 'TEACHER'){
                $role = $this->portConfig['role']['teacher'];
            } else if($mysqlData['category'] == 'ADMIN'){
                $role = $this->portConfig['role']['admin'];
            } else if($mysqlData['category'] == 'STUDENT'){
                $role = $this->portConfig['role']['student'];
            }

 
             $classAndSectionChange['schoolCode'] = $orgRelatedIDs['oldOrgID'];
             $key = array_search($this->defaultMathPID, array_column($mongoData['userProducts'], 'PID'));
             $pValue = $mongoData['userProducts'][$key];
            $userProductUpdateData = [];

            $this->UPID = $UPID =  $pValue['UPID'];
            $activeGroups = $pValue['activeGroups'];
            $inactiveGroups = $pValue['inactiveGroups'];

            $sectionPSBAndGroupResult = $userObject->checkAndCreateSectionPSBAndGroup($classAndSectionChange, $pValue['PID']);

            $oldActiveBatch = $pValue['batch']['ActiveBatch'] ?? [];
            //checks if the new group is present
            if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false) {

                $groupMembers = $groupDetails['members'] ?? [];

                $this->groupID = $groupID = $groupDetails['groupID'];

                if ($roleAndMembers = $groupMembers[$role] ?? false) {
                    array_push($roleAndMembers, $UPID);
                    $groupMembers[$role] = $roleAndMembers;
                } else {
                    $groupMembers[$role][] = $UPID;
                }

                $groupMembers = $userObject->getUniqeMembers($groupMembers);

                $tmpGroupDetails = array_merge(['members' => $groupMembers], $updatedByField);
                $this->mongoGroupsObject->update(['groupID' => $groupID], $tmpGroupDetails);

                $activeBatch = [
                    "grade" => $classAndSectionChange['class'],
                    "section" => $classAndSectionChange['section'],
                    "groupID" => $groupID,
                ];

                $userProductUpdateData['batch']['ActiveBatch'] = array_merge($oldActiveBatch, $activeBatch);


                $userProductUpdateData['activeGroups'][] = ["groupID" => $groupID, "role" => $role, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"];
                // array_map(function (&$v) {$v['status'] = 'D';}, $activeGroups);

                // $userProductUpdateData['inactiveGroups'] = array_merge($inactiveGroups, $activeGroups);

                $userProductUpdateData = array_merge($userProductUpdateData, $updatedByField);

                $this->mongoUserProductObject->update(['UPID' => $UPID], $userProductUpdateData);

                $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                if (!empty($oldActiveGroupIDs)) {
                    foreach ($oldActiveGroupIDs as $oldGroupID) {
                        $userObject->removeUserFromGroup($oldGroupID, $UPID, $role);
                    }
                }

                $userObject->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $pValue['PID'], $groupID);
                $this->log[ $this->UPID ]['UserManagement'] = 'done';
            }
            $returnData = true;
 
             //call all APIs to archive
 
             //UserAuthentication
             $paramsForUserAuthenticationAPI = array("classUpgradeType" => "TYPE8","UIDs[]"=>$data['UID'],"oldClass"=>$oldActiveBatch['grade'],"newClass"=>$classAndSectionChange['class']);
             $urlAPI = $this->upgradeConfig['UAClassUpgrade'];
             $userAuthenticationAPI = $this->callAPI($paramsForUserAuthenticationAPI,$urlAPI);
             $this->log[ $this->UPID ]['UserAuthentication'] = $userAuthenticationAPI['resultMessage'];
 
              //CommunicationService 
             $paramsForCommunicationServiceAPI = array("classUpgradeType" => "TYPE8","UPIDs[]"=>$this->UPID);
             $urlAPI = $this->upgradeConfig['CSClassUpgrade'];
             $communicationServiceAPI = $this->callAPI($paramsForCommunicationServiceAPI,$urlAPI);
             $this->log[ $this->UPID ]['CommunicationService'] = $communicationServiceAPI['resultMessage'];
 
             //NotificationService 
             $paramsForNotificationServiceAPI = array("classUpgradeType" => "TYPE8","UPIDs[]"=>$this->UPID);
             $urlAPI = $this->upgradeConfig['NSClassUpgrade'];
             $notificationService = $this->callAPI($paramsForNotificationServiceAPI,$urlAPI);
             $this->log[ $this->UPID ]['NotificationService'] = $notificationService['resultMessage'];
 
             //ReportingService 
             $paramsForReportingServiceAPI = array("classUpgradeType" => "TYPE8","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
             $urlAPI = $this->upgradeConfig['RSClassUpgrade'];
             $reportingService = $this->callAPI($paramsForReportingServiceAPI,$urlAPI);
             $this->log[ $this->UPID ]['ReportingService'] = $reportingService['resultMessage'];
 
             //PedagogyService @TODO: Vini has to verify
             $paramsForPedagogyServiceAPI = array("classUpgradeType" => "TYPE8","UPIDs[]"=>$this->UPID, "newClass" => $classAndSectionChange['class'] );
             $urlAPI = $this->upgradeConfig['PSClassUpgrade'];
             $pedagogyService = $this->callAPI($paramsForPedagogyServiceAPI,$urlAPI);
             $this->log[ $this->UPID ]['PedagogyService'] = $pedagogyService['resultMessage'];
 
             //RewardEngine @TODO: Prasanth has to verify
             $paramsForRewardEngineAPI = array("classUpgradeType" => "TYPE8","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
             $urlAPI = $this->upgradeConfig['REClassUpgrade'];
             $rewardEngine = $this->callAPI($paramsForRewardEngineAPI,$urlAPI);
             $this->log[ $this->UPID ]['RewardEngine'] = $rewardEngine['resultMessage'];
         }
         print_r( $this->log);
    }
    //New school, same class (6)
    public function updateType9($data, $mongoUpdatedData, $mysqlData, $mongoData){
        $this->UPID = [];
        $this->groupID = [];
        //convert UPID into UID
        $userObject = new User();
        isset($mysqlData['updated_by']) ? $updatedByField = ['updatedBy' => ($updatedBy = $mysqlData['updated_by']) ?? false ? $userObject->getFromGlobal($updatedBy, $updatedBy) : 'script'] : $updatedByField = ['updatedBy' => 'script'];
        if ($organizationChange = $mongoUpdatedData['organizationChange'] ?? false) {
            //do operations for organization change
            //obtain orgID for the newOrg
            if ($orgDetails = $this->mongoOrgObject->find(["oldOrgID" => $organizationChange['newOrgID']]) ?? false) {
                $orgID = $orgDetails['orgID'];
                $data = ["orgID" => $orgID, "UID" => $mongoData['UID'] ];
                //new orgID updated in user details
                $this->updateOrgID( $data );
                //$this->checkAndSyncOrderDetails($mysqlData['schoolCode'],$mongoData['orgID']);
                $createBatchResult = $this->createBatch($orgID);
                $orgRelatedIDs = $userObject->getOrgAndRelatedIDsByOrgID($orgID);
                if($mysqlData['category'] == 'TEACHER'){
                    $role = $this->portConfig['role']['teacher'];
                } else if($mysqlData['category'] == 'ADMIN'){
                    $role = $this->portConfig['role']['admin'];
                } else if($mysqlData['category'] == 'STUDENT'){
                    $role = $this->portConfig['role']['student'];
                }

                $organizationChange['schoolCode'] = $orgRelatedIDs['oldOrgID'];
                $organizationChange['class'] = $mysqlData['class'];
                $organizationChange['section'] = $mysqlData['section'];

                $key = array_search($this->defaultMathPID, array_column($mongoData['userProducts'], 'PID'));
                $pValue = $mongoData['userProducts'][$key];
                $userProductUpdateData = [];

                $this->UPID = $UPID =  $pValue['UPID'];

                $activeGroups = $pValue['activeGroups'];
                $inactiveGroups = $pValue['inactiveGroups'];
                

                $sectionPSBAndGroupResult = $userObject->checkAndCreateSectionPSBAndGroup($organizationChange, $pValue['PID']);

                $oldActiveBatch = $pValue['batch']['ActiveBatch'] ?? [];

                if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false) {
                    $groupMembers = $groupDetails['members'] ?? [];
                    $this->groupID = $groupID = $groupDetails['groupID'];

                    if ($roleAndMembers = $groupMembers[$role] ?? false) {
                        array_push($roleAndMembers, $UPID);
                        $groupMembers[$role] = $roleAndMembers;
                    } else {
                        $groupMembers[$role][] = $UPID;
                    }

                    $groupMembers = $userObject->getUniqeMembers($groupMembers);

                    $tmpGroupDetails = array_merge(['members' => $groupMembers], $updatedByField);

                    $this->mongoGroupsObject->update(['groupID' => $groupID], $tmpGroupDetails);

                    $activeBatch = [
                        "grade" => $organizationChange['class'],
                        "section" => $organizationChange['section'],
                        "groupID" => $groupID,
                    ];

                    $userProductUpdateData['batch']['ActiveBatch'] = array_merge($oldActiveBatch, $activeBatch);
                    $userProductUpdateData['activeGroups'][] = ["groupID" => $groupID, "role" => $role, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"];

                    // array_map(function (&$v) {$v['status'] = 'D';}, $activeGroups);

                    // $userProductUpdateData['inactiveGroups'] = array_merge($inactiveGroups, $activeGroups);

                    $userProductUpdateData = array_merge($userProductUpdateData, $updatedByField);

                    $this->mongoUserProductObject->update(['UPID' => $UPID], $userProductUpdateData);

                    $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                    if (!empty($oldActiveGroupIDs)) {
                        foreach ($oldActiveGroupIDs as $oldGroupID) {
                            $userObject->removeUserFromGroup($oldGroupID, $UPID, $role);
                        }
                    }

                    $userObject->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $pValue['PID'], $groupID);
                    $this->log[ $this->UPID ]['UserManagement'] = 'done';

                }
                
                $returnData = true;

                 // update User product orderID 
                $this->updateUserProductOrderID($UPID,$orgID);
                

                //call all APIs to archive

                //UserAuthentication 
                $paramsForUserAuthenticationAPI = array("classUpgradeType" => "TYPE9","UIDs[]"=>$data['UID'],"oldClass"=>$oldActiveBatch['grade'],"newClass"=>$organizationChange['class']);
                $urlAPI = $this->upgradeConfig['UAClassUpgrade'];
                $userAuthenticationAPI = $this->callAPI($paramsForUserAuthenticationAPI,$urlAPI);
                $this->log[ $this->UPID ]['UserAuthentication'] = $userAuthenticationAPI['resultMessage'];

                //CommunicationService 
                $paramsForCommunicationServiceAPI = array("classUpgradeType" => "TYPE9","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['CSClassUpgrade'];
                $communicationServiceAPI = $this->callAPI($paramsForCommunicationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['CommunicationService'] = $communicationServiceAPI['resultMessage'];

                //NotificationService 
                $paramsForNotificationServiceAPI = array("classUpgradeType" => "TYPE9","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['NSClassUpgrade'];
                $notificationService = $this->callAPI($paramsForNotificationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['NotificationService'] = $notificationService['resultMessage'];

                //ReportingService 
                $paramsForReportingServiceAPI = array("classUpgradeType" => "TYPE9","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $organizationChange['class'] );
                $urlAPI = $this->upgradeConfig['RSClassUpgrade'];
                $reportingService = $this->callAPI($paramsForReportingServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['ReportingService'] = $reportingService['resultMessage'];

                //PedagogyService @TODO: Vini has to verify
                $paramsForPedagogyServiceAPI = array("classUpgradeType" => "TYPE9","UPIDs[]"=>$this->UPID, "newClass" => $organizationChange['class'] );
                $urlAPI = $this->upgradeConfig['PSClassUpgrade'];
                $pedagogyService = $this->callAPI($paramsForPedagogyServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['PedagogyService'] = $pedagogyService['resultMessage'];

                //RewardEngine @TODO: Prasanth has to verify
                $paramsForRewardEngineAPI = array("classUpgradeType" => "TYPE9","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $organizationChange['class'] );
                $urlAPI = $this->upgradeConfig['REClassUpgrade'];
                $rewardEngine = $this->callAPI($paramsForRewardEngineAPI,$urlAPI);
                $this->log[ $this->UPID ]['RewardEngine'] = $rewardEngine['resultMessage'];
            } 
            print_r( $this->log);
        }
    }
    //New school, new class (9)
    public function updateType10($data, $mongoUpdatedData, $mysqlData, $mongoData){
        $this->UPID = [];
        $this->groupID = [];
        //convert UPID into UID
        $userObject = new User();
        isset($mysqlData['updated_by']) ? $updatedByField = ['updatedBy' => ($updatedBy = $mysqlData['updated_by']) ?? false ? $userObject->getFromGlobal($updatedBy, $updatedBy) : 'script'] : $updatedByField = ['updatedBy' => 'script'];
        if (($organizationChange = $mongoUpdatedData['organizationChange'] && $classAndSectionChange = $mongoUpdatedData['classAndSectionChange']) ?? false) {
            //do operations for organization change
            $organizationChange = $mongoUpdatedData['organizationChange'];
            $classAndSectionChange = $mongoUpdatedData['classAndSectionChange'];

            //check if the new ORGID is present in database
            if ($orgDetails = $this->mongoOrgObject->find(["oldOrgID" => $organizationChange['newOrgID']]) ?? false) {
                $orgID = $orgDetails['orgID'];
                $data = ["orgID" => $orgID, "UID" => $mongoData['UID'] ];
                //new orgID updated in user details
                $this->updateOrgID( $data );
                //$this->checkAndSyncOrderDetails($mysqlData['schoolCode'],$mongoData['orgID']);
                $createBatchResult = $this->createBatch($orgID);
                $orgRelatedIDs = $userObject->getOrgAndRelatedIDsByOrgID($orgID);
                if($mysqlData['category'] == 'TEACHER'){
                    $role = $this->portConfig['role']['teacher'];
                } else if($mysqlData['category'] == 'ADMIN'){
                    $role = $this->portConfig['role']['admin'];
                } else if($mysqlData['category'] == 'STUDENT'){
                    $role = $this->portConfig['role']['student'];
                }

                $classAndSectionChange['schoolCode'] = $orgRelatedIDs['oldOrgID'];
                $key = array_search($this->defaultMathPID, array_column($mongoData['userProducts'], 'PID'));
                $pValue = $mongoData['userProducts'][$key];

                $userProductUpdateData = [];

                $this->UPID = $UPID =  $pValue['UPID'];

                $activeGroups = $pValue['activeGroups'];
                $inactiveGroups = $pValue['inactiveGroups'];
                

                $sectionPSBAndGroupResult = $userObject->checkAndCreateSectionPSBAndGroup($classAndSectionChange, $pValue['PID']);

                $oldActiveBatch = $pValue['batch']['ActiveBatch'] ?? [];

                if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false) {
                    $groupMembers = $groupDetails['members'] ?? [];
                    $this->groupID = $groupID = $groupDetails['groupID'];

                    if ($roleAndMembers = $groupMembers[$role] ?? false) {
                        array_push($roleAndMembers, $UPID);
                        $groupMembers[$role] = $roleAndMembers;
                    } else {
                        $groupMembers[$role][] = $UPID;
                    }

                    $groupMembers = $userObject->getUniqeMembers($groupMembers);

                    $tmpGroupDetails = array_merge(['members' => $groupMembers], $updatedByField);

                    $this->mongoGroupsObject->update(['groupID' => $groupID], $tmpGroupDetails);

                    $activeBatch = [
                        "grade" => $classAndSectionChange['class'],
                        "section" => $classAndSectionChange['section'],
                        "groupID" => $groupID,
                    ];

                    $userProductUpdateData['batch']['ActiveBatch'] = array_merge($oldActiveBatch, $activeBatch);
                    $userProductUpdateData['activeGroups'][] = ["groupID" => $groupID, "role" => $role, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"];

                    // array_map(function (&$v) {$v['status'] = 'D';}, $activeGroups);

                    // $userProductUpdateData['inactiveGroups'] = array_merge($inactiveGroups, $activeGroups);

                    $userProductUpdateData = array_merge($userProductUpdateData, $updatedByField);

                    $this->mongoUserProductObject->update(['UPID' => $UPID], $userProductUpdateData);

                    $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                    if (!empty($oldActiveGroupIDs)) {
                        foreach ($oldActiveGroupIDs as $oldGroupID) {
                            $userObject->removeUserFromGroup($oldGroupID, $UPID, $role);
                        }
                    }

                    $userObject->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $pValue['PID'], $groupID);
                    $this->log[ $this->UPID ]['UserManagement'] = 'done';

                }

                 // update User product orderID 
                 $this->updateUserProductOrderID($UPID,$orgID);
                
                //call all APIs to archive

                //UserAuthentication
                $paramsForUserAuthenticationAPI = array("classUpgradeType" => "TYPE10","UIDs[]"=>$data['UID'],"oldClass"=>$oldActiveBatch['grade'],"newClass"=>$classAndSectionChange['class']);
                $urlAPI = $this->upgradeConfig['UAClassUpgrade'];
                $userAuthenticationAPI = $this->callAPI($paramsForUserAuthenticationAPI,$urlAPI);
                $this->log[ $this->UPID ]['UserAuthentication'] = $userAuthenticationAPI['resultMessage'];

                //CommunicationService 
                $paramsForCommunicationServiceAPI = array("classUpgradeType" => "TYPE10","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['CSClassUpgrade'];
                $communicationServiceAPI = $this->callAPI($paramsForCommunicationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['CommunicationService'] = $communicationServiceAPI['resultMessage'];

                //NotificationService 
                $paramsForNotificationServiceAPI = array("classUpgradeType" => "TYPE10","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['NSClassUpgrade'];
                $notificationService = $this->callAPI($paramsForNotificationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['NotificationService'] = $notificationService['resultMessage'];

                //ReportingService 
                $paramsForReportingServiceAPI = array("classUpgradeType" => "TYPE10","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
                $urlAPI = $this->upgradeConfig['RSClassUpgrade'];
                $reportingService = $this->callAPI($paramsForReportingServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['ReportingService'] = $reportingService['resultMessage'];

                //PedagogyService @TODO: Vini has to verify
                $paramsForPedagogyServiceAPI = array("classUpgradeType" => "TYPE10","UPIDs[]"=>$this->UPID, "newClass" => $classAndSectionChange['class'] );
                $urlAPI = $this->upgradeConfig['PSClassUpgrade'];
                $pedagogyService = $this->callAPI($paramsForPedagogyServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['PedagogyService'] = $pedagogyService['resultMessage'];

                //RewardEngine @TODO: Prasanth has to verify
                $paramsForRewardEngineAPI = array("classUpgradeType" => "TYPE10","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
                $urlAPI = $this->upgradeConfig['REClassUpgrade'];
                $rewardEngine = $this->callAPI($paramsForRewardEngineAPI,$urlAPI);
                $this->log[ $this->UPID ]['RewardEngine'] = $rewardEngine['resultMessage'];
            } 
            print_r( $this->log);
        }
    }
    //Subscription mode changed school=>retail or retail=>school, same class (8)
    public function updateType11( $data, $mongoUpdatedData, $mysqlData, $mongoData){
        $this->UPID = [];
        $this->groupID = [];
        //convert UPID into UID
        $userObject = new User();
        isset($mysqlData['updated_by']) ? $updatedByField = ['updatedBy' => ($updatedBy = $mysqlData['updated_by']) ?? false ? $userObject->getFromGlobal($updatedBy, $updatedBy) : 'script'] : $updatedByField = ['updatedBy' => 'script'];
        if ($organizationChange = $mongoUpdatedData['organizationChange'] ?? false) {
            //do operations for organization change
            //obtain orgID for the newOrg
            if ($orgDetails = $this->mongoOrgObject->find(["oldOrgID" => $organizationChange['newOrgID']]) ?? false) {
                $orgID = $orgDetails['orgID'];
                $data = ["orgID" => $orgID, "UID" => $mongoData['UID'] ];
                //new orgID updated in user details
                $this->updateOrgID( $data );
                //$this->checkAndSyncOrderDetails($mysqlData['schoolCode'],$mongoData['orgID']);
                $createBatchResult = $this->createBatch($orgID);
                $orgRelatedIDs = $userObject->getOrgAndRelatedIDsByOrgID($orgID);
                if($mysqlData['category'] == 'TEACHER'){
                    $role = $this->portConfig['role']['teacher'];
                } else if($mysqlData['category'] == 'ADMIN'){
                    $role = $this->portConfig['role']['admin'];
                } else if($mysqlData['category'] == 'STUDENT'){
                    $role = $this->portConfig['role']['student'];
                }

                $organizationChange['schoolCode'] = $orgRelatedIDs['oldOrgID'];
                $organizationChange['class'] = $mysqlData['class'];
                $organizationChange['section'] = $mysqlData['section'];

                $key = array_search($this->defaultMathPID, array_column($mongoData['userProducts'], 'PID'));
                $pValue = $mongoData['userProducts'][$key];

                $userProductUpdateData = [];

                $this->UPID= $UPID = $pValue['UPID'];

                $activeGroups = $pValue['activeGroups'];
                $inactiveGroups = $pValue['inactiveGroups'];
                

                $sectionPSBAndGroupResult = $userObject->checkAndCreateSectionPSBAndGroup($organizationChange, $pValue['PID']);

                $oldActiveBatch = $pValue['batch']['ActiveBatch'] ?? [];

                if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false) {
                    $groupMembers = $groupDetails['members'] ?? [];
                    $this->groupID = $groupID = $groupDetails['groupID'];

                    if ($roleAndMembers = $groupMembers[$role] ?? false) {
                        array_push($roleAndMembers, $UPID);
                        $groupMembers[$role] = $roleAndMembers;
                    } else {
                        $groupMembers[$role][] = $UPID;
                    }

                    $groupMembers = $userObject->getUniqeMembers($groupMembers);

                    $tmpGroupDetails = array_merge(['members' => $groupMembers], $updatedByField);

                    $this->mongoGroupsObject->update(['groupID' => $groupID], $tmpGroupDetails);

                    $activeBatch = [
                        "grade" => $organizationChange['class'],
                        "section" => $organizationChange['section'],
                        "groupID" => $groupID,
                    ];

                    $userProductUpdateData['batch']['ActiveBatch'] = array_merge($oldActiveBatch, $activeBatch);
                    $userProductUpdateData['activeGroups'][] = ["groupID" => $groupID, "role" => $role, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"];

                    // array_map(function (&$v) {$v['status'] = 'D';}, $activeGroups);

                    // $userProductUpdateData['inactiveGroups'] = array_merge($inactiveGroups, $activeGroups);

                    $userProductUpdateData = array_merge($userProductUpdateData, $updatedByField);

                    $this->mongoUserProductObject->update(['UPID' => $UPID], $userProductUpdateData);

                    $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                    if (!empty($oldActiveGroupIDs)) {
                        foreach ($oldActiveGroupIDs as $oldGroupID) {
                            $userObject->removeUserFromGroup($oldGroupID, $UPID, $role);
                        }
                    }

                    $userObject->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $pValue['PID'], $groupID);
                    $this->log[ $this->UPID ]['UserManagement'] = 'done';

                     // updateUserOrgTag

                $this->updateUserOrgTag($mongoUpdatedData,$UPID);

                }
                
                $returnData = true;

                 // updateUserOrgTag
                 $this->updateUserOrgTag($mongoUpdatedData ,$UPID );
               
                 // update User product orderID 
                 $this->updateUserProductOrderID($UPID,$orgID);

                //call all APIs to archive

                //UserAuthentication 
                $paramsForUserAuthenticationAPI = array("classUpgradeType" => "TYPE11","UIDs[]"=>$data['UID'],"oldClass"=>$oldActiveBatch['grade'],"newClass"=>$organizationChange['class']);
                $urlAPI = $this->upgradeConfig['UAClassUpgrade'];
                $userAuthenticationAPI = $this->callAPI($paramsForUserAuthenticationAPI,$urlAPI);
                $this->log[ $this->UPID ]['UserAuthentication'] = $userAuthenticationAPI['resultMessage'];


                //CommunicationService 
                $paramsForCommunicationServiceAPI = array("classUpgradeType" => "TYPE11","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['CSClassUpgrade'];
                $communicationServiceAPI = $this->callAPI($paramsForCommunicationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['CommunicationService'] = $communicationServiceAPI['resultMessage'];


                //NotificationService 
                $paramsForNotificationServiceAPI = array("classUpgradeType" => "TYPE11","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['NSClassUpgrade'];
                $notificationService = $this->callAPI($paramsForNotificationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['NotificationService'] = $notificationService['resultMessage'];


                //ReportingService 
                $paramsForReportingServiceAPI = array("classUpgradeType" => "TYPE11","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $organizationChange['class'] );
                $urlAPI = $this->upgradeConfig['RSClassUpgrade'];
                $reportingService = $this->callAPI($paramsForReportingServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['ReportingService'] = $reportingService['resultMessage'];


                //PedagogyService @TODO: Vini has to verify
                $paramsForPedagogyServiceAPI = array("classUpgradeType" => "TYPE11","UPIDs[]"=>$this->UPID, "newClass" => $organizationChange['class'] );
                $urlAPI = $this->upgradeConfig['PSClassUpgrade'];
                $pedagogyService = $this->callAPI($paramsForPedagogyServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['PedagogyService'] = $pedagogyService['resultMessage'];


                //RewardEngine @TODO: Prasanth has to verify
                $paramsForRewardEngineAPI = array("classUpgradeType" => "TYPE11","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $organizationChange['class'] );
                $urlAPI = $this->upgradeConfig['REClassUpgrade'];
                $rewardEngine = $this->callAPI($paramsForRewardEngineAPI,$urlAPI);
                $this->log[ $this->UPID ]['RewardEngine'] = $rewardEngine['resultMessage'];

            } 
            print_r( $this->log);
        }
    }
    //Subscription mode changed school=>retail or retail=>school, new class (10)
    public function updateType12( $data, $mongoUpdatedData, $mysqlData, $mongoData){
        $this->UPID = [];
        $this->groupID = [];
        //convert UPID into UID
        $userObject = new User();
        isset($mysqlData['updated_by']) ? $updatedByField = ['updatedBy' => ($updatedBy = $mysqlData['updated_by']) ?? false ? $userObject->getFromGlobal($updatedBy, $updatedBy) : 'script'] : $updatedByField = ['updatedBy' => 'script'];
        if (($organizationChange = $mongoUpdatedData['organizationChange'] && $classAndSectionChange = $mongoUpdatedData['classAndSectionChange']) ?? false) {
            //do operations for organization change
            $organizationChange = $mongoUpdatedData['organizationChange'];
            $classAndSectionChange = $mongoUpdatedData['classAndSectionChange'];

            //check if the new ORGID is present in database
            if ($orgDetails = $this->mongoOrgObject->find(["oldOrgID" => $organizationChange['newOrgID']]) ?? false) {
                $orgID = $orgDetails['orgID'];
                $data = ["orgID" => $orgID, "UID" => $mongoData['UID'] ];
                //new orgID updated in user details
                $this->updateOrgID( $data );
                //$this->checkAndSyncOrderDetails($mysqlData['schoolCode'],$mongoData['orgID']);
                $createBatchResult = $this->createBatch($orgID);
                $orgRelatedIDs = $userObject->getOrgAndRelatedIDsByOrgID($orgID);
                if($mysqlData['category'] == 'TEACHER'){
                    $role = $this->portConfig['role']['teacher'];
                } else if($mysqlData['category'] == 'ADMIN'){
                    $role = $this->portConfig['role']['admin'];
                } else if($mysqlData['category'] == 'STUDENT'){
                    $role = $this->portConfig['role']['student'];
                }

                $classAndSectionChange['schoolCode'] = $orgRelatedIDs['oldOrgID'];

                $key = array_search($this->defaultMathPID, array_column($mongoData['userProducts'], 'PID'));
                $pValue = $mongoData['userProducts'][$key];

                $userProductUpdateData = [];

                $this->UPID = $UPID =  $pValue['UPID'];

                $activeGroups = $pValue['activeGroups'];
                $inactiveGroups = $pValue['inactiveGroups'];
                

                $sectionPSBAndGroupResult = $userObject->checkAndCreateSectionPSBAndGroup($classAndSectionChange, $pValue['PID']);

                $oldActiveBatch = $pValue['batch']['ActiveBatch'] ?? [];

                if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false) {
                    $groupMembers = $groupDetails['members'] ?? [];
                    $this->groupID = $groupID = $groupDetails['groupID'];

                    if ($roleAndMembers = $groupMembers[$role] ?? false) {
                        array_push($roleAndMembers, $UPID);
                        $groupMembers[$role] = $roleAndMembers;
                    } else {
                        $groupMembers[$role][] = $UPID;
                    }

                    $groupMembers = $userObject->getUniqeMembers($groupMembers);

                    $tmpGroupDetails = array_merge(['members' => $groupMembers], $updatedByField);

                    $this->mongoGroupsObject->update(['groupID' => $groupID], $tmpGroupDetails);

                    $activeBatch = [
                        "grade" => $classAndSectionChange['class'],
                        "section" => $classAndSectionChange['section'],
                        "groupID" => $groupID,
                    ];

                    $userProductUpdateData['batch']['ActiveBatch'] = array_merge($oldActiveBatch, $activeBatch);
                    $userProductUpdateData['activeGroups'][] = ["groupID" => $groupID, "role" => $role, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"];

                    // array_map(function (&$v) {$v['status'] = 'D';}, $activeGroups);

                    // $userProductUpdateData['inactiveGroups'] = array_merge($inactiveGroups, $activeGroups);

                    $userProductUpdateData = array_merge($userProductUpdateData, $updatedByField);

                    $this->mongoUserProductObject->update(['UPID' => $UPID], $userProductUpdateData);

                    $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                    if (!empty($oldActiveGroupIDs)) {
                        foreach ($oldActiveGroupIDs as $oldGroupID) {
                            $userObject->removeUserFromGroup($oldGroupID, $UPID, $role);
                        }
                    }

                    $userObject->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $pValue['PID'], $groupID);
                    $this->log[ $this->UPID ]['UserManagement'] = 'done';

                }
                $this->updateUserOrgTag($mongoUpdatedData,$UPID);
                $returnData = true;

                  // updateUserOrgTag
                  $this->updateUserOrgTag($mongoUpdatedData ,$UPID );

                 // update User product orderID 
                 $this->updateUserProductOrderID($UPID,$orgID);

                //call all APIs to archive

                //UserAuthentication
                $paramsForUserAuthenticationAPI = array("classUpgradeType" => "TYPE12","UIDs[]"=>$data['UID'],"oldClass"=>$oldActiveBatch['grade'],"newClass"=>$classAndSectionChange['class']);
                $urlAPI = $this->upgradeConfig['UAClassUpgrade'];
                $userAuthenticationAPI = $this->callAPI($paramsForUserAuthenticationAPI,$urlAPI);
                $this->log[ $this->UPID ]['UserAuthentication'] = $userAuthenticationAPI['resultMessage'];


                //CommunicationService 
                $paramsForCommunicationServiceAPI = array("classUpgradeType" => "TYPE12","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['CSClassUpgrade'];
                $communicationServiceAPI = $this->callAPI($paramsForCommunicationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['CommunicationService'] = $communicationServiceAPI['resultMessage'];


                //NotificationService 
                $paramsForNotificationServiceAPI = array("classUpgradeType" => "TYPE12","UPIDs[]"=>$this->UPID);
                $urlAPI = $this->upgradeConfig['NSClassUpgrade'];
                $notificationService = $this->callAPI($paramsForNotificationServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['NotificationService'] = $notificationService['resultMessage'];


                //ReportingService 
                $paramsForReportingServiceAPI = array("classUpgradeType" => "TYPE12","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
                $urlAPI = $this->upgradeConfig['RSClassUpgrade'];
                $reportingService = $this->callAPI($paramsForReportingServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['ReportingService'] = $reportingService['resultMessage'];


                //PedagogyService @TODO: Vini has to verify
                $paramsForPedagogyServiceAPI = array("classUpgradeType" => "TYPE12","UPIDs[]"=>$this->UPID, "newClass" => $classAndSectionChange['class'] );
                $urlAPI = $this->upgradeConfig['PSClassUpgrade'];
                $pedagogyService = $this->callAPI($paramsForPedagogyServiceAPI,$urlAPI);
                $this->log[ $this->UPID ]['PedagogyService'] = $pedagogyService['resultMessage'];


                //RewardEngine @TODO: Prasanth has to verify
                $paramsForRewardEngineAPI = array("classUpgradeType" => "TYPE12","UPIDs[]"=>$this->UPID, "groupID" => $this->groupID, "orgID" => $mongoData['orgID'], "newClass" => $classAndSectionChange['class'] );
                $urlAPI = $this->upgradeConfig['REClassUpgrade'];
                $rewardEngine = $this->callAPI($paramsForRewardEngineAPI,$urlAPI);
                $this->log[ $this->UPID ]['RewardEngine'] = $rewardEngine['resultMessage'];

            } 
            print_r( $this->log);
        }
    }

   
    // create class and section if not exist 
    // Input : OrgId, grade and section
    private function createClassAndSection( $data ){

        // read input data;
        $orgID = $data['orgID'] ?? "";
        $grade = $data['grade'] ?? "";
        $section = $data['section'] ?? "";
        $orgDetails = $this->mongoOrgObject->findAll(['orgID' => $orgID]);
        $sectionObject = $this->mongoUserDetailsObject->findAll(['orgID' => $orgID]);

        // organisation exists
        if(count($orgDetails)>0)
        {
            // grade and sectio not empty
            if (!empty($grade)&& !empty($section) ) {

                // Is organisation has grades
                if(!in_array($grade, array_column($orgDetails, 'grades'))){
                    
                     // insert into organisation
                    $grades=  $orgDetails['grades'];
                    array_push($grades, $grade);                 
                    $this->mongoOrgObject->update(['orgID' => $orgID],['grades'=> $grades]);                    
                }

                // check section detail and Update 
                if(!in_array($grade." ".$section, array_column($sectionObject, 'name'))){

                    // update old section details as inactive
                    $this->mongoSectionObject->update(['orgID' => $orgID],['status'=> "D","updatedAt" => $this->getTodayDateTime(),"updatedBy" => 'script']); 

                    $sectionData = [
                        'orgID' => $orgID,
                        'grade' => $grade,
                        'name' => $grade." ".$section,
                        "createdAt" => $this->getTodayDateTime(),
                        "createdBy" => 'script',
                        "updatedAt" => $this->getTodayDateTime(),
                        "status" => "A"
                        ];
                    // insert section details
                    $result =  $this->mongoSectionObject->insert($orgTeacherGroupDetails);
                }               

            } else {
                // set error message 
                $error['message']= "Input grade and section";
                return false;
                }

        } else {
            // set error message
            $error['message']= "Organization doesn't exist";
            return false;
            }
        // completed
        return $result;
    }     

    // update orgId
    // Input : OrgId and UPID
    private function updateOrgID( $data ){

        // read input data;
        $orgID = $data['orgID'] ?? "";
        $UID = $data['UID'] ?? "";
       
        // read existing data of UPID
        $UserDetailsObject = $this->mongoUserDetailsObject->findAll(['orgID' => $orgID,"UID"=>$UID]);        
        // check UPID and organisation match
        if(count($UserDetailsObject) == 0) {
            // update  orgId in user details            
           $result = $this->mongoUserDetailsObject->update(["UID"=> $UID],['orgID' => $orgID]);          
           
        }else{
            $error["message"]="Organisation and UPID match not found";
            return false;
        }
        return $result;
    }


    // Subscription change update
     
    public function updateUserOrgTag($mongoUpdatedData,$UPID){
        
        $UserProductObject = $this->mongoUserProductObject->find(["UPID"=>$UPID,'PID' => $this->defaultMathPID]);
        $userProductTags = $UserProductObject['tags'];
        
        foreach($userProductTags as $key => $tags){
            if($tags == 'School' && $mongoUpdatedData['subscriptionModeChange'] == 'Individual' ){
                $userProductTags[$key] = 'Retail';
            }else if($tags == 'Retail' && $mongoUpdatedData['subscriptionModeChange'] == 'School'){
                $userProductTags[$key] = 'School';
            }
        }
        $UserProductObject['tags'] = $userProductTags;
        
        $result = $this->mongoUserProductObject->update(["UPID"=> $UPID ], [ "tags"=> $userProductTags]);
       

    }

    // update user products order ID 
    public function updateUserProductOrderID($UPID,$newOrgID)    {
        $currentOrder = [
            'orgID' => $newOrgID,
            'status' => 'A',
            'PID' => ['$in' => [$this->defaultMathPID]]            
        ];
        $orderDetailsResult = $this->mongoOrderObject->find($currentOrder);
        $result = $this->mongoUserProductObject->update(["UPID"=> $UPID ], [ "orderID"=> $orderDetailsResult['orderID']]); 
    }

      // check passed orgID exists or not
    //Input Param: orgID
    public function checkOrganizationExists($orgID){

        $orgDetails = $this->mongoOrgObject->find(["orgID" => $orgID]);

        if( count($orgDetails) > 0 ){ 
            return $orgDetails;
        }else{
            return false;
        }

    }

    public function checkAndSyncOrderDetails($schoolCode,$orgID){
        $sql = "SELECT * FROM educatio_educat.ms_orderMaster where schoolCode=$schoolCode and is_active=1 order by end_date  desc limit 1";
        if($latestOrderDetails = $this->mysqlOrderMasterObject->rawQuery($sql)['data'][0] ?? false){
            $currentOrder = [
                'orgID' => $orgID,
                'status' => 'A',
                'PID' => ['$in' => [$this->defaultMathPID]]
                
            ];
            $orderDetailsResult = $this->mongoOrderObject->find($currentOrder);
    
            if(isset($orderDetailsResult) && ($latestOrderDetails['order_id'] != $orderDetailsResult['oldOrderID'])){
                $orderDetails = [
                    'oldOrderID' => $latestOrderDetails['order_id'],
                    'year' => $latestOrderDetails['year'],
                    'orderType' => $latestOrderDetails['order_type'],
                    'startDate' => $latestOrderDetails['start_date'],
                    'endDate' => $latestOrderDetails['end_date'],
                    'totalStudent' => $latestOrderDetails['total_students'],
                    'duration' => $latestOrderDetails['duration'],
                    'paid' => $latestOrderDetails['paid']
                ];
                $this->mongoOrderObject->update(['_id' => $orderDetailsResult['_id']], $orderDetails);
            } else if(isset($orderDetailsResult) && (($latestOrderDetails['start_date'] != $orderDetailsResult['startDate']) || ($latestOrderDetails['end_date'] != $orderDetailsResult['endDate']))){
                $orderDetails = [
                    'oldOrderID' => $latestOrderDetails['order_id'],
                    'year' => $latestOrderDetails['year'],
                    'orderType' => $latestOrderDetails['order_type'],
                    'startDate' => $latestOrderDetails['start_date'],
                    'endDate' => $latestOrderDetails['end_date'],
                    'totalStudent' => $latestOrderDetails['total_students'],
                    'duration' => $latestOrderDetails['duration'],
                    'paid' => $latestOrderDetails['paid']
                ];
                $this->mongoOrderObject->update(['_id' => $orderDetailsResult['_id']], $orderDetails);
            }
        } 

            
    }
    
      
    public function createBatch($orgID){
        $this->resultArray = [];
        if($orgDetails = $this->checkOrganizationExists($orgID)){

            if($orderDetails = $this->mongoOrderObject->find([
                'orgID' => $orgDetails['orgID'],
                'status' => 'A',
                'PID' => ['$in' => [$this->defaultMathPID]]
            ]) ?? false){
                $currentYear = date('Y');
                $nextYear = $currentYear+1;
                //@TODO: Uncomment this later
                $newBatchName = "$currentYear-$nextYear"; 
                //$newBatchName = "2018-2019";
                // create new batch if not exists                 
                $batchDetails = [
                    'name' => $newBatchName,
                    'orgID' => $orgDetails['orgID'],
                    'tag' => $this->portConfig['batch']['tag'],
                    'startDate' => $orderDetails['startDate'],
                    'endDate' => $orderDetails['endDate'],                        
                ];
                $batchDetailsAPIResult = $this->callAPI($batchDetails,$this->upgradeConfig['CreateBatch']);                
                if($batchDetailsAPIResult['resultCode'] == 'UM170'){
                    $this->resultArray['isSuccess'] = "true";
                    $this->resultArray['message'] = $batchDetailsAPIResult['resultMessage'];
                    $this->resultArray['data'] = $batchDetailsAPIResult['data'];   
                }  else {
                    $this->changeUserProductBatchStatus($batchDetails);
                    $this->resultArray['isSuccess'] = "false";
                    $this->resultArray['message'] =$batchDetailsAPIResult['resultMessage'];
                    $this->resultArray['data'] =$batchDetailsAPIResult['data'];
                }
            } else {
                $this->resultArray['isSuccess'] = "false";
                $this->resultArray['message'] = "Order details are not present for Orgnaization ID: $orgID";
            }

        } else {
            $this->resultArray['isSuccess'] = false;
            $this->resultArray['message'] = "Oganization ID: ".$orgDetails['orgID']." is not found";
        }
 
        return $this->resultArray;
 
}

    // Make current batch deactive / activate in user products
    public function changeUserProductBatchStatus($batchDetails){
        if($batchDetailsResult = $this->mongoBatchObject->findAll([
            'orgID' => $batchDetails['orgID'],
            'status' => 'A',
            'name' => ['$ne' => $batchDetails['name']]
        ]) ?? false){
            foreach($batchDetailsResult as $dBatchDetails){
                $this->mongoBatchObject->update( ['batchID' => $dBatchDetails['batchID'] ], ['status' => 'D' ]);
           }
        }
    }

    // Input Parameter : name, PID, subject, type
    public function createGroup($data){

        // check group is already exists or not 
        
        $groupDetails = $this->mongoGroupsObject->find([ 'name' => $data['name']]);
        
         // create new group if not exists
        if( count($groupDetails) < 1 ){
            $createGroupResult = $this->mongoGroupsObject->insertOne([
               'name' => $data['name'],
               'type' => $data['type'],
            // 'members' => array of objects
               'PSBId' => $data['PSBId'],
               'description' => $data['description'],
               'passwordResetRequest' => $data['passwordResetRequest'],
               'createdAt' => $data['createdAt'],
               'createdBy' => $data['createdBy'],
               'updatedAt' => $data['updatedAt'],
               'updatedBy' => $data['updatedBy'],
               'status' => $data['status'],
               'settings' => $data['settings'],
               'version' => $data['version'],
            ]);
            return true;
        }else{
            // group already exists
            $this->resultArray['isSuccess'] = false;
            $this->resultArray['message'] = "Group ".$data['name']." is already exists";
         }

         return $this->resultArray;

	    // $header = array("Auth:EISecret");
		// curl_setopt_array($curl, array(
		// 							CURLOPT_URL => $this->upgradeConfig['CreateGroup'],
		// 							CURLOPT_RETURNTRANSFER => true,
		// 							CURLOPT_ENCODING => "",
		// 							CURLOPT_MAXREDIRS => 10,
		// 							CURLOPT_TIMEOUT => 30,
		// 							CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		// 							CURLOPT_CUSTOMREQUEST => "POST",
		// 							CURLOPT_POSTFIELDS => $data,
		// 							CURLOPT_HTTPHEADER => $header,
		// 						)
		// 				);
		// $response = curl_exec($curl);
		// $err = curl_error($curl);
		// curl_close($curl);
		// if(!$err){
		// 	$jsonData = json_decode($response, true);
		// 	$return = array("result" => "success", "data" => $jsonData['data']);
		// 	error_log(json_encode($jsonData));
		// 	return $return;
		// }
	}

    // check and create PSBMapping
    // Input : OrgId,PID,OrderID,SDId  and BatchId   
    private function checkAndCreatePSBMapping($Data)
    {
        $orgId  = $Data['orgID'] ?? "";
        $PID  = $Data['PID'] ?? "";
        $orderID= $Data['orderID'] ?? ""; 
        $SDId   = $Data['SDId'] ?? "";
        $grade  = $Data['grade'] ?? "";
        $batchID= $Data['batchID'] ?? "";
        $groupID= $Data['groupID'] ?? "";

        // check if all the input parameters passed        
        if(isset($orgId) && isset($PID) && isset($orderID) && isset($SDId) && isset($grade) && isset($batchID)){

            $seacrhCondPSB = [
                'batchID' => $batchID,
                'orgID' => $orgID,
                'orderID' => $orderID,
                'PID' => $PID,
                'grade' => $grade,
                'SDId' => $SDId,
                'status' => 'A',
            ];

            // check if exists 
            $PSBDetails = $this->mongoPSBObject->find($seacrhCondPSB);

            if (!($PSBId = $PSBDetails['PSBId'] ?? false)) {

                $PSBId = $this->mongoPSBObject->newMongoId();

                $PSBDetails = [
                    '_id' => $PSBId,
                    'PSBId' => $PSBId,
                    'batchID' => $batchID,
                    'orgID' => $orgID,
                    'groupIDs' => [$groupID],
                    'orderID' => $orderID,
                    'PID' => $PID,
                    'grade' => $oldData['class'],
                    'SDId' => $SDId,
                    'updatedAt' => $this->getTodayDateTime(),
                    'status' => 'A',
                    'version' => 1,
                ];

                // insert into PSBMapping 
                $result = $this->mongoPSBObject->insert($PSBDetails);            
            } else {
                 $error["message"]= "No records found";
            return false;
            }
           
        }else{
            $error["message"]= "Missing input arguments";
            return false;
        }
        return $result;
    }

    // check and update PSBMapping
    // Input : PSBId  and groupID    
    private function checkAndUpdatePSBMapping($Data)
    {
        
        $PSBId  = $Data['PSBId'] ?? "";
        $groupID= $Data['groupID'] ?? "";

        // check if all the input parameters passed        
        if(isset($PSBId) && isset($groupID)) {

            $seacrhCondPSB = [
                'PSBId' => $PSBId
            ];

            // check if exists 
            $PSBDetails = $this->mongoPSBObject->find($seacrhCondPSB);
            if (($PSBId = $PSBDetails['PSBId'] ?? false)) {              
               // update into PSBMapping 
                $result = $this->mongoPSBObject->update($seacrhCondPSB,['groupIDs' => [$groupID]]);            
            } else {
                 $error["message"]= "No records found";
            return false;
            }
           
        }else{
            $error["message"]= "Missing input arguments";
            return false;
        }
        return $result;
    }

    // Activate and deactivate userproduct batch 
    // Input : UPID,newBatchID, newGroupID,grade,section
    private function userProductBatchUpdate($Data){
        $UPID=$Data['UPID'] ?? "";
        $newBatchID = $Data['batchID'] ?? "";
        $newGroupID = $Data['groupID'] ?? "";
        $grade = $Data['grade'] ?? "";
        $section =$Data['section'] ?? "";
        $seacrhCondUP = ["UPID"=> $UPID];

        $activeBatch =["batchID" => $newBatchID,
			"grade" => $grade ,
			"section" => $section,
			"groupID" => $newGroupID,
            "rollNo" => null
        ];
            
        // check all input parameters passed
        if(isset($UPID) && isset($newBatchID) && isset($newGroupID) && isset($grade) && isset($section) ){
            // make existing batch inactive and new batch active
            $UserProductObject = $this->mongoUserProductObject->find($seacrhCondUP);  
            if(count($UserProductObject) > 0){                
                $updateValues = [];
                $updateValues['batch']["InActiveBatch"][] = $UserProductObject['batch']["ActiveBatch"];
                $updateValues['batch']["ActiveBatch"] = $activeBatch;
                // update the existing doc                
                $result = $this->mongoUserProductObject->update($seacrhCondUP,$updateValues);
            } else{
                $error["message"] = "no records found";
                return false;            }
            
        } else {
            $error["message"]= "Missing input arguments";
            return false;
        }
        return $result;
    }

    // Activate and deactivate userproduct group
    // Input : UPID,newBatchID, newGroupID
    private function userProductGroupUpdate($Data){    
        $UPID=$Data['UPID'] ?? "";
        $newBatchID = $Data['batchID'] ?? "";
        $newGroupID = $Data['groupID'] ?? "";
        
        $seacrhCondUP = ["UPID"=> $UPID];

        $activeGroup =[ "groupID" => $newGroupID,
            "roll" => "regularStudent",
            "batchID" => $newBatchID,
			"status" => "A"			
        ];
         // check all input parameters passed
         if(isset($UPID) && isset($newBatchID) && isset($newGroupID)){
            // make existing batch inactive and new batch active
            $UserProductObjects = $this->mongoUserProductObject->findAll($seacrhCondUP); 
             
            if(count($UserProductObjects) > 0){
               $UserProductObjects = $UserProductObjects[0];
               
                
                // check array and not empty records
                if(is_array($UserProductObjects["activeGroups"]) && !empty($UserProductObjects["activeGroups"])){
                
                    // if teacher exclude having same batch
                    if($UserProductObjects["category"]== "teacher"){
                        $UserProductObject["activeGroups"]= array_filter($UserProductObjects["activeGroups"], function ($var) {	
                            if($var['role'] == 'classTeacher' && $var["batchID"] != $newBatchID ){
                                return $var;
                            }else{
                                return $var;
                            }
                        });
                    } else{
                        $UserProductObject["activeGroups"]= $UserProductObjects["activeGroups"];
                    }
                    // if multiple groups make all deactive
                  
                    foreach($UserProductObject["activeGroups"] as $key => $value){
                        
                            $UserProductObject["activeGroups"][$key]['status']= "D";
                                          
                    }
                }
                $updateValues = [];
               
                $updateValues['inactiveGroups'] = $UserProductObject["activeGroups"] ?? [];
                $updateValues["activeGroups"] = $activeGroup;
               
                // update the existing doc
                $result = $this->mongoUserProductObject->update($seacrhCondUP,$updateValues);
            } else{
                $error["message"] = "no records found";
                return false;            }
            
        } else {
            $error["message"]= "Missing input arguments";
            return false;
        }
        return $result;

    }

    public function identifyClassUpgradeType($dataDiff,$mongoUpdatedData, $mysqlData, $mongoData){

        $this->mongoUpdatedData = $mongoUpdatedData;
        $classAndSectionChange = [];
        $organizationChange = [];
        $academicYearChange = $mongoUpdatedData['academicYearChange'];
        $classUpgradeType = null;
        if ( isset($academicYearChange) && $academicYearChange == 1) {
            //academic year changed
            (empty($mongoUpdatedData['organizationChange']) && !empty($mongoUpdatedData['classAndSectionChange']) ) ? $classUpgradeType = "TYPE7" : NULL;
            (!array_key_exists('class',$dataDiff) && array_key_exists('section',$dataDiff) ) ? $classUpgradeType = "TYPE8" : NULL;
            (!empty($mongoUpdatedData['organizationChange']) && empty($mongoUpdatedData['classAndSectionChange']) ) ? $classUpgradeType = "TYPE9" : NULL;
            (!empty($mongoUpdatedData['organizationChange']) && !empty($mongoUpdatedData['classAndSectionChange']) ) ? $classUpgradeType = "TYPE10" : NULL;
            (empty($mongoUpdatedData['classAndSectionChange']) && isset($mongoUpdatedData['subscriptionModeChange']) ) ? $classUpgradeType = "TYPE11" : NULL;
            (!empty($mongoUpdatedData['classAndSectionChange']) && isset($mongoUpdatedData['subscriptionModeChange']) ) ? $classUpgradeType = "TYPE12" : NULL;
        } else {
            //academic year did not change
            (empty($mongoUpdatedData['organizationChange']) && !empty($mongoUpdatedData['classAndSectionChange']) ) ? $classUpgradeType = "TYPE1" : NULL;
            (!array_key_exists('class',$dataDiff) && array_key_exists('section',$dataDiff) ) ? $classUpgradeType = "TYPE2" : NULL;
            (!empty($mongoUpdatedData['organizationChange']) && empty($mongoUpdatedData['classAndSectionChange']) ) ? $classUpgradeType = "TYPE3" : NULL;
            (!empty($mongoUpdatedData['organizationChange']) && !empty($mongoUpdatedData['classAndSectionChange']) ) ? $classUpgradeType = "TYPE4" : NULL;
            (empty($mongoUpdatedData['classAndSectionChange']) && isset($mongoUpdatedData['subscriptionModeChange']) ) ? $classUpgradeType = "TYPE5" : NULL;
            (!empty($mongoUpdatedData['classAndSectionChange']) && isset($mongoUpdatedData['subscriptionModeChange']) && !empty($mongoUpdatedData['organizationChange'])) ? $classUpgradeType = "TYPE6" : NULL;
        }

        return $classUpgradeType;
    }
 
    public function upgradeTypeMapping($classUpgradeType, $mongoUpdatedData, $mysqlData, $mongoData){
        echo "<pre>";print_r($mongoUpdatedData);               
        echo "<pre>";print_r($classUpgradeType);
       
        $data = [ 
            'UID' => $mongoData['UID']
        ];
        $this->checkAndSyncOrderDetails($mysqlData['schoolCode'],$mongoData['orgID']);

        switch( $classUpgradeType ){
            case 'TYPE1' : $this->updateType1($data, $mongoUpdatedData, $mysqlData, $mongoData); break;
            case 'TYPE2' : $this->updateType2( $data, $mongoUpdatedData, $mysqlData, $mongoData ); break;
            case 'TYPE3' : $this->updateType3( $data, $mongoUpdatedData, $mysqlData, $mongoData ); break;
            case 'TYPE4' : $this->updateType4( $data, $mongoUpdatedData, $mysqlData, $mongoData ); break;
            case 'TYPE5' : $this->updateType5( $data, $mongoUpdatedData, $mysqlData, $mongoData ); break;
            case 'TYPE6' : $this->updateType6( $data, $mongoUpdatedData, $mysqlData, $mongoData ); break;
            case 'TYPE7' : $this->updateType7( $data, $mongoUpdatedData, $mysqlData, $mongoData ); break;
            case 'TYPE8' : $this->updateType8( $data, $mongoUpdatedData, $mysqlData, $mongoData ); break;
            case 'TYPE9' : $this->updateType9( $data, $mongoUpdatedData, $mysqlData, $mongoData ); break;
            case 'TYPE10' : $this->updateType10( $data, $mongoUpdatedData, $mysqlData, $mongoData ); break;
            case 'TYPE11' : $this->updateType11( $data, $mongoUpdatedData, $mysqlData, $mongoData ); break;
            case 'TYPE12' : $this->updateType12( $data, $mongoUpdatedData, $mysqlData, $mongoData ); break;
        }

    }

    public function callAPI($params,$urlAPI){
        $curl = curl_init();
        curl_setopt_array($curl, array(
			CURLOPT_URL => $urlAPI,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "POST",
			CURLOPT_POSTFIELDS => http_build_query($params),
			CURLOPT_HTTPHEADER => array("Auth: EISecret")
		));
		$response = curl_exec($curl);
		$err = curl_error($curl);
        curl_close($curl);
		if(!$err){			
            $jsonData = json_decode($response, true);
			error_log(json_encode($jsonData));
            return $jsonData;
		} 
    }

}
