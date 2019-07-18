<?php
require_once 'ITypes.php';

class Organization implements ITypes
{
    use HelperFunctions;

    private $mongoOrgObject = null;
    private $mongoBatchObject = null;
    private $mongoOrderObject = null;
    private $mongoGroupsObject = null;
    private $mongoSectionObject = null;
    private $mongoPSBObject = null;
    private $mongoSettingsObject = null;
    private $userDetailsObject = null;
    private $userProductObject = null;

    private $mysqlSchoolObject = null;
    private $mysqlCommonUserDetailsObject = null;
    private $mysqlOrgNotInRearchObject = null;

    private $batchStartDate = null;
    private $batchEndDate = null;

    private $portConfig = null;
    private $currentDateTime = null;
    public $portingStatus = [];
    public $portedOrgAndMappings = [];

    public $orgIDForOffline = null;
    public $isOffline = false;

    private $selectedColumns = [
        'schoolno', 'schoolname', 'asset_taken', 'ms_taken', 'da_taken', 'contact_person_1', 'role',
        'designation_1', 'contact_std_1', 'contact_no_1', 'mobile_no_1', 'contact_mail_1', 'address',
        'city', 'state', 'country', 'pincode', 'std_code', 'phones', 'fax', 'email', 'webpage', 'board',
        'mediums', 'comments', 'modified_by', 'modified_at', 'school_image', 'tan',
    ];

    private $directFieldMapping = [
        'schoolname' => 'name', 'webpage' => 'website', 'std_code' => 'STDCode',
        'fax' => 'fax', 'board' => 'board', 'mediums' => 'medium', 'tan' => 'tanNumber',
        'school_image' => 'orgLogo', 'modified_by' => 'updatedBy', 'modified_at' => 'updatedAt',
    ];

    public function __construct()
    {
        $this->mongoOrgObject = new MongoDB('Organizations');
        $this->mongoBatchObject = new MongoDB('Batch');
        $this->mongoOrderObject = new MongoDB('Order');
        $this->mongoGroupsObject = new MongoDB('Groups');
        $this->mongoSectionObject = new MongoDB('SectionDetails');
        $this->mongoPSBObject = new MongoDB('ProductSectionBatchMapping');
        $this->mongoSettingsObject = new MongoDB('Settings');
        $this->userDetailsObject = new MongoDB('UserDetails');
        $this->userProductObject = new MongoDB('UserProducts');

        $this->mysqlSchoolObject = new MySqlDB('schools', 'educatio_educat');
        $this->mysqlCommonUserDetailsObject = new MySqlDB('common_user_details', 'educatio_educat');
        $this->mysqlOrgNotInRearchObject = new MySqlDB(ORG_NOT_IN_REARCH_TABLE, MAPPING_DB);

        $this->portConfig = $this->getConfig('porting');

        $this->defaultMathPID = $this->portConfig['defaultMathPID'];
        $this->defaultEnglighPID = $this->portConfig['defaultEnglighPID'];

        $this->currentDateTime = date(DATETIME_FORMAT);
    }

    public function getOldOrgDetails($oldOrgIDs)
    {
        $where = "schoolno IN (" . implode(",", $oldOrgIDs) . ")";

        return $this->mysqlSchoolObject->getAll($where)['data'] ?? [];
    }

    public function portOrganizations($oldOrgIDs)
    {
        $oldOrgDetails = $this->getOldOrgDetails($oldOrgIDs);

        if (!empty($oldOrgDetails)) {
            foreach ($oldOrgDetails as $key => $oldOrg) {
                $oldOrgID = $oldOrg['schoolno'];
                $this->portOrgAndAll($oldOrgID, $oldOrg);
            }
        } else {
            $this->portedOrgAndMappings['errorMsg'] = $this->portingStatus['errorMsg'] = 'No such organization found in old structure';
        }

        return ['portingStatus' => $this->portingStatus, 'portedOrgAndMappings' => $this->portedOrgAndMappings];
    }

