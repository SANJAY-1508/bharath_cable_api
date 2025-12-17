<?php
include 'config/config.php';
header("Access-Control-Allow-Origin: *"); 
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}


$json = file_get_contents('php://input');
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

if (isset($obj->search_text)) {
    $search_text = $obj->search_text;
    $sql = "SELECT p.*, COUNT(c.customer_id) as customer_count 
            FROM `plan` p 
            LEFT JOIN `customer` c ON p.plan_id = c.plan_id AND c.deleted_at = 0
            WHERE p.deleted_at = 0 
            AND p.plan_name LIKE '%$search_text%'
            GROUP BY p.plan_id";

    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $count = 0;
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Success";
        
        while ($row = $result->fetch_assoc()) {
            $output["body"]["plan"][$count] = $row;
            $count++;
        }
    } else {
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Plan Details Not Found";
        $output["body"]["plan"] = []; 
    }
}
else if (isset($obj->plan_name) && isset($obj->current_user_id)) {

    $plan_name = $obj->plan_name;
    $plan_prize = $obj->plan_prize ?? 0;
    $current_user_id = $obj->current_user_id;


    if (!empty($plan_name) && !empty($current_user_id)) {

        if ($current_user_id) {

            $current_user_name = getUserName($current_user_id);

            if (!empty($current_user_name)) {

                if (isset($obj->edit_plan_id)) {
                    $edit_id = $obj->edit_plan_id;
                    
                    
                    $updatePlan = "UPDATE `plan` SET `plan_name`='$plan_name',`plan_prize`='$plan_prize' WHERE `plan_id` = '$edit_id'";
                
                if ($conn->query($updatePlan)) {
                    $output["head"]["code"] = 200;
                    $output["head"]["msg"] = "Successfully Plan Details Updated";
                } else {
                    $output["head"]["code"] = 400;
                    $output["head"]["msg"] = "Failed to connect. Please try again.".$conn->error;
                }
                  
                } else {

                    $planCheck = $conn->query("SELECT `id` FROM `plan` WHERE `plan_name` LIKE '%$plan_name%' AND `deleted_at`=0 ");
                    if ($planCheck->num_rows == 0) {

                            $createPlan = "INSERT INTO `plan`( `plan_name`,`plan_prize`,`created_by_id`,`deleted_at`, `create_at`) VALUES ('$plan_name','$plan_prize','$current_user_id','0','$timestamp')";
                        
                        if ($conn->query($createPlan)) {
                            $plan_id = $conn->insert_id;
                            $enIdPlan = uniqueID('Plan', $plan_id);
                            $updateplan_id = "UPDATE `plan` SET `plan_id`='$enIdPlan' WHERE `id`='$plan_id'";
                            $conn->query($updateplan_id);
                            
                            $output["head"]["code"] = 200;
                            $output["head"]["msg"] = "Successfully Plan Created";
                        } else {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Failed to connect. Please try again.";
                        }
                    } else {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = "Plan Name Already Exist.";
                    }
                }
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "User not found.";
                $output["head"]["user_name"] = $current_user_name;
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Error Occurred: Please restart the application and try again.";
        }
          
       
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
}else if (isset($obj->delete_plan_id) && isset($obj->current_user_id)) {

    $delete_plan_id = $obj->delete_plan_id;
    $current_user_id = $obj->current_user_id;


    if (!empty($delete_plan_id) && !empty($current_user_id)) {

        if ($delete_plan_id && $current_user_id) {
            $deleteplan = "UPDATE `plan` SET `deleted_at`=1 where `plan_id`='$delete_plan_id'";
            if ($conn->query($deleteplan) === true) {
                $output["head"]["code"] = 200;
                $output["head"]["msg"] = "successfully plan deleted !.";
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "faild to deleted.please try againg.";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Invalid data's.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
}else{
      $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter is Mismatch";
    $output["head"]["inputs"] = $obj;
}
echo json_encode($output, JSON_NUMERIC_CHECK);
?>