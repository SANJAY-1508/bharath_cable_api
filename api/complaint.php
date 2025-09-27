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

// List Complaints 
if (isset($obj->action) && $obj->action == 'list') {
    $output["body"] = [
        "complaints" => [],
        "customers" => [],
        "staff" => []
    ];

    // Fetch customers for dropdown
    $sql = "SELECT `customer_id`, `customer_no`, `name` FROM `customer` WHERE `deleted_at`=0";
    $result = $conn->query($sql);
    $output["body"]["customers"] = $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];

    // Fetch staff for dropdown
    $sql = "SELECT `staff_id`, `staff_name` FROM `staff` WHERE `delete_at`=0";
    $result = $conn->query($sql);
    $output["body"]["staff"] = $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];

    // Fetch complaints
    $sql = "SELECT `id`, `complaint_id`, `customer_id`, `customer_no`, `name`, `phone`, `address`, `area_id`, `area_name`, `box_no`, `plan_id`, `plan_name`, `staff_id`, `staff_name`, `status`, `description`, `created_by_id`, `create_at` 
            FROM `complaint` 
            WHERE `deleted_at`=0";
    
    // Optional search filter
    if (isset($obj->search_text) && !empty($obj->search_text)) {
        $search_text = "%" . $obj->search_text . "%";
        $sql .= " AND (`name` LIKE ? OR `customer_no` LIKE ? OR `description` LIKE ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $search_text, $search_text, $search_text);
    } else {
        $stmt = $conn->prepare($sql);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $output["body"]["complaints"] = $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $output["head"]["code"] = 200;
    $output["head"]["msg"] = $result->num_rows > 0 ? "Success" : "No Complaints Found";
    $stmt->close();
}

// Create Complaint 
else if (isset($obj->action) && $obj->action == 'create' && isset($obj->staff_id) && isset($obj->customer_id) && isset($obj->description) && isset($obj->current_user_id)) {
    $staff_id = $obj->staff_id;
    $customer_id = $obj->customer_id;
    $description = $obj->description;
    $current_user_id = $obj->current_user_id;
    $status = "Pending"; // Default status for new complaints

    // Initialize variables for customer details
    $customer_no = "";
    $name = "";
    $phone = "";
    $address = "";
    $area_id = "";
    $area_name = "";
    $box_no = "";
    $plan_id = "";
    $plan_name = "";
    $staff_name = "";

    // Fetch customer details
    if (!empty($customer_id)) {
        $sql = "SELECT `customer_no`, `name`, `phone`, `address`, `area_id`, `area_name`, `box_no`, `plan_id`, `plan_name`, `staff_id`, `staff_name` 
                FROM `customer` 
                WHERE `customer_id`=? AND `deleted_at`=0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $customer_no = $row["customer_no"];
            $name = $row["name"];
            $phone = $row["phone"];
            $address = $row["address"];
            $area_id = $row["area_id"];
            $area_name = $row["area_name"];
            $box_no = $row["box_no"];
            $plan_id = $row["plan_id"];
            $plan_name = $row["plan_name"];
            $staff_name = $row["staff_name"];
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Invalid customer_id.";
            echo json_encode($output, JSON_NUMERIC_CHECK);
            $stmt->close();
            exit();
        }
        $stmt->close();
    }

    // Fetch staff_name
    if (!empty($staff_id)) {
        $sql = "SELECT `staff_name` FROM `staff` WHERE `staff_id`=? AND `delete_at`=0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $staff_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $staff_name = $row["staff_name"];
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Invalid staff_id.";
            echo json_encode($output, JSON_NUMERIC_CHECK);
            $stmt->close();
            exit();
        }
        $stmt->close();
    }

    // Validate required fields
    if (!empty($staff_id) && !empty($customer_id) && !empty($description) && !empty($current_user_id)) {
        $current_user_name = getUserName($current_user_id);
        if (!empty($current_user_name)) {
            // Generate unique complaint_id
            $complaint_id = uniqueID('Complaint', null); // Generate unique ID before insert

            // Insert complaint into the database with complaint_id
            $sql = "INSERT INTO `complaint` (`complaint_id`, `customer_id`, `customer_no`, `name`, `phone`, `address`, `area_id`, `area_name`, `box_no`, `plan_id`, `plan_name`, `staff_id`, `staff_name`, `status`, `description`, `created_by_id`, `create_at`) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssssssssssssss", $complaint_id, $customer_id, $customer_no, $name, $phone, $address, $area_id, $area_name, $box_no, $plan_id, $plan_name, $staff_id, $staff_name, $status, $description, $current_user_id, $timestamp);
            
            if ($stmt->execute()) {
                // Log history in customer_history
                $new_complaint = [
                    'complaint_id' => $complaint_id,
                    'customer_id' => $customer_id,
                    'customer_no' => $customer_no,
                    'name' => $name,
                    'phone' => $phone,
                    'address' => $address,
                    'area_id' => $area_id,
                    'area_name' => $area_name,
                    'box_no' => $box_no,
                    'plan_id' => $plan_id,
                    'plan_name' => $plan_name,
                    'staff_id' => $staff_id,
                    'staff_name' => $staff_name,
                    'status' => $status,
                    'description' => $description,
                    'created_by_id' => $current_user_id
                ];
                logCustomerHistory($customer_id, $customer_no, 'complaint_create', null, $new_complaint, "Complaint created by $current_user_name");

                $output["head"]["code"] = 200;
                $output["head"]["msg"] = "Successfully Complaint Created";
                $output["body"]["complaint_id"] = $complaint_id;
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "Failed to create complaint: " . $conn->error;
            }
            $stmt->close();
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "User not found.";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Please provide all the required details.";
    }
}