    public function portOrgAndAll($oldOrgID, $oldOrg = [])
    {

        $oldOrg = empty($oldOrg) ? $this->getMysqlDetailsForSync($oldOrgID, false) : $oldOrg;

        $this->isOffline = $this->checkOffline($oldOrgID);

        $cleanUpThis = ['schoolname', 'address', 'city', 'state', 'country', 'webpage'];
        $this->cleanUpArray($oldOrg, $cleanUpThis);

        $newOrgDetail = $this->portSingleOrganization($oldOrg);
        if ($newOrgDetail) {
            $this->orgIDForOffline = $newOrgDetail['orgID'];

            $this->setOrgIDForOffline();

            $orderDetails = $this->createOrder($oldOrg);

            $batchDetails = $this->createBatch($oldOrg);

            $this->portSettings($oldOrgID);

            $this->mysqlOrgNotInRearchObject->delete('orgID=' . $oldOrgID); //remove school from org_not_in_rearch table, so that sync will work

            if ($this->isOffline) {

                $sourceSyncObject = new MongoDB('SourceSync', SYNC_SERVICE_DB);

                $lastSourcCount = $sourceSyncObject->count([]);
                $sourceSyncData = [
                    '_id' => $newOrgDetail['orgID'],
                    'srno' => intval(++$lastSourcCount),
                    'sourceID' => $newOrgDetail['orgID'],
                    'lastSyncNo' => 0,
                ];

                $searchCond = ['sourceID' => $newOrgDetail['orgID']];

                $sourceSyncObject->findInsert($searchCond, $sourceSyncData);

            }

        }
        return $this->portedOrgAndMappings[$oldOrg['schoolno']];
    }

    private function portSingleOrganization($oldOrg)
    {

        $orgID = (string) $this->mongoOrgObject->newMongoId();

        $productsTaken = [];
        if ($oldOrg['asset_taken'] == 'Y') {
            $productsTaken[] = 'ASSET';
        }

        if ($oldOrg['ms_taken'] == 'Y') {
            $productsTaken[] = 'Mindspark';
        }

        if ($oldOrg['da_taken'] == 'Y') {
            $productsTaken[] = 'DA';
        }

        $grades = [$oldOrg['lowest_class'], $oldOrg['highest_class']];

        $insertData = [
            "_id" => $orgID,
            "orgID" => $orgID,
            "name" => $oldOrg['schoolname'],
            "orgType" => $this->portConfig['orgType'],
            "grades" => $grades,
            "dateOfRegistration" => $this->getFormatedDate($oldOrg['entered_at'], DATE_FORMAT),
            "parentOrgID" => null,
            "childOrgIDs" => [],
            "address" => [
                "line1" => $oldOrg['address'],
                "city" => $oldOrg['city'],
                "pincode" => $oldOrg['pincode'],
                "state" => $oldOrg['state'],
                "country" => $oldOrg['country'],
            ],
            "email" => array_map('trim', explode(',', $oldOrg['email'])),
            "ISDCode" => $this->portConfig['countryCode'],
            "STDCode" => $oldOrg['std_code'],
            "fax" => $oldOrg['fax'],
            "phone" => array_map('trim', explode(',', $oldOrg['phones'])),
            "website" => $oldOrg['webpage'],
            "products" => $productsTaken,
            "orgLogo" => $oldOrg['school_image'],
            "description" => null,
            "SDIds" => [],
            "groupIDs" => [],
            "contactPerson" => [
                [
                    "name" => $oldOrg['contact_person_1'],
                    "role" => $oldOrg['role'],
                    "designation" => $oldOrg['designation_1'],
                    "email" => $oldOrg['contact_mail_1'],
                    "mobile" => $oldOrg['mobile_no_1'],
                    "landline" => $oldOrg['contact_no_1'],
                    "landlineSTD" => $oldOrg['contact_std_1'],
                ],
            ],
            "board" => $oldOrg['board'],
            "medium" => $oldOrg['mediums'],
            "visitDate" => $this->getFormatedDate($oldOrg['visit_date']),
            "otherOrgDetails" => [
                "fees" => $oldOrg['fees'],
                "accountingName" => $oldOrg['accounting_name'],
                "funding" => $oldOrg['funding'],
                "hec" => $oldOrg['hec'],
                "comments" => $oldOrg['comments'],
                "monitor" => $oldOrg['monitor'],
                "category" => $oldOrg['category'],
                "priority" => $oldOrg['priority'],
                "rating" => $oldOrg['rating'],
                "dndFlag" => $oldOrg['dnd_flag'],
                "newsletterSent" => $oldOrg['newsletterSent'],
            ],
            "tanNumber" => $oldOrg['tan'],
            "workingHour" => "",
            "oldOrgID" => $oldOrg['schoolno'],
            "createdAt" => $this->getFormatedDate($oldOrg['entered_at'], DATETIME_FORMAT),
            "createdBy" => $oldOrg['entered_by'],
            "updatedAt" => $this->getFormatedDate($oldOrg['modified_at'], DATETIME_FORMAT),
            "updatedBy" => $oldOrg['modified_by'],
            "registeredBy" => $oldOrg['entered_by'],
            "approved" => ($oldOrg['approved'] == "1"),
            "approvedBy" => $oldOrg['approved_by'],
            "approvedAt" => null,
            "region" => $oldOrg['region'],
            "portedAt" => $this->getTodayDateTime(),
            "status" => "A",
            "version" => 1,
        ];
        $seacrhCondOrg = ['oldOrgID' => $oldOrg['schoolno']];

        $this->mongoOrgObject->orgIDForOffline = $orgID;
        $this->mongoOrgObject->offlineSync = $this->isOffline;
        $orgInsert = $this->mongoOrgObject->findInsert($seacrhCondOrg, $insertData);

        if ($orgInsert['errorCode'] == 1) {
            $insertData['_id'] = $orgInsert['val']['_id'];
            $insertData['orgID'] = $orgInsert['val']['orgID'];
            $orgID = $orgInsert['val']['orgID'];
        }
        $this->mongoOrgObject->orgIDForOffline = $orgID;

        $this->portingStatus = $this->getPrintStatus($orgInsert, 'Organization', $this->portingStatus);

        $this->portedOrgAndMappings[$oldOrg['schoolno']]['oldOrgID'] = $oldOrg['schoolno'];
        $this->portedOrgAndMappings[$oldOrg['schoolno']]['orgID'] = $orgID;
        $this->portedOrgAndMappings[$oldOrg['schoolno']]['orgName'] = $insertData['name'];

        return $insertData;

    }

