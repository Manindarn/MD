<?php
require_once 'Organization.php';
require_once 'ITypes.php';
require_once dirname(__FILE__)."/../ClassUpgradeHelper.php";

class User implements ITypes
{
    use HelperFunctions;

    private $userAuthObject = null;
    private $userUserDetailsObject = null;
    private $mongoOrgObject = null;
    private $mongoBatchObject = null;
    private $mongoOrderObject = null;
    private $mongoGroupsObject = null;
    private $mongoSectionObject = null;
    private $userStateObject = null;
    private $userProductObject = null;
    private $mongoPSBObject = null;
    private $secretQuesObject = null;

    private $mysqlCommonUserObject = null;
    private $mysqlUpgradationConfirmation = null;
    private $portConfig = null;
    public $portingStatus = [];
    private $allInsertResult = [];
    private $portedUsersAndMapping = [];
    private $organizationAndMappings = [];
    private $groups = [];
    private $currentDateTime = null;
    private $userStatus = 'A';

    public $orgIDForOffline = null;
    public $oldOrgID = null;

    private $selectedColumns = [
        'id', 'Name', 'first_name', 'last_name', 'username', 'secretQues', 'secretAns', 'category', 'class',
        'section', 'subcategory', 'gender', 'dob', 'pan_number', 'childEmail', 'parentName', 'additionalEmail',
        'address', 'city', 'state', 'country', 'pincode', 'contactno_cel', 'profilePicture', 'MS_enabled', 'MS_userID',
        'startDate', 'endDate', 'MSE_enabled', 'MSE_userID', 'MSE_startDate', 'MSE_endDate', 'teacherClasses', 'schoolCode', 'lastModified', 'updated_by', 'created_by',
    ];

    private $directFieldMapping = ['secretAns' => 'secretAns', 'dob' => 'dateOfBirth', 'pan_number' => 'panNumber', 'lastModified' => 'updatedAt']; //mysql => mongo

    public function __construct()
    {
        $this->mongoOrgObject = new MongoDB('Organizations');
        $this->mongoBatchObject = new MongoDB('Batch');
        $this->mongoOrderObject = new MongoDB('Order');
        $this->mongoGroupsObject = new MongoDB('Groups');
        $this->mongoSectionObject = new MongoDB('SectionDetails');
        $this->mongoPSBObject = new MongoDB('ProductSectionBatchMapping');
        $this->userUserDetailsObject = new MongoDB('UserDetails');
        $this->userProductObject = new MongoDB('UserProducts');
        $this->secretQuesObject = new MongoDB('SecretQuestions', USER_MANAGEMENT_DB);
        $this->userAuthObject = new MongoDB('Authentication', USER_AUTHENTICATION_DB);
        $this->userStateObject = new MongoDB('UserState', PEDAGOGY_DB);

        $this->UMPortingLogObject = new MongoDB('UMPortingLog', PORTING_DB);

        $this->mysqlCommonUserObject = new MySqlDB('common_user_details', 'educatio_educat');
        $this->mysqlUpgradationConfirmation = new MySqlDB('upgradation_conformation', 'educatio_educat');

        $this->portConfig = $this->getConfig('porting');
        $this->tagsAndSettings = $this->portConfig['tagsAndSettings'];
        $this->defaultMathPID = $this->portConfig['defaultMathPID'];
        $this->defaultEnglighPID = $this->portConfig['defaultEnglighPID'];

        $this->currentDateTime = date(DATETIME_FORMAT);
    }

    public function getSecretQuestions($value = '')
    {
        $secretQues = $this->secretQuesObject->findAll();
        return array_combine(array_column($secretQues, 'SQID'), array_column($secretQues, 'question'));
    }

    public function getOrgRelatedIDs($oldOrgIDs, $ifNotPortedPort = false)
    {
        $returnData = false;

        $oldOrgIDs = array_values(array_unique($oldOrgIDs));

        $orgDetails = $this->mongoOrgObject->findAll(['oldOrgID' => ['$in' => $oldOrgIDs]]) ?? [];

        if (!empty($orgDetails)) {

            foreach ($orgDetails as $key => $orgData) {
                $oldOrgID = $orgData['oldOrgID'];
                $newOrgID = $orgData['orgID'];

                $batchDetails = $this->mongoBatchObject->find(['orgID' => $newOrgID, 'status' => 'A']);
                if ($batchID = $batchDetails['batchID'] ?? false) {

                    $orderDetails = $this->mongoOrderObject->findAll([
                        'orgID' => $newOrgID,
                        'status' => 'A',
                        'PID' => ['$in' => [$this->defaultMathPID, $this->defaultEnglighPID]],
                    ]);

                    if (!empty($orderDetails)) {
                        $orderDetails = array_combine(array_column($orderDetails, 'PID'), array_column($orderDetails, 'orderID'));

                        $this->organizationAndMappings[$oldOrgID] = [
                            'oldOrgID' => $oldOrgID,
                            'orgID' => $newOrgID,
                            'orgName' => $orgData['name'],
                            'batchID' => $batchID,
                            'batchName' => $batchDetails['name'],
                            'order' => $orderDetails,
                        ];

                        $returnData = true;

                    } else {
                        $msg = "Order details not found for this orgID: " . $newOrgID;
                        $this->triggerError(['message' => $msg, 'docID' => $newOrgID], $this->UMPortingLogObject);
                    }
                } else {
                    $msg = "Please create batch first for this orgID: " . $newOrgID;
                    $this->triggerError(['message' => $msg, 'docID' => $newOrgID], $this->UMPortingLogObject);
                }

            }

        } else {
            if ($ifNotPortedPort && false) {
                //added false, to prevent force organization porting
                $orgPortObject = new Organization();
                $oldOrgID = current($oldOrgIDs);
                $orgPortObject->portOrgAndAll($oldOrgID);

                $this->organizationAndMappings = $orgPortObject->portedOrgAndMappings;
                $returnData = true;
            }
        }

        return $returnData;

    }

    public function getOldUsers($oldOrgIDs)
    {

        $returnData = [];

        $oldOrgIDs = array_values(array_unique($oldOrgIDs));

        if ($this->getOrgRelatedIDs($oldOrgIDs)) {

            $sqlQueryEndDate = $this->portConfig['userEndDate'];

            $sql = "SELECT cu.id commonID, cu.*, GROUP_CONCAT(CONCAT(tcm.class,'~', tcm.section,'~', tcm.subjectno) order by tcm.subjectno, tcm.class, tcm.section  SEPARATOR '|') teacherClasses
                FROM educatio_educat.common_user_details cu
                LEFT JOIN educatio_adepts.adepts_teacherClassMapping tcm ON ((cu.MS_userID=tcm.userID and tcm.subjectno=2) or (cu.MSE_userID=tcm.userID  and tcm.subjectno=1))
                WHERE cu.schoolCode IN (" . implode(', ', $oldOrgIDs) . ")
                AND ((cu.MS_enabled=1 AND cu.endDate >=" . $sqlQueryEndDate . " ) OR (cu.MSE_enabled=1 AND cu.MSE_endDate >=" . $sqlQueryEndDate . ")) GROUP BY cu.MS_userID, cu.MSE_userID";

            $returnData = $this->mysqlCommonUserObject->rawQuery($sql)['data'] ?? [];

            if (!empty($returnData)) {
                $this->setCreatedByAndUpdatedBy_Mysql($returnData);
            }

        } else {
            $this->portingStatus['errorMsg'] = 'no Organizations ported, please port Organization first';
        }

        return $returnData;

    }

    public function portUsers($oldOrgIDs)
    {
        $oldUsersDetails = $this->getOldUsers($oldOrgIDs);

        if (!empty($oldUsersDetails)) {
            foreach ($oldUsersDetails as $key => $oldData) {
                $this->groups = [];
                $this->portThisUser($oldData['id'], $oldData);
            }
        }

        foreach ($this->allInsertResult as $type => $insertResult) {
            $this->portingStatus = $this->getPrintStatus($insertResult, $type, $this->portingStatus);
        }

        return $this->portingStatus;
    }

    public function portThisUser($commonID, $oldData = [])
    {

        $this->orgIDForOffline = $this->getOrgID($oldData['schoolCode']);
        $this->oldOrgID = $oldData['schoolCode'];
        $this->setOrgIDForOffline();

        $this->getUserDetailsAndProducts($oldData);

        $activeGrades = $this->portConfig['activeGrades'];
        $activeSections = $this->portConfig['activeSections'];
        $teacherUsers = $this->portConfig['teacherUsers'];

        $this->userStatus = 'A';
        $teacherStatus = 'D';
        # if there is no specified grade or section port all the data
        if (!empty($activeGrades) || !empty($activeSection) || !empty($teacherUsers)) {
            # grade is not mentioned, set status as deactive
            if (!empty($activeGrades) && !in_array($oldData['class'], $activeGrades)) {
                $this->userStatus = 'D';
            }
            if (!empty($activeSection) && !in_array($oldData['section'], $activeSection)) {
                $this->userStatus = 'D';
            }
            //!empty($teacherUsers) &&

            if (in_array($oldData['username'], $teacherUsers) && in_array(strtolower($oldData['category']), ['teacher', 'school admin'])) {
                $teacherStatus = 'A';
                $this->userStatus = $teacherStatus;
            }
        }

        $userDetailsResult = $this->createUserDetails($oldData);
        $userAuthResult = $this->createUserAuth($oldData, $userDetailsResult);
        $userProductResult = $this->createUserProduct($oldData, $userDetailsResult);

        $class = (string) $this->isStudent($oldData) ? $oldData['class'] : '';

        $this->findAndInsertToMSRearchTable($oldData['schoolCode'], $class);

        return ['userDetails' => $userDetailsResult, 'userAuth' => $userAuthResult, 'userProduct' => $userProductResult];

    }

    private function getUserDetailsAndProducts(&$oldData)
    {
        $products = [];
        if (($MS_enabled = $oldData['MS_enabled'] ?? false) && ($MS_userID = $oldData['MS_userID'] ?? false)) {
            $products['Mindspark'] = [
                'userID' => $MS_userID,
                'startDate' => $oldData['startDate'],
                'endDate' => $oldData['endDate'],
            ];
        }

        if (($MSE_enabled = $oldData['MSE_enabled'] ?? false) && ($MSE_userID = $oldData['MSE_userID'] ?? false)) {
            $products['MSE'] = [
                'userID' => $MSE_userID,
                'startDate' => $oldData['MSE_startDate'],
                'endDate' => $oldData['MSE_endDate'],
            ];
        }

        $oldData['products'] = $products;
    }

