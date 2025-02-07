<?php
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_GET['visa_type_id'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing visa type ID'
    ]);
    exit;
}

$visa_type_id = $_GET['visa_type_id'];

try {
    $query = "
        SELECT financial_year_start, allocation_amount
        FROM visa_allocations
        WHERE visa_type_id = ?
        ORDER BY financial_year_start DESC
    ";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $visa_type_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $allocations = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $allocations[] = [
            'financial_year_start' => intval($row['financial_year_start']),
            'allocation_amount' => intval($row['allocation_amount'])
        ];
    }
    
    echo json_encode([
        'status' => 'success',
        'allocations' => $allocations
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} 