<?php
require_once 'config/database.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['visa_type_id']) || !isset($data['year']) || !isset($data['amount'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required parameters'
    ]);
    exit;
}

try {
    // Check if allocation exists
    $check_query = "
        SELECT id 
        FROM visa_allocations 
        WHERE visa_type_id = ? AND financial_year_start = ?
    ";
    
    $stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($stmt, "ii", $data['visa_type_id'], $data['year']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) > 0) {
        // Update existing allocation
        $query = "
            UPDATE visa_allocations 
            SET allocation_amount = ?, 
                updated_at = CURRENT_TIMESTAMP 
            WHERE visa_type_id = ? AND financial_year_start = ?
        ";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iii", $data['amount'], $data['visa_type_id'], $data['year']);
    } else {
        // Insert new allocation
        $query = "
            INSERT INTO visa_allocations 
            (visa_type_id, financial_year_start, allocation_amount) 
            VALUES (?, ?, ?)
        ";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iii", $data['visa_type_id'], $data['year'], $data['amount']);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode([
            'status' => 'success'
        ]);
    } else {
        throw new Exception(mysqli_error($conn));
    }

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} 