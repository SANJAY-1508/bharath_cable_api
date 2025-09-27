<?php
// bulk_customer_upload.php

header('Content-Type: application/json');

// bootstrap
include 'vendor/autoload.php';
include 'config/config.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function json_out($arr, $code=200){ 
    http_response_code($code); 
    echo json_encode($arr, JSON_NUMERIC_CHECK); 
    exit; 
}

function sanitize($v){ 
    return trim((string)$v); 
}

/**
 * Generate next customer_no based on area_prefix (e.g., BHA001, BHA002)
 */
function generate_customer_no(mysqli $conn, $area_id) {
    // Fetch area_prefix from area table
    $sql = "SELECT `area_prefix` FROM `area` WHERE `area_id`=? AND `deleted_at`=0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $area_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $prefix = strtoupper($row['area_prefix']); // e.g., BHA
    } else {
        $stmt->close();
        return [null, "Invalid area_id"];
    }
    $stmt->close();
    
    // Query to find the highest numeric part for this area_prefix
    $sql = "SELECT `customer_no` FROM `customer` WHERE `customer_no` LIKE ? AND `deleted_at`=0 ORDER BY CAST(SUBSTRING(`customer_no`, ?) AS UNSIGNED) DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $search_prefix = "$prefix%";
    $start_pos = strlen($prefix) + 1;
    $stmt->bind_param("si", $search_prefix, $start_pos);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $nextNumber = 1;
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastCustomerNo = $row['customer_no'];
        $lastNumber = (int)substr($lastCustomerNo, strlen($prefix)); // Extract numeric part (e.g., 001 from BHA001)
        $nextNumber = $lastNumber + 1;
    }
    $stmt->close();
    
    return [$prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT), null]; // e.g., BHA001
}

/**
 * Create or get area_id properly
 */
function get_or_create_area(mysqli $conn, $area_name, $current_user_id, $timestamp) {
    $area_name = sanitize($area_name);
    if ($area_name === '') return [null, "Area name empty"];

    // Find existing
    $stmt = $conn->prepare("SELECT area_id FROM area WHERE area_name = ? AND deleted_at = 0 LIMIT 1");
    $stmt->bind_param('s', $area_name);
    $stmt->execute();
    $stmt->bind_result($area_id);
    if ($stmt->fetch()) { 
        $stmt->close();
        return [$area_id, null];
    }
    $stmt->close();

    // Create new
    $deleted_at = "0";
    $stmt = $conn->prepare("INSERT INTO area (area_name, deleted_at, create_at, created_by_id) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('siss', $area_name, $deleted_at, $timestamp, $current_user_id);
    if (!$stmt->execute()) { 
        $err = $stmt->error; 
        $stmt->close(); 
        return [null, "Area insert failed: $err"]; 
    }
    $new_id = $stmt->insert_id;
    $stmt->close();

    $new_area_id = uniqueID('Area', $new_id);
    $stmt = $conn->prepare("UPDATE area SET area_id=? WHERE id=?");
    $stmt->bind_param('si', $new_area_id, $new_id);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        return [null, "Area id update failed: $err"];
    }
    $stmt->close();

    return [$new_area_id, null];
}

/**
 * Get plan_id by plan_name
 */
function get_plan_id_by_name(mysqli $conn, $plan_name) {
    $plan_name = sanitize($plan_name);
    if ($plan_name === '') return [null, null, null, "Plan name empty"];

    $stmt = $conn->prepare("SELECT plan_id, plan_name, plan_prize FROM plan WHERE LOWER(plan_name)=LOWER(?) AND deleted_at = 0 LIMIT 1");
    $stmt->bind_param('s', $plan_name);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) { 
        $stmt->close(); 
        return [$row['plan_id'], $row['plan_name'], floatval($row['plan_prize']), null]; 
    }
    $stmt->close();
    return [null, null, null, "Plan not found: {$plan_name}"];
}

/**
 * Get staff_id by staff_name
 */
