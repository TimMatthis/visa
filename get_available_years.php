<?php
require_once 'config/database.php';

header('Content-Type: application/json');

try {
    $query = "
        SELECT DISTINCT financial_year_start 
        FROM visa_allocations 
        ORDER BY financial_year_start DESC
    ";
    
    $result = mysqli_query($conn, $query);
    $years = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $years[] = intval($row['financial_year_start']);
    }
    
    // Add current and next FY if not in list
    $currentFY = getCurrentFinancialYearDates()['fy_start_year'];
    if (!in_array($currentFY, $years)) {
        $years[] = $currentFY;
    }
    
    echo json_encode([
        'status' => 'success',
        'years' => $years
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} 