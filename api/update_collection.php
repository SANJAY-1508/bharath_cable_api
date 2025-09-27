<?php
include 'config/config.php';

date_default_timezone_set('Asia/Calcutta');

function updatePendingFields() {
    global $conn;

    $sql = "SELECT `customer_id`, `create_at`, `plan_prize`, `total_collection_amount`, 
                   `total_collection_months`, `total_pending_amount`, `total_pending_months`, 
                   `total_due_months`, `total_due_amount`, `plan_name`
            FROM `customer` 
            WHERE `deleted_at` = 0";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $customer_id = $row['customer_id'];
            $plan_name = $row['plan_name'];
            $total_pending_months = floatval($row['total_pending_months'] ?? 0);

            // Check if plan_name is 'disconnect' or total_pending_months is 3
            if ($plan_name === 'disconnect' || $total_pending_months >= 3) {
                continue; // Skip this customer
            }

            $create_at = new DateTime($row['create_at']);
            $plan_prize = floatval($row['plan_prize']);
            $total_collection_amount = floatval($row['total_collection_amount'] ?? 0);
            $total_collection_months = floatval($row['total_collection_months'] ?? 0);
            $current_pending_amount = floatval($row['total_pending_amount'] ?? 0);
            $current_pending_months = floatval($row['total_pending_months'] ?? 0);
            $total_due_months = intval($row['total_due_months'] ?? 0);
            $total_due_amount = floatval($row['total_due_amount'] ?? 0);

            $now = new DateTime();
            $interval = $create_at->diff($now);
            $days_since_creation = $interval->days;
            $current_due_months = floor($days_since_creation / 30) + 1;
            $current_due_amount = $current_due_months * $plan_prize;

            if ($current_due_months != $total_due_months || $current_due_amount != $total_due_amount) {
                // Remove max(0, ...) to allow negative values
                $new_pending_amount = $current_due_amount - $total_collection_amount;
                $new_pending_months = $current_due_months - $total_collection_months;

                $update_sql = "UPDATE `customer` 
                              SET `total_due_months` = ?, 
                                  `total_due_amount` = ?, 
                                  `total_pending_amount` = ?, 
                                  `total_pending_months` = ? 
                              WHERE `customer_id` = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("iddds", $current_due_months, $current_due_amount, 
                                $new_pending_amount, $new_pending_months, $customer_id);
                if (!$stmt->execute()) {
                    error_log("Failed to update customer $customer_id: " . $conn->error);
                }
                $stmt->close();
            }
        }
    }
}

updatePendingFields();
$conn->close();
?>