    private function createUserDetails($oldData)
    {
        $UID = $this->userUserDetailsObject->newMongoId();

        $orgID = $this->organizationAndMappings[$oldData['schoolCode']]['orgID'];

        $name = explode(" ", $oldData['Name'], 2);

        $secretQnData = array_search($oldData['secretQues'], $this->getSecretQuestions());

        $insertData = [
            "_id" => $UID,
            "UID" => $UID,
            "name" => $oldData['Name'],
            "firstName" => $name[0],
            "lastName" => $name[1] ?? null,
            "secretQn" => $secretQnData,
            "secretAns" => $oldData['secretAns'],
            "childUIDs" => null,
            "orgID" => $orgID,
            "parents" => $this->getParentDetails($oldData),
            "dateOfBirth" => $this->getFormatedDate($oldData['dob']),
            "imageURL" => $oldData['gender'] == "B" ? "male.png" : "female.png",
            "email" => $this->getEmail($oldData),
            "mobile" => $this->getMobile($oldData),
            "address" => $this->getAddress($oldData),
            // "products" => [], update this after user product creation
            'userOtherDetails' => [
                'db' => 'educatio_educat',
                'table' => 'common_user_details',
                'id' => 'id',
                'value' => $oldData['id'],
            ],
            "panNumber" => $oldData['pan_number'] ?? null,
            "gender" => (($oldData['gender'] == "B") ? "M" : "F"),
            // "timeAllowedPerDay"  => $oldData['timeAllowedPerDay'],
            "oldUserID" => $oldData['id'],
            "dateOfRegistration" => $this->getFormatedDate($oldData['startDate']),
            "verified" => ($verified = $oldData['verified'] ?? null == null) ? true : $verified,
            "_createdAt" => $this->getFormatedDate($oldData['created_dt'], DATETIME_FORMAT),
            "createdAt" => $this->getFormatedDate($oldData['created_dt'], DATETIME_FORMAT),
            "createdBy" => ($createdBy = $oldData['created_by'] ?? false) ? $this->getFromGlobal($createdBy, $createdBy) : 'script',
            "updatedAt" => $this->getFormatedDate($oldData['lastModified'], DATETIME_FORMAT),
            "_updatedAt" => $this->getFormatedDate($oldData['lastModified'], DATETIME_FORMAT),
            "updatedBy" => ($updatedBy = $oldData['updated_by'] ?? false) ? $this->getFromGlobal($updatedBy, $updatedBy) : 'script',
            "version" => 1,
            "status" => $this->userStatus,
            "portedAt" => $this->getTodayDateTime(),
        ];

        $searchCondition = ['oldUserID' => $oldData['id']];

        $this->allInsertResult['UserDetails'][] = $insertResult = $this->userUserDetailsObject->findInsert($searchCondition, $insertData);

        if ($insertResult['errorCode'] == 1) {
            $insertData['_id'] = $insertResult['val']['_id'];
            $insertData['UID'] = $insertResult['val']['UID'];
        }

        return $insertData;

    }

    private function createUserAuth($oldData, $userDetailsResult)
    {

        $_id = $this->userAuthObject->newMongoId();

        $username = strtolower($oldData['username']);

        $insertData = [
            "_id" => $_id,
            "username" => $username,
            "password" => md5($username),
            "passwordType" => "text",
            "wrongAttempt" => 0,
            "accountLocked" => false,
            "passwordReset" => [
                "requestFlag" => false,
                "requestAt" => $this->currentDateTime,
                "resetFlag" => false,
                "resetBy" => "script",
                "resetAt" => $this->currentDateTime,
                "resetPasswordType" => (($oldData['class'] <= $this->tagsAndSettings['lowerGradeUpperLimit'] && $oldData['category'] == "STUDENT") ? "picture" : "text"),
            ],
            "UID" => $userDetailsResult['UID'],
            "createdAt" => $this->currentDateTime,
            "active" => $userDetailsResult['status'] == 'A',
            "version" => 1,

        ];
        $searchCondition = ['username' => $username];

        $this->allInsertResult['UserAuthentication'][] = $insertResult = $this->userAuthObject->findInsert($searchCondition, $insertData);

        if ($insertResult['errorCode'] == 1) {
            $insertData['_id'] = $insertResult['val']['_id'];
        }

        return $insertData;

    }

    public function findAndInsertToMSRearchTable($schoolCode, $class = '')
    {
        $MSRearchTable = new MySqlDB('mindsparkReArch', 'educatio_adepts');

        $MSRearchTableData = $MSRearchTable->get('schoolCode = ' . $schoolCode . ' AND childClass = "' . $class . '"')['data'] ?? [];

        if ($MSRearchTableData['id'] ?? false) {
            $allowed = $MSRearchTableData['allowed'] ?? false;

            if ($allowed == '0') {
                $MSRearchTable->update('id=' . $MSRearchTableData['id'], ['allowed' => 1, 'message' => '']);
            }
        } else {
            $insertData = ['schoolCode' => $schoolCode, 'childClass' => $class];
            $MSRearchTable->insertMany([$insertData]);
        }
    }

    public function checkAndCreateSectionPSBAndGroup($oldData, $PID)
    {
        $returnData = [];
        if ((in_array($PID, [$this->defaultMathPID, $this->defaultEnglighPID])) && ($oldData['class'] ?? false)) {
            $orgMappingIDs = $this->organizationAndMappings[$oldData['schoolCode']];      
            $order = $orgMappingIDs['order'];

            $sectionResult = $this->checkAndCreateSection($oldData);
            $PSBResult = $this->checkAndCreatePSBMapping($oldData, $PID, $order[$PID], $sectionResult['SDId']);
            $groupResult = $this->checkAndCreateGroup($oldData, $PID, $PSBResult);

            $returnData = ['section' => $sectionResult, 'PSBMapping' => $PSBResult, 'group' => $groupResult];

        }

        return $returnData;

    }

    private function createUserProduct($oldData, &$userDetails)
    {

        $oldOrgID = $oldData['schoolCode'];

        $orgMappingIDs = $this->organizationAndMappings[$oldOrgID];
        $batchID = $orgMappingIDs['batchID'];
        $order = $orgMappingIDs['order'];

        $UID = $userDetails['UID'];
        $userDetailsProductDetails = $userDetails['products'] ?? [];

        $useProducts = $sectionPSBAndGroupResult = [];

        foreach ($oldData['products'] as $PID => $pValue) {

            if ($this->isStudent($oldData)) {
                $sectionPSBAndGroupResult = $this->checkAndCreateSectionPSBAndGroup($oldData, $PID);
            }

            if ($this->isTeacher($oldData) || $this->isSchoolAdmin($oldData)) {
                $this->checkAndCreateTeacherGroup($orgMappingIDs, $PID);
            }

            $UPID = $this->userProductObject->newMongoId();

            $oldUPID = $pValue['userID'];

            $insertData = [
                '_id' => $UPID,
                'UPID' => $UPID,
                'oldUPID' => $oldUPID,
                'UID' => $UID,
                "PID" => $PID,
                "activeGroups" => [],
                "inactiveGroups" => [],
                'batch' => [
                    'ActiveBatch' => [
                        "batchID" => $batchID,
                        "grade" => $this->isStudent($oldData) ? $oldData['class'] : null,
                        "section" => $this->isStudent($oldData) ? $oldData['section'] : null,
                        "rollNo" => $oldData['rollNo'] ?? null,
                        "groupID" => $this->isStudent($oldData) ? ($sectionPSBAndGroupResult['group']['groupID'] ?? null) : null,
                    ],
                ],
                "category" => $this->isStudent($oldData) ? 'student' : 'teacher',
                'activationDate' => $this->getFormatedDate($pValue['startDate']),
                'expiryDate' => $this->getFormatedDate($pValue['endDate']),
                'dateOfRegistration' => $this->getFormatedDate($pValue['startDate']),
                'orderID' => $order[$PID],
                'tags' => $this->getTagsAndSettings($oldData)['tags'] ?? [],
                'userProductOtherDetails' => [
                    'db' => 'educatio_adepts',
                    'table' => 'adepts_userDetails',
                    'id' => 'userID',
                    'value' => $oldUPID,
                ],
                'langContext' => 'en-IN',
                'settings' => $this->getTagsAndSettings($oldData)['settings'] ?? [],
                "_createdAt" => $this->currentDateTime,
                "createdAt" => $this->currentDateTime,
                "createdBy" => ($createdBy = $oldData['created_by'] ?? false) ? $this->getFromGlobal($createdBy, $createdBy) : 'script',
                "_updatedAt" => $this->currentDateTime,
                "updatedAt" => $this->currentDateTime,
                "updatedBy" => ($updatedBy = $oldData['updated_by'] ?? false) ? $this->getFromGlobal($updatedBy, $updatedBy) : 'script',
                "status" => $this->userStatus,
                "version" => 1,
            ];

            $searchCondition = ['oldUPID' => $oldUPID, 'PID' => $PID];

            $this->allInsertResult['UserProducts'][] = $insertResult = $this->userProductObject->findInsert($searchCondition, $insertData);

            if ($insertResult['errorCode'] == 1) {
                $insertData['_id'] = $insertResult['val']['_id'];
                $insertData['UPID'] = $UPID = $insertResult['val']['UPID'];

            }

            $useProducts[$PID] = [
                "activationDate" => $this->getFormatedDate($pValue['startDate']),
                "expiryDate" => $this->getFormatedDate($pValue['endDate']),
                "isFirstLogin" => $userDetailsProductDetails[$PID]['isFirstLogin'] ?? true,
                "UPID" => $UPID,
            ];

            $this->updateUserGroups($oldData, $insertData);

            if ($this->isStudent($oldData)) {
                $this->createUserUserState($oldData, $insertData);
            }
        }

        $updatedUP = [];
        foreach ($useProducts as $key => $value) {
            $userDetails['products'][$key] = $value;
            $updatedUP['products.' . $key] = $userDetails['products'][$key];
        }
        if (count($updatedUP) > 0) {
            $this->userUserDetailsObject->update(['UID' => $UID], $updatedUP);
        }

    }

