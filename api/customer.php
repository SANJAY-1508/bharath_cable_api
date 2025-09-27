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


// Handle generate customer_no request
if (isset($obj->generate_customer_no) && isset($obj->area_id)) {
    $area_id = $obj->area_id;
    if (!empty($area_id)) {
        $customer_no = generateCustomerNo($area_id);
        if ($customer_no) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Customer Number Generated";
            $output["body"]["customer_no"] = $customer_no;
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Invalid area_id";
        }
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Area ID is required to generate customer_no";
    }
} else if (isset($obj->search_text)) {
    $search_text = $obj->search_text;

    $sql = "SELECT * FROM `area` WHERE `deleted_at` = 0";
    $result = $conn->query($sql);
    $output["body"]["area"] = $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];

    $sql = "SELECT * FROM `plan` WHERE `deleted_at` = 0";
    $result = $conn->query($sql);
    $output["body"]["plan"] = $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];

    $sql = "SELECT * FROM `staff` WHERE `delete_at` = 0";
    $result = $conn->query($sql);
    $output["body"]["staff"] = $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];

    $sql = "SELECT * FROM `customer` WHERE `deleted_at`= 0 AND `name` LIKE ? ORDER BY customer_no ASC";
    $stmt = $conn->prepare($sql);
    $search_param = "%$search_text%";
    $stmt->bind_param("s", $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
    $output["body"]["customer"] = $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $output["head"]["code"] = 200;
    $output["head"]["msg"] = $result->num_rows > 0 ? "Success" : "Customer Details Not Found";
    $stmt->close();
} else if (isset($obj->name) && isset($obj->phone) && isset($obj->address) && isset($obj->area_id) && isset($obj->box_no) && isset($obj->plan_id) && isset($obj->plan_prize) && isset($obj->staff_id) && isset($obj->customer_no) && isset($obj->current_user_id)) {
    $name = $obj->name;
    $phone_number = $obj->phone;
    $address = $obj->address;
    $area_id = $obj->area_id;
    $box_no = $obj->box_no;
    $plan_id = $obj->plan_id;
    $plan_prize = floatval($obj->plan_prize);
    $staff_id = $obj->staff_id;
    $customer_no = $obj->customer_no;
    $current_user_id = $obj->current_user_id;
    $area_name = "";
    $plan_name = "";
    $staff_name = "";
    $total_pending_amount = 0;
    $total_pending_months = 0;

    // Fetch area_name
    if (!empty($area_id)) {
        $sql = "SELECT `area_name` FROM `area` WHERE `area_id`=? AND `deleted_at` = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $area_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $area_name = $row["area_name"];
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Invalid area_id.";
            echo json_encode($output, JSON_NUMERIC_CHECK);
            $stmt->close();
            exit();
        }
        $stmt->close();
    }

    // Fetch plan_name and plan_prize
    if (!empty($plan_id)) {
        $sql = "SELECT `plan_name`, `plan_prize` FROM `plan` WHERE `plan_id`=? AND `deleted_at` = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $plan_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $plan_name = $row["plan_name"];
            $plan_prize = floatval($row["plan_prize"]);
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Invalid plan_id.";
            echo json_encode($output, JSON_NUMERIC_CHECK);
            $stmt->close();
            exit();
        }
        $stmt->close();
    }

    // Fetch staff_name
    if (!empty($staff_id)) {
        $sql = "SELECT `staff_name` FROM `staff` WHERE `staff_id`=? AND `delete_at` = 0";
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

    if (!empty($name) && !empty($box_no) && !empty($area_id) && !empty($plan_id)  && !empty($staff_id) && !empty($customer_no) && !empty($current_user_id)) {

        // ✅ phone optional check
        if (!empty($phone_number)) {
            if (!(is_numeric($phone_number) && strlen($phone_number) == 10)) {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "Invalid Phone Number.";
                echo json_encode($output, JSON_NUMERIC_CHECK);
                exit();
            }
        }

        if ($current_user_id) {
            $current_user_name = getUserName($current_user_id);
            if (!empty($current_user_name)) {
                if (isset($obj->edit_customer_id)) {
                    $edit_id = $obj->edit_customer_id;
                    // Fetch old customer data for logging and plan comparison
                    $sql = "SELECT * FROM `customer` WHERE `customer_id`=? AND `deleted_at`=0";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("s", $edit_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $old_customer = $result->num_rows > 0 ? $result->fetch_assoc() : null;
                    $stmt->close();

                    // Check if customer_no exists (excluding the current customer)
                    $sql = "SELECT `id` FROM `customer` WHERE `customer_no`=? AND `customer_id` != ? AND `deleted_at`=0";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ss", $customer_no, $edit_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        // Customer number exists, rearrange customer_no values
                        if (!rearrangeCustomerNo($customer_no, $area_id, $edit_id)) {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Failed to rearrange customer numbers.";
                            echo json_encode($output, JSON_NUMERIC_CHECK);
                            $stmt->close();
                            exit();
                        }
                    }
                    $stmt->close();

                    // Check if plan_id has changed
                    if ($old_customer && $old_customer['plan_id'] != $plan_id) {
                        // Check if total_pending_amount and total_pending_months are 0
                        if ($old_customer['total_pending_amount'] != 0 || $old_customer['total_pending_months'] != 0) {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Cannot update plan: Pending amount or months must be 0.";
                            echo json_encode($output, JSON_NUMERIC_CHECK);
                            exit();
                        }

                        // Update end_date and updated_at for the previous plan in plan_history
                        $sql_plan_history = "UPDATE `plan_history` SET `end_date`=?, `updated_at`=? WHERE `customer_id`=? AND `end_date` IS NULL";
                        $stmt_plan_history = $conn->prepare($sql_plan_history);
                        $stmt_plan_history->bind_param("sss", $timestamp, $timestamp, $edit_id);
                        $stmt_plan_history->execute();
                        $stmt_plan_history->close();

                        // Insert new plan into plan_history
                        $start_date = date('Y-m-d'); // Current date as start_date
                        $sql_plan_history = "INSERT INTO `plan_history` (`customer_id`, `plan_id`, `plan_name`, `plan_prize`, `start_date`, `created_at`) 
                                             VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt_plan_history = $conn->prepare($sql_plan_history);
                        $stmt_plan_history->bind_param("ssssss", $edit_id, $plan_id, $plan_name, $plan_prize, $start_date, $timestamp);
                        $stmt_plan_history->execute();
                        $stmt_plan_history->close();
                    }

                    // Proceed with update
                    $sql = "UPDATE `customer` SET `name`=?, `phone`=?, `address`=?, `area_id`=?, `area_name`=?, `box_no`=?, `plan_id`=?, `plan_name`=?, `plan_prize`=?, `staff_id`=?, `staff_name`=?, `customer_no`=? WHERE `customer_id`=?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sssssssssssss", $name, $phone_number, $address, $area_id, $area_name, $box_no, $plan_id, $plan_name, $plan_prize, $staff_id, $staff_name, $customer_no, $edit_id);
                    if ($stmt->execute()) {
                        // Log history
                        $new_customer = [
                            'customer_id' => $edit_id,
                            'name' => $name,
                            'phone' => $phone_number,
                            'address' => $address,
                            'area_id' => $area_id,
                            'area_name' => $area_name,
                            'box_no' => $box_no,
                            'plan_id' => $plan_id,
                            'plan_name' => $plan_name,
                            'plan_prize' => $plan_prize,
                            'staff_id' => $staff_id,
                            'staff_name' => $staff_name,
                            'customer_no' => $customer_no
                        ];
                        logCustomerHistory($edit_id, $customer_no, 'customer_update', $old_customer, $new_customer, "Customer updated by $current_user_name");
                        $output["head"]["code"] = 200;
                        $output["head"]["msg"] = "Successfully Customer Details Updated";
                    } else {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = "Failed to update customer: " . $conn->error;
                    }
                    $stmt->close();
                } else {
                    // Check if phone number exists
                    if (!empty($phone_number)) {
                        $sql = "SELECT `id` FROM `customer` WHERE `phone`=? AND `deleted_at`=0";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("s", $phone_number);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($result->num_rows > 0) {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Mobile Number Already Exists.";
                            $stmt->close();
                            echo json_encode($output, JSON_NUMERIC_CHECK);
                            exit();
                        }
                        $stmt->close();
                    }

                    // Check if customer_no exists
                    $sql = "SELECT `id` FROM `customer` WHERE `customer_no`=? AND `deleted_at`=0";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("s", $customer_no);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        // Customer number exists, rearrange customer_no values
                        if (!rearrangeCustomerNo($customer_no, $area_id)) {
                            $output["head"]["code"] = 400;
                            $output["head"]["msg"] = "Failed to rearrange customer numbers.";
                            echo json_encode($output, JSON_NUMERIC_CHECK);
                            $stmt->close();
                            exit();
                        }
                    }
                    $stmt->close();

                    // Proceed with creation
                    $sql = "INSERT INTO `customer`(`name`, `phone`, `address`, `area_id`, `area_name`, `box_no`, `plan_id`, `plan_name`, `plan_prize`, `staff_id`, `staff_name`, `customer_no`, `total_pending_amount`, `total_pending_months`, `deleted_at`, `create_at`, `created_by_id`) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sssssssssssssdss", $name, $phone_number, $address, $area_id, $area_name, $box_no, $plan_id, $plan_name, $plan_prize, $staff_id, $staff_name, $customer_no, $total_pending_amount, $total_pending_months, $timestamp, $current_user_id);
                    if ($stmt->execute()) {
                        $cus_id = $conn->insert_id;
                        $enIduser = uniqueID('Customer', $cus_id);
                        $sql = "UPDATE `customer` SET `customer_id`=? WHERE `id`=?";
                        $stmt_update = $conn->prepare($sql);
                        $stmt_update->bind_param("si", $enIduser, $cus_id);
                        $stmt_update->execute();
                        $stmt_update->close();

                        // Insert into plan_history
                        $start_date = date('Y-m-d'); // Current date as start_date
                        $sql_plan_history = "INSERT INTO `plan_history` (`customer_id`, `plan_id`, `plan_name`, `plan_prize`, `start_date`, `created_at`) 
                                             VALUES (?, ?, ?, ?, ?, ?)";
                        $stmt_plan_history = $conn->prepare($sql_plan_history);
                        $stmt_plan_history->bind_param("ssssss", $enIduser, $plan_id, $plan_name, $plan_prize, $start_date, $timestamp);
                        $stmt_plan_history->execute();
                        $stmt_plan_history->close();

                        // Log history
                        $new_customer = [
                            'customer_id' => $enIduser,
                            'name' => $name,
                            'phone' => $phone_number,
                            'address' => $address,
                            'area_id' => $area_id,
                            'area_name' => $area_name,
                            'box_no' => $box_no,
                            'plan_id' => $plan_id,
                            'plan_name' => $plan_name,
                            'plan_prize' => $plan_prize,
                            'staff_id' => $staff_id,
                            'staff_name' => $staff_name,
                            'customer_no' => $customer_no,
                            'created_by_id' => $current_user_id
                        ];
                        logCustomerHistory($enIduser, $customer_no, 'customer_create', null, $new_customer, "Customer created by $current_user_name");
                        $output["head"]["code"] = 200;
                        $output["head"]["msg"] = "Successfully Customer Created";
                    } else {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = "Failed to create customer: " . $conn->error;
                    }
                    $stmt->close();
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
} else if (isset($obj->delete_customer_id) && isset($obj->current_user_id)) {
    $delete_customer_id = $obj->delete_customer_id;
    $current_user_id = $obj->current_user_id;

    if (!empty($delete_customer_id) && !empty($current_user_id)) {
        if ($delete_customer_id && $current_user_id) {
            // Fetch customer data for logging
            $sql = "SELECT * FROM `customer` WHERE `customer_id`=? AND `deleted_at`=0";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $delete_customer_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $old_customer = $result->num_rows > 0 ? $result->fetch_assoc() : null;
            $stmt->close();

            $sql = "UPDATE `customer` SET `deleted_at`=1 WHERE `customer_id`=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $delete_customer_id);
            if ($stmt->execute() && $conn->affected_rows > 0) {
                // Log history
                if ($old_customer) {
                    $current_user_name = getUserName($current_user_id);
                    logCustomerHistory($delete_customer_id, $old_customer['customer_no'], 'customer_delete', $old_customer, null, "Customer deleted by $current_user_name");
                }
                $output["head"]["code"] = 200;
                $output["head"]["msg"] = "Successfully Customer Deleted!";
            } else {
                $output["head"]["code"] = 400;
                $output["head"]["msg"] = "Failed to delete. Customer not found or already deleted.";
            }
            $stmt->close();
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

echo json_encode($output);
$conn->close();
