<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");

header("Expires: 0");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$method = $_SERVER['REQUEST_METHOD'];
if($method == "OPTIONS") {
    die();
}

require_once '../include/DbHandler.php'; 
require_once '../services/fcm_service.php'; 

require '../libs/Slim/Slim.php'; 

require ('../libs/PHPMailer/src/PHPMailer.php');
require ('../libs/PHPMailer/src/SMTP.php');
require ('../libs/PHPMailer/src/Exception.php');

\Slim\Slim::registerAutoloader();
$app = new \Slim\Slim();

$app->get('/delete-notifications', function () use ($app) {

    $response = array();
    $dbHandler = new DbHandler();
    $db = $dbHandler->getConnection();
    try {

        $uid = $app->request()->params('uid');

        $sqlUserUpdate = "DELETE FROM notification WHERE open = 1";
        $sthUserUpdate = $db->prepare($sqlUserUpdate);
        $sthUserUpdate->execute();
        
        $response["status"] = true;
        $response["description"] = "Exitoso";
        $response["idTransaction"] = time();
        $response["parameters"] = [];
        $response["timeRequest"] = date("Y-m-d H:i:s");

        echoResponse(200, $response);

    } catch (Exception $e) {

        $response["status"] = false;
        $response["description"] = "Ocurrió un error en el servicio";
        $response["idTransaction"] = time();
        $response["parameters"] = $e->getMessage();
        $response["timeRequest"] = date("Y-m-d H:i:s");
        
        echoResponse(400, $response);       
    }

});
$app->run();