    private function checkAndCreateTeacherGroup($orgMappingIDs, $PID)
    {
        $newOrgID = $orgMappingIDs['orgID'];
        $newBatchID = $orgMappingIDs['batchID'];
        $orgName = $orgMappingIDs['orgName'];
        $batchName = $orgMappingIDs['batchName'];
        $oldOrgID = $orgMappingIDs['oldOrgID'];

        $seacrhCond = ['otherIds.orgID' => $newOrgID, 'otherIds.batchID' => $newBatchID, 'type' => $this->portConfig['orgTeacherGroup'], 'PID' => $PID];

        $orgTeacherGroupDetails = $this->mongoGroupsObject->find($seacrhCond);

        if (!($groupID = $orgTeacherGroupDetails['groupID'] ?? false)) {
            $groupID = $this->mongoGroupsObject->newMongoId();

            $orgTeacherGroupDetails = [
                "_id" => $groupID,
                "groupID" => $groupID,
                "contentAssigned" => [],
                "type" => $this->portConfig['orgTeacherGroup'],
                "members" => [],
                "name" => $orgName . " - " . $batchName,
                "PSBId" => null,
                "description" => null,
                "PID" => $PID,
                "otherIds" => [
                    'batchID' => $newBatchID,
                    'orgID' => $newOrgID,
                ],
                "passwordResetRequest" => [],
                "createdAt" => $this->getTodayDateTime(),
                "createdBy" => 'script',
                "updatedAt" => $this->getTodayDateTime(),
                "updatedBy" => "script",
                "status" => "A",
                "version" => 1,
            ];
            $this->mongoGroupsObject->insert($orgTeacherGroupDetails);
        }

        $this->organizationAndMappings[$oldOrgID]['orgGroupID'] = $this->organizationAndMappings[$oldOrgID]['groupIDs'][] = $groupID;
        $this->organizationAndMappings[$oldOrgID]['orgGroupIDProductWise'][$PID] = $this->organizationAndMappings[$oldOrgID]['groupIDs'][] = $groupID;

        return $orgTeacherGroupDetails;
    }

    private function checkAndCreateSection($oldData)
    {

        $oldOrgID = $oldData['schoolCode'];

        $orgMappingIDs = $this->organizationAndMappings[$oldOrgID];
        $sectionName = trim($oldData['class'] . " " . trim($oldData['section']));

        $seacrhCondSection = [
            'orgID' => $orgMappingIDs['orgID'],
            'grade' => $oldData['class'],
            'name' => $sectionName,
            'status' => 'A',
        ];



        $sectionDetails = $this->mongoSectionObject->find($seacrhCondSection);

        //Checks for section, if not present it creates
        if (!($SDId = $sectionDetails['SDId'] ?? false)) {

            $SDId = $this->mongoSectionObject->newMongoId();

            $sectionDetails = [
                '_id' => $SDId,
                'SDId' => $SDId,
                'orgID' => $orgMappingIDs['orgID'],
                'grade' => $oldData['class'],
                'name' => $sectionName,
                'createdAt' => $this->getTodayDateTime(),
                'createdBy' => 'script',
                'updatedAt' => $this->getTodayDateTime(),
                'updatedBy' => 'script',
                'version' => 1,
                'status' => 'A',
            ];

            $this->mongoSectionObject->insert($sectionDetails);
        }

        if (!isset($this->organizationAndMappings[$oldOrgID]['SDIds'])) {
            $this->organizationAndMappings[$oldOrgID]['SDIds'] = [];
        }

        array_push($this->organizationAndMappings[$oldOrgID]['SDIds'], $SDId);

        if (!isset($this->organizationAndMappings[$oldOrgID]['grades'])) {
            $this->organizationAndMappings[$oldOrgID]['grades'] = [];
        }

        if (!isset($this->organizationAndMappings[$oldOrgID]['grades'][$oldData['class'] . ""])) {
            $this->organizationAndMappings[$oldOrgID]['grades'][$oldData['class'] . ""] = [];
        }

        $this->organizationAndMappings[$oldOrgID]['grades'][$oldData['class'] . ""]["_" . $oldData['section']]['SDId'] = $SDId;

        return $sectionDetails;

    }

    private function checkAndCreatePSBMapping($oldData, $PID, $orderID, $SDId)
    {
        $oldOrgID = $oldData['schoolCode'];
        $orgRelatedIDs = $this->organizationAndMappings[$oldOrgID];
        $seacrhCondPSB = [
            'batchID' => $orgRelatedIDs['batchID'],
            'orgID' => $orgRelatedIDs['orgID'],
            'orderID' => $orderID,
            'PID' => $PID,
            'grade' => $oldData['class'],
            'SDId' => $SDId,
            'status' => 'A',
        ];


        $PSBDetails = $this->mongoPSBObject->find($seacrhCondPSB);

        if (!($PSBId = $PSBDetails['PSBId'] ?? false)) {

            $PSBId = $this->mongoPSBObject->newMongoId();

            $groupID = $this->mongoGroupsObject->newMongoId();

            $PSBDetails = [
                '_id' => $PSBId,
                'PSBId' => $PSBId,
                'batchID' => $orgRelatedIDs['batchID'],
                'orgID' => $orgRelatedIDs['orgID'],
                'groupIDs' => [$groupID],
                'orderID' => $orderID,
                'PID' => $PID,
                'grade' => $oldData['class'],
                'SDId' => $SDId,
                'updatedAt' => $this->getTodayDateTime(),
                'status' => 'A',
                'version' => 1,
            ];

            $insertResult = $this->mongoPSBObject->insert($PSBDetails);
        }

        return $PSBDetails;

    }

    private function checkAndCreateGroup($oldData, $PID, $PSBResult)
    {
        $groupID = $PSBResult['groupIDs'][0];
        $PSBId = $PSBResult['PSBId'];

        $oldOrgID = $oldData['schoolCode'];
        $orgRelatedIDs = $this->organizationAndMappings[$oldOrgID];

        $seacrhCond = ['groupID' => $groupID];

        $groupDetails = $this->mongoGroupsObject->find($seacrhCond);

        if (!($existGroupID = $groupDetails['groupID'] ?? false)) {

            $groupDetails = [
                '_id' => $groupID,
                'groupID' => $groupID,
                'contentAssigned' => [],
                'type' => $this->portConfig['sectionType'],
                'members' => [],
                'name' => trim($oldData['class'] . " " . trim($oldData['section'])),
                'PSBId' => $PSBId,
                'description' => null,
                'PID' => $PID,
                'otherIds' => [
                    'batchID' => $orgRelatedIDs['batchID'],
                    'grade' => $oldData['class'],
                    'orgID' => $orgRelatedIDs['orgID'],
                    'SDId' => $PSBResult['SDId'],
                ],
                'passwordResetRequest' => [],
                'createdAt' => $this->getTodayDateTime(),
                'createdBy' => 'script',
                'updatedAt' => $this->getTodayDateTime(),
                'updatedBy' => "script",
                'status' => 'A',
                'settings' => [],
                'version' => 1,
            ];

            $insertResult = $this->mongoGroupsObject->insert($groupDetails);
            $groupDetails['newSectionGroupCreated'] = true;

        }

        $this->organizationAndMappings[$oldOrgID]['grades'][$oldData['class'] . ""]["_" . $oldData['section']]['groupID'] = $groupID;
        if (!isset($this->organizationAndMappings[$oldOrgID]['groupIDs'])) {
            $this->organizationAndMappings[$oldOrgID]['groupIDs'] = [];
        }
        array_push($this->organizationAndMappings[$oldOrgID]['groupIDs'], $groupID);

        return $groupDetails;
    }