    private function createBatch($oldOrg)
    {
        $batchConfig = $this->portConfig['batch'];
        $newOrgID = $this->portedOrgAndMappings[$oldOrg['schoolno']]['orgID'];

        $batchStartDate = $this->batchStartDate = is_null($this->batchStartDate) ? $this->getFormatedDate() : $this->batchStartDate;
        $batchEndDate = $this->batchEndDate = is_null($this->batchEndDate) ? $this->getFormatedDate('+1 year') : $this->batchEndDate;

        $batchName = date('Y', strtotime($batchStartDate)) . '-' . date('Y', strtotime($batchEndDate));
        $batchTag = $batchConfig['tag'];

        $month = date("M", strtotime($batchStartDate));
        $year = strval(date("Y", strtotime($batchStartDate)));
        $diff = (date_diff(new DateTime($batchStartDate), new DateTime($batchEndDate)));
        $duration = intval($diff->y * 12 + $diff->m + $diff->d / 30 + $diff->h / 24);

        $batchId = (string) $this->mongoBatchObject->newMongoId();
        $insertData = [
            '_id' => $batchId,
            'batchID' => $batchId,
            'name' => $batchName,
            'tag' => $batchTag,
            'orgID' => $newOrgID,
            'month' => $month,
            'year' => $year,
            'semester' => null,
            'type' => null,
            'startDate' => $batchStartDate,
            'endDate' => $batchEndDate,
            'duration' => $duration,
            "createdAt" => $this->getTodayDateTime(),
            "createdBy" => 'script',
            "updatedAt" => $this->getTodayDateTime(),
            "updatedBy" => "script",
            'status' => 'A',
            'version' => 1,
        ];
        $seacrhCondBatch = ['orgID' => $newOrgID, 'name' => $batchName, 'year' => $year, 'status' => 'A'];

        $batchInsert = $this->mongoBatchObject->findInsert($seacrhCondBatch, $insertData);

        if ($batchInsert['errorCode'] == 1) {
            $insertData['_id'] = $batchInsert['val']['_id'];
            $insertData['batchID'] = $batchInsert['val']['batchID'];
        }

        $this->portingStatus = $this->getPrintStatus($batchInsert, 'Batch', $this->portingStatus);

        $this->portedOrgAndMappings[$oldOrg['schoolno']]['batchID'] = $insertData['_id'];
        $this->portedOrgAndMappings[$oldOrg['schoolno']]['batchName'] = $insertData['name'];

        return $insertData;

    }

