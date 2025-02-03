<?php
include_once 'config/database.php';
include_once 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $visa_type_id = $_POST['visaType'];
    $application_year = $_POST['applicationYear'];
    $application_month = $_POST['applicationMonth'];
    $application_day = $_POST['applicationDay'];
    
    $application_date = sprintf('%04d-%02d-%02d', $application_year, $application_month, $application_day);
    
    // Get processing rate data
    $monthly_processing = getMonthlyProcessing($visa_type_id);
    $processingRate = [
        'labels' => [],
        'values' => []
    ];
    
    foreach ($monthly_processing as $month) {
        $processingRate['labels'][] = date('M Y', strtotime($month['update_month']));
        $processingRate['values'][] = $month['total_processed'];
    }
    
    // Get queue position data
    $queue_data = getQueueBreakdown($visa_type_id, $application_date);
    $queuePosition = [
        'labels' => [],
        'queueSizes' => [],
        'yourPosition' => []
    ];
    
    foreach ($queue_data['months'] as $month) {
        $queuePosition['labels'][] = date('M Y', strtotime($month['month']));
        $queuePosition['queueSizes'][] = $month['queue_size'];
        $queuePosition['yourPosition'][] = $month['month'] === $queue_data['your_month'] ? 
            $queue_data['your_position'] : null;
    }
    
    // Get processing time data
    $processing_times = getProcessingTimes($visa_type_id);
    $processingTime = [
        'labels' => [],
        'modalAges' => []
    ];
    
    foreach ($processing_times as $month) {
        $processingTime['labels'][] = date('M Y', strtotime($month['month']));
        $processingTime['modalAges'][] = $month['modal_age'];
    }
    
    // Get forecast data
    $age_projection = getGrantAgeProjection($visa_type_id);
    $forecast = [
        'labels' => [],
        'projectedAges' => [],
        'queueSizes' => [],
        'processingRates' => []
    ];
    
    if (!isset($age_projection['error'])) {
        // Use the projected data from the same function that populates the table
        foreach ($age_projection['projected'] as $data) {
            $forecast['labels'][] = date('M Y', strtotime($data['month']));
            $forecast['projectedAges'][] = floatval($data['modal_age']);
            $forecast['queueSizes'][] = intval($data['queue_size']);
            // Convert the grants to a processing rate if it's not a note
            $forecast['processingRates'][] = isset($data['note']) ? null : intval($data['grants']);
        }
    }
    
    // Get processing breakdown data
    $processing_breakdown = [
        'labels' => [],
        'processed' => [],
        'unprocessed' => []
    ];

    // Get the queue data like in admin page
    $queue_data = getVisaQueueData($visa_type_id);
    if ($queue_data && isset($queue_data['lodgements'])) {
        foreach ($queue_data['lodgements'] as $lodgement) {
            // Get the latest count from the updates
            $latest_count = end($lodgement['updates'])['queue_count'];
            
            // Calculate processed cases (Initial - Latest)
            $processed_count = max(0, $lodgement['first_count'] - $latest_count);
            
            // Add to the arrays
            $processing_breakdown['labels'][] = date('M Y', strtotime($lodgement['lodged_month']));
            $processing_breakdown['processed'][] = $processed_count;
            $processing_breakdown['unprocessed'][] = $latest_count;
        }
    }
    
    // Get queue position progress data
    $queue_progress = [
        'total_queue' => 0,
        'position' => 0,
        'ahead' => 0,
        'behind' => 0
    ];

    // Get current total queue size
    $total_queue = getVisasOnHand($visa_type_id);

    // Get cases ahead if application date is provided
    if ($application_date) {
        $cases_ahead = getCasesAheadInQueue($visa_type_id, $application_date);
        
        if (!isset($cases_ahead['error'])) {
            $position = $cases_ahead['estimated_current_ahead'];
            
            $queue_progress['total_queue'] = $total_queue;
            $queue_progress['position'] = $position;
            $queue_progress['ahead'] = $position;
            $queue_progress['behind'] = max(0, $total_queue - $position - 1); // -1 to account for your application
        }
    }
    
    echo json_encode([
        'processingRate' => $processingRate,
        'queuePosition' => $queuePosition,
        'processingTime' => $processingTime,
        'forecast' => $forecast,
        'processingBreakdown' => $processing_breakdown,
        'queueProgress' => $queue_progress
    ]);
} 