    private function updateUserGroups($oldData, $userProductResult)
    {
        $portConfig = $this->portConfig;
        $orgRelatedIDs = $this->organizationAndMappings[$oldData['schoolCode']];
        $batchID = $orgRelatedIDs['batchID'];

        $PIDSubjectWise = $this->portConfig['PIDSubjectWise'];

        $sectionsAndGroupsGradeWise = $orgRelatedIDs['grades'] ?? [];

        $orgGroupIDProductWise = $this->isSchoolAdmin($oldData) || $this->isTeacher($oldData) ? ($orgRelatedIDs['orgGroupIDProductWise'] ?? null) : null;

        $role = null;

        $userGroupData = [];

        $UPID = $userProductResult['UPID'];
        $PID = $userProductResult['PID'];

        $orgGroupID = $orgGroupIDProductWise[$PID] ?? null;

        switch (strtolower($oldData['category'])) {
            case 'school admin':
                $role = $this->portConfig['role']['admin'];
                $userGroupData = $this->addGroupsForSchoolAdmin($UPID);

                if ($orgGroupID) {

                    if (!isset($this->groups[$orgGroupID])) {
                        $this->groups[$orgGroupID] = [];
                    }
                    if (!isset($this->groups[$orgGroupID][$role])) {
                        $this->groups[$orgGroupID][$role] = [];
                    }

                    array_push($this->groups[$orgGroupID][$role], $UPID);

                    $userGroupData[] = ["groupID" => $orgGroupID, "role" => $role, "batchID" => $batchID, "status" => "A"];

                    $oldGroupMembers = $this->mongoGroupsObject->find(['groupID' => $orgGroupID])['members'] ?? [];

                    $updatedGroupMembers = $this->getUniqeMembers(array_merge_recursive($oldGroupMembers, [$role => $UPID]));
                    $this->mongoGroupsObject->update(['groupID' => $orgGroupID], ['members' => $updatedGroupMembers]);
                }

                $teacherClass = explode("|", $oldData['teacherClasses']);

                if (!empty($teacherClass)) {
                    $role = $this->portConfig['role']['teacher'];

                    foreach ($teacherClass as $key => $value) {

                        $gradeArr = explode("~", $value);

                        if (($gradeArr[0] ?? false) && ($subjectno = $gradeArr[2] ?? false) && ($PIDForGroup = $PIDSubjectWise[$subjectno] ?? false)) {

                            $oldData['class'] = $gradeArr[0];
                            $oldData['section'] = $gradeArr[1] ?? '';

                            $sectionPSBAndGroupResult = $this->checkAndCreateSectionPSBAndGroup($oldData, $PIDForGroup);

                            if ($gid = $sectionPSBAndGroupResult['group']['groupID'] ?? false) {
                                if (!isset($this->groups[$gid])) {
                                    $this->groups[$gid] = [];
                                }
                                if (!isset($this->groups[$gid][$role])) {
                                    $this->groups[$gid][$role] = [];
                                }
                                array_push($this->groups[$gid][$role], $UPID);
                                $userGroupData[] = ["groupID" => $gid, "role" => $role, "batchID" => $batchID, "status" => "A"];
                            }

                        }
                    }
                }

                break;
            case 'teacher':
                $role = $this->portConfig['role']['teacher'];

                if (!isset($this->groups[$orgGroupID])) {
                    $this->groups[$orgGroupID] = [];
                }
                if (!isset($this->groups[$orgGroupID][$role])) {
                    $this->groups[$orgGroupID][$role] = [];
                }
                array_push($this->groups[$orgGroupID][$role], $UPID);
                $userGroupData[] = ["groupID" => $orgGroupID, "role" => $role, "batchID" => $batchID, "status" => "A"];

                $teacherClass = explode("|", $oldData['teacherClasses']);

                if (!empty($teacherClass)) {

                    foreach ($teacherClass as $key => $value) {
                        $gradeArr = explode("~", $value);

                        if (($gradeArr[0] ?? false) && ($subjectno = $gradeArr[2] ?? false) && ($PIDForGroup = $PIDSubjectWise[$subjectno] ?? false)) {

                            $oldData['class'] = $gradeArr[0];
                            $oldData['section'] = $gradeArr[1] ?? '';

                            $sectionPSBAndGroupResult = $this->checkAndCreateSectionPSBAndGroup($oldData, $PIDForGroup);

                            if ($gid = $sectionPSBAndGroupResult['group']['groupID'] ?? false) {
                                if (!isset($this->groups[$gid])) {
                                    $this->groups[$gid] = [];
                                }
                                if (!isset($this->groups[$gid][$role])) {
                                    $this->groups[$gid][$role] = [];
                                }
                                array_push($this->groups[$gid][$role], $UPID);
                                $userGroupData[] = ["groupID" => $gid, "role" => $role, "batchID" => $batchID, "status" => "A"];

                                $this->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $PIDForGroup, $gid);
                            }

                        }
                    }
                }

                break;
            case 'student':
                $role = $this->portConfig['role']['student'];

                $sectionPSBAndGroupResult = $this->checkAndCreateSectionPSBAndGroup($oldData, $PID);

                if ($gid = $sectionPSBAndGroupResult['group']['groupID'] ?? false) {
                    if (!isset($this->groups[$gid])) {
                        $this->groups[$gid] = [];
                    }
                    if (!isset($this->groups[$gid][$role])) {
                        $this->groups[$gid][$role] = [];
                    }
                    array_push($this->groups[$gid][$role], $UPID);
                    $userGroupData[] = ["groupID" => $gid, "role" => $role, "batchID" => $batchID, "status" => "A"];

                    $this->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $PID, $gid);
                }

