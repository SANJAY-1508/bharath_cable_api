<?php
$name = "localhost";
$username = "root";
$password = "";
$database = "cable";

$conn = new mysqli($name, $username, $password, $database);

if ($conn->connect_error) {
    $output = array();
    $output["head"]["code"] = 400;
    $output["head"]["msg"] = "DB Connection Lost...";

    echo json_encode($output, JSON_NUMERIC_CHECK);
};


// <<<<<<<<<<===================== Function For Check Numbers Only =====================>>>>>>>>>>

function numericCheck($data)
{
    if (!preg_match('/[^0-9]+/', $data)) {
        return true;
    } else {
        return false;
    }
}

// <<<<<<<<<<===================== Function For Check Alphabets Only =====================>>>>>>>>>>

function alphaCheck($data)
{
    if (!preg_match('/[^a-zA-Z]+/', $data)) {
        return true;
    } else {
        return false;
    }
}

// <<<<<<<<<<===================== Function For Check Alphabets and Numbers Only =====================>>>>>>>>>>

function alphaNumericCheck($data)
{
    if (!preg_match('/[^a-zA-Z0-9]+/', $data)) {
        return true;
    } else {
        return false;
    }
}

// <<<<<<<<<<===================== Function for checking user exist or not =====================>>>>>>>>>>
function userExist($user)
{
    global $conn;

    $checkUser = $conn->query("SELECT `name` FROM `user` WHERE `user_id`='$user'");
    if ($checkUser->num_rows > 0) {
        return true;
    } else {
        return false;
    }
}

function staffExist($staff)
{
    global $conn;

    $checkUser = $conn->query("SELECT `staff_name` FROM `staff` WHERE `id`='$staff'");
    if ($checkUser->num_rows > 0) {
        return true;
    } else {
        return false;
    }
}

function customerExist($customer)
{
    global $conn;

    $checkUser = $conn->query("SELECT `customer_name` FROM `customer` WHERE `id`='$customer'");
    if ($checkUser->num_rows > 0) {
        return true;
    } else {
        return false;
    }
}

// <<<<<<<<<<===================== Function for get the username =====================>>>>>>>>>>
function getUserName($user)
{
    global $conn; // assuming $conn is your mysqli connection
    $result = "";

    // Check in the `user` table first
    $checkUser = $conn->query("SELECT `name` FROM `user` WHERE `user_id`='$user' LIMIT 1");
    if ($checkUser && $checkUser->num_rows > 0) {
        $userData = $checkUser->fetch_assoc();
        $result = $userData['name'];
    } else {
        // If not found, check in the `staff` table
        $checkStaff = $conn->query("SELECT `staff_name` FROM `staff` WHERE `staff_id`='$user' LIMIT 1");
        if ($checkStaff && $checkStaff->num_rows > 0) {
            $staffData = $checkStaff->fetch_assoc();
            $result = $staffData['staff_name'];
        }
    }

    return $result;
}


function convertUniqueName($value)
{

    $value = str_replace(' ', '', $value);
    $value = strtolower($value);

    return $value;
}

function getCategoryId($namecategory)
{
    global $conn;

    $Getcategory_id = $conn->query("SELECT `id` FROM `category` WHERE name LIKE '%$namecategory%'");
    $row = $Getcategory_id->fetch_row();
    $category_id = $row[0];

    return $category_id;
}
function generateReceiptNo()
{
    global $conn;

    // Query to get the maximum receipt number from the database
    $sql = "SELECT MAX(CAST(recipt_no AS UNSIGNED)) AS max_recipt_no FROM pawnjewelry";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastReciptNo = $row['max_recipt_no'];

        if ($lastReciptNo) {
            // Increment the last receipt number by 1
            $newReciptNo = intval($lastReciptNo) + 1;
        } else {
            // If no valid receipt number is found, start with 1001
            $newReciptNo = '101';
        }
    } else {
        // If no records are found, start with 1001
        $newReciptNo = '101';
    }

    return $newReciptNo;
}



