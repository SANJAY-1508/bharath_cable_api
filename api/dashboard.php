<?php
include 'config/config.php';
// At the top of login.php (before any output)
header("Access-Control-Allow-Origin: *"); // Or restrict to http://localhost:53075
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
    
    $sql = "SELECT COUNT(id) AS userCount FROM `user` WHERE deleted_at =0;";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $output["body"]["userCount"][] = $row['userCount'];
        }
    } else {
        $output["body"]["userCount"] = [];
    }
    $sql = "SELECT COUNT(id) AS areaCount FROM `area` WHERE `deleted_at` = 0";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $output["body"]["areaCount"][] = $row["areaCount"];
        }
    } else {
        $output["body"]["areaCount"] = [];
    }
    $sql = "SELECT COUNT(id) AS planCount FROM `plan` WHERE `deleted_at` = 0";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $output["body"]["planCount"][] = $row["planCount"];
        }
    } else {
        $output["body"]["planCount"] = [];
    }
    $sql = "SELECT COUNT(id) AS customerCount FROM `customer` WHERE `deleted_at` = 0";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $output["body"]["customerCount"][] = $row["customerCount"];
        }
    } else {
        $output["body"]["customerCount"] = [];
    }
    $sql = "SELECT COUNT(id) AS collectionCount FROM `collection` WHERE `deleted_at`=0";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $output["head"]["code"] = 200;
            $output["head"]["msg"] = "Success";
            $output["body"]["collectionCount"][$count] = $row["collectionCount"];
            $count++;
        }
    } else {
        $output["head"]["code"] = 200;
        $output["head"]["msg"] = "collectionCount Count Not Found";
        $output["body"]["collectionCount"] = [];
    }
}else{
      $output["head"]["code"] = 400;
    $output["head"]["msg"] = "Parameter is Mismatch";
    $output["head"]["inputs"] = $obj;
}
echo json_encode($output, JSON_NUMERIC_CHECK);
?>