    private function createOrder($oldOrg)
    {

        $this->createOrderForMath($oldOrg);
        $this->createOrderForEngligh($oldOrg);

        return $this->portedOrgAndMappings[$oldOrg['schoolno']]['order'] ?? false;
    }

    private function portSettings($oldOrgID)
    {

        $orgAndRelatedIDs = $this->portedOrgAndMappings[$oldOrgID];

        $allowedSettings = $this->portConfig['allowedSettings'];

        $newOrgID = $orgAndRelatedIDs['orgID'];

        $settingsQuery = "SELECT GROUP_CONCAT(distinct us.settingName, '=', us.settingValue separator '|' ) settings FROM educatio_adepts." . SETTINGS_TABLE . " us WHERE us.schoolCode =$oldOrgID";

        $settingsResult = $this->mysqlSchoolObject->rawQuery($settingsQuery)['data'][0] ?? [];

        $isOffline = $this->checkOffline($oldOrgID);

        if (!empty($orders = $orgAndRelatedIDs['order'] ?? [])) {

            $settingsData = [];

            if ($settings = $settingsResult['settings'] ?? false) {

                array_map(function ($etting) use (&$settingsData, $allowedSettings) {
                    $key = ($tmp = explode('=', $etting))[0];
                    if (in_array($key, $allowedSettings) && isset($tmp[1])) {
                        $value = $tmp[1];

                        if ($key == 'curriculum') {
                            $key = 'board';
                        }
                        $settingsData[$key] = $value;
                    }

                }, explode('|', $settings));
            }

            $settingsData['offlineUser'] = $isOffline;

            foreach ($orders as $PID => $orderID) {
                $settingsID = $this->mongoSettingsObject->newMongoId();
                $insertData = [
                    '_id' => $settingsID,
                    "PID" => $PID,
                    "searchID" => $newOrgID,
                    "settings" => $settingsData,
                    "type" => 'organization',
                    "createdAt" => $this->getTodayDateTime(),
                    "updatedAt" => $this->getTodayDateTime(),
                    "updatedBy" => "script",
                    "version" => 1,
                ];

                $searchCondition = [
                    "PID" => $PID,
                    "searchID" => $newOrgID,
                    "type" => 'organization',
                ];
                $insertResult = $this->mongoSettingsObject->findInsert($searchCondition, $insertData);

                $this->portingStatus = $this->getPrintStatus($insertResult, 'Settings', $this->portingStatus);

            }
        }
        return true;
    }