function generateReceiptesitmateNo()
{
    global $conn;

    // Query to get the maximum receipt number from the database
    $sql = "SELECT MAX(CAST(recipt_no AS UNSIGNED)) AS max_recipt_no FROM pawnjewelry_estimate";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastReciptNo = $row['max_recipt_no'];

        if ($lastReciptNo) {

            // Increment the last receipt number by 1
            $newReciptNo = intval($lastReciptNo) + 1;
        } else {
            // If no valid receipt number is found, start with 001
            $newReciptNo = '101';
        }
    } else {
        // If no records are found, start with 001
        $newReciptNo = '101';
    }

    return $newReciptNo;
}


function getGroupName($GroupID)
{
    global $conn;

    $sql_Group = $conn->query("SELECT `Group_type` FROM `groups` WHERE `Group_id` = '$GroupID' AND `delete_at` ='0'");
    if ($sql_Group->num_rows > 0) {
        $row = $sql_Group->fetch_row();
        $Group_type = $row[0];

        return $Group_type;
    } else {
        return null;
    }
}

function getCategoryName($CategoryID)
{
    global $conn;

    $sql_Group = $conn->query("SELECT `Category_type` FROM `category` WHERE `category_id` = '$CategoryID' AND `delete_at` ='0'");
    if ($sql_Group->num_rows > 0) {
        $row = $sql_Group->fetch_row();
        $Category_type = $row[0];

        return $Category_type;
    } else {
        return null;
    }
}

function pngImageToWebP($data, $file_path)
{
    // Check if the GD extension is available
    if (!extension_loaded('gd')) {
        echo 'GD extension is not available. Please install or enable the GD extension.';
        return false;
    }

    // Decode the base64 image data
    $imageData = base64_decode($data);

    // Create an image resource from the PNG data
    $sourceImage = imagecreatefromstring($imageData);

    if ($sourceImage === false) {
        echo 'Failed to create the source image.';
        return false;
    }
    //dyanamic file path
    date_default_timezone_set('Asia/Calcutta');

    $timestamp = date('Y-m-d H:i:s');

    $timestamp = str_replace(array(" ", ":"), "-", $timestamp);

    $file_pathnew = $file_path . $timestamp . ".webp";

    $retunfilename = $timestamp . ".webp";
    try {
        // Convert PNG to WebP
        if (!imagewebp($sourceImage, $file_pathnew, 80)) {
            echo 'Failed to convert PNG to WebP.';
            return false;
        }
    } catch (\Throwable $th) {
        echo $th;
    }



    // Free up memory
    imagedestroy($sourceImage);

    //echo 'WebP image saved successfully.';
    return $retunfilename;
}

function isBase64ImageValid($base64Image)
{
    // Check if the provided string is a valid base64 string
    if (!preg_match('/^(data:image\/(png|jpeg|jpg|gif);base64,)/', $base64Image)) {
        return false;
    }

    // Remove the data URI prefix
    $base64Image = str_replace('data:image/png;base64,', '', $base64Image);
    $base64Image = str_replace('data:image/jpeg;base64,', '', $base64Image);
    $base64Image = str_replace('data:image/jpg;base64,', '', $base64Image);
    $base64Image = str_replace('data:image/gif;base64,', '', $base64Image);

    // Check if the remaining string is a valid base64 string
    if (!base64_decode($base64Image, true)) {
        return false;
    }

    // Check if the decoded data is a valid image
    $image = imagecreatefromstring(base64_decode($base64Image));
    if (!$image) {
        return false;
    }

    // Clean up resources
    imagedestroy($image);

    return true;
}


