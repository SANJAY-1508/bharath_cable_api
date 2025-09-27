<?php
// Include config file only once
include_once 'config/config.php';

// Allow from specific origin
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// If preflight request, return OK
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json, true); // Decode JSON as associative array
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

// <<<<<<<<<<===================== API Data Handling Starts Here =====================>>>>>>>>>>
if (isset($obj['mobile_number']) && isset($obj['password'])) {

    $mobile_number = $obj['mobile_number'];
    $password = $obj['password'];
    $staff_pin = isset($obj['staff_pin']) ? $obj['staff_pin'] : null;
    $forgot_pin = isset($obj['forgot_pin']) ? filter_var($obj['forgot_pin'], FILTER_VALIDATE_BOOLEAN) : false;
    $action = isset($obj['action']) ? $obj['action'] : null;

    if (!empty($mobile_number) && !empty($password)) {
        if (numericCheck($mobile_number) && strlen($mobile_number) == 10) {

            // Check if the staff exists in the staff table
            $result = $conn->query("SELECT `id`, `staff_id`, `staff_no`, `staff_name`, `mobile_number`, `password`, `staff_pin`, `created_by_id` FROM `staff` WHERE `mobile_number`='$mobile_number' AND `delete_at`=0");

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();

                // Case 1: Username and password are correct
                if ($row['password'] == $password) {
                    $staff_id = $row['id'];

                    // Case 2: Logout request
                    if ($action === 'logout') {
                        // Insert logout record into staff_login_history
                        $insert_log = "INSERT INTO `staff_login_history` (`staff_id`, `staff_no`, `mobile_number`, `type`, `create_at`) VALUES ('{$row['staff_id']}', '{$row['staff_no']}', '$mobile_number', 'Out', '$timestamp')";
                        if ($conn->query($insert_log)) {
                            $output["head"]["code"] = 200;
                            $output["head"]["msg"] = "Logged out successfully.";
                            $output["body"]["staff"] = array_diff_key($row, array_flip(['password', 'staff_pin']));
                        } else {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Failed to log logout event: " . $conn->error;
                        }
                    }
                    // Case 3: If staff_pin is empty or null in the request
                    elseif (empty($staff_pin)) {
                        // Check if staff_pin in the database is empty or null
                        if (is_null($row['staff_pin']) || $row['staff_pin'] === '') {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Please create your pin.";
                            $output["body"]["id"] = "";
                        } else {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Please Enter your pin.";
                            $output["body"]["id"] = $row['staff_id'];
                        }
                    }
                    // Case 4: If staff_pin is provided
                    else {
                        // Check if staff_pin in the database is empty or null
                        if (is_null($row['staff_pin']) || $row['staff_pin'] === '') {
                            // Set new pin without requiring forgot_pin
                            $update_pin = "UPDATE `staff` SET `staff_pin`='$staff_pin', `create_at`='$timestamp' WHERE `id`='$staff_id'";
                            if ($conn->query($update_pin)) {
                                $output["head"]["code"] = 200;
                                $output["head"]["msg"] = "Staff pin created successfully.";
                                $output["body"]["staff"] = array_diff_key($row, array_flip(['password', 'staff_pin']));
                            } else {
                                $output["head"]["code"] = 400;
                                $output["head"]["msg"] = "Failed to set staff pin: " . $conn->error;
                            }
                        }
                        // Case 5: Forgot pin scenario (update existing pin)
                        elseif ($forgot_pin === true) {
                            // Ensure a new staff_pin is provided
                            if (!empty($staff_pin)) {
                                // Update the staff_pin in the database
                                $update_pin = "UPDATE `staff` SET `staff_pin`='$staff_pin', `create_at`='$timestamp' WHERE `id`='$staff_id'";
                                if ($conn->query($update_pin)) {
                                    $output["head"]["code"] = 200;
                                    $output["head"]["msg"] = "Staff pin updated successfully.";
                                    $output["body"]["staff"] = array_diff_key($row, array_flip(['password', 'staff_pin']));
                                } else {
                                    $output["head"]["code"] = 400;
                                    $output["head"]["msg"] = "Failed to update staff pin: " . $conn->error;
                                }
                            } else {
                                $output["head"]["code"] = 400;
                                $output["head"]["msg"] = "Please provide a new pin to update.";
                            }
                        }
                        // Case 6: If staff_pin is provided and matches the database
                        elseif ($row['staff_pin'] == $staff_pin) {
                            // Insert login record into staff_login_history
                            $insert_log = "INSERT INTO `staff_login_history` (`staff_id`, `staff_no`,`staff_name`, `mobile_number`, `type`, `create_at`) VALUES ('{$row['staff_id']}', '{$row['staff_no']}', '{$row['staff_name']}', '$mobile_number', 'In', '$timestamp')";
                            if ($conn->query($insert_log)) {
                                $output["head"]["code"] = 200;
                                $output["head"]["msg"] = "Success";
                                $output["body"]["staff"] = array_diff_key($row, array_flip(['password', 'staff_pin']));
                            } else {
                                $output["head"]["code"] = 400;
                                $output["head"]["msg"] = "Failed to log login event: " . $conn->error;
                            }
                        }
                        // Case 7: If staff_pin is incorrect
                        else {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Invalid pin.";
                        }
                    }
                } else {
                    $output["head"]["code"] = 400;
                    $output["head"]["msg"] = "Invalid Credentials";
                     $output["body"]["id"] = "";
                }
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "Staff Not Found.";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Please provide a 10-digit Mobile Number.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
} else if(isset($obj['staff_id']) && isset($obj['staff_pin'])){
    $staff_id = isset($obj['staff_id']) ? $obj['staff_id'] : null;
    $staff_pin = isset($obj['staff_pin']) ? $obj['staff_pin'] : null;
    $forgot_pin = isset($obj['forgot_pin']) ? filter_var($obj['forgot_pin'], FILTER_VALIDATE_BOOLEAN) : false;
    $action = isset($obj['action']) ? $obj['action'] : null;
    
    $result = $conn->query("SELECT `staff_id`,`staff_no`, `staff_name`, `mobile_number`, `password`, `staff_pin`, `created_by_id` FROM `staff` WHERE `staff_id`='$staff_id' AND `delete_at`=0");

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();


                    // Case 2: Logout request
                    if ($action === 'logout') {
                        // Insert logout record into staff_login_history
                        $insert_log = "INSERT INTO `staff_login_history` (`staff_id`, `staff_no`, `mobile_number`, `type`, `create_at`) VALUES ('{$row['staff_id']}', '{$row['staff_no']}', '$mobile_number', 'Out', '$timestamp')";
                        if ($conn->query($insert_log)) {
                            $output["head"]["code"] = 200;
                            $output["head"]["msg"] = "Logged out successfully.";
                            $output["body"]["staff"] = array_diff_key($row, array_flip(['password', 'staff_pin']));
                        } else {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Failed to log logout event: " . $conn->error;
                        }
                    }
                    // Case 4: If staff_pin is provided
                    else {
                        // Check if staff_pin in the database is empty or null
                       if ($forgot_pin === true) {
                            // Ensure a new staff_pin is provided
                            if (!empty($staff_pin)) {
                                // Update the staff_pin in the database
                                $update_pin = "UPDATE `staff` SET `staff_pin`='$staff_pin', `create_at`='$timestamp' WHERE `staff_id`='$staff_id'";
                                if ($conn->query($update_pin)) {
                                    $output["head"]["code"] = 200;
                                    $output["head"]["msg"] = "Staff pin updated successfully.";
                                    $output["body"]["staff"] = array_diff_key($row, array_flip(['password', 'staff_pin']));
                                } else {
                                    $output["head"]["code"] = 400;
                                    $output["head"]["msg"] = "Failed to update staff pin: " . $conn->error;
                                }
                            } else {
                                $output["head"]["code"] = 400;
                                $output["head"]["msg"] = "Please provide a new pin to update.";
                            }
                        }
                        // Case 6: If staff_pin is provided and matches the database
                        elseif ($row['staff_pin'] == $staff_pin) {
                            // Insert login record into staff_login_history
                            $insert_log = "INSERT INTO `staff_login_history` (`staff_id`, `staff_no`,`staff_name`, `mobile_number`, `type`, `create_at`) VALUES ('$staff_id', '{$row['staff_no']}', '{$row['staff_name']}', '{$row['mobile_number']}', 'In', '$timestamp')";
                            if ($conn->query($insert_log)) {
                                $output["head"]["code"] = 200;
                                $output["head"]["msg"] = "Success";
                                $output["body"]["staff"] = array_diff_key($row, array_flip(['password', 'staff_pin']));
                            } else {
                                $output["head"]["code"] = 400;
                                $output["head"]["msg"] = "Failed to log login event: " . $conn->error;
                            }
                        }
                        // Case 7: If staff_pin is incorrect
                        else {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Invalid pin.";
                        }
                    }
              
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "Staff Not Found.";
            }
    
} else if(isset($obj['staff_id']) && isset($obj['action'])){
     $staff_id = isset($obj['staff_id']) ? $obj['staff_id'] : null;
     $action = isset($obj['action']) ? $obj['action'] : null;
      $result = $conn->query("SELECT `staff_id`,`staff_no`, `staff_name`, `mobile_number`, `password`, `staff_pin`, `created_by_id` FROM `staff` WHERE `staff_id`='$staff_id' AND `delete_at`=0");

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
              
            if ($action === 'logout') {
                // Insert logout record into staff_login_history
                $insert_log = "INSERT INTO `staff_login_history` (`staff_id`, `staff_no`,`staff_name`, `mobile_number`, `type`, `create_at`) VALUES ('{$row['staff_id']}', '{$row['staff_no']}','{$row['staff_name']}', '{$row['mobile_number']}', 'Out', '$timestamp')";
                if ($conn->query($insert_log)) {
                    $output["head"]["code"] = 200;
                    $output["head"]["msg"] = "Logged out successfully.";
                    $output["body"]["staff"] = array_diff_key($row, array_flip(['password', 'staff_pin']));
                } else {
                    $output["head"]["code"] = 400;
                    $output["head"]["msg"] = "Failed to log logout event: " . $conn->error;
                }
            }
           
            else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "Logout Session Error!";
               
            }
          
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Staff Not Found.";
        }
    
    
} else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter Mismatch";
}

echo json_encode($output, JSON_NUMERIC_CHECK);
?>