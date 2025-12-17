<?php
include 'config/config.php';

header( 'Access-Control-Allow-Origin: *' );
header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );

if ( $_SERVER[ 'REQUEST_METHOD' ] == 'OPTIONS' ) {
    http_response_code( 200 );
    exit();
}

$output = array( 'head' => array( 'code' => 400, 'msg' => 'Parameter is Mismatch' ), 'body' => array() );
$json = file_get_contents( 'php://input' );
$obj = json_decode( $json, true );
date_default_timezone_set( 'Asia/Calcutta' );
$timestamp = date( 'Y-m-d H:i:s' );

if ( isset( $obj[ 'search_text' ] ) ) {
    $search_text = $obj[ 'search_text' ];
    $sql = 'SELECT * FROM `area` WHERE `deleted_at` = 0';
    $result = $conn->query( $sql );
    $output[ 'body' ][ 'area' ] = $result->num_rows > 0 ? $result->fetch_all( MYSQLI_ASSOC ) : [];

    $sql = 'SELECT * FROM `plan` WHERE `deleted_at` = 0';
    $result = $conn->query( $sql );
    $output[ 'body' ][ 'plan' ] = $result->num_rows > 0 ? $result->fetch_all( MYSQLI_ASSOC ) : [];

    $sql = 'SELECT * FROM `customer` WHERE `deleted_at` = 0';
    $result = $conn->query( $sql );
    $output[ 'body' ][ 'customer' ] = $result->num_rows > 0 ? $result->fetch_all( MYSQLI_ASSOC ) : [];

    $sql = "SELECT 
                c.`id`, c.`collection_id`, c.`collection_paid_date`, c.`customer_id`, cu.`customer_no`, 
                cu.`name`, cu.`phone`, cu.`address`, c.`area_id`, a.`area_name`, c.`box_no`, 
                c.`plan_id`, p.`plan_name`, p.`plan_prize`, c.`staff_id`, s.`staff_name`, 
                c.`entry_amount`, c.`payment_method`,c.`total_pending_amount`,c.`balance_amount`, c.`paid_by`, c.`paid_by_name`, 
                c.`create_at`, c.`deleted_at`, c.`edited_by`, c.`edited_by_name` 
            FROM `collection` c
            LEFT JOIN `customer` cu ON c.`customer_id` = cu.`customer_id`
            LEFT JOIN `area` a ON c.`area_id` = a.`area_id`
            LEFT JOIN `plan` p ON c.`plan_id` = p.`plan_id`
            LEFT JOIN `staff` s ON c.`staff_id` = s.`staff_id`
        
        WHERE c.`deleted_at` = 0 AND (cu.`name` LIKE ? OR cu.`customer_no` LIKE ?)";
    $stmt = $conn->prepare( $sql );
    if ( $stmt ) {
        $search_param = "%$search_text%";
     $stmt->bind_param("ss", $search_param, $search_param);
        $stmt->execute();
        $result = $stmt->get_result();
        $collections = $result->num_rows > 0 ? $result->fetch_all( MYSQLI_ASSOC ) : [];
        $output[ 'body' ][ 'collection' ] = $collections;
        $output[ 'head' ][ 'code' ] = 200;
        $output[ 'head' ][ 'msg' ] = $result->num_rows > 0 ? 'Success' : 'Customer Details Not Found';
        $stmt->close();
    }

    $sql = 'SELECT * FROM `staff` WHERE `delete_at` = 0';
    $result = $conn->query( $sql );
    $output[ 'body' ][ 'staff' ] = $result->num_rows > 0 ? $result->fetch_all( MYSQLI_ASSOC ) : [];
} elseif ( isset( $obj[ 'collection_paid_date' ], $obj[ 'area_id' ], $obj[ 'customer_id' ], $obj[ 'box_no' ], $obj[ 'plan_id' ], $obj[ 'current_user_id' ] ) ) {
    $collection_paid_date = $obj[ 'collection_paid_date' ];
    $area_id = $obj[ 'area_id' ];
    $customer_id = $obj[ 'customer_id' ];
    $box_no = $obj[ 'box_no' ];
    $plan_id = $obj[ 'plan_id' ];
    $current_user_id = $obj[ 'current_user_id' ];
    $staff_id = isset( $obj[ 'staff_id' ] ) ? $obj[ 'staff_id' ] : null;
    $entry_amount = isset( $obj[ 'entry_amount' ] ) ? floatval( $obj[ 'entry_amount' ] ) : null;
    $payment_method = isset( $obj[ 'payment_method' ] ) ? $obj[ 'payment_method' ] : null;
    $total_pending_amount = isset( $obj[ 'total_pending_amount' ] ) ? floatval( $obj[ 'total_pending_amount' ] ) : null;
    $balance_amount = isset( $obj[ 'balance_amount' ] ) ? floatval( $obj[ 'balance_amount' ] ) : null;

    $area_name = '';
    $plan_name = '';
    $plan_prize = '';
    $name = '';
    $customer_no = '';
    $phone = '';
    $address = '';
    $staff_name = '';

    // Fetch customer details
    if ( !empty( $customer_id ) ) {
        $sql = 'SELECT `name`, `customer_no`, `phone`, `address`, `plan_prize` FROM `customer` WHERE `customer_id` = ? AND `deleted_at` = 0';
        $stmt_customer = $conn->prepare( $sql );
        if ( $stmt_customer ) {
            $stmt_customer->bind_param( 's', $customer_id );
            $stmt_customer->execute();
            $result = $stmt_customer->get_result();
            if ( $result->num_rows > 0 ) {
                $row = $result->fetch_assoc();
                $name = $row[ 'name' ];
                $customer_no = $row[ 'customer_no' ];
                $phone = $row[ 'phone' ];
                $address = $row[ 'address' ];
                $plan_prize = floatval( $row[ 'plan_prize' ] );
            } else {
                $output[ 'head' ][ 'code' ] = 400;
                $output[ 'head' ][ 'msg' ] = 'Invalid customer_id.';
                echo json_encode( $output, JSON_NUMERIC_CHECK );
                $conn->close();
                exit();
            }
            $stmt_customer->close();
        }
    }

    // Fetch area, plan, staff details ( unchanged )
    if ( !empty( $area_id ) ) {
        $sql = 'SELECT `area_name` FROM `area` WHERE `area_id` = ? AND `deleted_at` = 0';
        $stmt_area = $conn->prepare( $sql );
        if ( $stmt_area ) {
            $stmt_area->bind_param( 's', $area_id );
            $stmt_area->execute();
            $result = $stmt_area->get_result();
            if ( $result->num_rows > 0 ) {
                $row = $result->fetch_assoc();
                $area_name = $row[ 'area_name' ];
            }
            $stmt_area->close();
        }
    }

    if ( !empty( $plan_id ) ) {
        $sql = 'SELECT `plan_name`, `plan_prize` FROM `plan` WHERE `plan_id` = ? AND `deleted_at` = 0';
        $stmt_plan = $conn->prepare( $sql );
        if ( $stmt_plan ) {
            $stmt_plan->bind_param( 's', $plan_id );
            $stmt_plan->execute();
            $result = $stmt_plan->get_result();
            if ( $result->num_rows > 0 ) {
                $row = $result->fetch_assoc();
                $plan_name = $row[ 'plan_name' ];
                $plan_prize = floatval( $row[ 'plan_prize' ] );
            }
            $stmt_plan->close();
        }
    }

    if ( !empty( $staff_id ) ) {
        $sql = 'SELECT `staff_name` FROM `staff` WHERE `staff_id` = ? AND `delete_at` = 0';
        $stmt_staff = $conn->prepare( $sql );
        if ( $stmt_staff ) {
            $stmt_staff->bind_param( 's', $staff_id );
            $stmt_staff->execute();
            $result = $stmt_staff->get_result();
            if ( $result->num_rows > 0 ) {
                $row = $result->fetch_assoc();
                $staff_name = $row[ 'staff_name' ];
            }
            $stmt_staff->close();
        }
    }

    if ( !empty( $collection_paid_date ) && !empty( $area_id ) && !empty( $customer_id ) && !empty( $plan_id ) && !empty( $box_no ) && !empty( $current_user_id ) && !empty( $entry_amount ) && !empty( $plan_prize ) ) {
        $current_user_name = getUserName( $current_user_id );
        if ( !empty( $current_user_name ) ) {
            $total_collection_months = $entry_amount / $plan_prize;
            // Still calculate for customer table

            // **Validations**
            $month = date( 'Y-m', strtotime( $collection_paid_date ) );
            // Month: YYYY-MM
            $day = date( 'Y-m-d', strtotime( $collection_paid_date ) );
            // Day: YYYY-MM-DD

            // 1. Check same day collection exists? ( one per day )
            $sql_day_check = 'SELECT COUNT(*) as count FROM `collection` WHERE `customer_id` = ? AND DATE(`collection_paid_date`) = ? AND `deleted_at` = 0';
            $stmt_day = $conn->prepare( $sql_day_check );
            $day_count = 0;
            if ( $stmt_day ) {
                $stmt_day->bind_param( 'ss', $customer_id, $day );
                $stmt_day->execute();
                $result_day = $stmt_day->get_result();
                $row_day = $result_day->fetch_assoc();
                $day_count = $row_day[ 'count' ];
                $stmt_day->close();
            }

            $is_edit_mode = isset( $obj[ 'edit_collection_id' ] );
            $edit_id = $is_edit_mode ? $obj[ 'edit_collection_id' ] : null;

            if ( $day_count > 0 && ( !$is_edit_mode || $day_count > 1 ) ) {
                $output[ 'head' ][ 'code' ] = 400;
                $output[ 'head' ][ 'msg' ] = 'One collection per day per customer. Use update mode.';
                echo json_encode( $output, JSON_NUMERIC_CHECK );
                $conn->close();
                exit();
            }

            // 2. Same user check ( for partial payments )
            $sql_month_check = "
                SELECT c.paid_by, u.name AS paid_by_name 
                FROM `collection` c 
                LEFT JOIN `user` u ON c.paid_by = u.user_id 
                WHERE c.customer_id = ? 
                AND DATE_FORMAT(c.collection_paid_date, '%Y-%m') = ? 
                AND c.deleted_at = 0 
                LIMIT 1";

            $stmt_month = $conn->prepare( $sql_month_check );
            $previous_paid_by = null;
            $previous_paid_by_name = null;
            if ( $stmt_month ) {
                $stmt_month->bind_param( 'ss', $customer_id, $month );
                $stmt_month->execute();
                $result_month = $stmt_month->get_result();
                if ( $result_month->num_rows > 0 ) {
                    $row_month = $result_month->fetch_assoc();
                    $previous_paid_by = $row_month[ 'paid_by' ];
                    $previous_paid_by_name = $row_month[ 'paid_by_name' ] ?? 'Unknown';
                }
                $stmt_month->close();
            }

            if ( $previous_paid_by && $previous_paid_by !== $current_user_id ) {
                $output[ 'head' ][ 'code' ] = 400;
                $output[ 'head' ][ 'msg' ] = "Balance can only be collected by the same user/staff who started the month. Previous collector: $previous_paid_by_name";
                echo json_encode( $output, JSON_NUMERIC_CHECK );
                $conn->close();
                exit();
            }

            // **No sum check** ( Removed: total_month_collected <= plan_prize )

            if ( $is_edit_mode ) {
                // Edit mode
                $sql_prev = 'SELECT `entry_amount`, `paid_by`, `created_by_id` FROM `collection` WHERE `collection_id` = ? AND `deleted_at` = 0';
                $stmt_prev = $conn->prepare( $sql_prev );
                $previous_entry_amount = 0;
                $old_collection = null;
                if ( $stmt_prev ) {
                    $stmt_prev->bind_param( 's', $edit_id );
                    $stmt_prev->execute();
                    $result_prev = $stmt_prev->get_result();
                    if ( $result_prev->num_rows > 0 ) {
                        $old_collection = $result_prev->fetch_assoc();
                        $previous_entry_amount = floatval( $old_collection[ 'entry_amount' ] );
                    }
                    $stmt_prev->close();
                }

                $previous_collection_months = $previous_entry_amount / $plan_prize;

                $sql = 'UPDATE `collection` SET `collection_paid_date`=?, `area_id`=?, `area_name`=?, `customer_id`=?, `customer_no`=?, `name`=?, `phone`=?, `address`=?, `box_no`=?, `plan_id`=?, `plan_name`=?, `plan_prize`=?, `staff_id`=?, `staff_name`=?, `entry_amount`=?, `payment_method`=?, `total_pending_amount`=?, `balance_amount`=?, `paid_by`=?, `paid_by_name`=?, `edited_by`=?, `edited_by_name`=? WHERE `collection_id`=?';
                $stmt_update = $conn->prepare( $sql );
                if ( $stmt_update ) {
                    $stmt_update->bind_param( 'sssssssssssssssssssssss', $collection_paid_date, $area_id, $area_name, $customer_id, $customer_no, $name, $phone, $address, $box_no, $plan_id, $plan_name, $plan_prize, $staff_id, $staff_name, $entry_amount, $payment_method, $total_pending_amount, $balance_amount, $current_user_id, $current_user_name, $current_user_id, $current_user_name, $edit_id );
                    if ( $stmt_update->execute() ) {
                        $sql = "UPDATE `customer` SET 
                                `total_collection_amount` = COALESCE(`total_collection_amount`, 0) - ? + ?, 
                                `total_collection_months` = COALESCE(`total_collection_months`, 0) - ? + ?,
                                `total_pending_amount` = COALESCE(`total_pending_amount`, 0) + ? - ?,
                                `total_pending_months` = COALESCE(`total_pending_months`, 0) + ? - ?
                                WHERE `customer_id` = ?";
                        $stmt_customer_update = $conn->prepare( $sql );
                        if ( $stmt_customer_update ) {
                            $stmt_customer_update->bind_param( 'dddddddds', $previous_entry_amount, $entry_amount, $previous_collection_months, $total_collection_months, $previous_entry_amount, $entry_amount, $previous_collection_months, $total_collection_months, $customer_id );
                            if ( $stmt_customer_update->execute() ) {
                                $new_collection = [
                                    'collection_paid_date' => $collection_paid_date, 'area_id' => $area_id, 'area_name' => $area_name,
                                    'customer_id' => $customer_id, 'customer_no' => $customer_no, 'name' => $name, 'phone' => $phone,
                                    'address' => $address, 'box_no' => $box_no, 'plan_id' => $plan_id, 'plan_name' => $plan_name,
                                    'plan_prize' => $plan_prize, 'staff_id' => $staff_id, 'staff_name' => $staff_name,
                                    'entry_amount' => $entry_amount, 'payment_method' => $payment_method,
                                    'total_pending_amount' => $total_pending_amount, 'balance_amount' => $balance_amount,
                                    'created_by_id' => $old_collection[ 'created_by_id' ]
                                ];
                                logCustomerHistory( $customer_id, $customer_no, 'collection_update', $old_collection, $new_collection, "Collection updated by $current_user_name" );
                                $year = date( 'Y', strtotime( $collection_paid_date ) );
                                $month_num = date( 'm', strtotime( $collection_paid_date ) );
                                $first_day_of_month = date( 'Y-m-01', strtotime( $collection_paid_date ) );
                                updateMonthlyBoxHistory( $conn, $year, $month_num, $first_day_of_month, $collection_paid_date );
                                $output[ 'head' ][ 'code' ] = 200;
                                $output[ 'head' ][ 'msg' ] = 'Successfully Collection Details Updated';
                            } else {
                                $output[ 'head' ][ 'code' ] = 400;
                                $output[ 'head' ][ 'msg' ] = 'Failed to update customer table: ' . $conn->error;
                            }
                            $stmt_customer_update->close();
                        }
                        $stmt_update->close();
                    } else {
                        $output[ 'head' ][ 'code' ] = 400;
                        $output[ 'head' ][ 'msg' ] = 'Failed to update collection: ' . $conn->error;
                    }
                }
            } else {
                // Insert mode
                $sql = "INSERT INTO `collection` (`collection_paid_date`, `area_id`, `area_name`, `customer_id`, `customer_no`, `name`, `phone`, `address`, `box_no`, `plan_id`, `plan_name`, `plan_prize`, `staff_id`, `staff_name`, `entry_amount`, `payment_method`, `total_pending_amount`, `balance_amount`, `paid_by`, `paid_by_name`, `create_at`, `deleted_at`, `created_by_id`) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)";
                $stmt_insert = $conn->prepare( $sql );
                if ( $stmt_insert ) {
                    $stmt_insert->bind_param( 'ssssssssssssssssssssss', $collection_paid_date, $area_id, $area_name, $customer_id, $customer_no, $name, $phone, $address, $box_no, $plan_id, $plan_name, $plan_prize, $staff_id, $staff_name, $entry_amount, $payment_method, $total_pending_amount, $balance_amount, $current_user_id, $current_user_name, $timestamp, $current_user_id );
                    if ( $stmt_insert->execute() ) {
                        $coll_id = $conn->insert_id;
                        $enIdcoll = uniqueID( 'Collection', $coll_id );
                        $sql_update = 'UPDATE `collection` SET `collection_id` = ? WHERE `id` = ?';
                        $stmt_coll_id = $conn->prepare( $sql_update );
                        if ( $stmt_coll_id ) {
                            $stmt_coll_id->bind_param( 'si', $enIdcoll, $coll_id );
                            $stmt_coll_id->execute();
                            $stmt_coll_id->close();
                        }

                        $sql = "UPDATE `customer` SET 
                                `total_collection_amount` = COALESCE(`total_collection_amount`, 0) + ?, 
                                `total_collection_months` = COALESCE(`total_collection_months`, 0) + ?,
                                `total_pending_amount` = COALESCE(`total_pending_amount`, 0) - ?,
                                `total_pending_months` = COALESCE(`total_pending_months`, 0) - ?
                                WHERE `customer_id` = ?";
                        $stmt_customer_update = $conn->prepare( $sql );
                        if ( $stmt_customer_update ) {
                            $stmt_customer_update->bind_param( 'dddds', $entry_amount, $total_collection_months, $entry_amount, $total_collection_months, $customer_id );
                            if ( $stmt_customer_update->execute() ) {
                                $new_collection = [
                                    'collection_id' => $enIdcoll, 'collection_paid_date' => $collection_paid_date, 'area_id' => $area_id,
                                    'area_name' => $area_name, 'customer_id' => $customer_id, 'customer_no' => $customer_no,
                                    'name' => $name, 'phone' => $phone, 'address' => $address, 'box_no' => $box_no,
                                    'plan_id' => $plan_id, 'plan_name' => $plan_name, 'plan_prize' => $plan_prize,
                                    'staff_id' => $staff_id, 'staff_name' => $staff_name, 'entry_amount' => $entry_amount,
                                    'payment_method' => $payment_method, 'total_pending_amount' => $total_pending_amount,
                                    'balance_amount' => $balance_amount, 'created_by_id' => $current_user_id
                                ];
                                logCustomerHistory( $customer_id, $customer_no, 'collection_create', null, $new_collection, "Collection created by $current_user_name" );
                                $year = date( 'Y', strtotime( $collection_paid_date ) );
                                $month_num = date( 'm', strtotime( $collection_paid_date ) );
                                $first_day_of_month = date( 'Y-m-01', strtotime( $collection_paid_date ) );
                                updateMonthlyBoxHistory( $conn, $year, $month_num, $first_day_of_month, $collection_paid_date );
                                $output[ 'head' ][ 'code' ] = 200;
                                $output[ 'head' ][ 'msg' ] = 'Successfully Collection Created';
                            } else {
                                $output[ 'head' ][ 'code' ] = 400;
                                $output[ 'head' ][ 'msg' ] = 'Failed to update customer table: ' . $conn->error;
                            }
                            $stmt_customer_update->close();
                        }
                        $stmt_insert->close();
                    } else {
                        $output[ 'head' ][ 'code' ] = 400;
                        $output[ 'head' ][ 'msg' ] = 'Failed to create collection: ' . $conn->error;
                    }
                }
            }
        } else {
            $output[ 'head' ][ 'code' ] = 400;
            $output[ 'head' ][ 'msg' ] = 'User not found.';
        }
    } else {
        $output[ 'head' ][ 'code' ] = 400;
        $output[ 'head' ][ 'msg' ] = 'Please provide all required details.';
    }
} elseif ( isset( $obj[ 'delete_collection_id' ], $obj[ 'current_user_id' ] ) ) {
    $delete_collection_id = $obj[ 'delete_collection_id' ];
    $current_user_id = $obj[ 'current_user_id' ];

    if ( !empty( $delete_collection_id ) && !empty( $current_user_id ) ) {
        $sql = 'SELECT `entry_amount`, `customer_id`, `customer_no`, `plan_prize`, `total_pending_amount`, `balance_amount`, `created_by_id`, `collection_paid_date` FROM `collection` WHERE `collection_id` = ? AND `deleted_at` = 0';
        $stmt_fetch = $conn->prepare( $sql );
        if ( $stmt_fetch ) {
            $stmt_fetch->bind_param( 's', $delete_collection_id );
            $stmt_fetch->execute();
            $result = $stmt_fetch->get_result();
            if ( $result->num_rows > 0 ) {
                $row = $result->fetch_assoc();
                $entry_amount = floatval( $row[ 'entry_amount' ] );
                $customer_id = $row[ 'customer_id' ];
                $customer_no = $row[ 'customer_no' ];
                $plan_prize = floatval( $row[ 'plan_prize' ] );
                $total_pending_amount = floatval( $row[ 'total_pending_amount' ] );

                $balance_amount = floatval( $row[ 'balance_amount' ] );

                $collection_paid_date = $row[ 'collection_paid_date' ];
                $months = $entry_amount / $plan_prize;

                $old_collection = [
                    'collection_id' => $delete_collection_id, 'entry_amount' => $entry_amount,
                    'customer_id' => $customer_id, 'customer_no' => $customer_no,
                    'total_pending_amount' => $total_pending_amount,
                    'balance_amount' => $balance_amount,
                    'created_by_id' => $row[ 'created_by_id' ]
                ];

                $sql = "UPDATE `customer` SET 
                        `total_collection_amount` = COALESCE(`total_collection_amount`, 0) - ?, 
                        `total_collection_months` = COALESCE(`total_collection_months`, 0) - ?,
                        `total_pending_amount` = COALESCE(`total_pending_amount`, 0) + ?,
                        `total_pending_months` = COALESCE(`total_pending_months`, 0) + ?
                        WHERE `customer_id` = ?";
                $stmt_adjust = $conn->prepare( $sql );
                if ( $stmt_adjust ) {
                    $stmt_adjust->bind_param( 'dddds', $entry_amount, $months, $entry_amount, $months, $customer_id );
                    $stmt_adjust->execute();
                    $stmt_adjust->close();
                }

                $sql = 'UPDATE `collection` SET `deleted_at` = 1 WHERE `collection_id` = ?';
                $stmt = $conn->prepare( $sql );
                if ( $stmt ) {
                    $stmt->bind_param( 's', $delete_collection_id );
                    if ( $stmt->execute() ) {
                        // Log history for collection deletion
                        $current_user_name = getUserName( $current_user_id );
                        logCustomerHistory( $customer_id, $customer_no, 'collection_delete', $old_collection, null, "Collection deleted by $current_user_name" );
                        $year = date( 'Y', strtotime( $collection_paid_date ) );
                        $month_num = date( 'm', strtotime( $collection_paid_date ) );
                        $first_day_of_month = date( 'Y-m-01', strtotime( $collection_paid_date ) );
                        updateMonthlyBoxHistory( $conn, $year, $month_num, $first_day_of_month, $collection_paid_date );
                        $output[ 'head' ][ 'code' ] = 200;
                        $output[ 'head' ][ 'msg' ] = 'Successfully Collection Entry deleted!';
                    } else {
                        $output[ 'head' ][ 'code' ] = 400;
                        $output[ 'head' ][ 'msg' ] = 'Failed to delete collection: ' . $conn->error;
                    }
                    $stmt->close();
                }
            } else {
                $output[ 'head' ][ 'code' ] = 400;
                $output[ 'head' ][ 'msg' ] = 'Collection not found or already deleted.';
            }
            $stmt_fetch->close();
        }
    } else {
        $output[ 'head' ][ 'code' ] = 400;
        $output[ 'head' ][ 'msg' ] = 'Please provide all required details.';
    }
}

echo json_encode( $output, JSON_NUMERIC_CHECK );
$conn->close();
?>