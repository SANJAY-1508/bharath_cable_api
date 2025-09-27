<?php
include 'config/config.php';

// Set CORS headers for cross-origin requests
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


// Handle generate user_no request
if (isset($obj->generate_user_no) && isset($obj->name)) {
    $name = $obj->name;
    if (!empty($name)) {
        $user_no = generateUserNo($name);
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "User Number Generated";
        $output["body"]["user_no"] = $user_no;
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Name is required to generate user_no";
    }
} else if (isset($obj->search_text)) {
    $search_text = $obj->search_text;
    $sql = "SELECT * FROM `user` WHERE `deleted_at`=0 AND `name` LIKE '%$search_text%'";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Success";
            $output["body"]["user"][$count] = $row;
            $count++;
        }
    } else {
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "User Details Not Found";
        $output["body"]["user"] = [];
    }
} else if (isset($obj->name) && isset($obj->phone_number) && isset($obj->role) && isset($obj->password) && isset($obj->user_no) && isset($obj->current_user_id)) {
    $name = $obj->name;
    $phone_number = $obj->phone_number;
    $role = $obj->role;
    $password = $obj->password;
    $user_no = $obj->user_no;
    $current_user_id = $obj->current_user_id;

    if (!empty($name) && !empty($phone_number) && !empty($role) && !empty($password) && !empty($user_no) && !empty($current_user_id)) {
        if (numericCheck($phone_number) && strlen($phone_number) == 10) {
            if ($current_user_id) {
                $current_user_name = getUserName($current_user_id);
                if (!empty($current_user_name)) {
                    if (isset($obj->edit_user_id)) {
                        $edit_id = $obj->edit_user_id;
                        if (userExist($edit_id)) {
                            $userNoCheck = $conn->query("SELECT `id` FROM `user` WHERE `user_no`='$user_no' AND `user_id` != '$edit_id' AND `deleted_at`=0");
                            $mobileCheck = $conn->query("SELECT `id` FROM `user` WHERE `phone`='$phone_number' AND `user_id` != '$edit_id' AND `deleted_at`=0");
                            if ($userNoCheck->num_rows > 0) {
                                $output["head"]["code"] = 400;
                                $output["head"]["msg"] = "User Number Already Exists. Update not allowed.";
                            } else if ($mobileCheck->num_rows > 0) {
                                $output["head"]["code"] = 400;
                                $output["head"]["msg"] = "Mobile Number Already Exists. Update not allowed.";
                            } else {
                                $updateUser = "UPDATE `user` SET `name`='$name',`phone`='$phone_number',`role`='$role',`password`='$password',`user_no`='$user_no' WHERE `user_id`='$edit_id'";
                                if ($conn->query($updateUser)) {
                                    $output["head"]["code"] = 200;
                                    $output["head"]["msg"] = "Successfully User Details Updated";
                                } else {
                                    $output["head"]["code"] = 400;
                                    $output["head"]["msg"] = "Failed to update. Please try again: " . $conn->error;
                                }
                            }
                        } else {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "User not found.";
                        }
                    } else {
                        $userNoCheck = $conn->query("SELECT `id` FROM `user` WHERE `user_no`='$user_no' AND `deleted_at`=0");
                        $mobileCheck = $conn->query("SELECT `id` FROM `user` WHERE `phone`='$phone_number' AND `deleted_at`=0");
                        if ($userNoCheck->num_rows > 0) {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "User Number Already Exists. Creation not allowed.";
                        } else if ($mobileCheck->num_rows > 0) {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Mobile Number Already Exists. Creation not allowed.";
                        } else {
                            $createUser = "INSERT INTO `user` (`name`, `phone`, `role`, `password`, `user_no`, `deleted_at`, `created_date`) 
                                           VALUES ('$name', '$phone_number', '$role', '$password', '$user_no', '0', '$timestamp')";
                            if ($conn->query($createUser)) {
                                $cus_id = $conn->insert_id;
                                $enIduser = uniqueID('user', $cus_id);
                                $updateuser_id = "UPDATE `user` SET `user_id`='$enIduser' WHERE `id`='$cus_id'";
                                $conn->query($updateuser_id);
                                
                                $output["head"]["code"] = 200;
                                $output["head"]["msg"] = "Successfully User Created";
                            } else {
                                $output["head"]["code"] = 400;
                                $output["head"]["msg"] = "Failed to create. Please try again: " . $conn->error;
                            }
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
            $output["head"]["msg"] = "Invalid Phone Number.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
} else if (isset($obj->delete_user_id) && isset($obj->current_user_id)) {
    $delete_user_id = $obj->delete_user_id;
    $current_user_id = $obj->current_user_id;

    if (!empty($delete_user_id) && !empty($current_user_id)) {
        if ($current_user_id) {
            $deleteuser = "UPDATE `user` SET `deleted_at`=1 WHERE `user_id`='$delete_user_id'";
            if ($conn->query($deleteuser) && $conn->affected_rows > 0) {
                $output["head"]["code"] = 200;
                $output["head"]["msg"] = "Successfully User Deleted!";
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "Failed to delete. User not found or already deleted.";
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Invalid data.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
} else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter Mismatch";
    $output["head"]["inputs"] = $obj;
}

echo json_encode($output, JSON_NUMERIC_CHECK);
?>