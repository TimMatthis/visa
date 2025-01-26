<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

if (!isset($_GET['visa_type_id']) || !isset($_GET['application_date'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

$visa_type_id = $_GET['visa_type_id'];
$application_date = $_GET['application_date'];

try {
    $stmt = $pdo->prepare("
        WITH LatestUpdate AS (
            SELECT MAX(update_month) as latest_update
            FROM visa_queue_updates qu
            JOIN visa_lodgements l ON l.id = qu.lodged_month_id
            WHERE l.visa_type_id = ?
        )
        SELECT 
            l.lodged_month,
            qu.queue_count
        FROM visa_queue_updates qu
        JOIN visa_lodgements l ON l.id = qu.lodged_month_id
        CROSS JOIN LatestUpdate lu
        WHERE l.visa_type_id = ?
        AND qu.update_month = lu.latest_update
        ORDER BY l.lodged_month
    ");
    
    $stmt->execute([$visa_type_id, $visa_type_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $before = 0;
    $after = 0;
    
    // Get first day of next month after application date
    $marker_date = new DateTime($application_date);
    $marker_date->modify('first day of next month');
    $marker_date = $marker_date->format('Y-m-d');
    
    foreach ($results as $row) {
        if ($row['lodged_month'] < $marker_date) {
            $before += $row['queue_count'];
        } else {
            $after += $row['queue_count'];
        }
    }
    
    echo json_encode([
        'before' => $before,
        'after' => $after
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} 