<?php
require_once '../include/DbHandler.php'; 

	class FCMNotificationTransportista {
		function __construct() {  

    	}

    	function sendData($json_data){
    		/*token juan : f99ERxWzZLM:APA91bG5U5zsltA6rObvRz0K9Lu7N0r1cds6kRVt-d_w1c1whh8nFdYtfmZVehVGMLFA-J_bXh-TXL_eCUYV_Q6GHY5R_AQahLf6r4ow-tAjdJ2Zpzx-pFZ-24KpSIe8eCHznJqrziyo*/
			$data = json_encode($json_data);
			//FCM API end-point
			$url = 'https://fcm.googleapis.com/fcm/send';
			//api_key in Firebase Console -> Project Settings -> CLOUD MESSAGING -> Server key
			$server_key = 'AAAAqVNWOAE:APA91bE90QKFWN_hNPw2Hsz6m0lHbpLe3XBDGwNJ9PmflK0kLwxRwWY4IBGDoJIL3O-HsealVGeLhEc0pP3xeeqhUH05q5rXiEl73_j98AYwicOiyXP9J8YOuLYOPxLvsNCakrsEwBOq';
			//header with content_type api key
			$headers = array(
			    'Content-Type:application/json',
			    'project_id:255962443872',
			    'Authorization:key='.$server_key
			);
			//CURL request to route notification to FCM connection server (provided by Google)
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			$result = curl_exec($ch);
			if ($result === FALSE) {
			    die('Oops! FCM Send Error: ' . curl_error($ch));
			}
			curl_close($ch);
			return $result;
    	}
    	
    	function sendDataJSON($json_data){
			$data = json_encode($json_data);
			//FCM API end-point
			$url = 'https://fcm.googleapis.com/fcm/send';
			$server_key = 'AAAAqVNWOAE:APA91bE90QKFWN_hNPw2Hsz6m0lHbpLe3XBDGwNJ9PmflK0kLwxRwWY4IBGDoJIL3O-HsealVGeLhEc0pP3xeeqhUH05q5rXiEl73_j98AYwicOiyXP9J8YOuLYOPxLvsNCakrsEwBOq';
			//header with content_type api key
			$headers = array(
			    'Content-Type:application/json',
			    'project_id:255962443872',
			    'Authorization:key='.$server_key
			);
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			$result = curl_exec($ch);
			if ($result === FALSE) {
			    die('Oops! FCM Send Error: ' . curl_error($ch));
			}
			curl_close($ch);
			return $result;
    	}
	}
?>