    private function createOrderForMath($oldOrg)
    {
        $newOrgID = $this->portedOrgAndMappings[$oldOrg['schoolno']]['orgID'];
        $oldOrgID = $oldOrg['schoolno'];

        // $sqlQuery = "SELECT  om.*, GROUP_CONCAT(sb.class, '=',  sb.section SEPARATOR '|'  ) classes FROM `educatio_educat`.`ms_orderMaster` om JOIN  `educatio_educat`.`ms_studentBreakup` sb ON om.order_id = sb.order_id WHERE om.schoolCode = $oldOrgID  ORDER BY om.year DESC ";
        $sqlQuery = "SELECT  * FROM `educatio_educat`.`ms_orderMaster` om WHERE om.schoolCode = $oldOrgID AND (om.start_date <= CURDATE() AND om.end_date > CURDATE())  ORDER BY om.year DESC LIMIT 1";

        $orderDetails = $this->mysqlSchoolObject->rawQuery($sqlQuery)['data'][0] ?? [];

        $orderID = (string) $this->mongoOrderObject->newMongoId();

        $insertData = [
            '_id' => $orderID,
            "orderID" => $orderID,
            "oldOrderID" => $orderDetails['order_id'] ?? ($oldOrgID . '-' . $this->defaultMathPID),
            "orgID" => $newOrgID,
            "PID" => $this->defaultMathPID,
            "year" => isset($orderDetails['year']) && $orderDetails['year'] != '' ? $orderDetails['year'] : date('Y'),
            "orderType" => $orderDetails['order_type'] ?? $this->portConfig['order']['orderType'],
            "defaultFlow" => $orderDetails['defaultFlow'] ?? null,
            "duration" => $orderDetails['duration'] ?? 0,
            "startDate" => $orderDetails['start_date'] ?? null,
            "endDate" => $orderDetails['end_date'] ?? null,
            "subjects" => [],
            "grades" => [],
            "totalStudent" => $orderDetails['total_students'] ?? 0,
            "payableAmount" => $orderDetails['net_payable'] ?? 0,
            "paid" => $orderDetails['paid'] ?? 0,
            "orderBy" => $orderDetails['order_by'] ?? 'script',
            "createdAt" => $this->getFormatedDate($orderDetails['added_on'] ?? $this->currentDateTime, DATETIME_FORMAT),
            "createdBy" => $orderDetails['added_by'] ?? 'script',
            "updatedAt" => $this->getFormatedDate($orderDetails['last_modified'] ?? $this->currentDateTime, DATETIME_FORMAT),
            "updatedBy" => $orderDetails['modified_by'] ?? 'script',
            "comments" => $orderDetails['comments'] ?? null,
            'ssfNumber' => $orderDetails['ssf_number'] ?? null,
            'ssfDate' => $orderDetails['ssf_date'] ?? null,
            'ssfNumber' => $orderDetails['ssf_number'] ?? null,
            'refund' => ['amount' => $orderDetails['refund_amount'] ?? 0, 'cheque' => $orderDetails['refund_cheque'] ?? null, 'date' => $orderDetails['refund_date'] ?? null],
            'deductions' => [
                ['deduct' => $orderDetails['deduct_1'] ?? null, 'equipment' => $orderDetails['equipment_deduct_1'] ?? null, 'desc' => $orderDetails['deduct_1_desc'] ?? null],
                ['deduct' => $orderDetails['deduct_2'] ?? null, 'equipment' => $orderDetails['equipment_deduct_2'] ?? null, 'desc' => $orderDetails['deduct_2_desc'] ?? null],
            ],
            "orderOtherDetails" => [],
            'version' => 1,
            'status' => ($orderDetails['is_active'] ?? 1) == 1 || is_null($orderDetails['is_active']) ? 'A' : 'D',
        ];
        $seacrhCondOrder = [
            "orgID" => $newOrgID,
            "PID" => $this->defaultMathPID,
            "year" => $insertData['year'],
            "orderType" => $insertData['orderType'],
            "duration" => $insertData['duration'],
        ];

        $orderInsert = $this->mongoOrderObject->findInsert($seacrhCondOrder, $insertData);

        if ($orderInsert['errorCode'] == 1) {
            $insertData['_id'] = $orderInsert['val']['_id'];
            $insertData['orderID'] = $orderInsert['val']['orderID'];
        }
        $this->portingStatus = $this->getPrintStatus($orderInsert, 'Order', $this->portingStatus);

        $this->portedOrgAndMappings[$oldOrg['schoolno']]['order'][$this->defaultMathPID] = $insertData['_id'];

        if (!($this->batchStartDate && $this->batchEndDate) && ($insertData['startDate'] && $insertData['endDate'])) {
            $this->batchStartDate = $insertData['startDate'];
            $this->batchEndDate = $insertData['endDate'];
        }

    }

