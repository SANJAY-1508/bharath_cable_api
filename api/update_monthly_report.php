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

// Create the table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS `monthly_box_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `monthly_box_history_id` VARCHAR(50) UNIQUE,
    `year` INT NOT NULL,
    `month` INT NOT NULL,
    `total_boxes` INT DEFAULT 0,
    `active_boxes` INT DEFAULT 0,
    `disconnect_boxes` INT DEFAULT 0,
    `total_collection` FLOAT DEFAULT 0,
    `created_at` DATETIME,
    `updated_at` DATETIME
)");

date_default_timezone_set('Asia/Calcutta');
$current_date = date('Y-m-d');
$current_year = date('Y');
$current_month = date('m');

// If today is the 1st, snapshot the previous month if not done
if (date('d') == '01') {
    $prev_year = date('Y', strtotime('-1 month'));
    $prev_month = date('m', strtotime('-1 month'));
    $prev_first_day = date('Y-m-01', strtotime('-1 month'));
    $prev_last_day = date('Y-m-t', strtotime('-1 month'));

    $stmt = $conn->prepare("SELECT `id` FROM `monthly_box_history` WHERE `year` = ? AND `month` = ?");
    $stmt->bind_param("ii", $prev_year, $prev_month);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        // Calculate total_boxes: customers created on or before last day of prev month, not deleted
        $stmt_total = $conn->prepare("SELECT COUNT(*) AS count FROM `customer` WHERE `deleted_at` = 0 AND `create_at` <= ?");
        $stmt_total->bind_param("s", $prev_last_day);
        $stmt_total->execute();
        $total_boxes = $stmt_total->get_result()->fetch_assoc()['count'];
        $stmt_total->close();

        // Active boxes: plan_history where active (not disconnect) on prev_last_day
        $stmt_active = $conn->prepare("SELECT COUNT(DISTINCT `customer_id`) AS count FROM `plan_history` 
                                       WHERE `start_date` <= ? AND (`end_date` IS NULL OR `end_date` >= ?) AND `plan_name` != 'disconnect'");
        $stmt_active->bind_param("ss", $prev_last_day, $prev_last_day);
        $stmt_active->execute();
        $active_boxes = $stmt_active->get_result()->fetch_assoc()['count'];
        $stmt_active->close();

        $disconnect_boxes = $total_boxes - $active_boxes;

        // Total collection for prev month
        $stmt_coll = $conn->prepare("SELECT SUM(`entry_amount`) AS sum FROM `collection` WHERE `deleted_at` = 0 AND `collection_paid_date` BETWEEN ? AND ?");
        $stmt_coll->bind_param("ss", $prev_first_day, $prev_last_day);
        $stmt_coll->execute();
        $total_collection = $stmt_coll->get_result()->fetch_assoc()['sum'] ?: 0.0;
        $stmt_coll->close();

        // Insert the row
        $timestamp = date('Y-m-d H:i:s');
        $stmt_insert = $conn->prepare("INSERT INTO `monthly_box_history` (`year`, `month`, `total_boxes`, `active_boxes`, `disconnect_boxes`, `total_collection`, `created_at`, `updated_at`) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_insert->bind_param("iiiiddss", $prev_year, $prev_month, $total_boxes, $active_boxes, $disconnect_boxes, $total_collection, $timestamp, $timestamp);
        $stmt_insert->execute();
        $id = $conn->insert_id;
        $stmt_insert->close();

        // Generate unique ID
        $enId = uniqueID('MonthlyBoxHistory', $id);
        $stmt_update_id = $conn->prepare("UPDATE `monthly_box_history` SET `monthly_box_history_id` = ? WHERE `id` = ?");
        $stmt_update_id->bind_param("si", $enId, $id);
        $stmt_update_id->execute();
        $stmt_update_id->close();
    }
    $stmt->close();
}

// Always update/create the current month's row with current snapshot
$first_day = date('Y-m-01');
updateMonthlyBoxHistory($conn, $current_year, $current_month, $first_day, $current_date);

// Output for testing (optional, remove for actual cron)
$output = ["head" => ["code" => 200, "msg" => "Monthly box history updated"]];
echo json_encode($output);

$conn->close();
