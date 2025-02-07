<?php
// Turn off error reporting for this file
error_reporting(0);

// Ensure no whitespace or output before this point
if (ob_get_level()) ob_end_clean();

// Set strict headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

try {
    // Include files after headers
    require_once 'config/database.php';
    require_once 'includes/functions.php';

    // Get feedback data
    $feedback = getUserFeedback();
    
    // Ensure we have valid data structure
    if (!is_array($feedback)) {
        throw new Exception('Invalid feedback data structure');
    }
    
    // Encode with error checking
    $json = json_encode($feedback);
    if ($json === false) {
        throw new Exception('JSON encoding failed: ' . json_last_error_msg());
    }
    
    echo $json;
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to load feedback: ' . $e->getMessage()
    ]);
}

// Ensure no further output
exit; 