    private function createOrderForEngligh($oldOrg)
    {
        $newOrgID = $this->portedOrgAndMappings[$oldOrg['schoolno']]['orgID'];
        $oldOrgID = $oldOrg['schoolno'];

        // $sqlQuery = "SELECT  om.*, GROUP_CONCAT(sb.class, '=',  sb.section SEPARATOR '|'  ) classes FROM `educatio_educat`.`mseng_orderMaster` om JOIN  `educatio_educat`.`msEng_studentBreakup` sb ON om.order_id = sb.order_id WHERE om.schoolCode = $oldOrgID  ORDER BY om.year DESC ";

        $sqlQuery = "SELECT  * FROM `educatio_educat`.`mseng_orderMaster` om WHERE om.schoolCode = $oldOrgID AND (om.start_date <= CURDATE() AND om.end_date > CURDATE()) ORDER BY om.year DESC LIMIT 1";

        $orderDetails = $this->mysqlSchoolObject->rawQuery($sqlQuery)['data'][0] ?? [];

        $orderID = (string) $this->mongoOrderObject->newMongoId();

        $insertData = [
            '_id' => $orderID,
            "orderID" => $orderID,
            "oldOrderID" => $orderDetails['order_id'] ?? ($oldOrgID . '-' . $this->defaultEnglighPID),
            "orgID" => $newOrgID,
            "PID" => $this->defaultEnglighPID,
            "year" => isset($orderDetails['year']) && $orderDetails['year'] != '' ? $orderDetails['year'] : date('Y'),
            "orderType" => $orderDetails['order_type'] ?? $this->portConfig['order']['orderType'],
            "defaultFlow" => $orderDetails['defaultFlow'] ?? null,
            "duration" => $orderDetails['duration'] ?? 0,
            "startDate" => $orderDetails['start_date'] ?? null,
            "endDate" => $orderDetails['end_date'] ?? null,
            "subjects" => [],
            "grades" => [],
            "totalStudent" => $orderDetails['total_students'] ?? 0,
            "payableAmount" => $orderDetails['net_payable'] ?? 0,
            "paid" => $orderDetails['paid'] ?? 0,
            "orderBy" => $orderDetails['order_by'] ?? 'script',
            "createdAt" => $this->getFormatedDate($orderDetails['added_on'] ?? $this->currentDateTime, DATETIME_FORMAT),
            "createdBy" => $orderDetails['added_by'] ?? 'script',
            "updatedAt" => $this->getFormatedDate($orderDetails['last_modified'] ?? $this->currentDateTime, DATETIME_FORMAT),
            "updatedBy" => $orderDetails['modified_by'] ?? 'script',
            "comments" => $orderDetails['comments'] ?? null,
            'ssfNumber' => $orderDetails['ssf_number'] ?? null,
            'ssfDate' => $orderDetails['ssf_date'] ?? null,
            'ssfNumber' => $orderDetails['ssf_number'] ?? null,
            'refund' => ['amount' => $orderDetails['refund_amount'] ?? 0, 'cheque' => $orderDetails['refund_cheque'] ?? null, 'date' => $orderDetails['refund_date'] ?? null],
            'deductions' => [
                ['deduct' => $orderDetails['deduct_1'] ?? null, 'equipment' => $orderDetails['equipment_deduct_1'] ?? null, 'desc' => $orderDetails['deduct_1_desc'] ?? null],
                ['deduct' => $orderDetails['deduct_2'] ?? null, 'equipment' => $orderDetails['equipment_deduct_2'] ?? null, 'desc' => $orderDetails['deduct_2_desc'] ?? null],
            ],
            "orderOtherDetails" => [],
            'version' => 1,
            'status' => ($orderDetails['is_active'] ?? 1) == 1 || is_null($orderDetails['is_active']) ? 'A' : 'D',
        ];
        $seacrhCondOrder = [
            "orgID" => $newOrgID,
            "PID" => $this->defaultEnglighPID,
            "year" => $insertData['year'],
            "orderType" => $insertData['orderType'],
            "duration" => $insertData['duration'],
        ];

        $orderInsert = $this->mongoOrderObject->findInsert($seacrhCondOrder, $insertData);

        if ($orderInsert['errorCode'] == 1) {
            $insertData['_id'] = $orderInsert['val']['_id'];
            $insertData['orderID'] = $orderInsert['val']['orderID'];
        }
        $this->portingStatus = $this->getPrintStatus($orderInsert, 'Order', $this->portingStatus);

        $this->portedOrgAndMappings[$oldOrg['schoolno']]['order'][$this->defaultEnglighPID] = $insertData['_id'];

        if (!($this->batchStartDate && $this->batchEndDate) && ($insertData['startDate'] && $insertData['endDate'])) {
            $this->batchStartDate = $insertData['startDate'];
            $this->batchEndDate = $insertData['endDate'];
        }

    }

///////////////////////mapping code///////////////

    public function getMysqlDetailsForSync($id, $filterColumns = true)
    {
        $orgDetails = $this->mysqlSchoolObject->get("schoolno = " . $id)['data'] ?? [];
        return $filterColumns ? $this->arrayFilterByKeys($orgDetails, $this->selectedColumns) : $orgDetails;
    }

    public function getMongoDetailsForSync($mappingData)
    {
        return $this->mongoOrgObject->find([$mappingData['newIDName'] => $mappingData['newID']]);
    }