function get_staff_id_by_name(mysqli $conn, $staff_name) {
    $staff_name = sanitize($staff_name);
    if ($staff_name === '') return [null, "Staff name empty"];

    $stmt = $conn->prepare("SELECT staff_id, staff_name FROM staff WHERE LOWER(staff_name)=LOWER(?) AND delete_at = 0 LIMIT 1");
    $stmt->bind_param('s', $staff_name);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) { 
        $stmt->close(); 
        return [$row['staff_id'], $row['staff_name'], null]; 
    }
    $stmt->close();
    return [null, null, "Staff not found: {$staff_name}"];
}

/**
 * Check if customer exists by phone
 */
function customer_exists_by_phone(mysqli $conn, $phone) {
    $stmt = $conn->prepare("SELECT id FROM customer WHERE phone=? AND deleted_at=0 LIMIT 1");
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $stmt->bind_result($id);
    $exists = $stmt->fetch();
    $stmt->close();
    return (bool)$exists;
}

try {
    date_default_timezone_set('Asia/Calcutta');
    $current_user_id = "abcdef5ghlemenop454asd"; // This should ideally come from auth
    $timestamp = date('Y-m-d H:i:s');
    $dry_run = isset($_POST['dry_run']) && (int)$_POST['dry_run'] === 1;

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_out(["head"=>["code"=>400,"msg"=>"Upload a valid .xlsx file"]], 400);
    }

    $tmp = $_FILES['file']['tmp_name'];
    $spreadsheet = IOFactory::load($tmp);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);

    if (count($rows) === 0) json_out(["head"=>["code"=>400,"msg"=>"Empty spreadsheet"]], 400);

    $expected = ['name', 'phone', 'address', 'area_name', 'box_no', 'plan_name', 'staff_name'];
    $headerMap = [];
    foreach ($rows[1] as $colLetter=>$colVal) {
        $key = mb_strtolower(trim((string)$colVal));
        if ($key !== '') $headerMap[$key]=$colLetter;
    }
    foreach ($expected as $col) {
        if (!isset($headerMap[$col])) json_out(["head"=>["code"=>400,"msg"=>"Missing header: $col"]], 400);
    }

    $conn->begin_transaction();
    $success=0; $skipped=0; $errors=[]; $results=[];

    $lastRow = $sheet->getHighestRow();
    for ($r=2; $r<=$lastRow; $r++) {
        $row=$rows[$r]??null;
        if (!$row) continue;

        $name = sanitize($row[$headerMap['name']]??'');
        $phone = sanitize($row[$headerMap['phone']]??'N/A');
        $address = sanitize($row[$headerMap['address']]??'N/A');
        $area_name = sanitize($row[$headerMap['area_name']]??'N/A');
        $box_no = sanitize($row[$headerMap['box_no']]??'N/A');
        $plan_name = sanitize($row[$headerMap['plan_name']]??'N/A');
        $staff_name = sanitize($row[$headerMap['staff_name']]??'N/A');

        if ($name === '') { 
            $skipped++; 
            $errors[]=["row"=>$r,"issues"=>["name empty"]]; 
            continue; 
        }

        if ($phone !== "N/A" && (!is_numeric($phone) || strlen($phone) != 10)) {
            $skipped++;
            $errors[]=["row"=>$r,"phone"=>$phone,"issues"=>["Invalid phone number"]]; 
            continue;
        }

        list($plan_id, $plan_name_db, $plan_prize, $planErr) = get_plan_id_by_name($conn, $plan_name);
        if ($planErr) {
            $skipped++;
            $errors[]=["row"=>$r,"issues"=>[$planErr]]; 
            continue;
        }

        list($area_id, $areaErr) = get_or_create_area($conn, $area_name, $current_user_id, $timestamp);
        if ($areaErr) { 
            $skipped++; 
            $errors[]=["row"=>$r,"issues"=>[$areaErr]]; 
            continue; 
        }

        list($staff_id, $staff_name_db, $staffErr) = get_staff_id_by_name($conn, $staff_name);
        if ($staffErr) {
            $skipped++;
            $errors[]=["row"=>$r,"issues"=>[$staffErr]]; 
            continue;
        }

        if ($phone !== "N/A" && customer_exists_by_phone($conn, $phone)) {
            $skipped++; 
            $errors[]=["row"=>$r,"phone"=>$phone,"issues"=>["Duplicate phone"]]; 
            continue;
        }

        list($customer_no, $customerNoErr) = generate_customer_no($conn, $area_id);
        if ($customerNoErr) {
            $skipped++;
            $errors[]=["row"=>$r,"issues"=>[$customerNoErr]]; 
            continue;
        }

        if ($dry_run) { 
            $success++; 
            continue; 
        }

        $deleted_at = "0";
        $total_pending_amount = 0;
        $total_pending_months = 0;
        $stmt = $conn->prepare("
            INSERT INTO customer (customer_no, name, phone, address, area_id, area_name, box_no, plan_id, plan_name, plan_prize, staff_id, staff_name, total_pending_amount, total_pending_months, deleted_at, create_at, created_by_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('sssssssssssssisss', $customer_no, $name, $phone, $address, $area_id, $area_name, $box_no, $plan_id, $plan_name_db, $plan_prize, $staff_id, $staff_name_db, $total_pending_amount, $total_pending_months, $deleted_at, $timestamp, $current_user_id);
        if (!$stmt->execute()) {
            $skipped++; 
            $errors[]=["row"=>$r,"phone"=>$phone,"issues"=>["insert failed: ".$stmt->error]];
            $stmt->close(); 
            continue;
        }
        $new_id = $stmt->insert_id;
        $stmt->close();

        $customer_id = uniqueID('Customer', $new_id);
        $stmt = $conn->prepare("UPDATE customer SET customer_id=? WHERE id=?");
        $stmt->bind_param('si', $customer_id, $new_id);
        $stmt->execute();
        $stmt->close();

        // Insert into plan_history
        $start_date = date('Y-m-d');
        $stmt_plan_history = $conn->prepare("
            INSERT INTO plan_history (customer_id, plan_id, plan_name, plan_prize, start_date, created_at) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt_plan_history->bind_param("ssssss", $customer_id, $plan_id, $plan_name_db, $plan_prize, $start_date, $timestamp);
        $stmt_plan_history->execute();
        $stmt_plan_history->close();

        // Log customer history
        $stmt_hist = $conn->prepare("
            INSERT INTO customer_history 
                (customer_id, customer_no, action_type, old_value, new_value, remarks, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $action_type = "customer_create";
        $old_value = null;
        $new_value = json_encode([
            "customer_id" => $customer_id,
            "name" => $name,
            "phone" => $phone,
            "address" => $address,
            "area_id" => $area_id,
            "area_name" => $area_name,
            "box_no" => $box_no,
            "plan_id" => $plan_id,
            "plan_name" => $plan_name_db,
            "plan_prize" => $plan_prize,
            "staff_id" => $staff_id,
            "staff_name" => $staff_name_db,
            "created_by_id" => $current_user_id
        ], JSON_NUMERIC_CHECK);
        $remarks = "Customer created via bulk upload";
        $stmt_hist->bind_param(
            "sssssss",
            $customer_id,
            $customer_no,
            $action_type,
            $old_value,
            $new_value,
            $remarks,
            $timestamp
        );
        $stmt_hist->execute();
        $stmt_hist->close();

        $success++;
    }

    if ($dry_run) $conn->rollback(); else $conn->commit();

    json_out([
        "head"=>["code"=>200,"msg"=>$dry_run?"Dry run completed":"Upload completed"],
        "body"=>[
            "summary"=>["success"=>$success,"skipped"=>$skipped,"total_processed"=>($success+$skipped),"dry_run"=>$dry_run],
            "errors"=>$errors
        ]
    ], 200);

} catch(Throwable $e) {
    @mysqli_rollback($conn);
    json_out(["head"=>["code"=>500,"msg"=>"Server error","error"=>$e->getMessage()]], 500);
}
?>