function ImageRemove($string, $id)
{
    global $conn;
    $status = "No Data Updated";
    if ($string == "user") {
        $sql_user = "UPDATE `user` SET `img`=null WHERE `id` ='$id' ";
        if ($conn->query($sql_user) === TRUE) {
            $status = "User Image Removed Successfully";
        } else {
            $status = "User Image Not Removed !";
        }
    } else if ($string == "staff") {
        $sql_staff = "UPDATE `staff` SET `img`=null WHERE `id`='$id' ";
        if ($conn->query($sql_staff) === TRUE) {
            $status = "staff Image Removed Successfully";
        } else {
            $status = "staff Image Not Removed !";
        }
    } else if ($string == "company") {
        $sql_company = "UPDATE `company` SET  `img`=null WHERE `id`='$id' ";
        if ($conn->query($sql_company) === TRUE) {
            $status = "company Image Removed Successfully";
        } else {
            $status = "company Image Not Removed !";
        }
    } else if ($string == "product") {
        $sql_products = " UPDATE `products` SET `img`=null WHERE `id`='$id' ";
        if ($conn->query($sql_products) === TRUE) {
            $status = "products Image Removed Successfully";
        } else {
            $status = "products Image Not Removed !";
        }
    }
    return $status;
}
function uniqueID($prefix_name, $auto_increment_id)
{

    date_default_timezone_set('Asia/Calcutta');
    $timestamp = date('Y-m-d H:i:s');
    $encryptId = $prefix_name . "_" . $timestamp . "_" . $auto_increment_id;

    $hashid = md5($encryptId);

    return $hashid;
}



// Function to generate unique staff_no
function generateStaffNo($name)
{
    global $conn;
    $prefix = substr(strtolower($name), 0, 4); // Get first 4 letters of name
    $prefix = preg_replace("/[^a-z]/", "", $prefix); // Remove non-alphabetic characters
    if (strlen($prefix) < 4) {
        $prefix = str_pad($prefix, 4, 'x'); // Pad with 'x' if name is shorter than 4 letters
    }

    // Query to find the highest numeric part across all staff_no
    $sql = "SELECT `staff_no` FROM `staff` WHERE `delete_at`=0 ORDER BY CAST(SUBSTRING(`staff_no`, 5) AS UNSIGNED) DESC LIMIT 1";
    $result = $conn->query($sql);

    $nextNumber = 1;
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastStaffNo = $row['staff_no'];
        $lastNumber = (int)substr($lastStaffNo, 4); // Extract numeric part (e.g., 001 from pand001)
        $nextNumber = $lastNumber + 1;
    }

    return strtoupper($prefix) . str_pad($nextNumber, 3, '0', STR_PAD_LEFT); // e.g., SANJ002
}

// Function to generate unique user_no
function generateUserNo($name)
{
    global $conn;
    $prefix = substr(strtolower($name), 0, 4); // Get first 4 letters of name
    $prefix = preg_replace("/[^a-z]/", "", $prefix); // Remove non-alphabetic characters
    if (strlen($prefix) < 4) {
        $prefix = str_pad($prefix, 4, 'x'); // Pad with 'x' if name is shorter than 4 letters
    }

    // Query to find the highest numeric part across all user_no
    $sql = "SELECT `user_no` FROM `user` WHERE `deleted_at`=0 ORDER BY CAST(SUBSTRING(`user_no`, 5) AS UNSIGNED) DESC LIMIT 1";
    $result = $conn->query($sql);

    $nextNumber = 1;
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastUserNo = $row['user_no'];
        $lastNumber = (int)substr($lastUserNo, 4); // Extract numeric part (e.g., 001 from pand001)
        $nextNumber = $lastNumber + 1;
    }

    return strtoupper($prefix) . str_pad($nextNumber, 3, '0', STR_PAD_LEFT); // e.g., SANJ002
}

// Function to generate unique customer_no based on area_prefix
function generateCustomerNo($area_id)
{
    global $conn;

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
        return null; // Invalid area_id
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

    return $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT); // e.g., BHA001
}