    public function convertToMysqlFormat($details, &$mysqlData = [])
    {
        $address = $details['address'] ?? [];
        $contactPerson = $details['contactPerson'][0] ?? [];

        return [
            'schoolname' => $details['name'],
            'approved' => $details['approved'] ? 1 : 0,
            'asset_taken' => in_array('ASSET', $details['products']) ? 'Y' : '',
            'ms_taken' => in_array('Mindspark', $details['products']) ? 'Y' : '',
            'da_taken' => in_array('DA', $details['products']) ? 'Y' : '',
            'contact_person_1' => $contactPerson['name'] ?? '',
            'role' => $contactPerson['role'] ?? '',
            'designation_1' => $contactPerson['designation'] ?? '',
            'contact_std_1' => $contactPerson['landlineSTD'] ?? '',
            'contact_no_1' => $contactPerson['landline'] ?? '',
            'mobile_no_1' => $contactPerson['mobile'] ?? '',
            'contact_mail_1' => $contactPerson['email'] ?? '',
            'address' => $address['line1'] ?? '',
            'city' => $address['city'] ?? '',
            'state' => $address['state'] ?? '',
            'country' => $address['country'] ?? '',
            'pincode' => $address['pincode'] ?? '',
            'std_code' => $details['STDCode'] ?? '',
            'fax' => $details['fax'] ?? '',
            'phones' => implode(', ', $details['phone']),
            'email' => implode(', ', $details['email']),
            'webpage' => $details['website'],
            'board' => $details['board'],
            'mediums' => $details['medium'],
            'comments' => $details['otherOrgDetails']['comments'] ?? '',
            'category' => $details['otherOrgDetails']['category'] ?? '',
            'priority' => $details['otherOrgDetails']['priority'] ?? '',
            'newsletterSent' => $details['otherOrgDetails']['newsletterSent'] ?? '',
            'hec' => $details['otherOrgDetails']['hec'] ?? '',
            'region' => $details['region'] ?? '',
            'modified_by' => $details['updatedBy'],
            'modified_at' => $details['updatedAt'],
            'school_image' => $details['orgLogo'],
            'tan' => $details['tanNumber'],
        ];
    }

    public function convertToMongoFormat($details, $oldData = [], $newData = [])
    {
        $contactPersonMapping = [
            'contact_person_1' => 'name',
            'role' => 'role',
            'designation_1' => 'designation',
            'contact_std_1' => 'landlineSTD',
            'contact_no_1' => 'mobile',
            'contact_mail_1' => 'email',
        ];

        $convertedDetails = [];

        foreach ($details as $key => $value) {
            switch ($key) {

                case 'comments':
                case 'category':
                case 'priority':
                case 'newsletterSent':
                case 'hec':
                    $convertedDetails['otherOrgDetails'][$key] = $value;
                    break;

                case 'email':
                case 'phones':
                    $convertedDetails[$key == 'phone' ? 'phones' : 'email'] = explode(',', $value);
                    break;

                case 'address':
                    $convertedDetails['address']['line1'] = $value;
                    break;

                case 'city':
                    $convertedDetails['address']['city'] = $value;
                    break;

                case 'state':
                    $convertedDetails['address']['state'] = $value;
                    break;

                case 'country':
                    $convertedDetails['address']['country'] = $value;
                    break;

                case 'pincode':
                    $convertedDetails['address']['pincode'] = $value;
                    break;

                case 'contact_person_1':
                case 'role':
                case 'designation_1':
                case 'contact_std_1':
                case 'contact_no_1':
                case 'contact_mail_1':
                    $convertedDetails['contactPerson'][0][$contactPersonMapping[$key]] = $value;
                    break;

                default:
                    if ($key = $this->directFieldMapping[$key] ?? false) {
                        $convertedDetails[$key] = $value;
                    }
                    break;
            }
        }
        return $convertedDetails;
    }

    public function createRecordInMongo($mappingData)
    {
        return $this->portOrgAndAll($mappingData['oldID'])['orgID'] ?? false;
    }

    public function createRecordInMysql($mappingData)
    {
    }

