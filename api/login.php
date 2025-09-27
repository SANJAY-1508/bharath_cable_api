<?php
// Allow from specific origin
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// If preflight request, return OK
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
include 'config/config.php';

$json = file_get_contents('php://input');
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

// <<<<<<<<<<===================== API Data Handling Starts Here =====================>>>>>>>>>>
if (isset($obj->phone) && isset($obj->password)) {

    $phone = $obj->phone;
    $password = $obj->password;
   
    if (!empty($phone) && !empty($password)) {
        if (numericCheck($phone) && strlen($phone) == 10) {

            // <<<<<<<<<<===================== Checking the user table =====================>>>>>>>>>>
            $result = $conn->query("SELECT `id`,`user_id`, `name`, `phone`,`role`,`password`,`area` FROM `user` WHERE `phone`='$phone' AND `deleted_at` = 0");
            if ($result->num_rows > 0) {
                if ($row = $result->fetch_assoc()) {

                    if ($row['password'] == $password) {
                        $userID = $row['id'];
                            
                        $output["head"]["code"] = 200;
                        $output["head"]["msg"] = "Success";
                        $output["body"]["user"]["id"] = $userID;
                        $output["body"]["user"]["user_id"]=$row["user_id"];
                        $output["body"]["user"]["user_name"] = $row['name'];
                        $output["body"]["user"]["phone_no"] = $row['phone'];
                        $output["body"]["user"]["role"] = $row['role'];
                        $output["body"]["user"]["area"] = $row['area'];
                               
                        } else {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Invalid Credentials";
                        }
                }
            }else {
                  $output["head"]["code"] = 400;
                  $output["head"]["msg"] = "User Not Found.";
            }
           
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Please 10 digit Mobile Number.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
} else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter is Mismatch";
}


echo json_encode($output, JSON_NUMERIC_CHECK);
