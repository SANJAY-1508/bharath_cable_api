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
$current_month = date('Y-m');
$current_date = date('Y-m-d');

if (isset($obj->action) && $obj->action === 'collection_api' && isset($obj->staff_id)) {

    $staff_id = $obj->staff_id;
    $search_text = isset($obj->search_text) ? $obj->search_text : "";
    $from_date = isset($obj->fromdate) ? $obj->fromdate : "";
    $to_date = isset($obj->todate) ? $obj->todate : "";
    $area_name = isset($obj->area_name) ? $obj->area_name : "";

    // Base SQL
    $sql = "SELECT 
                c.`id`, c.`collection_id`, c.`collection_paid_date`, c.`customer_id`, cu.`customer_no`, 
                cu.`name`, cu.`phone`, cu.`address`, c.`area_id`, a.`area_name`, c.`box_no`, 
                c.`plan_id`, p.`plan_name`, p.`plan_prize`, c.`staff_id`, s.`staff_name`, 
                c.`entry_amount`, c.`payment_method`, c.`total_pending_amount`, c.`balance_amount`, 
                c.`paid_by`, c.`paid_by_name`, c.`create_at`, c.`deleted_at`, 
                c.`edited_by`, c.`edited_by_name`
            FROM `collection` c
            LEFT JOIN `customer` cu ON c.`customer_id` = cu.`customer_id`
            LEFT JOIN `area` a ON c.`area_id` = a.`area_id`
            LEFT JOIN `plan` p ON c.`plan_id` = p.`plan_id`
            LEFT JOIN `staff` s ON c.`staff_id` = s.`staff_id`
            WHERE c.`deleted_at` = 0 
              AND c.`staff_id` = ?";

    $params = [$staff_id];
    $types = "s"; // assuming staff_id is string

    // Optional customer name filter
    if (trim($search_text) !== "") {
        $sql .= " AND cu.`name` LIKE ?";
        $params[] = "%$search_text%";
        $types .= "s";
    }

    // Optional area_name filter
    if (trim($area_name) !== "") {
        $sql .= " AND a.`area_name` LIKE ?";
        $params[] = "%$area_name%";
        $types .= "s";
    }

    // Optional date range filter
    if ($from_date !== "" && $to_date !== "") {
        $sql .= " AND c.`collection_paid_date` BETWEEN ? AND ?";
        $params[] = $from_date;
        $params[] = $to_date;
        $types .= "ss";
    }

    // Prepare and execute
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $collections = $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $output["body"]["collection"] = $collections;
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = $result->num_rows > 0 ? "Success" : "No Collection Found";
        $stmt->close();
    } else {
        $output["head"]["code"] = 500;
        $output["head"]["msg"] = "DB Prepare Error: " . $conn->error;
    }
} else if (isset($obj->list_history)) {
    $customer_id = isset($obj->customer_id) ? $obj->customer_id : null;
    $customer_no = isset($obj->customer_no) ? $obj->customer_no : null;

    $sql = "SELECT `id`, `customer_id`, `customer_no`, `action_type`, `old_value`, `new_value`, `remarks`, `created_at` FROM `customer_history` WHERE 1";
    $params = [];
    $types = "";

    if (!empty($customer_id)) {
        $sql .= " AND `customer_id` = ?";
        $params[] = $customer_id;
        $types .= "s";
    }
    if (!empty($customer_no)) {
        $sql .= " AND `customer_no` = ?";
        $params[] = $customer_no;
        $types .= "s";
    }

    $sql .= " ORDER BY `created_at` DESC";
    $stmt = $conn->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $history = $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];

    foreach ($history as &$record) {
        try {
            $record['old_value'] = $record['old_value'] ? json_decode($record['old_value'], true) : null;
            $record['new_value'] = $record['new_value'] ? json_decode($record['new_value'], true) : null;
        } catch (Exception $e) {
            $record['old_value'] = null;
            $record['new_value'] = null;
        }
    }

    $output["body"]["history"] = $history;
    $output["head"]["code"] = 200;
    $output["head"]["msg"] = $result->num_rows > 0 ? "Success" : "No History Found";
    $stmt->close();
} else if (isset($obj->get_staff_grouped_data)) {
    // Query to fetch staff-wise and user-wise grouped collection data
    $sql = "
        SELECT 
            c.staff_id,
            c.staff_name,
            s.staff_no,
            c.area_id,
            c.area_name,
            u.user_id AS created_by_user_id,
            u.name AS created_by_name,
            COALESCE(SUM(CASE WHEN DATE(col.collection_paid_date) = ? THEN col.entry_amount ELSE 0 END), 0) AS today_collection,
            COALESCE(SUM(CASE WHEN DATE_FORMAT(col.collection_paid_date, '%Y-%m') = ? THEN col.entry_amount ELSE 0 END), 0) AS month_collection
        FROM `customer` c
        LEFT JOIN `collection` col 
            ON c.customer_id = col.customer_id 
            AND col.deleted_at = 0
        LEFT JOIN `staff` s 
            ON c.staff_id = s.staff_id 
            AND s.delete_at = 0
        LEFT JOIN `user` u
            ON col.created_by_id = u.user_id
        WHERE c.deleted_at = 0 
            AND c.plan_name != 'disconnect'
        GROUP BY c.staff_id, c.staff_name, s.staff_no, c.area_id, c.area_name, u.user_id, u.name
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ss", $current_date, $current_month);
        $stmt->execute();
        $result = $stmt->get_result();
        $raw_data = $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        // Initialize response structures
        $staff_data = [];
        $user_data = [];

        foreach ($raw_data as $row) {
            $staff_id = $row['staff_id'];
            $created_by_user_id = $row['created_by_user_id'];
            $area_id = $row['area_id'];

            // Initialize staff data if not exists
            if (!isset($staff_data[$staff_id])) {
                $staff_data[$staff_id] = [
                    'staff_id' => $staff_id,
                    'staff_name' => $row['staff_name'],
                    'staff_no' => $row['staff_no'] ?: $row['staff_id'],
                    'today_collection' => 0,
                    'month_collection' => 0,
                    'areas' => []
                ];
            }

            // Initialize user data if not exists
            if ($created_by_user_id && $row['created_by_name'] && !isset($user_data[$created_by_user_id])) {
                $user_data[$created_by_user_id] = [
                    'user_id' => $created_by_user_id,
                    'name' => $row['created_by_name'],
                    'today_collection' => 0,
                    'month_collection' => 0,
                    'areas' => []
                ];
            }

            // Aggregate area data for staff
            $area_exists = false;
            foreach ($staff_data[$staff_id]['areas'] as &$area) {
                if ($area['area_id'] === $area_id) {
                    $area['today_collection'] += (float)$row['today_collection'];
                    $area['month_collection'] += (float)$row['month_collection'];
                    $area_exists = true;
                    break;
                }
            }
            if (!$area_exists) {
                $staff_data[$staff_id]['areas'][] = [
                    'area_id' => $area_id,
                    'area_name' => $row['area_name'],
                    'today_collection' => (float)$row['today_collection'],
                    'month_collection' => (float)$row['month_collection']
                ];
            }

            // Aggregate staff-level collections
            $staff_data[$staff_id]['today_collection'] += (float)$row['today_collection'];
            $staff_data[$staff_id]['month_collection'] += (float)$row['month_collection'];

            // Handle user data
            if ($created_by_user_id && $row['created_by_name']) {
                // Aggregate area data for user
                $area_exists = false;
                foreach ($user_data[$created_by_user_id]['areas'] as &$area) {
                    if ($area['area_id'] === $area_id) {
                        $area['today_collection'] += (float)$row['today_collection'];
                        $area['month_collection'] += (float)$row['month_collection'];
                        $area_exists = true;
                        break;
                    }
                }
                if (!$area_exists) {
                    $user_data[$created_by_user_id]['areas'][] = [
                        'area_id' => $area_id,
                        'area_name' => $row['area_name'],
                        'today_collection' => (float)$row['today_collection'],
                        'month_collection' => (float)$row['month_collection']
                    ];
                }

                // Aggregate user-level collections
                $user_data[$created_by_user_id]['today_collection'] += (float)$row['today_collection'];
                $user_data[$created_by_user_id]['month_collection'] += (float)$row['month_collection'];

                // Subtract user-attributed collections from staff
                foreach ($staff_data[$staff_id]['areas'] as &$staff_area) {
                    if ($staff_area['area_id'] === $area_id) {
                        $staff_area['today_collection'] -= (float)$row['today_collection'];
                        $staff_area['month_collection'] -= (float)$row['month_collection'];
                        // Ensure collections don't go negative
                        $staff_area['today_collection'] = max(0, $staff_area['today_collection']);
                        $staff_area['month_collection'] = max(0, $staff_area['month_collection']);
                    }
                }
                $staff_data[$staff_id]['today_collection'] -= (float)$row['today_collection'];
                $staff_data[$staff_id]['month_collection'] -= (float)$row['month_collection'];
                // Ensure staff collections don't go negative
                $staff_data[$staff_id]['today_collection'] = max(0, $staff_data[$staff_id]['today_collection']);
                $staff_data[$staff_id]['month_collection'] = max(0, $staff_data[$staff_id]['month_collection']);
            }
        }

        // Clean up areas array by removing entries with zero collections
        foreach ($staff_data as &$staff) {
            $staff['areas'] = array_filter($staff['areas'], function ($area) {
                return $area['today_collection'] > 0 || $area['month_collection'] > 0;
            });
            $staff['areas'] = array_values($staff['areas']); // Reindex array
        }

        foreach ($user_data as &$user) {
            $user['areas'] = array_filter($user['areas'], function ($area) {
                return $area['today_collection'] > 0 || $area['month_collection'] > 0;
            });
            $user['areas'] = array_values($user['areas']); // Reindex array
        }

        // Convert associative arrays to indexed arrays
        $staff_data = array_values($staff_data);
        $user_data = array_values($user_data);

        $output["head"]["code"] = 200;
        $output["head"]["msg"] = $result->num_rows > 0 ? "Success" : "No staff data found.";
        $output["body"]["staff_data"] = $staff_data;
        $output["body"]["user_data"] = $user_data;
        $output["body"]["month"] = $current_month;
        $output["body"]["date"] = $current_date;
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Failed to fetch staff data: " . $conn->error;
    }
} else if (isset($obj->get_grouped_customer_data)) {
    // Query to fetch counts for connected, disconnected, and total boxes
    $sql_counts = "
        SELECT 
            SUM(CASE WHEN plan_name = 'disconnect' AND deleted_at = 0 THEN 1 ELSE 0 END) AS disconnected_boxes,
            SUM(CASE WHEN plan_name != 'disconnect' AND deleted_at = 0 THEN 1 ELSE 0 END) AS connected_boxes,
            COUNT(*) AS total_boxes
        FROM `customer`
        WHERE deleted_at = 0
    ";

    $stmt_counts = $conn->prepare($sql_counts);
    $stmt_counts->execute();
    $result_counts = $stmt_counts->get_result();
    $counts = $result_counts->fetch_assoc();
    $stmt_counts->close();

    // Query to fetch connected boxes customer data
    $sql_connected = "
        SELECT id, customer_id, customer_no, name, phone, address, area_id, area_name, box_no, plan_id, plan_name, plan_prize, staff_id, staff_name, total_collection_amount, total_pending_amount, total_due_amount, total_collection_months, total_pending_months, total_due_months, create_at, created_by_id
        FROM `customer`
        WHERE plan_name != 'disconnect' AND deleted_at = 0
    ";
    $stmt_connected = $conn->prepare($sql_connected);
    $stmt_connected->execute();
    $result_connected = $stmt_connected->get_result();
    $connected_boxes_data = $result_connected->num_rows > 0 ? $result_connected->fetch_all(MYSQLI_ASSOC) : [];
    $stmt_connected->close();

    // Query to fetch disconnected boxes customer data
    $sql_disconnected = "
        SELECT id, customer_id, customer_no, name, phone, address, area_id, area_name, box_no, plan_id, plan_name, plan_prize, staff_id, staff_name, total_collection_amount, total_pending_amount, total_due_amount, total_collection_months, total_pending_months, total_due_months, create_at, created_by_id
        FROM `customer`
        WHERE plan_name = 'disconnect' AND deleted_at = 0
    ";
    $stmt_disconnected = $conn->prepare($sql_disconnected);
    $stmt_disconnected->execute();
    $result_disconnected = $stmt_disconnected->get_result();
    $disconnected_boxes_data = $result_disconnected->num_rows > 0 ? $result_disconnected->fetch_all(MYSQLI_ASSOC) : [];
    $stmt_disconnected->close();

    // Query to fetch all customer data (total boxes)
    $sql_total = "
        SELECT id, customer_id, customer_no, name, phone, address, area_id, area_name, box_no, plan_id, plan_name, plan_prize, staff_id, staff_name, total_collection_amount, total_pending_amount, total_due_amount, total_collection_months, total_pending_months, total_due_months, create_at, created_by_id
        FROM `customer`
        WHERE deleted_at = 0
    ";
    $stmt_total = $conn->prepare($sql_total);
    $stmt_total->execute();
    $result_total = $stmt_total->get_result();
    $total_boxes_data = $result_total->num_rows > 0 ? $result_total->fetch_all(MYSQLI_ASSOC) : [];
    $stmt_total->close();

    if ($result_counts->num_rows > 0) {
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Success";
        $output["body"]["grouped_data"] = [
            "connected_boxes" => (int)$counts['connected_boxes'],
            "disconnected_boxes" => (int)$counts['disconnected_boxes'],
            "total_boxes" => (int)$counts['total_boxes'],
            "connected_boxes_data" => $connected_boxes_data,
            "disconnected_boxes_data" => $disconnected_boxes_data,
            "total_boxes_data" => $total_boxes_data
        ];
    } else {
        $output["head"]["code"] = 404;
        $output["head"]["msg"] = "No customer data found.";
        $output["body"]["grouped_data"] = [
            "connected_boxes" => 0,
            "disconnected_boxes" => 0,
            "total_boxes" => 0,
            "connected_boxes_data" => [],
            "disconnected_boxes_data" => [],
            "total_boxes_data" => []
        ];
    }
} else if (isset($obj->get_staff_grouped_counts)) {
    $sql = "
        SELECT 
            c.staff_id,
            c.staff_name,
            s.staff_no,
            c.area_id,
            c.area_name,
            COUNT(DISTINCT c.customer_id) AS box_count,
            COUNT(DISTINCT CASE WHEN DATE(col.collection_paid_date) = ? THEN c.customer_id END) AS current_date_paid_count,
            COUNT(DISTINCT CASE WHEN DATE_FORMAT(col.collection_paid_date, '%Y-%m') = ? THEN c.customer_id END) AS current_month_paid_count,  
            u.user_id AS created_by_user_id,
            u.name AS created_by_name
        FROM `customer` c
        LEFT JOIN `collection` col 
            ON c.customer_id = col.customer_id 
            AND col.deleted_at = 0
            AND DATE_FORMAT(col.collection_paid_date, '%Y-%m') = ?  
        LEFT JOIN `staff` s 
            ON c.staff_id = s.staff_id 
            AND s.delete_at = 0
        LEFT JOIN `user` u
            ON col.created_by_id = u.user_id
        WHERE c.deleted_at = 0 
            AND c.plan_name != 'disconnect'
        GROUP BY c.staff_id, c.staff_name, s.staff_no, c.area_id, c.area_name, u.user_id, u.name
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        // Bind three parameters: current_date, current_month, current_month
        $stmt->bind_param("sss", $current_date, $current_month, $current_month);
        $stmt->execute();
        $result = $stmt->get_result();
        $raw_data = $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        // Rest of your PHP logic for processing $raw_data remains unchanged
        $staff_data = [];
        $user_data = [];

        foreach ($raw_data as $row) {
            $staff_id = $row['staff_id'];
            $created_by_user_id = $row['created_by_user_id'];

            // Initialize staff data if not exists
            if (!isset($staff_data[$staff_id])) {
                $staff_data[$staff_id] = [
                    'staff_id' => $staff_id,
                    'staff_name' => $row['staff_name'],
                    'staff_no' => $row['staff_no'] ?: $row['staff_id'],
                    'total_count' => 0,
                    'current_date_paid_count' => 0,
                    'current_month_paid_count' => 0,
                    'current_month_unpaid_count' => 0,
                    'areas' => []
                ];
            }

            // Initialize user data if not exists
            if ($created_by_user_id && $row['created_by_name'] && !isset($user_data[$created_by_user_id])) {
                $user_data[$created_by_user_id] = [
                    'user_id' => $created_by_user_id,
                    'name' => $row['created_by_name'],
                    'total_count' => 0,
                    'current_date_paid_count' => 0,
                    'current_month_paid_count' => 0,
                    'current_month_unpaid_count' => 0,
                    'areas' => []
                ];
            }

            // Aggregate area data for staff (avoid duplicates by area_id)
            $area_id = $row['area_id'];
            $area_exists = false;
            foreach ($staff_data[$staff_id]['areas'] as &$area) {
                if ($area['area_id'] === $area_id) {
                    $area['box_count'] += (int)$row['box_count'];
                    $area['current_date_paid_count'] += (int)$row['current_date_paid_count'];
                    $area['current_month_paid_count'] += (int)$row['current_month_paid_count'];
                    $area['current_month_unpaid_count'] += ((int)$row['box_count'] - (int)$row['current_month_paid_count']);
                    $area_exists = true;
                    break;
                }
            }
            if (!$area_exists) {
                $staff_data[$staff_id]['areas'][] = [
                    'area_id' => $area_id,
                    'area_name' => $row['area_name'],
                    'box_count' => (int)$row['box_count'],
                    'current_date_paid_count' => (int)$row['current_date_paid_count'],
                    'current_month_paid_count' => (int)$row['current_month_paid_count'],
                    'current_month_unpaid_count' => (int)$row['box_count'] - (int)$row['current_month_paid_count']
                ];
            }

            // Aggregate staff-level counts
            $staff_data[$staff_id]['total_count'] += (int)$row['box_count'];
            $staff_data[$staff_id]['current_date_paid_count'] += (int)$row['current_date_paid_count'];
            $staff_data[$staff_id]['current_month_paid_count'] += (int)$row['current_month_paid_count'];

            // Handle user data
            if ($created_by_user_id && $row['created_by_name']) {
                // Aggregate area data for user (avoid duplicates by area_id)
                $area_exists = false;
                foreach ($user_data[$created_by_user_id]['areas'] as &$area) {
                    if ($area['area_id'] === $area_id) {
                        $area['box_count'] += (int)$row['box_count'];
                        $area['current_date_paid_count'] += (int)$row['current_date_paid_count'];
                        $area['current_month_paid_count'] += (int)$row['current_month_paid_count'];
                        $area['current_month_unpaid_count'] += ((int)$row['box_count'] - (int)$row['current_month_paid_count']);
                        $area_exists = true;
                        break;
                    }
                }
                if (!$area_exists) {
                    $user_data[$created_by_user_id]['areas'][] = [
                        'area_id' => $area_id,
                        'area_name' => $row['area_name'],
                        'box_count' => (int)$row['box_count'],
                        'current_date_paid_count' => (int)$row['current_date_paid_count'],
                        'current_month_paid_count' => (int)$row['current_month_paid_count'],
                        'current_month_unpaid_count' => (int)$row['box_count'] - (int)$row['current_month_paid_count']
                    ];
                }

                // Aggregate user-level counts
                $user_data[$created_by_user_id]['total_count'] += (int)$row['box_count'];
                $user_data[$created_by_user_id]['current_date_paid_count'] += (int)$row['current_date_paid_count'];
                $user_data[$created_by_user_id]['current_month_paid_count'] += (int)$row['current_month_paid_count'];

                // Subtract from staff counts if assigned to user (adjust area-specific counts)
                foreach ($staff_data[$staff_id]['areas'] as &$staff_area) {
                    if ($staff_area['area_id'] === $area_id) {
                        $staff_area['box_count'] -= (int)$row['box_count'];
                        $staff_area['current_date_paid_count'] -= (int)$row['current_date_paid_count'];
                        $staff_area['current_month_paid_count'] -= (int)$row['current_month_paid_count'];
                        $staff_area['current_month_unpaid_count'] -= ((int)$row['box_count'] - (int)$row['current_month_paid_count']);
                        // Ensure counts don't go negative
                        $staff_area['box_count'] = max(0, $staff_area['box_count']);
                        $staff_area['current_date_paid_count'] = max(0, $staff_area['current_date_paid_count']);
                        $staff_area['current_month_paid_count'] = max(0, $staff_area['current_month_paid_count']);
                        $staff_area['current_month_unpaid_count'] = max(0, $staff_area['current_month_unpaid_count']);
                    }
                }

                // Subtract from staff-level counts
                $staff_data[$staff_id]['total_count'] -= (int)$row['box_count'];
                $staff_data[$staff_id]['current_date_paid_count'] -= (int)$row['current_date_paid_count'];
                $staff_data[$staff_id]['current_month_paid_count'] -= (int)$row['current_month_paid_count'];
                $staff_data[$staff_id]['current_month_unpaid_count'] -= ((int)$row['box_count'] - (int)$row['current_month_paid_count']);
                $staff_data[$staff_id]['current_month_unpaid_count'] = max(0, $staff_data[$staff_id]['current_month_unpaid_count']);
            }
        }

        // Clean up areas array by removing entries with zero counts
        foreach ($staff_data as &$staff) {
            $staff['areas'] = array_filter($staff['areas'], function ($area) {
                return $area['box_count'] > 0;
            });
            $staff['areas'] = array_values($staff['areas']); // Reindex array
            $staff['current_month_unpaid_count'] = $staff['total_count'] - $staff['current_month_paid_count'];
        }

        foreach ($user_data as &$user) {
            $user['current_month_unpaid_count'] = $user['total_count'] - $user['current_month_paid_count'];
        }

        // Convert associative arrays to indexed arrays
        $staff_data = array_values($staff_data);
        $user_data = array_values($user_data);

        $output["head"]["code"] = 200;
        $output["head"]["msg"] = $result->num_rows > 0 ? "Success" : "No data found for the current month ($current_month).";
        $output["body"]["staff_grouped_counts"] = $staff_data;
        $output["body"]["user_grouped_counts"] = $user_data;
        $output["body"]["month"] = $current_month;
        $output["body"]["date"] = $current_date;
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Failed to fetch data: " . $conn->error;
    }
} else if (isset($obj->login_history)) {

    $search_text = isset($obj->search_text) ? $conn->real_escape_string($obj->search_text) : '';

    if (!empty($search_text)) {
        $sql = "SELECT `id`, `staff_id`, `staff_no`, `staff_name`, `mobile_number`, `type`, `create_at`
                FROM `staff_login_history`
                WHERE `staff_name` LIKE '%$search_text%'
                   OR `staff_no` LIKE '%$search_text%'
                   OR `mobile_number` LIKE '%$search_text%'
                ORDER BY `create_at` DESC";
    } else {
        $sql = "SELECT `id`, `staff_id`, `staff_no`, `staff_name`, `mobile_number`, `type`, `create_at`
                FROM `staff_login_history`
                ORDER BY `create_at` DESC";
    }

    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Success";
            $output["body"]["LoginHistory"][$count] = $row;
            $count++;
        }
    } else {
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "Login History Not Found";
        $output["body"]["LoginHistory"] = [];
    }
} else if (isset($obj->action) && $obj->action === 'dashboard' && isset($obj->staff_id)) {
    $current_date = date('Y-m-d');
    $current_month = date('Y-m');

    $sql = "
        SELECT 
            c.staff_id,
            c.staff_name,
            s.staff_no,
            c.area_id,
            c.area_name,
            u.user_id AS created_by_user_id,
            u.name AS created_by_name,
            COALESCE(SUM(CASE WHEN DATE(col.collection_paid_date) = ? THEN col.entry_amount ELSE 0 END), 0) AS today_collection,
            COALESCE(COUNT(DISTINCT CASE WHEN DATE(col.collection_paid_date) = ? THEN col.customer_id END), 0) AS today_collection_count,
            COALESCE(SUM(CASE WHEN DATE_FORMAT(col.collection_paid_date, '%Y-%m') = ? THEN col.entry_amount ELSE 0 END), 0) AS month_collection,
            COALESCE(COUNT(DISTINCT CASE WHEN DATE_FORMAT(col.collection_paid_date, '%Y-%m') = ? THEN col.customer_id END), 0) AS month_collection_count,
            COUNT(DISTINCT c.customer_id) AS total_box_count,
            COALESCE(COUNT(DISTINCT CASE WHEN DATE(col.collection_paid_date) = ? THEN c.customer_id END), 0) AS today_paid_box_count,
            COALESCE(COUNT(DISTINCT CASE WHEN DATE_FORMAT(col.collection_paid_date, '%Y-%m') = ? THEN c.customer_id END), 0) AS month_paid_box_count,
            (COUNT(DISTINCT c.customer_id) - COALESCE(COUNT(DISTINCT CASE WHEN DATE_FORMAT(col.collection_paid_date, '%Y-%m') = ? THEN c.customer_id END), 0)) AS month_unpaid_box_count
        FROM `customer` c
        LEFT JOIN `collection` col 
            ON c.customer_id = col.customer_id 
            AND col.deleted_at = 0
        LEFT JOIN `staff` s 
            ON c.staff_id = s.staff_id 
            AND s.delete_at = 0
        LEFT JOIN `user` u
            ON col.created_by_id = u.user_id
        WHERE c.deleted_at = 0 
            AND c.plan_name != 'disconnect'
            AND c.staff_id = ?
        GROUP BY c.staff_id, c.staff_name, s.staff_no, c.area_id, c.area_name, u.user_id, u.name
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        // Bind parameters: current_date (twice), current_month (twice), current_date, current_month, current_month, staff_id
        $stmt->bind_param("sssssssi", $current_date, $current_date, $current_month, $current_month, $current_date, $current_month, $current_month, $obj->staff_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $raw_data = $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        // Initialize response structures
        $staff_data = [
            'staff_id' => $obj->staff_id,
            'staff_name' => '',
            'staff_no' => '',
            'today_collection' => 0,
            'today_collection_count' => 0,
            'month_collection' => 0,
            'month_collection_count' => 0,
            'total_box_count' => 0,
            'today_paid_box_count' => 0,
            'month_paid_box_count' => 0,
            'month_unpaid_box_count' => 0,
            'areas' => []
        ];
        $user_data = [];

        // Aggregate data
        foreach ($raw_data as $row) {
            $staff_id = $row['staff_id'];
            $created_by_user_id = $row['created_by_user_id'];
            $area_id = $row['area_id'];

            // Set staff-level data (only once)
            if (empty($staff_data['staff_name'])) {
                $staff_data['staff_name'] = $row['staff_name'];
                $staff_data['staff_no'] = $row['staff_no'] ?: $row['staff_id'];
            }

            // Initialize user data if not exists
            if ($created_by_user_id && $row['created_by_name'] && !isset($user_data[$created_by_user_id])) {
                $user_data[$created_by_user_id] = [
                    'user_id' => $created_by_user_id,
                    'name' => $row['created_by_name'],
                    'today_collection' => 0,
                    'today_collection_count' => 0,
                    'month_collection' => 0,
                    'month_collection_count' => 0,
                    'total_box_count' => 0,
                    'today_paid_box_count' => 0,
                    'month_paid_box_count' => 0,
                    'month_unpaid_box_count' => 0,
                    'areas' => []
                ];
            }

            // Aggregate area data for staff
            $area_exists = false;
            foreach ($staff_data['areas'] as &$area) {
                if ($area['area_id'] === $area_id) {
                    $area['total_box_count'] += (int)$row['total_box_count'];
                    $area['today_paid_box_count'] += (int)$row['today_paid_box_count'];
                    $area['month_paid_box_count'] += (int)$row['month_paid_box_count'];
                    $area['month_unpaid_box_count'] += (int)$row['month_unpaid_box_count'];
                    $area_exists = true;
                    break;
                }
            }
            if (!$area_exists) {
                $staff_data['areas'][] = [
                    'area_id' => $area_id,
                    'area_name' => $row['area_name'],
                    'total_box_count' => (int)$row['total_box_count'],
                    'today_paid_box_count' => (int)$row['today_paid_box_count'],
                    'month_paid_box_count' => (int)$row['month_paid_box_count'],
                    'month_unpaid_box_count' => (int)$row['month_unpaid_box_count']
                ];
            }

            // Aggregate staff-level counts
            $staff_data['today_collection'] += (float)$row['today_collection'];
            $staff_data['today_collection_count'] += (int)$row['today_collection_count'];
            $staff_data['month_collection'] += (float)$row['month_collection'];
            $staff_data['month_collection_count'] += (int)$row['month_collection_count'];
            $staff_data['total_box_count'] += (int)$row['total_box_count'];
            $staff_data['today_paid_box_count'] += (int)$row['today_paid_box_count'];
            $staff_data['month_paid_box_count'] += (int)$row['month_paid_box_count'];
            $staff_data['month_unpaid_box_count'] += (int)$row['month_unpaid_box_count'];

            // Handle user data
            if ($created_by_user_id && $row['created_by_name']) {
                // Aggregate area data for user
                $area_exists = false;
                foreach ($user_data[$created_by_user_id]['areas'] as &$area) {
                    if ($area['area_id'] === $area_id) {
                        $area['total_box_count'] += (int)$row['total_box_count'];
                        $area['today_paid_box_count'] += (int)$row['today_paid_box_count'];
                        $area['month_paid_box_count'] += (int)$row['month_paid_box_count'];
                        $area['month_unpaid_box_count'] += (int)$row['month_unpaid_box_count'];
                        $area_exists = true;
                        break;
                    }
                }
                if (!$area_exists) {
                    $user_data[$created_by_user_id]['areas'][] = [
                        'area_id' => $area_id,
                        'area_name' => $row['area_name'],
                        'total_box_count' => (int)$row['total_box_count'],
                        'today_paid_box_count' => (int)$row['today_paid_box_count'],
                        'month_paid_box_count' => (int)$row['month_paid_box_count'],
                        'month_unpaid_box_count' => (int)$row['month_unpaid_box_count']
                    ];
                }

                // Aggregate user-level counts
                $user_data[$created_by_user_id]['today_collection'] += (float)$row['today_collection'];
                $user_data[$created_by_user_id]['today_collection_count'] += (int)$row['today_collection_count'];
                $user_data[$created_by_user_id]['month_collection'] += (float)$row['month_collection'];
                $user_data[$created_by_user_id]['month_collection_count'] += (int)$row['month_collection_count'];
                $user_data[$created_by_user_id]['total_box_count'] += (int)$row['total_box_count'];
                $user_data[$created_by_user_id]['today_paid_box_count'] += (int)$row['today_paid_box_count'];
                $user_data[$created_by_user_id]['month_paid_box_count'] += (int)$row['month_paid_box_count'];
                $user_data[$created_by_user_id]['month_unpaid_box_count'] += (int)$row['month_unpaid_box_count'];

                // Subtract user-attributed counts from staff
                foreach ($staff_data['areas'] as &$staff_area) {
                    if ($staff_area['area_id'] === $area_id) {
                        $staff_area['total_box_count'] -= (int)$row['total_box_count'];
                        $staff_area['today_paid_box_count'] -= (int)$row['today_paid_box_count'];
                        $staff_area['month_paid_box_count'] -= (int)$row['month_paid_box_count'];
                        $staff_area['month_unpaid_box_count'] -= (int)$row['month_unpaid_box_count'];
                        // Ensure counts don't go negative
                        $staff_area['total_box_count'] = max(0, $staff_area['total_box_count']);
                        $staff_area['today_paid_box_count'] = max(0, $staff_area['today_paid_box_count']);
                        $staff_area['month_paid_box_count'] = max(0, $staff_area['month_paid_box_count']);
                        $staff_area['month_unpaid_box_count'] = max(0, $staff_area['month_unpaid_box_count']);
                    }
                }
                $staff_data['today_collection'] -= (float)$row['today_collection'];
                $staff_data['today_collection_count'] -= (int)$row['today_collection_count'];
                $staff_data['month_collection'] -= (float)$row['month_collection'];
                $staff_data['month_collection_count'] -= (int)$row['month_collection_count'];
                $staff_data['total_box_count'] -= (int)$row['total_box_count'];
                $staff_data['today_paid_box_count'] -= (int)$row['today_paid_box_count'];
                $staff_data['month_paid_box_count'] -= (int)$row['month_paid_box_count'];
                $staff_data['month_unpaid_box_count'] -= (int)$row['month_unpaid_box_count'];
                // Ensure staff counts don't go negative
                $staff_data['today_collection'] = max(0, $staff_data['today_collection']);
                $staff_data['today_collection_count'] = max(0, $staff_data['today_collection_count']);
                $staff_data['month_collection'] = max(0, $staff_data['month_collection']);
                $staff_data['month_collection_count'] = max(0, $staff_data['month_collection_count']);
                $staff_data['total_box_count'] = max(0, $staff_data['total_box_count']);
                $staff_data['today_paid_box_count'] = max(0, $staff_data['today_paid_box_count']);
                $staff_data['month_paid_box_count'] = max(0, $staff_data['month_paid_box_count']);
                $staff_data['month_unpaid_box_count'] = max(0, $staff_data['month_unpaid_box_count']);
            }
        }

        // Clean up areas array by removing entries with zero counts
        $staff_data['areas'] = array_filter($staff_data['areas'], function ($area) {
            return $area['total_box_count'] > 0;
        });
        $staff_data['areas'] = array_values($staff_data['areas']); // Reindex array

        foreach ($user_data as &$user) {
            $user['areas'] = array_filter($user['areas'], function ($area) {
                return $area['total_box_count'] > 0;
            });
            $user['areas'] = array_values($user['areas']); // Reindex array
        }
        $user_data = array_values($user_data); // Convert to indexed array

        $output["head"]["code"] = 200;
        $output["head"]["msg"] = $result->num_rows > 0 ? "Success" : "No data found for staff ID {$obj->staff_id}.";
        $output["body"]["staff_data"] = $staff_data;
        $output["body"]["user_data"] = $user_data;
        $output["body"]["date"] = $current_date;
        $output["body"]["month"] = $current_month;
    } else {
        $output["head"]["code"] = 400;
        $output["head"]["msg"] = "Failed to fetch dashboard data: " . $conn->error;
    }
} else if (isset($obj->search_text) || isset($obj->area_name) || (isset($obj->staff_id) && isset($obj->action) && $obj->action === 'due_list')) {
    // Check if action is due_list and staff_id is provided
    if (isset($obj->action) && $obj->action === 'due_list' && isset($obj->staff_id)) {
        // Build SQL query to fetch customer data filtered by staff_id
        $sql = "SELECT * FROM `customer` WHERE `deleted_at` = 0 AND `staff_id` = ?";
        $params = [$obj->staff_id];
        $types = "i"; // staff_id is an integer

        // If search_text is provided, add filter for customer name
        if (isset($obj->search_text) && !empty($obj->search_text)) {
            $sql .= " AND `name` LIKE ?";
            $search_param = "%" . $obj->search_text . "%";
            $params[] = $search_param;
            $types .= "s"; // search_text is a string
        }

        // If area_name is provided, add filter for area_name
        if (isset($obj->area_name) && !empty($obj->area_name)) {
            $sql .= " AND `area_name` LIKE ?";
            $area_param = "%" . $obj->area_name . "%";
            $params[] = $area_param;
            $types .= "s"; // area_name is a string
        }

        // Prepare and execute the query
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        // Group results by area_name
        $grouped_data = [];
        while ($row = $result->fetch_assoc()) {
            // Cast numeric fields to appropriate types
            $row['plan_prize'] = (float)$row['plan_prize'];
            $row['total_collection_amount'] = $row['total_collection_amount'] !== null ? (float)$row['total_collection_amount'] : null;
            $row['total_pending_amount'] = (float)$row['total_pending_amount'];
            $row['total_due_amount'] = (float)$row['total_due_amount'];
            $row['total_collection_months'] = $row['total_collection_months'] !== null ? (int)$row['total_collection_months'] : null;
            $row['total_pending_months'] = (int)$row['total_pending_months'];
            $row['total_due_months'] = (int)$row['total_due_months'];

            $area_name = $row['area_name'] ?? 'Unknown'; // Fallback if area_name is null
            if (!isset($grouped_data[$area_name])) {
                $grouped_data[$area_name] = [];
            }
            $grouped_data[$area_name][] = $row; // Add customer to the respective area group
        }

        // Structure the output
        $output["body"]["customer"] = $grouped_data;
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = $result->num_rows > 0 ? "Success" : "No customers found for this staff";
        $stmt->close();
    }
} else if (isset($obj->action) && $obj->action === 'monthly_report') {
    // Query all records from monthly_box_history
    $sql = "SELECT `year`, `month`, `total_boxes`, `active_boxes`, `disconnect_boxes` AS disconnected_boxes, `total_collection`
            FROM `monthly_box_history`
            ORDER BY `year` DESC, `month` DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();

    $report = [];
    while ($row = $result->fetch_assoc()) {
        $report[] = [
            'year' => (int)$row['year'],
            'month' => (int)$row['month'],
            'total_boxes' => (int)$row['total_boxes'],
            'active_boxes' => (int)$row['active_boxes'],
            'disconnected_boxes' => (int)$row['disconnected_boxes'],
            'total_collection' => (float)$row['total_collection']
        ];
    }
    $stmt->close();

    $output["head"]["code"] = 200;
    $output["head"]["msg"] = "Success";
    $output["body"]["report"] = $report;

    echo json_encode($output, JSON_NUMERIC_CHECK);
    exit();
} else {
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter Mismatch";
    $output["head"]["inputs"] = $obj;
}

echo json_encode($output, JSON_NUMERIC_CHECK);
$conn->close();
