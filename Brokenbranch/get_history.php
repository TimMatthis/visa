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
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters']);
    exit;
}

try {
    $visa_type = $_GET['visa_type'];
    $lodged_month = $_GET['lodged_month'];
    
    // First get the visa type ID from the visa_types table
    $sql = "SELECT id FROM visa_types WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $visa_type);
    $stmt->execute();
    $result = $stmt->get_result();
    $visa_type_row = $result->fetch_assoc();
    
    if (!$visa_type_row) {
        throw new Exception('Invalid visa type');
    }
    $visa_type_id = $visa_type_row['id'];
    
    // Now get the history data using the correct column name
    $sql = "SELECT update_month, queue_count 
            FROM visa_queue_updates 
            WHERE visa_type_id = ? 
            AND lodged_month = ?
            ORDER BY update_month ASC";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('is', $visa_type_id, $lodged_month);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    
    // Ensure we always return an array, even if empty
    echo json_encode([
        'status' => 'success',
        'data' => $history
    ]);
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
    exit;
} 