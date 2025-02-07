<?php
// Prevent any output
ob_start();

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

require_once 'config/database.php';
require_once 'includes/functions.php';

session_start();

try {
    // Validate required data
    if (!isset($_POST['confirm_import']) || !isset($_POST['file_info']) || !isset($_POST['preview_data'])) {
        throw new Exception('Missing required import data');
    }

    // Decode the JSON data
    $file_info = json_decode($_POST['file_info'], true);
    $preview_data = json_decode($_POST['preview_data'], true);

    if (!$file_info || !$preview_data) {
        throw new Exception('Invalid import data format');
    }

    // Store in session for compatibility with existing code
    $_SESSION['import_preview'] = $preview_data;

    // Process the import
    $result = processVisaQueueImport(
        $file_info,
        $file_info['delimiter']
    );
    
    // Clean up the temporary file if it exists
    if (isset($file_info['tmp_name']) && file_exists($file_info['tmp_name'])) {
        cleanupImportFile($file_info['tmp_name']);
    }

    // Clear the preview data
    unset($_SESSION['import_preview']);
    
    // Return success response
    echo json_encode([
        'status' => 'success',
        'message' => $result['message'] ?? 'Import completed successfully',
        'complete' => true
    ]);
    
} catch (Exception $e) {
    error_log("Import error: " . $e->getMessage());
    error_log("POST data: " . print_r($_POST, true));
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Import failed: ' . $e->getMessage(),
        'complete' => true
    ]);
}

exit(); 