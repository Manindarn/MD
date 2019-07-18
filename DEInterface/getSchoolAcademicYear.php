<?php
require_once('DEInterface.php');

$deInterfaceObj = new DEInterface();

$action = isset($_POST['action']) ? $_POST['action'] : "";

switch ($action){

    case "getOrgBatches":
            $orgList = $deInterfaceObj->getOrgBatches($_POST['orgID']);
            echo json_encode($orgList);
            break;

    case "getOrgStudents":
            $orgList = $deInterfaceObj->getOrgStudents($_POST['orgID']);
            
            echo json_encode($orgList);
            break;

    default:
       // die("No Action is selected");
       // break;
    
}


;

?>