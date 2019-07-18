<?php
echo "Click <a href='index.php'>here</a> to port school";
die;
// ini_set('max_execution_time', 10000);

// require_once 'define.php';
// require_once 'HelperFunctions.php';
// require_once 'Mapping.php';

// require_once DB_PATH . 'MySqlDB.php';
// require_once DB_PATH . 'MongoDB.php';

// require_once 'types/Organization.php';
// require_once 'types/User.php';

// $response = ['status' => 'error', 'msg' => 'Something went wrong'];

// $portSchool = ($portSchool = $_GET['portSchool'] ?? false) && $portSchool == 1;
// $portUsers = ($portUsers = $_GET['portUsers'] ?? false) && $portUsers == 1;

// $portResult = [];

// echo "<pre>";

// $GLOBALS['currentTime'] = time();

// if ($portSchool || $portUsers) {
//     if ($schoolCode = $_GET['schoolCode'] ?? false) {

//         $schoolCodes = explode(',', $schoolCode);

//         $data = [];
//         if ($portSchool) {
//             $organizationObject = new Organization();
//             $portResult['organization'] = $organizationObject->portOrganizations($schoolCodes);
//         }

//         if ($portUsers) {
//             $userObject = new User();
//             $portResult['Users'] = $userObject->portUsers($schoolCodes);
//         }

//         if (!empty($portResult)) {
//             $mappingObject = new Mapping();
//             $mappingResult = $mappingObject->startMapping();
//         }

//         $response['status'] = 'success';
//         $response['msg'] = 'Porting done';
//         $response['data'] = $portResult;

//     } else {
//         $response['msg'] = 'Old organization id not given.';
//     }
// } else {
//     $response['msg'] = 'Action not given (e.g.: schoolCode=1752&portSchool=1&portUsers=1)';
// }

// print_r($response);
// die("Test");
// echo json_encode($response);
