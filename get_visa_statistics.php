<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

// Add debugging
error_log("Received request: " . print_r($_GET, true));

if (!isset($_GET['visa_type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing visa type parameter']);
    exit;
}

try {
    // Get visa type ID
    $stmt = $pdo->prepare("SELECT id FROM visa_types WHERE visa_type = ?");
    $stmt->execute([$_GET['visa_type']]);
    $visa_type_id = $stmt->fetchColumn();

    if (!$visa_type_id) {
        throw new Exception('Invalid visa type');
    }

    // Get queue summary
    $queue_summary = getVisaQueueSummary($visa_type_id);
    
    // Calculate queue position if application date is provided
    $queue_position = null;
    if (isset($_GET['application_date'])) {
        error_log("Processing application date: " . $_GET['application_date']);
        
        $application_date = new DateTime($_GET['application_date']);
        $application_day = (int)$application_date->format('d');
        $days_in_month = (int)$application_date->format('t');
        $application_month = $application_date->format('Y-m-d');
        
        error_log("Marker date: " . $application_month);

        $marker_date = new DateTime($_GET['application_date']);
        $marker_date->modify('first day of next month');
        $marker_date = $marker_date->format('Y-m-d');
        
        error_log("Marker date: " . $marker_date);

        $stmt = $pdo->prepare("
            WITH LatestUpdate AS (
                SELECT MAX(update_month) as latest_update
                FROM visa_queue_updates qu
                JOIN visa_lodgements l ON l.id = qu.lodged_month_id
                WHERE l.visa_type_id = ?
            )
            SELECT 
                l.lodged_month,
                qu.queue_count,
                qu.update_month,
                CASE 
                    WHEN DATE_FORMAT(l.lodged_month, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')
                    THEN 1 
                    ELSE 0 
                END as is_application_month
            FROM visa_queue_updates qu
            JOIN visa_lodgements l ON l.id = qu.lodged_month_id
            CROSS JOIN LatestUpdate lu
            WHERE l.visa_type_id = ?
            AND qu.update_month = lu.latest_update
            AND l.lodged_month <= ?
            ORDER BY l.lodged_month
        ");
        
        $stmt->execute([$visa_type_id, $application_month, $visa_type_id, $marker_date]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Queue results: " . print_r($results, true));
        
        $before = 0;
        $monthly_counts = [];
        $application_month_count = 0;
        foreach ($results as $row) {
            if ($row['is_application_month']) {
                // For application month, calculate proportional count
                $proportional_count = round(($row['queue_count'] / $days_in_month) * $application_day);
                $before += $proportional_count;
                $application_month_count = $row['queue_count'];
                $monthly_counts[] = [
                    'lodged_month' => $row['lodged_month'],
                    'queue_count' => (int)$row['queue_count'],
                    'proportional_count' => $proportional_count,
                    'is_application_month' => true,
                    'explanation' => sprintf(
                        "Your lodgement month - estimated %d applications ahead of you (based on day %d of %d)",
                        $proportional_count,
                        $application_day,
                        $days_in_month
                    )
                ];
            } else if ($row['lodged_month'] < $application_month) {
                $before += $row['queue_count'];
                $monthly_counts[] = [
                    'lodged_month' => $row['lodged_month'],
                    'queue_count' => (int)$row['queue_count']
                ];
            }
        }
        
        error_log("Calculated positions - Before: $before");
        
        $queue_position = [
            'before' => $before,
            'monthly_counts' => $monthly_counts
        ];
    }

    // Get processing rates
    $processing_rates = getVisaProcessingRates($visa_type_id);
    
    // Get visa allocation
    $visa_type = $_GET['visa_type'];
    $allocation_data = getVisaAllocation($visa_type);
    
    // Get processed age analysis
    $processed_age_analysis = getProcessedVisasAge($visa_type_id);
    
    // Add this before combining the response data
    $monthly_ages = getMonthlyVisaAges($visa_type_id);
    
    // Combine all data
    $response = array_merge(
        $queue_summary,
        [
            'processing_rates' => $processing_rates,
            'queue_position' => $queue_position,
            'annual_allocation' => $allocation_data['current'],
            'previous_allocation' => $allocation_data['previous'],
            'financial_year' => $allocation_data['financial_year'],
            'processed_age_analysis' => $processed_age_analysis,
            'monthly_ages' => $monthly_ages
        ]
    );

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Error in get_visa_statistics.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} 