                break;
            default:
                $role = 'regularStudent';
                break;
        }

        if (!empty($userGroupData)) {
            foreach ($this->groups as $key => $value) {
                $groupDetails = $this->mongoGroupsObject->find(['_id' => $key]);

                if (!empty($groupDetails)) {
                    $oldGroupMembers = $groupDetails['members'] ?? [];

                    $updatedGroupMembers = $this->getUniqeMembers(array_merge_recursive($oldGroupMembers, $value));

                    $this->mongoGroupsObject->update(['_id' => $key], ['members' => $updatedGroupMembers]);
                }

            }
            $userGroupData = array_values(array_combine(array_column($userGroupData, 'groupID'), $userGroupData));

            $this->userProductObject->update(['UPID' => $UPID], ['activeGroups' => $userGroupData]);
        }
    }

    public function checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $PID, $groupID)
    {
        $batchID = $orgRelatedIDs['batchID'];

        $role = $this->portConfig['role']['admin'];

        if ($orgGroupDetails = $this->checkAndCreateTeacherGroup($orgRelatedIDs, $PID) ?? null) {

            $newGroupDetails = $this->mongoGroupsObject->find(['groupID' => $groupID]);

            $groupMembers = $newGroupDetails['members'] ?? [];

            $schoolAdminUPIDs = $orgGroupDetails['members'][$role] ?? [];

            if (!empty($schoolAdminUPIDs)) {

                $schoolAdminUPs = $this->userProductObject->findAll(['UPID' => ['$in' => array_values(array_unique($schoolAdminUPIDs))]]);

                foreach ($schoolAdminUPs as $key => $schoolAdminUP) {

                    $UPID = $schoolAdminUP['UPID'];
                    $activeGroups = $schoolAdminUP['activeGroups'];

                    $activeGroups[] = ["groupID" => $groupID, "role" => $role, "batchID" => $batchID, "status" => "A"];
                    $activeGroups = array_values(array_combine(array_column($activeGroups, 'groupID'), $activeGroups));

                    $this->userProductObject->update(['UPID' => $UPID], ['activeGroups' => $activeGroups]);

                    if (isset($groupMembers[$role])) {
                        array_push($groupMembers[$role], $UPID);
                    } else {
                        $groupMembers[$role][] = $UPID;
                    }

                }

                $groupMembers = $this->getUniqeMembers($groupMembers);

                $this->mongoGroupsObject->update(['groupID' => $groupID], ['members' => $groupMembers]);

            }

        }

    }

    public function getUniqeMembers($groupMembers)
    {

        $uniqueMembers = [];
        if (!empty($groupMembers)) {
            foreach ($groupMembers as $role => $UPIDs) {
                if (is_array($UPIDs)) {
                    $uniqueMembers[$role] = array_values(array_unique($UPIDs));
                }
            }
        }
        return $uniqueMembers;
    }

    private function createUserUserState($oldData, $userProductResult)
    {

        $userStateID = $this->userStateObject->newMongoId();

        $class = $oldData['class'];
        $UPID = $userProductResult['UPID'];

        $pedagogyTags = [];
        if (!in_array($class, [1, 2])) {
            $pedagogyTags = ['ChallengeQuestions'];
        }

        if (in_array($class, [1, 2, 3])) {
            array_push($pedagogyTags, 'SDLAdaptiveLowerGrades');
        }

        $insertData = [
            '_id' => $UPID,
            'lang' => $userProductResult['langContext'],
            'currentContent' => null,
            'contentModules' => [
                "topic" => [],
                "practiceModule" => [],
                "test" => [],
                "practiceModule" => [],
                "worksheet" => [],
                "activities" => [],
            ],
            'lastAttempted' => ['topic' => [], 'practiceModule' => [], 'test' => [], 'worksheet' => [], 'activities' => []],
            'misconceptions' => [],
            'bookmarks' => [],
            'userTags' => $pedagogyTags,
            'level' => ['math' => $class, 'english' => $class, 'science' => $class],
            'variables' => ['lastSessionID' => null, 'contentSequence' => 0, 'lastAttemptedContentType' => ''],
            'version' => 1,
        ];

        $searchCondUS = ['_id' => $UPID];

        $this->allInsertResult['UserState'][] = $insertResult = $this->userStateObject->findInsert($searchCondUS, $insertData);

    }

    private function getParentDetails($oldData = [])
    {
        $parents = [];

        $parentNames = explode(',', $oldData['parentName'] ?? '');
        $additionalEmails = explode(',', $oldData['additionalEmail'] ?? '');
        $mobiles = explode(',', $oldData['contactno_cel'] ?? '');

        $fatherMobile = ($oldData['category'] == "STUDENT") ? $mobiles[0] ?? null : null;
        $motherMobile = ($oldData['category'] == "STUDENT") ? $mobiles[1] ?? null : null;

        $countryCode = $this->portConfig['countryCode'];

        if ($oldData['category'] == "STUDENT") {
            $parents = [
                "father" => [
                    "UID" => null,
                    "name" => $parentNames[0] ?? null,
                    "email" => ["email" => $additionalEmails[0] ?? null, "hash" => $this->generateHashCode($additionalEmails[0] ?? null), "verified" => true],
                    "mobile" => ["countryCode" => $countryCode, "mobile" => $this->cleanMobile($fatherMobile), "otp" => 502404, "verified" => true],
                ],
                "mother" => [
                    "UID" => null,
                    "name" => $parentNames[1] ?? null,
                    "email" => ["email" => $additionalEmails[1] ?? null, "hash" => $this->generateHashCode($additionalEmails[1] ?? null), "verified" => true],
                    "mobile" => ["countryCode" => $countryCode, "mobile" => $this->cleanMobile($motherMobile), "otp" => 502404, "verified" => true],
                ],
            ];
        }

        return $parents;

    }

    private function getEmail($oldData = null)
    {
        $email = [];

        if (!empty($oldData['childEmail'])) {
            $email = [
                "email" => $oldData['childEmail'],
                "hash" => $this->generateHashCode($oldData['childEmail']),
                "verified" => true,
            ];
        }
        return $email;
    }

    private function getMobile($oldData = [])
    {
        $mobile = [];
        if (!empty($oldData['contactno_cel']) && $oldData['category'] != "STUDENT") {
            $mobile = [
                "countryCode" => $this->portConfig['countryCode'],
                "mobile" => $this->cleanMobile(explode(',', $oldData['contactno_cel'])[0] ?? ''),
                "otp" => 502404,
                "verified" => true,
            ];
        }
        return $mobile;
    }

    private function getAddress($oldData = [])
    {
        return [
            "line1" => $oldData['address'],
            "city" => $oldData['city'],
            "state" => $oldData['state'],
            "country" => $oldData['country'],
            "pincode" => $oldData['pincode'],
            "contact" => "",
        ];
    }

    private function isStudent($oldData)
    {
        return strtolower($oldData['category']) == "student";
    }

    private function isTeacher($oldData)
    {
        return strtolower($oldData['category']) == "teacher";
    }

    private function isSchoolAdmin($oldData)
    {
        return strtolower($oldData['category']) == "school admin";
    }

    private function getTagsAndSettings($oldData)
    {
        $tagsAndSettings = $this->tagsAndSettings;

        $settings = [];
        $tags[] = $tagsAndSettings['all'];

        if ($oldData['subcategory'] != "") {
            $tags[] = $oldData['subcategory'];
        }
        if ($oldData['category'] == "School Admin") {
            $tags[] = $tagsAndSettings['admin'];
        }
        if ($oldData['category'] == "TEACHER") {
            $tags = array_merge($tags, $tagsAndSettings['teacher']);
            $settings = $tagsAndSettings['settingsTeacher'];
        }
        if ($oldData['category'] == "STUDENT") {
            if ($oldData['class'] <= $tagsAndSettings["lowerGradeUpperLimit"]) {
                $tags = array_merge($tags, $tagsAndSettings['studentLower']);
                $settings = $tagsAndSettings['settingsLower'];
            } else {
                $tags = array_merge($tags, $tagsAndSettings['studentHigher']);
                $settings = $tagsAndSettings['settingsHigher'];

            }
            if ($oldData['class'] >= $tagsAndSettings["challengeQuestionLowerLimit"]) {
                $tags = array_merge($tags, $tagsAndSettings['challengeQuestion']);
            }
        }
        return ['tags' => array_values(array_unique($tags)), 'settings' => $settings];
    }

    ///////////////sync code////////////

    public function getUserDetailsFromMysql($id, $checkCondition = false)
    {
        $sqlQueryEndDate = $this->portConfig['userEndDate'];

        $sql = "SELECT cu.id commonID, cu.*, GROUP_CONCAT(CONCAT(tcm.class,'~', tcm.section,'~', tcm.subjectno) order by tcm.subjectno, tcm.class, tcm.section SEPARATOR '|') teacherClasses
                FROM educatio_educat.common_user_details cu
                LEFT JOIN educatio_adepts.adepts_teacherClassMapping tcm ON ((cu.MS_userID=tcm.userID and tcm.subjectno=2) or (cu.MSE_userID=tcm.userID  and tcm.subjectno=1))
                WHERE cu.id =" . $id . "";

        if ($checkCondition) {
            $sql .= " AND ((cu.MS_enabled=1 AND cu.endDate >=" . $sqlQueryEndDate . " ) OR (cu.MSE_enabled=1 AND cu.MSE_endDate >=" . $sqlQueryEndDate . ")) ";
        }

        $sql .= " GROUP BY cu.MS_userID, cu.MSE_userID";

        $returnData = $this->mysqlCommonUserObject->rawQuery($sql)['data'][0] ?? [];

        if (!empty($returnData)) {
            $this->setCreatedByAndUpdatedBy_Mysql($returnData);
        }
        return $returnData;
    }

    public function getMysqlDetailsForSync($id)
    {
        return $this->arrayFilterByKeys($this->getUserDetailsFromMysql($id), $this->selectedColumns);
    }

    public function getMongoDetailsForSync($mappingData)
    {
        $userDetails = $this->userUserDetailsObject->find([$mappingData['newIDName'] => $mappingData['newID']]);

        if (!empty($userDetails)) {
            $userDetails['username'] = $this->userAuthObject->find(['UID' => $userDetails['UID']])['username'];
            $userDetails['userProducts'] = $userProducts = $this->userProductObject->findAll(['UID' => $userDetails['UID']]);

            $UDUpdatedDate = $userDetails['_updatedAt'] ?? $userDetails['updatedAt'];

            if ($UDUpdatedDate && (!empty($userProducts))) {

                usort($userProducts, function ($a, $b) {
                    return strtotime($a['_updatedAt'] ?? $a['updatedAt']) - strtotime($b['_updatedAt'] ?? $b['updatedAt']);
                });

                foreach ($userProducts as $value) {
                    $_updatedAt = $value['_updatedAt'] ?? $value['updatedAt'];

                    if (($updatedBy = $value['updatedBy'] ?? false) && ($_updatedAt > $UDUpdatedDate)) {
                        $userDetails['_updatedAt'] = $_updatedAt;
                        $userDetails['updatedBy'] = $updatedBy;
                        break;
                    }
                }
            }

            $this->setCreatedByAndUpdatedBy_Mongo($userDetails);
        }

        return $userDetails;
    }

    public function convertToMysqlFormat($details, &$mysqlData = [])
    {
        $address = $details['address'] ?? [];
        $userProducts = $details['userProducts'];
        $activeBatchDetails = $userProducts[0]['batch']['ActiveBatch'] ?? [];

        $subjectWisePID = array_flip($this->portConfig['PIDSubjectWise']);

        $userProductsPIDWise = array_combine(array_column($userProducts, 'PID'), $userProducts);

        $name = explode(' ', $details['name'], 2);

        $tags = (array) $details['userProducts'][0]['tags'] ?? [];
        $parents = $details['parents'] ?? [];
        $address = $details['address'] ?? [];
        $imageURL = $details['imageURL'] ?? false;

        $convertedDetails = [
            'Name' => $details['name'],
            'first_name' => $name[0] ?? null,
            'last_name' => $name[1] ?? null,
            'username' => $details['username'],
            'pan_number' => $details['panNumber'],
            'childEmail' => $details['email']['email'] ?? null,
            'class' => (isset($activeBatchDetails['groupID'])) ? ($activeBatchDetails['grade'] ?? null) : null,
            'section' => (isset($activeBatchDetails['groupID'])) ? ($activeBatchDetails['section'] ?? null) : null,
            'dob' => $details['dateOfBirth'],
            'rollNo' => $activeBatchDetails['rollNo'] ?? null,
            'gender' => $details['gender'] == 'M' ? 'B' : 'G',
            'secretQues' => $this->getSecretQuestions()[$details['secretQn']] ?? null,
            'secretAns' => $details['secretAns'],
            'category' => strtoupper($details['userProducts'][0]['category']),
            'subcategory' => in_array('School', $tags) ? 'School' : '',
            'parentName' => ($fatherName = $parents['father']['name'] ?? '') . ($fatherName && !empty($motherName = $parents['mother']['name'] ?? null) ? ',' . $motherName : ''),
            'additionalEmail' => ($fatherEmail = $parents['father']['email']['email'] ?? '') . ($fatherEmail && !empty($motherEmail = $parents['mother']['email']['email'] ?? null) ? ',' . $motherEmail : ''),
            'address' => $address['line1'] ?? '',
            'city' => $address['city'] ?? '',
            'state' => $address['state'] ?? '',
            'country' => $address['country'] ?? '',
            'pincode' => $address['pincode'] ?? '',
            'country' => $address['country'] ?? '',
            'profilePicture' => !in_array($imageURL, ['male.png', 'femail.png']) ? $imageURL : null,
            'contactno_cel' => $details['mobile']['mobile'] ?? '',
            'lastModified' => $details['_updatedAt'] ?? $details['updatedAt'],
        ];

        if ($teacherClasses = $mysqlData['teacherClasses'] ?? false) {
            $activeGroups = array_combine(array_column($userProducts, 'PID'), array_column($userProducts, 'activeGroups'));

            $classTeacherGroupIDds = [];
            foreach ($activeGroups as $PID => $groupsPIDWise) {
                foreach ($groupsPIDWise as $groups) {
                    if ($groups['role'] == 'classTeacher') {
                        $classTeacherGroupIDds[] = $groups['groupID'];
                    }
                }
            }

            if (!empty($classTeacherGroupIDds)) {
                $classTeacherGroups = $this->mongoGroupsObject->findAll(['groupID' => ['$in' => array_values(array_unique($classTeacherGroupIDds))], 'type' => 'section']);

                $mongoTeacherClassMapping = [];

                array_map(function ($group) use (&$mongoTeacherClassMapping, $subjectWisePID) {
                    $mongoTeacherClassMapping[] = str_replace(' ', '~', $group['name']) . '~' . $subjectWisePID[$group['PID']];
                }, $classTeacherGroups);

                $convertedDetails['teacherClasses'] = implode('|', $mongoTeacherClassMapping);
            }
        }

        if (isset($userProductsPIDWise[$this->defaultMathPID])) {
            $convertedDetails['MS_userID'] = $userProductsPIDWise[$this->defaultMathPID]['oldUPID'];
            $convertedDetails['MS_enabled'] = $userProductsPIDWise[$this->defaultMathPID]['status'] == 'A' ? 1 : 0;
            $convertedDetails['startDate'] = $userProductsPIDWise[$this->defaultMathPID]['activationDate'] ?? ($userProductsPIDWise[$this->defaultMathPID]['startDate']);
            $convertedDetails['endDate'] = $userProductsPIDWise[$this->defaultMathPID]['expiryDate'];
        } else {
            unset($mysqlData['MS_enabled']);
            unset($mysqlData['startDate']);
            unset($mysqlData['endDate']);
        }

        if (isset($userProductsPIDWise[$this->defaultEnglighPID])) {
            $convertedDetails['MSE_userID'] = $userProductsPIDWise[$this->defaultEnglighPID]['oldUPID'];
            $convertedDetails['MSE_enabled'] = $userProductsPIDWise[$this->defaultEnglighPID]['status'] == 'A' ? 1 : 0;
            $convertedDetails['MSE_startDate'] = $userProductsPIDWise[$this->defaultEnglighPID]['activationDate'] ?? ($userProductsPIDWise[$this->defaultEnglighPID]['startDate']);
            $convertedDetails['MSE_endDate'] = $userProductsPIDWise[$this->defaultEnglighPID]['expiryDate'];
        } else {
            unset($mysqlData['MSE_enabled']);
            unset($mysqlData['MSE_startDate']);
            unset($mysqlData['MSE_endDate']);
        }

        if (isset($mysqlData['updated_by'])) {
            unset($mysqlData['updated_by']);
        }

        if (isset($mysqlData['created_by'])) {
            unset($mysqlData['created_by']);
        }

        $convertedDetails = $this->arrayFilterByKeys($convertedDetails, $this->selectedColumns);

        return $convertedDetails;

    }

    public function convertToMongoFormat($userDetails, $oldData = [], $newData = [])
    {
        $convertedUserDetails = [];
        $convertedUserProductDetails = [];
        $classAndSectionChange = [];
        $convertedUserAuthDetails = [];
        $organizationChange = [];
        $sectionChange = [];
        $academicYearChange = 0;
        $subscriptionModeChange = null;
        $mysqlLastModified = $oldData['lastModified'] ?? null;
        foreach ($userDetails as $key => $value) {
            switch ($key) {
                case 'Name':
                    $explodedName = explode(' ', $value, 2);
                    $convertedUserDetails['name'] = $value;
                    $convertedUserDetails['firstName'] = $explodedName[0] ?? '';
                    $convertedUserDetails['lastName'] = $explodedName[1] ?? '';
                    break;

                case 'secretQues':
                    $convertedUserDetails['secretQn'] = ($sQn = array_search($value, $this->getSecretQuestions())) ? $sQn : null;
                    break;

                case 'category': //category can't be change
                    // $convertedUserProductDetails['category'] = strtolower($value);
                    break;

                case 'username':
                    $convertedUserAuthDetails['username'] = strtolower($value);

                    $convertedUserAuthDetails['_updatedAt'] = $mysqlLastModified;
                    break;
                case 'schoolCode': 
                    $orgRelatedIDs = $this->getOrgAndRelatedIDsByOrgID($newData['orgID']);
                    $organizationChange = $this->oldOrgID == $orgRelatedIDs['oldOrgID'] ? [] : array("oldOrgID" => $orgRelatedIDs["oldOrgID"], "newOrgID" => $this->oldOrgID);
                    break;
                case 'class':
                case 'section':
                    if ($category = $oldData['category'] ?? false) {
                        $classAndSectionChange['class'] = $oldData['class'];
                        $classAndSectionChange['section'] = $oldData['section'];
                    }
                    
                    break;

                case 'subcategory': //this can change
                    //$convertedUserProductDetails[$this->defaultMathPID]['tags'] = [$value ?? 'all'];
                    //$convertedUserProductDetails[$this->defaultEnglighPID]['tags'] = [$value ?? 'all'];
                    $subscriptionModeChange = $value;
                    break;

                case 'gender':
                    $gender = $value == 'B' ? 'M' : 'F';
                    $convertedUserDetails['gender'] = $gender;

                    if (is_null($oldData['profilePicture'] ?? null)) {
                        $convertedUserDetails["imageURL"] = $gender == "B" ? "male.png" : "female.png";
                    }

                    break;

                case 'childEmail':
                    $convertedUserDetails['email'] = [
                        "email" => $value,
                        "hash" => $this->generateHashCode($value),
                        "verified" => true,
                    ];
                    break;

                case 'parentName':
                    $explodedParentName = explode(',', $value);

                    $parentNames['father']['name'] = $explodedParentName[0] ?? '';
                    $parentNames['mother']['name'] = $explodedParentName[1] ?? '';

                    $convertedUserDetails['parents'] = array_merge_recursive($convertedUserDetails['parents'] ?? [], $parentNames);

                    break;

                case 'additionalEmail':

                    $explodedParentEmail = explode(',', $value);

                    $fatherEmail = $explodedParentEmail[0] ?? '';
                    $motherEmail = $explodedParentEmail[1] ?? '';

                    $parentEmails['father']['email'] = ["email" => $fatherEmail ?? '', "hash" => $this->generateHashCode($fatherEmail ?? ''), "verified" => true];
                    $parentEmails['mother']['email'] = ["email" => $motherEmail ?? '', "hash" => $this->generateHashCode($motherEmail ?? ''), "verified" => true];

                    $convertedUserDetails['parents'] = array_merge_recursive($convertedUserDetails['parents'] ?? [], $parentEmails);
                    break;

                case 'address':
                    $convertedUserDetails['address']['line1'] = $value;
                    break;

                case 'city':
                    $convertedUserDetails['address']['city'] = $value;
                    break;

                case 'state':
                    $convertedUserDetails['address']['state'] = $value;
                    break;

                case 'country':
                    $convertedUserDetails['address']['country'] = $value;
                    break;

                case 'pincode':
                    $convertedUserDetails['address']['pincode'] = $value;
                    break;

                case 'contactno_cel':
                    $convertedUserDetails['mobile'] = ['mobile' => $this->cleanMobile($value)];
                    break;

                case 'MS_enabled':
                    $convertedUserProductDetails[$this->defaultMathPID]['status'] = $value == 1 ? 'A' : 'D';
                    break;

                case 'startDate':
                    $convertedUserProductDetails[$this->defaultMathPID]['activationDate'] = $this->getFormatedDate($value);
                    break;

                case 'endDate':
                    $convertedUserProductDetails[$this->defaultMathPID]['expiryDate'] = $this->getFormatedDate($value);
                    break;

                case 'MSE_enabled':
                    $convertedUserProductDetails[$this->defaultEnglighPID]['status'] = $value == 1 ? 'A' : 'D';
                    break;

                case 'MSE_startDate':
                    $convertedUserProductDetails[$this->defaultEnglighPID]['activationDate'] = $this->getFormatedDate($value);
                    break;

                case 'MSE_endDate':
                    $convertedUserProductDetails[$this->defaultEnglighPID]['expiryDate'] = $this->getFormatedDate($value);
                    break;
                default:
                    if (isset($this->directFieldMapping[$key])) {
                        $convertedUserDetails[$this->directFieldMapping[$key]] = $value;
                    }
                    break;
            }
        }

        

        if (!empty($convertedUserProductDetails)) {

            if (isset($convertedUserProductDetails[$this->defaultMathPID])) {
                $convertedUserProductDetails[$this->defaultMathPID]['updatedAt'] = $mysqlLastModified;
                $convertedUserProductDetails[$this->defaultMathPID]['_updatedAt'] = $mysqlLastModified;
            }

            if (isset($convertedUserProductDetails[$this->defaultEnglighPID])) {
                $convertedUserProductDetails[$this->defaultEnglighPID]['updatedAt'] = $mysqlLastModified;
                $convertedUserProductDetails[$this->defaultEnglighPID]['_updatedAt'] = $mysqlLastModified;
            }
        }
        //@TODO: Check if this has to be verified for organizationChange
        $sql = "SELECT * from educatio_educat.upgradation_conformation WHERE schoolCode=$this->oldOrgID and upgrade_date >= curdate()";

        $returnData = $this->mysqlCommonUserObject->rawQuery($sql)['data'] ?? [];

        if (!empty($returnData) && $returnData[0]['is_upgrade'] == 1) {
            $academicYearChange = 1;
        } 

        return ['userDetails' => $convertedUserDetails, 'userAuth' => $convertedUserAuthDetails, 'userProducts' => $convertedUserProductDetails, 'classAndSectionChange' => $classAndSectionChange, 'organizationChange' => $organizationChange, 'academicYearChange' => $academicYearChange, 'subscriptionModeChange' => $subscriptionModeChange];
        
    }

    public function createRecordInMongo($mappingData)
    {
        $returnData = false;

        $userDetails = $this->getUserDetailsFromMysql($mappingData['oldID']);

        if ($userDetails) {
            if ($this->getOrgRelatedIDs([$userDetails['schoolCode']])) {
                // get org related other ids (batchID, orderID etc..)

                $this->orgIDForOffline = $this->organizationAndMappings[$userDetails['schoolCode']]['orgID'];
                $this->oldOrgID = $userDetails['schoolCode'];
                $this->setOrgIDForOffline();

                $returnData = $this->portThisUser($mappingData['oldID'], $userDetails); // port details using common Id from common_user_details table in mysql

                $returnData = $returnData['userDetails']['UID'] ?? null;
            }
        }

        return $returnData;
    }

    public function createRecordInMysql($mappingData)
    {
    }

    public function updateFromMysqlToMongo($mongoData, $mysqlData)
    {
        $originalMysqlData = $mysqlData;
        $updatedByField = ['updatedBy' => ($updatedBy = $originalMysqlData['updated_by']) ?? false ? $this->getFromGlobal($updatedBy, $updatedBy) : 'script'];
        $this->oldOrgID = $mysqlData['schoolCode'];
        $this->setOrgIDForOffline();

        $returnData = false;
        $convertedDataMongoToMysql = $this->convertToMysqlFormat($mongoData, $mysqlData); 
        if ($mysqlData['category'] == 'STUDENT') {
            unset($mysqlData['teacherClasses']);
        }

        $dataDiff = array_diff_assoc($mysqlData, $convertedDataMongoToMysql);
        //unset($dataDiff['schoolCode']); 

        if (($MS_userID = $dataDiff['MS_userID'] ?? false) && ($MS_enabled = $originalMysqlData['MS_enabled'] ?? false) && $MS_enabled == 1) {
            $this->createNewUserProduct($mongoData, $MS_userID, $this->defaultMathPID);
        }
        if (($MSE_userID = $dataDiff['MSE_userID'] ?? false) && ($MSE_enabled = $originalMysqlData['MSE_enabled'] ?? false) && $MSE_enabled == 1) {
            $this->createNewUserProduct($mongoData, $MSE_userID, $this->defaultEnglighPID);
        }

        if ($newTeacherClasses = $mysqlData['teacherClasses'] ?? false) {
            $newTeacherClasses = explode('|', $newTeacherClasses);

            $PIDSubjectWise = $this->portConfig['PIDSubjectWise'];

            $teacherRole = $this->portConfig['role']['teacher'];

            $orgRelatedIDs = $this->getOrgAndRelatedIDsByOrgID($mongoData['orgID']);
            $orgGroupIDProductWise = $orgRelatedIDs['orgGroupIDProductWise'] ?? false;

            foreach ($mongoData['userProducts'] as $productValue) {

                $activeGroups = $productValue['activeGroups'] ?? [];

                $orgGroupID = null;
                if ($orgGroupIDProductWise) {
                    $orgGroupID = $orgGroupIDProductWise[$productValue['PID']] ?? null;
                }

                $activeGroupsOtherThanCurrentRole = [];

                array_map(function ($v) use (&$activeGroupsOtherThanCurrentRole, $teacherRole, $orgGroupID) {
                    if ($teacherRole != $v['role'] || $v['groupID'] == $orgGroupID) {
                        $activeGroupsOtherThanCurrentRole[] = $v;
                    }
                }, $activeGroups);

                $UPID = $productValue['UPID'];

                $newGroups = [];
                foreach ($newTeacherClasses as $classAndSection) {
                    $explodedClassAndSection = explode('~', $classAndSection);

                    $newClassAndSections = [
                        'schoolCode' => $orgRelatedIDs['oldOrgID'],
                        'class' => $explodedClassAndSection[0],
                        'section' => $explodedClassAndSection[1],
                    ];
                    $tmpPID = $PIDSubjectWise[$explodedClassAndSection[2] ?? '2'] ?? $this->defaultMathPID;

                    $sectionPSBAndGroupResult = $this->checkAndCreateSectionPSBAndGroup($newClassAndSections, $tmpPID);

                    if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false) {

                        $groupMembers = $groupDetails['members'] ?? [];
                        $groupID = $groupDetails['groupID'];

                        if ($roleAndMembers = $groupMembers[$teacherRole] ?? false) {
                            array_push($roleAndMembers, $UPID);
                            $groupMembers[$teacherRole] = $roleAndMembers;
                        } else {
                            $groupMembers[$teacherRole][] = $UPID;
                        }

                        $groupMembers = $this->getUniqeMembers($groupMembers);

                        $this->mongoGroupsObject->update(['groupID' => $groupID], ['members' => $groupMembers]);

                        array_push($newGroups, ["groupID" => $groupID, "role" => $teacherRole, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"]);

                    }

                }

                array_push($newGroups, ["groupID" => $orgGroupID, "role" => $teacherRole, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"]);

                $newGroups = array_merge($activeGroupsOtherThanCurrentRole, $newGroups);

                $newGroups = array_values(array_combine(array_column($newGroups, 'groupID'), $newGroups));

                $tmpUserProduct = array_merge(['activeGroups' => $newGroups], $updatedByField);

                $this->userProductObject->update(['UPID' => $UPID], $tmpUserProduct);

            }

        }

        $mongoUpdatedData = $this->convertToMongoFormat($dataDiff, $mysqlData, $mongoData);

        if ($userAuth = $mongoUpdatedData['userAuth'] ?? false) {
            if (isset($userAuth['username'])) {
                $userAuth = array_merge($userAuth, $updatedByField);
                $this->userAuthObject->update(['UID' => $mongoData['UID']], $userAuth);
                $returnData = true;
            }
        }

        $classUpgradeObj = new ClassUpgradeHelper();
        $classUpgradeType = $classUpgradeObj->identifyClassUpgradeType($dataDiff,$mongoUpdatedData, $mysqlData, $mongoData);
        $classUpgradeObj->upgradeTypeMapping($classUpgradeType, $mongoUpdatedData, $mysqlData, $mongoData);

        /* if ($classAndSectionChange = $mongoUpdatedData['classAndSectionChange'] ?? false) {
            $orgRelatedIDs = $this->getOrgAndRelatedIDsByOrgID($mongoData['orgID']);
            $studentRole = $this->portConfig['role']['student'];

            $classAndSectionChange['schoolCode'] = $orgRelatedIDs['oldOrgID'];

            foreach ($mongoData['userProducts'] as $pValue) {
                $userProductUpdateData = [];

                $UPID = $pValue['UPID'];

                $activeGroups = $pValue['activeGroups'];
                $inactiveGroups = $pValue['inactiveGroups'];

                $sectionPSBAndGroupResult = $this->checkAndCreateSectionPSBAndGroup($classAndSectionChange, $pValue['PID']);

                $oldActiveBatch = $pValue['batch']['ActiveBatch'] ?? [];

                if ($groupDetails = $sectionPSBAndGroupResult['group'] ?? false) {

                    $groupMembers = $groupDetails['members'] ?? [];
                    $groupID = $groupDetails['groupID'];

                    if ($roleAndMembers = $groupMembers[$studentRole] ?? false) {
                        array_push($roleAndMembers, $UPID);
                        $groupMembers[$studentRole] = $roleAndMembers;
                    } else {
                        $groupMembers[$studentRole][] = $UPID;
                    }

                    $groupMembers = $this->getUniqeMembers($groupMembers);

                    $tmpGroupDetails = array_merge(['members' => $groupMembers], $updatedByField);

                    $this->mongoGroupsObject->update(['groupID' => $groupID], $tmpGroupDetails);

                    $activeBatch = [
                        "grade" => $classAndSectionChange['class'],
                        "section" => $classAndSectionChange['section'],
                        "groupID" => $groupID,
                    ];

                    $userProductUpdateData['batch']['ActiveBatch'] = array_merge($oldActiveBatch, $activeBatch);
                    $userProductUpdateData['activeGroups'][] = ["groupID" => $groupID, "role" => $studentRole, "batchID" => $orgRelatedIDs['batchID'], "status" => "A"];

                    // array_map(function (&$v) {$v['status'] = 'D';}, $activeGroups);

                    // $userProductUpdateData['inactiveGroups'] = array_merge($inactiveGroups, $activeGroups);

                    $userProductUpdateData = array_merge($userProductUpdateData, $updatedByField);

                    $this->userProductObject->update(['UPID' => $UPID], $userProductUpdateData);

                    $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                    if (!empty($oldActiveGroupIDs)) {
                        foreach ($oldActiveGroupIDs as $oldGroupID) {
                            $this->removeUserFromGroup($oldGroupID, $UPID, $studentRole);
                        }
                    }

                    $this->checkAndUpdatesGroupForSchoolAdmin($orgRelatedIDs, $pValue['PID'], $groupID);

                }
            }

            $returnData = true;
        } */

        if ($userProducts = $mongoUpdatedData['userProducts'] ?? false) {
            $allRoles = $this->portConfig['role'];
            foreach ($userProducts as $PID => $pValue) {

                if (($UPStatus = $pValue['status'] ?? false) && $UPStatus == 'D') {

                    $UPDetails = $this->userProductObject->find(['UID' => $mongoData['UID'], 'PID' => $PID]);
                    $userActiveGroups = $UPDetails['activeGroups'] ?? [];
                    $UPID = $UPDetails['UPID'];

                    if (!empty($userActiveGroups)) {

                        $updatedGroups = [];
                        foreach ($userActiveGroups as $userActiveGroup) {
                            if (!$this->removeUserFromGroup($userActiveGroup['groupID'], $UPID, $userActiveGroup['role'], true)) {
                                $updatedGroups[] = $userActiveGroup;
                            }

                        }
                        $pValue['activeGroups'] = $updatedGroups;
                    }

                    if ($this->isStudent($mysqlData)) {
                        $pValue['batch']['ActiveBatch'] = array_merge($UPDetails['batch']['ActiveBatch'] ?? [], ['groupID' => null, 'grade' => null, 'section' => null]);
                    }

                }
                $pValue = array_merge($pValue, $updatedByField);
                $updatedUP = $this->userProductObject->update(['UID' => $mongoData['UID'], 'PID' => $PID], $pValue);
            }

            //update activation and expiry date for user details
            $updatedUserProducts = $this->userProductObject->findAll(['UID' => $mongoData['UID']]);

            $userProductsForUD = [];

            foreach ($updatedUserProducts as $UPValue) {
                $userProductsForUD[$UPValue['PID']] = array_merge(
                    ($mongoData['products'][$UPValue['PID']] ?? []),
                    ['activationDate' => ($UPValue['activationDate'] ?? $UPValue['startDate']), 'expiryDate' => $UPValue['expiryDate']]
                );
            }
            if (!empty($userProductsForUD)) {
                $mongoUpdatedData['userDetails']['products'] = $userProductsForUD;
            }
            ////////

            $returnData = true;
        }

        if ($userDetails = $mongoUpdatedData['userDetails'] ?? false) {
            $userDetails = array_merge($userDetails, $updatedByField);
            $this->userUserDetailsObject->update(['UID' => $mongoData['UID']], $userDetails);
            $returnData = true;
        }
        
        return $returnData;
    }

    public function removeUserFromGroup($groupID, $UPID, $role, $exceptTeacherGroup = false)
    {
        $returnResult = false;

        $whereForGroup['groupID'] = $groupID;

        if ($exceptTeacherGroup) {
            $whereForGroup['type'] = ['$ne' => 'organizationTeacher'];
        }

        $groupDetails = $this->mongoGroupsObject->find($whereForGroup);
        if (!empty($groupDetails)) {
            $groupMembers = $groupDetails['members'] ?? [];

            $updatedUPIDsForARole = array_values(array_unique(array_diff(array_unique($groupMembers[$role] ?? []), [$UPID])));

            $updatedGroupMembers = $this->getUniqeMembers(array_merge($groupMembers, [$role => $updatedUPIDsForARole]));

            $this->mongoGroupsObject->update(['groupID' => $groupID], ['members' => $updatedGroupMembers]);
            $returnResult = true;

        }
        return $returnResult;
    }

    public function updateFromMongoToMysql($mongoData, $mysqlData)
    {
        $updatedByField = ['updated_by' => ($updatedBy = $mongoData['updatedBy']) ?? false ? $this->getFromGlobal($updatedBy, $updatedBy) : 'script'];

        $returnData = false;

        $convertedDataMongoToMysql = $this->convertToMysqlFormat($mongoData, $mysqlData);

        $dataDiff = array_diff_assoc($convertedDataMongoToMysql, $mysqlData);

        unset($dataDiff['category']);
        unset($dataDiff['subcategory']);

        if (!$this->isStudent($mysqlData)) {
            unset($dataDiff['class']);
            unset($dataDiff['section']);
        }

        if ($this->isStudent($mysqlData)) {
            unset($dataDiff['teacherClasses']);

            if (array_key_exists('class', $dataDiff) && is_null($dataDiff['class'])) {
                unset($dataDiff['class']);
            }
            if (array_key_exists('section', $dataDiff) && is_null($dataDiff['section'])) {
                unset($dataDiff['section']);
            }
        }

        if (isset($dataDiff['teacherClasses'])) {

            $mysqlTeacherClasses = explode('|', $mysqlData['teacherClasses']);
            $mongoTeacherClasses = explode('|', $dataDiff['teacherClasses']);

            $deleteThisClassMapping = array_diff($mysqlTeacherClasses, $mongoTeacherClasses);
            $createThisToMysql = array_diff($mongoTeacherClasses, $mysqlTeacherClasses);

            $teacherClassMappingObject = new MySqlDB('adepts_teacherClassMapping', 'educatio_adepts');

            if ($MS_userID = $mysqlData['MS_userID'] ?? false) {
                foreach ($deleteThisClassMapping as $classMapping) {
                    $explodedMaping = explode('~', $classMapping);

                    if (($class = $explodedMaping[0] ?? false) && ($section = $explodedMaping[1] ?? false) && ($subjectno = $explodedMaping[2] ?? false)) {

                        $whereForDelete = 'userID = ' . $MS_userID . ' AND class = ' . $class . ' AND section ="' . $section . '" AND subjectno =' . $subjectno;

                        $teacherClassMappingObject->delete($whereForDelete);
                    }

                }
            }

            if (!empty($createThisToMysql)) {
                $newTeacherClassMapping = [];
                foreach ($createThisToMysql as $newMapping) {
                    $explodedMaping = explode('~', $newMapping);

                    if (($class = $explodedMaping[0] ?? false) && ($section = $explodedMaping[1] ?? false) && ($subjectno = $explodedMaping[2] ?? false)) {

                        $userID = false;
                        if ($subjectno == 1) {
                            $userID = $mysqlData['MSE_userID'] ?? false;
                        } elseif ($subjectno == 2) {
                            $userID = $mysqlData['MS_userID'] ?? false;
                        }

                        if ($userID) {
                            $newTeacherClassMapping[] = ['userID' => $userID, 'class' => $class, 'section' => $section, 'subjectno' => $subjectno];
                        }

                    }

                }

                if (!empty($newTeacherClassMapping)) {
                    $teacherClassMappingObject->insertMany($newTeacherClassMapping);
                }

            }

            unset($dataDiff['teacherClasses']);

        }

        if (!empty($dataDiff)) {
            $this->mysqlCommonUserObject->rawQuery('SET @TRIGGER_CHECKS = FALSE;', false);

            $dataDiff = array_merge($dataDiff, $updatedByField);

            $this->mysqlCommonUserObject->update('id=' . $mysqlData['id'], $dataDiff);

            $returnData = true;
        }

        return $returnData;

    }

    public function getOrgAndRelatedIDsByOrgID($orgID)
    {

        $returnData = [];

        $orgDetails = $this->mongoOrgObject->find(['orgID' => $orgID]);
        if (!empty($orgDetails)) {
            $newOrgID = $orgDetails['orgID'];

            $batchDetails = $this->mongoBatchObject->find(['orgID' => $newOrgID, 'status' => 'A']);
            
            if ($batchID = $batchDetails['batchID'] ?? false) {

                $orderDetails = $this->mongoOrderObject->findAll([
                    'orgID' => $newOrgID,
                    'status' => 'A',
                    'PID' => ['$in' => [$this->defaultMathPID, $this->defaultEnglighPID]],
                ]);

                $orgTeacherGroup = $this->mongoGroupsObject->findAll(['otherIds.orgID' => $newOrgID, 'otherIds.batchID' => $batchID, 'type' => $this->portConfig['orgTeacherGroup']]);

                if (!empty($orderDetails)) {
                    $orderDetails = array_combine(array_column($orderDetails, 'PID'), array_column($orderDetails, 'orderID'));

                    $returnData = $this->organizationAndMappings[$orgDetails['oldOrgID']] = [
                        'oldOrgID' => $orgDetails['oldOrgID'],
                        'orgID' => $newOrgID,
                        'orgName' => $orgDetails['name'],
                        'batchID' => $batchID,
                        'batchName' => $batchDetails['name'],
                        'order' => $orderDetails,
                        'orgGroupIDProductWise' => array_combine(array_column($orgTeacherGroup, 'PID'), array_column($orgTeacherGroup, 'groupID')),
                    ];
                }
            }
            //@TODO: Write code to create batch if it is not already present.
        }
        return $returnData;
    }

    public function setOrgIDForOffline()
    {

        $objects = ['mongoOrgObject', 'mongoBatchObject', 'mongoOrderObject', 'mongoGroupsObject', 'mongoSectionObject', 'mongoPSBObject',
            'userUserDetailsObject', 'userProductObject', 'secretQuesObject', 'userAuthObject', 'userStateObject'];

        $isOffline = $this->checkOffline(intval($this->oldOrgID));

        foreach ($objects as $objectName) {
            $this->$objectName->orgIDForOffline = $this->orgIDForOffline;
            $this->$objectName->offlineSync = $isOffline;
        }
    }

    private function isOffline()
    {
        $returnData = false;

        $orgID = $this->orgIDForOffline;

        if ($orgID) {
            $settingTable = new MongoDB('Settings');
            $settingObject = $settingTable->find(['type' => 'organization', 'searchID' => $orgID, 'settings.offlineUser' => ['$exists' => true, '$eq' => true]]);
            if ($settingObject['_id'] ?? false) {
                $returnData = true;
            }
        }
        return $returnData;
    }

    public function getOrgID($oldOrgID)
    {
        return $this->mongoOrgObject->find(['oldOrgID' => $oldOrgID])['orgID'] ?? false;
    }

    public function checkOffline($oldOrgID)
    {
        $offlineSchoolObject = new MySqlDB(OFFLINE_SCHOOLS_TABLE, 'educatio_adepts');
        $MSE_offlineSchoolObject = new MySqlDB(OFFLINE_SCHOOLS_TABLE, 'educatio_msenglish');

        return ($offlineSchoolObject->getCount('schoolCode = ' . $oldOrgID) > 0) || ($MSE_offlineSchoolObject->getCount('schoolCode = ' . $oldOrgID) > 0);
    }

    public function createNewUserProduct(&$mongoData, $oldUPID, $PID)
    {

        $oldData = $this->getUserDetailsFromMysql($mongoData['oldUserID'], true);

        if (!empty($oldData)) {
            if ($this->getOrgRelatedIDs([$oldData['schoolCode']])) {

                $this->getUserDetailsAndProducts($oldData);

                $this->createUserProduct($oldData, $mongoData);

                $mongoData['userProducts'] = $this->userProductObject->findAll(['UID' => $mongoData['UID']]);
            }
        }

    }

    public function addGroupsForSchoolAdmin($UPID)
    {
        $returnData = [];
        $userProduct = $this->userProductObject->find(['UPID' => $UPID]);
        if ($userProduct['UPID'] ?? false) {
            $userDetials = $this->userUserDetailsObject->find(['UID' => $userProduct['UID']]);

            if ($userDetials['UID'] ?? false) {

                $orgID = $userDetials['orgID'];

                $PID = $userProduct['PID'];
                $batchID = $userProduct['batch']['ActiveBatch']['batchID'] ?? null;

                $activeGroups = $userProduct['activeGroups'] ?? [];
                $oldActiveGroupIDs = array_column($activeGroups, 'groupID');

                $groups = $this->getAllGroupsByOrgID($orgID, $PID, $batchID);

                $schoolAdminRole = $this->portConfig['role']['admin'];

                foreach ($groups as $group) {
                    $groupID = $group['groupID'];

                    if (!in_array($groupID, $oldActiveGroupIDs)) {

                        if ($this->addMemberToGroup($groupID, $schoolAdminRole, $UPID)) {
                            $activeGroups[] = ["groupID" => $groupID, "role" => $schoolAdminRole, "batchID" => $batchID, "status" => "A"];
                        }
                    }

                }
                $returnData = $activeGroups = array_values(array_combine(array_column($activeGroups, 'groupID'), $activeGroups));

                $this->userProductObject->update(['UPID' => $UPID], ['activeGroups' => $activeGroups]);
            }
        }
        return $returnData;
    }

    public function getAllGroupsByOrgID($orgID, $PID, $batchID, $groupType = 'section')
    {
        $where = ['otherIds.orgID' => $orgID, 'type' => $groupType, 'status' => 'A', 'PID' => $PID];

        if ($batchID) {
            $where['otherIds.batchID'] = $batchID;
        }

        return $this->mongoGroupsObject->findAll($where);
    }

    public function addMemberToGroup($groupID, $role, $UPID)
    {
        $returnData = false;
        $groupDetails = $this->mongoGroupsObject->find(['groupID' => $groupID]);

        if ($groupDetails['groupID'] ?? false) {
            $groupMembers = $groupDetails['members'] ?? [];

            if (isset($groupMembers[$role])) {
                array_push($groupMembers[$role], $UPID);
            } else {
                $groupMembers[$role][] = $UPID;
            }

            $this->mongoGroupsObject->update(['groupID' => $groupID], ['members' => $this->getUniqeMembers($groupMembers)]);

            $returnData = true;
        }
        return $returnData;

    }

    public function setCreatedByAndUpdatedBy_Mongo($mongoData)
    {

        $UPIds = [];
        if (($createdBy = $mongoData['createdBy'] ?? false) && !$this->getFromGlobal($createdBy)) {
            $UPIds[] = $createdBy;
        }

        if (($updatedBy = $mongoData['updatedBy'] ?? false) && !$this->getFromGlobal($updatedBy)) {
            $UPIds[] = $updatedBy;
        }

        if (!empty($UPIds = array_values(array_unique(array_filter($UPIds))))) {

            $userProductResult = $this->userProductObject->findAll(['UPID' => ['$in' => $UPIds]]);

            if (!empty($userProductResult)) {
                $UID_UPID = array_column($userProductResult, 'UPID', 'UID');
                $UIDs = array_keys($UID_UPID);

                $userNames = $this->userAuthObject->findAll(['UID' => ['$in' => $UIDs]]);

                if (!empty($userNames)) {
                    foreach ($userNames as $UNValue) {
                        if ($UPID = $UID_UPID[$UNValue['UID']] ?? false) {
                            $this->setToGlobal($UPID, $UNValue['username']);
                        }
                    }
                }
            }
        }

    }

    public function setCreatedByAndUpdatedBy_Mysql($mysqlData)
    {

        $userNames = [];
        if (($createdBy = $mysqlData['created_by'] ?? false) && !$this->getFromGlobal($createdBy)) {
            $userNames[] = $createdBy;
        }

        if (($updatedBy = $mysqlData['updated_by'] ?? false) && !$this->getFromGlobal($updatedBy)) {
            $userNames[] = $updatedBy;
        }

        if (!empty($userNames = array_values(array_unique(array_filter($userNames))))) {

            $userAuthResult = $this->userAuthObject->findAll(['username' => ['$in' => $userNames]]);

            if (!empty($userAuthResult)) {
                $UID_UserName = array_column($userAuthResult, 'username', 'UID');
                $UIDs = array_keys($UID_UserName);

                $userProductResult = $this->userProductObject->findAll(['UID' => ['$in' => $UIDs]]);

                if (!empty($userProductResult)) {
                    foreach ($userProductResult as $UPValue) {
                        if ($username = $UID_UserName[$UPValue['UID']] ?? false) {
                            $this->setToGlobal($username, $UPValue['UPID']);
                        }
                    }
                }
            }
        }
    }

}
