<?php
include 'config/config.php';

// At the top of area.php (before any output)
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
    $sql = "SELECT * FROM `area` WHERE `deleted_at` = 0 AND `area_name` LIKE '%$search_text%'";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Success";
            $output["body"]["Area"][$count] = $row;
            $count++;
        }
    } else {
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Area Details Not Found";
        $output["body"]["Area"] = [];
    }
} else if (isset($obj->area_name) && isset($obj->area_prefix) && isset($obj->current_user_id)) {
    $area_name = $obj->area_name;
    $area_prefix = $obj->area_prefix;
    $current_user_id = $obj->current_user_id;

    if (!empty($area_name) && !empty($area_prefix) && !empty($current_user_id)) {
        $current_user_name = getUserName($current_user_id);

        if (!empty($current_user_name)) {
            if (isset($obj->edit_area_id)) {
                $edit_id = $obj->edit_area_id;

                // Fetch the old area_prefix for comparison
                $sql = "SELECT area_prefix FROM area WHERE area_id = ? AND deleted_at = 0";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("s", $edit_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $old_area_prefix = $result->num_rows > 0 ? $result->fetch_assoc()['area_prefix'] : null;
                $stmt->close();

                if (!$old_area_prefix) {
                    $output["head"]["code"] = 400;
                    $output["head"]["msg"] = "Area not found.";
                    echo json_encode($output);
                    exit();
                }

                // Check if prefix already exists in another area
                $prefixCheck = $conn->query("SELECT id FROM area WHERE area_prefix='$area_prefix' AND area_id != '$edit_id' AND deleted_at=0");
                if ($prefixCheck->num_rows > 0) {
                    $output["head"]["code"] = 400;
                    $output["head"]["msg"] = "Area Prefix Already Exists. Update not allowed.";
                } else {
                    // Update area table
                    $updateArea = "UPDATE `area` SET `area_name`='$area_name', `area_prefix`='$area_prefix' WHERE `area_id`='$edit_id'";
                    if ($conn->query($updateArea) && $conn->affected_rows > 0) {
                        // Update customer_no in customer table
                        $sql = "UPDATE `customer` SET `customer_no` = CONCAT(?, SUBSTRING(`customer_no`, LENGTH(?) + 1)) WHERE `area_id` = ? AND `deleted_at` = 0";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sss", $area_prefix, $old_area_prefix, $edit_id);
                        $stmt->execute();
                        $stmt->close();

                        // Update customer_no in collection table
                        $sql = "UPDATE `collection` SET `customer_no` = CONCAT(?, SUBSTRING(`customer_no`, LENGTH(?) + 1)) WHERE `area_id` = ? AND `deleted_at` = 0";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sss", $area_prefix, $old_area_prefix, $edit_id);
                        $stmt->execute();
                        $stmt->close();

                        $output["head"]["code"] = 200;
                        $output["head"]["msg"] = "Successfully Area Details Updated and Customer/Collection tables updated";
                    } else {
                        $output["head"]["code"] = 400;
                        $output["head"]["msg"] = "Area not found or no changes";
                    }
                }
            } else {
                // Check if prefix already exists
                $prefixCheck = $conn->query("SELECT id FROM area WHERE area_prefix='$area_prefix' AND deleted_at=0");
                if ($prefixCheck->num_rows > 0) {
                    $output["head"]["code"] = 400;
                    $output["head"]["msg"] = "Area Prefix Already Exists. Creation not allowed.";
                } else {
                    $createArea = "INSERT INTO `area` (`area_name`, `area_prefix`, `created_by_id`, `deleted_at`, `create_at`) 
                                   VALUES ('$area_name', '$area_prefix', '$current_user_id', '0', '$timestamp')";

                    if ($conn->query($createArea)) {
                        $area_id = $conn->insert_id;
                        $enIdarea = uniqueID('Area', $area_id);
                        $updateuser_id = "UPDATE `area` SET `area_id`='$enIdarea' WHERE `id`='$area_id'";
                        $conn->query($updateuser_id);

                        $output["head"]["code"] = 200;
                        $output["head"]["msg"] = "Successfully Area Created";
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
} else if (isset($obj->delete_area_id) && isset($obj->current_user_id)) {
    $delete_area_id = $obj->delete_area_id;
    $current_user_id = $obj->current_user_id;

    if (!empty($delete_area_id) && !empty($current_user_id)) {
        $deleteuser = "UPDATE `area` SET `deleted_at`=1 WHERE `area_id`='$delete_area_id'";
        if ($conn->query($deleteuser) && $conn->affected_rows > 0) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Successfully Area deleted!";
        } else {
            $output["head"]["code"] = 400;
            $output["head"]["msg"] = "Area not found or already deleted";
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
