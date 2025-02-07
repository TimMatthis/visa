<?php
// Turn off error reporting for this file
error_reporting(0);
ini_set('display_errors', 0);

require_once 'config/database.php';
require_once 'includes/functions.php';

// Clear any previous output
ob_clean();

header('Content-Type: application/json');

if (!isset($_GET['visa_type']) || !isset($_GET['lodged_month'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required parameters'
    ]);
    exit;
}

$visa_type_id = $_GET['visa_type'];
$lodged_month = $_GET['lodged_month'];

try {
    // Get the lodgement ID first
    $query = "
        SELECT id 
        FROM visa_lodgements 
        WHERE visa_type_id = ? 
        AND lodged_month = ?
    ";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "is", $visa_type_id, $lodged_month);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $lodgement = mysqli_fetch_assoc($result);

    if (!$lodgement) {
        echo json_encode([
            'status' => 'error',
            'message' => 'No data found for this lodgement month'
        ]);
        exit;
    }

    // Get all updates for this lodgement
    $query = "
        SELECT 
            update_month,
            queue_count
        FROM visa_queue_updates
        WHERE lodged_month_id = ?
        ORDER BY update_month ASC
    ";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $lodgement['id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $updates = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $updates[] = [
            'update_month' => $row['update_month'],
            'queue_count' => intval($row['queue_count'])
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data' => $updates
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} 