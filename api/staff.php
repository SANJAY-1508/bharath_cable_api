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



// Handle generate staff_no request
if (isset($obj->generate_staff_no) && isset($obj->name)) {
    $name = $obj->name;
    if (!empty($name)) {
        $staff_no = generateStaffNo($name);
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Staff Number Generated";
        $output["body"]["staff_no"] = $staff_no;
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Name is required to generate staff_no";
    }
} else if (isset($obj->search_text)) {
    $search_text = $obj->search_text;
    $sql = "SELECT * FROM `staff` WHERE `delete_at` = 0 AND `staff_name` LIKE '%$search_text%'";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Success";
            $output["body"]["Staff"][$count] = $row;
            $count++;
        }
    } else {
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Staff Details Not Found";
        $output["body"]["Staff"] = [];
    }
} else if (isset($obj->staff_name) && isset($obj->staff_no) && isset($obj->mobile_number) && isset($obj->password) && isset($obj->current_user_id)) {
    $staff_name = $obj->staff_name;
    $staff_no = $obj->staff_no;
    $mobile_number = $obj->mobile_number;
    $password = $obj->password; // Consider hashing the password in production
    $current_user_id = $obj->current_user_id;

    if (!empty($staff_name) && !empty($staff_no) && !empty($mobile_number) && !empty($password) && !empty($current_user_id)) {
        $current_user_name = getUserName($current_user_id);

        if (!empty($current_user_name)) {
            if (isset($obj->edit_staff_id)) {
                $edit_id = $obj->edit_staff_id;

                // Check if staff_no already exists for another staff
                $staffNoCheck = $conn->query("SELECT id FROM staff WHERE staff_no='$staff_no' AND staff_id != '$edit_id' AND delete_at=0");
                if ($staffNoCheck->num_rows > 0) {
                    $output["head"]["code"] = 400;
                    $output["head"]["msg"] = "Staff Number Already Exists. Update not allowed.";
                } else {
                    $updateStaff = "UPDATE `staff` SET `staff_name`='$staff_name', `staff_no`='$staff_no', `mobile_number`='$mobile_number', `password`='$password' WHERE `staff_id`='$edit_id'";
                    if ($conn->query($updateStaff) && $conn->affected_rows > 0) {
                        $output["head"]["code"] = 200;
                        $output["head"]["msg"] = "Successfully Staff Details Updated";
                    } else {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = "Staff not found or no changes";
                    }
                }
            } else {
                // Check if staff_no already exists
                $staffNoCheck = $conn->query("SELECT id FROM staff WHERE staff_no='$staff_no' AND delete_at=0");
                if ($staffNoCheck->num_rows > 0) {
                    $output["head"]["code"] = 400;
                    $output["head"]["msg"] = "Staff Number Already Exists. Creation not allowed.";
                } else {
                    $createStaff = "INSERT INTO `staff` (`staff_name`, `staff_no`, `mobile_number`, `password`, `created_by_id`, `delete_at`, `create_at`) 
                                   VALUES ('$staff_name', '$staff_no', '$mobile_number', '$password', '$current_user_id', '0', '$timestamp')";
                    
                    if ($conn->query($createStaff)) {
                        $staff_id = $conn->insert_id;
                        $enIdstaff = uniqueID('Staff', $staff_id);
                        $updateStaffId = "UPDATE `staff` SET `staff_id`='$enIdstaff' WHERE `id`='$staff_id'";
                        $conn->query($updateStaffId);

                        $output["head"]["code"] = 200;
                        $output["head"]["msg"] = "Successfully Staff Created";
                    } else {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = "Failed to connect. Please try again: " . $conn->error;
                    }
                }
            }
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "User not found.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
} else if (isset($obj->delete_staff_id) && isset($obj->current_user_id)) {
    $delete_staff_id = $obj->delete_staff_id;
    $current_user_id = $obj->current_user_id;

    if (!empty($delete_staff_id) && !empty($current_user_id)) {
        $deleteStaff = "UPDATE `staff` SET `delete_at`=1 WHERE `staff_id`='$delete_staff_id'";
        if ($conn->query($deleteStaff) && $conn->affected_rows > 0) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Successfully Staff deleted!";
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Staff not found or already deleted";
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