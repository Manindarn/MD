<?php

ini_set('max_execution_time', 100000);

require_once 'define.php';
require_once 'HelperFunctions.php';
require_once 'Mapping.php';

require_once DB_PATH . 'MySqlDB.php';
require_once DB_PATH . 'MongoDB.php';

require_once 'types/Organization.php';
require_once 'types/User.php';

$GLOBALS['currentTime'] = time();

$postData = $_POST;

$schoolObject = new MySqlDB('schools', 'educatio_educat');
$commonUserDetailsObject = new MySqlDB('common_user_details', 'educatio_educat');
$UMMappingObject = new MySqlDB('user_management_mapping', 'mapping');

$mongoOrgObject = new MongoDB('Organizations', USER_MANAGEMENT_DB);
$UMPortingLogObject = new MongoDB('UMPortingLog', PORTING_DB);

$response = ['status' => 'error', 'msg' => 'Something went wrong...'];

switch ($postData['method'] ?? '') {

    case 'getPortedOrganizations':

        $allRecord = $filteredRecord = $UMMappingObject->getCount('type="organization" and newID IS NOT NULL');

        $page = ($postData['start'] ? ($postData['start'] / $postData['length']) + 1 : 1);

        $options['limit'] = (int) $postData['length'];
        $options['skip'] = (int) (($page - 1) * $options['limit']);

        $portedOrg = $UMMappingObject->getAll('type="organization" and newID IS NOT NULL')['data'] ?? [];

        $newOrgIDs = array_values(array_unique(array_column($portedOrg, 'newID')));

        $portedOrg = $mongoOrgObject->findAll(['orgID' => ['$in' => $newOrgIDs]], $options);

        $tmpOrgObject = new Organization();

        $dataForTable = [];
        if (!empty($portedOrg)) {

            foreach ($portedOrg as $org) {
                $mongoUserCount = $tmpOrgObject->getMongoUserCount($org['orgID']);
                $mysqlUserCount = $tmpOrgObject->getMysqlUserCount($org);

                $dataForTable[] = [
                    'oldOrgID' => $org['oldOrgID'],
                    'name' => $org['name'],
                    'newOrgID' => $org['orgID'],
                    'oldStudentCount' => $mysqlUserCount['students'] ?? 0,
                    'oldTeacherCount' => $mysqlUserCount['teachers'] ?? 0,
                    'newStudentCount' => $mongoUserCount['students'] ?? 0,
                    'newTeacherCount' => $mongoUserCount['teachers'] ?? 0,
                ];
            }
        }

        $response = ['recordsTotal' => $allRecord, 'recordsFiltered' => $filteredRecord, 'data' => $dataForTable, 'draw' => (int) $postData['draw']];

        break;

    case 'checkSchoolCode':

        if ($schoolCode = $postData['oldOrgID'] ?? false) {
            $schoolDetails = $schoolObject->get('schoolno=' . $schoolCode)['data'] ?? [];

            if ($schoolDetails['schoolno'] ?? false) {
                $tmpOrgObject = new Organization();
                $tmpOrgObject->cleanUpArray($schoolDetails, ['schoolname', 'address', 'city', 'state', 'country']);

                $productsTaken = [];
                if ($schoolDetails['asset_taken'] == 'Y') {
                    $productsTaken[] = 'ASSET';
                }

                if ($schoolDetails['ms_taken'] == 'Y') {
                    $productsTaken[] = 'Mindspark';
                }

                if ($schoolDetails['da_taken'] == 'Y') {
                    $productsTaken[] = 'DA';
                }

                $responseData['name'] = $schoolDetails['schoolname'];
                $responseData['address'] = $schoolDetails['address'];
                $responseData['city'] = $schoolDetails['city'];
                $responseData['state'] = $schoolDetails['state'];
                $responseData['country'] = $schoolDetails['country'];
                $responseData['pincode'] = $schoolDetails['pincode'];
                $responseData['products'] = $productsTaken;

                $responseData['alreadyPorted'] = ($newOrgID = $mongoOrgObject->find(['oldOrgID' => $schoolCode])['orgID'] ?? false) && !empty($newOrgID);

                $response = ['status' => 'success', 'msg' => 'School details found', 'data' => $responseData];
            } else {
                $response['msg'] = 'No record found...';
            }

        } else {
            $response['msg'] = 'School Code not passed';
        }

        break;

    case 'portSchool':

        if ($schoolCode = $postData['oldOrgID'] ?? false) {
            $portSchool = 1;
            $portUsers = 1;

            $schoolDetails = $schoolObject->get('schoolno=' . $schoolCode)['data'] ?? [];

            if ($schoolDetails['schoolno'] ?? false) {
                $portResult = [];
                if ($portSchool) {
                    $organizationObject = new Organization();
                    $portResult['organization'] = $organizationObject->portOrganizations([$schoolCode]);
                }

                if ($portUsers) {
                    $userObject = new User();
                    $portResult['Users'] = $userObject->portUsers([$schoolCode]);
                }

                if (!empty($portResult)) {
                    $mappingObject = new Mapping();

                    if (isset($portResult['organization']['portedOrgAndMappings'][$schoolCode]['orgID'])) {
                        $newOrgID = $portResult['organization']['portedOrgAndMappings'][$schoolCode]['orgID'];
                        $mappingResult = $mappingObject->startMapping($newOrgID);
                    }

                    $portResult['_id'] = $UMPortingLogObject->newMongoId();
                    $portResult['createdAt'] = date(DATETIME_FORMAT);
                    $UMPortingLogObject->insert($portResult);

                    $response = ['msg' => 'Porting done', 'status' => 'success', 'data' => $portResult];
                } else {
                    $response['msg'] = 'Something went wrong, please try again...';
                }
            } else {
                $response['msg'] = 'No record found...';
            }
        } else {
            $response['msg'] = 'School Code not passed';
        }

        break;
    default:
        $response = ['status' => 'error', 'msg' => 'Parameter not passed.'];
        break;
}
header('Content-Type: application/json');

if (($status = $response['status'] ?? false) && $status === 'error') {
    http_response_code(400);
}

echo json_encode($response);