// Update Complaint 
else if (isset($obj->action) && $obj->action == 'update' && isset($obj->complaint_id) && isset($obj->staff_id) && isset($obj->description) && isset($obj->status) && isset($obj->current_user_id)) {
    $complaint_id = $obj->complaint_id;
    $staff_id = $obj->staff_id;
    $description = $obj->description;
    $status = $obj->status;
    $current_user_id = $obj->current_user_id;

    // Fetch existing complaint data for logging
    $sql = "SELECT * FROM `complaint` WHERE `complaint_id`=? AND `deleted_at`=0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $complaint_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $old_complaint = $result->num_rows > 0 ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$old_complaint) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Complaint not found.";
        echo json_encode($output, JSON_NUMERIC_CHECK);
        exit();
    }

    // Fetch staff_name
    $staff_name = "";
    if (!empty($staff_id)) {
        $sql = "SELECT `staff_name` FROM `staff` WHERE `staff_id`=? AND `delete_at`=0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $staff_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $staff_name = $row["staff_name"];
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Invalid staff_id.";
            echo json_encode($output, JSON_NUMERIC_CHECK);
            $stmt->close();
            exit();
        }
        $stmt->close();
    }

    // Update complaint
    $sql = "UPDATE `complaint` SET `staff_id`=?, `staff_name`=?, `description`=?, `status`=?, `updated_by_id`=? WHERE `complaint_id`=? AND `deleted_at`=0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssss", $staff_id, $staff_name, $description, $status, $current_user_id, $complaint_id);
    
    if ($stmt->execute() && $conn->affected_rows > 0) {
        // Log history in customer_history
        $current_user_name = getUserName($current_user_id);
        $new_complaint = [
            'complaint_id' => $complaint_id,
            'staff_id' => $staff_id,
            'staff_name' => $staff_name,
            'description' => $description,
            'status' => $status,
            'updated_by_id' => $current_user_id
        ];
        logCustomerHistory($old_complaint['customer_id'], $old_complaint['customer_no'], 'complaint_update', $old_complaint, $new_complaint, "Complaint updated by $current_user_name");

        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Successfully Complaint Updated";
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Failed to update complaint: " . $conn->error;
    }
    $stmt->close();
}

// Delete Complaint 
else if (isset($obj->action) && $obj->action == 'delete' && isset($obj->complaint_id) && isset($obj->current_user_id)) {
    $complaint_id = $obj->complaint_id;
    $current_user_id = $obj->current_user_id;

    // Fetch complaint data for logging
    $sql = "SELECT * FROM `complaint` WHERE `complaint_id`=? AND `deleted_at`=0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $complaint_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $old_complaint = $result->num_rows > 0 ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$old_complaint) {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Complaint not found.";
        echo json_encode($output, JSON_NUMERIC_CHECK);
        exit();
    }

    // Soft delete complaint
    $sql = "UPDATE `complaint` SET `deleted_at`=1, `delete_by_id`=? WHERE `complaint_id`=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $current_user_id, $complaint_id);
    
    if ($stmt->execute() && $conn->affected_rows > 0) {
        // Log history in customer_history
        $current_user_name = getUserName($current_user_id);
        logCustomerHistory($old_complaint['customer_id'], $old_complaint['customer_no'], 'complaint_delete', $old_complaint, null, "Complaint deleted by $current_user_name");

        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Successfully Complaint Deleted";
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Failed to delete complaint.";
    }
    $stmt->close();
}

else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter Mismatch";
    $output["head"]["inputs"] = $obj;
}

echo json_encode($output, JSON_NUMERIC_CHECK);
$conn->close();
?>