// Function to log history in customer_history table
function logCustomerHistory($customer_id, $customer_no, $action_type, $old_value = null, $new_value = null, $remarks = null)
{
    global $conn, $timestamp;
    $old_value = $old_value ? json_encode($old_value, JSON_NUMERIC_CHECK) : null;
    $new_value = $new_value ? json_encode($new_value, JSON_NUMERIC_CHECK) : null;
    $sql = "INSERT INTO `customer_history` (`customer_id`, `customer_no`, `action_type`, `old_value`, `new_value`, `remarks`, `created_at`) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sssssss", $customer_id, $customer_no, $action_type, $old_value, $new_value, $remarks, $timestamp);
        $stmt->execute();
        $stmt->close();
    }
}



// Function to rearrange customer_no values
function rearrangeCustomerNo($new_customer_no, $area_id, $exclude_customer_id = null)
{
    global $conn, $timestamp;

    // Fetch area_prefix from area table
    $sql = "SELECT `area_prefix` FROM `area` WHERE `area_id`=? AND `deleted_at`=0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $area_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        $stmt->close();
        return false; // Invalid area_id
    }
    $row = $result->fetch_assoc();
    $prefix = strtoupper($row['area_prefix']);
    $stmt->close();

    // Fetch all customers with the same prefix, ordered by numeric part
    $sql = "SELECT `customer_id`, `customer_no` FROM `customer` WHERE `customer_no` LIKE ? AND `deleted_at`=0";
    if ($exclude_customer_id) {
        $sql .= " AND `customer_id` != ?";
    }
    $sql .= " ORDER BY CAST(SUBSTRING(`customer_no`, ?) AS UNSIGNED) ASC";
    $stmt = $conn->prepare($sql);
    $search_prefix = "$prefix%";
    $start_pos = strlen($prefix) + 1;
    if ($exclude_customer_id) {
        $stmt->bind_param("ssi", $search_prefix, $exclude_customer_id, $start_pos);
    } else {
        $stmt->bind_param("si", $search_prefix, $start_pos);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $customers = $result->num_rows > 0 ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    // Extract the numeric part of the new customer_no
    $new_number = (int)substr($new_customer_no, strlen($prefix));
    if ($new_number <= 0) {
        return false; // Invalid customer_no format
    }

    // Find if the new_customer_no already exists and collect affected customers
    $affected_customers = [];
    $conflict_found = false;
    foreach ($customers as $customer) {
        $current_number = (int)substr($customer['customer_no'], strlen($prefix));
        if ($customer['customer_no'] == $new_customer_no) {
            $conflict_found = true;
        }
        if ($conflict_found && $current_number >= $new_number) {
            $affected_customers[] = $customer;
        }
    }

    // If there's a conflict, shift the customer_no values
    if ($conflict_found) {
        foreach ($affected_customers as $index => $customer) {
            $old_customer_no = $customer['customer_no'];
            $new_numeric_part = $new_number + $index + 1;
            $new_customer_no = $prefix . str_pad($new_numeric_part, 3, '0', STR_PAD_LEFT);

            // Fetch old customer data for logging
            $sql = "SELECT * FROM `customer` WHERE `customer_id`=? AND `deleted_at`=0";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $customer['customer_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $old_customer = $result->num_rows > 0 ? $result->fetch_assoc() : null;
            $stmt->close();

            // Update customer_no
            $sql = "UPDATE `customer` SET `customer_no`=? WHERE `customer_id`=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $new_customer_no, $customer['customer_id']);
            $stmt->execute();
            $stmt->close();

            // Log the change in customer_history
            if ($old_customer) {
                $new_customer_data = $old_customer;
                $new_customer_data['customer_no'] = $new_customer_no;
                logCustomerHistory(
                    $customer['customer_id'],
                    $new_customer_no,
                    'customer_no_rearrange',
                    ['customer_no' => $old_customer_no],
                    ['customer_no' => $new_customer_no],
                    "Customer number rearranged due to conflict with $new_customer_no"
                );
            }
        }
    }

    return true;
}