    public function updateFromMysqlToMongo($mongoData, $mysqlData)
    {
        $this->isOffline = $this->checkOffline($mysqlData['schoolno']);
        $this->setOrgIDForOffline();

        $returnData = false;
        $convertedDataMongoToMysql = $this->convertToMysqlFormat($mongoData, $mysqlData);

        $dataDiff = array_diff_assoc($mysqlData, $convertedDataMongoToMysql);

        $mongoUpdatedData = $this->convertToMongoFormat($dataDiff);

        if (!empty($mongoUpdatedData)) {
            $this->mongoOrgObject->update(['orgID' => $mongoData['orgID']], $mongoUpdatedData);
            $returnData = true;
        }

        return $returnData;
    }

    public function updateFromMongoToMysql($mongoData, $mysqlData)
    {

        $returnData = false;

        $convertedDataMongoToMysql = $this->convertToMysqlFormat($mongoData, $mysqlData);

        $convertedDataMongoToMysql = $this->arrayFilterByKeys($convertedDataMongoToMysql, $this->selectedColumns);

        $dataDiff = array_diff_assoc($convertedDataMongoToMysql, $mysqlData);

        if (!empty($dataDiff)) {
            $this->mysqlSchoolObject->rawQuery('SET @TRIGGER_CHECKS = FALSE;', false);
            $this->mysqlSchoolObject->update('schoolno = ' . $mysqlData['schoolno'], $dataDiff);
            $returnData = true;
        }

        return $returnData;

    }

    public function setOrgIDForOffline()
    {

        $objects = ['mongoOrgObject', 'mongoBatchObject', 'mongoOrderObject', 'mongoGroupsObject', 'mongoSectionObject', 'mongoPSBObject', 'mongoSettingsObject'];

        foreach ($objects as $objectName) {
            $this->$objectName->orgIDForOffline = $this->orgIDForOffline;
            $this->$objectName->offlineSync = $this->isOffline;
        }
    }

    public function checkOffline($oldOrgID)
    {
        $offlineSchoolObject = new MySqlDB(OFFLINE_SCHOOLS_TABLE, 'educatio_adepts');
        $MSE_offlineSchoolObject = new MySqlDB(OFFLINE_SCHOOLS_TABLE, 'educatio_msenglish');

        return ($offlineSchoolObject->getCount('schoolCode = ' . $oldOrgID) > 0) || ($MSE_offlineSchoolObject->getCount('schoolCode = ' . $oldOrgID) > 0);
    }

    public function getMongoUserCount($orgID)
    {

        $userDetails = $this->userDetailsObject->findAll(['orgID' => $orgID]);
        $UIDs = array_values(array_unique(array_column($userDetails, 'UID')));

        $students = $this->userProductObject->findAll(['UID' => ['$in' => $UIDs], 'category' => 'student']);
        $studentUIDs = array_values(array_unique(array_column($students, 'UID')));

        $teachers = $this->userProductObject->findAll(['UID' => ['$in' => $UIDs], 'category' => 'teacher']);
        $teacherUIDs = array_values(array_unique(array_column($teachers, 'UID')));

        return [
            'students' => $this->userDetailsObject->count(['UID' => ['$in' => $studentUIDs]]),
            'teachers' => $this->userDetailsObject->count(['UID' => ['$in' => $teacherUIDs]]),
        ];

    }

    public function getMysqlUserCount($portedOrg)
    {
        $oldOrgID = $portedOrg['oldOrgID'];

        $portedAt = $this->getFormatedDate(($portedOrg['portedAt'] ?? '2018-06-01'));

        $rawQuery = "SELECT cu.`schoolCode`,
                            SUM(CASE WHEN cu.`category`='STUDENT' THEN 1 ELSE 0 END) students,
                            SUM(CASE WHEN cu.`category`='TEACHER' OR cu.`category`='School Admin' THEN 1 ELSE 0 END) teachers
                        FROM `educatio_educat`.`common_user_details` cu
                        WHERE cu.`schoolCode` =" . $oldOrgID . " AND
                        (
                            (cu.`MS_userID`!=0 AND cu.`MS_enabled`=1 AND cu.`endDate`> '" . $portedAt . "') OR
                            (cu.`MSE_userID`!=0 AND cu.`MSE_enabled`=1 AND cu.`MSE_endDate`> '" . $portedAt . "')
                        )";

        return $this->mysqlCommonUserDetailsObject->rawQuery($rawQuery)['data'][0] ?? [];
    }

}
