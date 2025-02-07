<?php
header('Content-Type: application/json');
require_once 'config/database.php';
require_once 'includes/functions.php';

// API security check (you might want to add more security)
$allowed_functions = [
    'getVisasOnHand' => ['visa_type_id'],
    'getProcessedByMonth' => ['visa_type_id', 'start_date?', 'end_date?'],
    'getMonthlyAverageProcessingRate' => ['visa_type_id', 'start_date?', 'end_date?'],
    'getWeightedAverageProcessingRate' => ['visa_type_id', 'start_date?', 'end_date?'],
    'getCasesAheadInQueue' => ['visa_type_id', 'lodgement_date'],
    'getTotalProcessedToDate' => ['visa_type_id'],
    'getPriorityCases' => ['visa_type_id', 'lodgement_date'],
    'getPriorityRatio' => ['visa_type_id', 'lodgement_date'],
    'getAllocationsRemaining' => ['visa_type_id'],
    'getVisaProcessingPrediction' => ['visa_type_id', 'lodgement_date'],
    // Add other functions here with their required parameters
];

$function = $_GET['function'] ?? null;
$visa_type_id = $_GET['visa_type_id'] ?? null;
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

if (!$function) {
    echo json_encode(['error' => 'No function specified']);
    exit;
}

if (!array_key_exists($function, $allowed_functions)) {
    echo json_encode(['error' => 'Function not found or not allowed']);
    exit;
}

try {
    switch ($function) {
        case 'getVisasOnHand':
            if (!$visa_type_id) {
                throw new Exception('visa_type_id is required');
            }
            $debug_data = debugVisaQueueSummary($visa_type_id);
            $result = getVisasOnHand($debug_data['details']);
            echo json_encode(['success' => true, 'data' => $result]);
            break;
        
        case 'getProcessedByMonth':
            if (!$visa_type_id) {
                echo json_encode(['error' => 'Visa type ID is required']);
                exit;
            }
            $result = getProcessedByMonth($visa_type_id, $start_date, $end_date);
            echo json_encode($result);
            break;
        
        case 'getMonthlyAverageProcessingRate':
            if (!$visa_type_id) {
                echo json_encode(['error' => 'Visa type ID is required']);
                exit;
            }
            $result = getMonthlyAverageProcessingRate($visa_type_id, $start_date, $end_date);
            echo json_encode($result);
            break;
        
        case 'getWeightedAverageProcessingRate':
            if (!$visa_type_id) {
                echo json_encode(['error' => 'Visa type ID is required']);
                exit;
            }
            $result = getWeightedAverageProcessingRate($visa_type_id, $start_date, $end_date);
            echo json_encode(['success' => true, 'data' => $result]);
            break;
        
        case 'getCasesAheadInQueue':
            if (!$visa_type_id) {
                echo json_encode(['error' => 'Visa type ID is required']);
                exit;
            }
            $lodgement_date = $_GET['lodgement_date'] ?? null;
            if (!$lodgement_date) {
                echo json_encode(['error' => 'Lodgement date is required']);
                exit;
            }
            $result = getCasesAheadInQueue($visa_type_id, $lodgement_date);
            echo json_encode($result);
            break;
        
        case 'getTotalProcessedToDate':
            if (!$visa_type_id) {
                echo json_encode(['error' => 'Visa type ID is required']);
                exit;
            }
            $result = getTotalProcessedToDate($visa_type_id);
            echo json_encode($result);
            break;
        
        case 'getPriorityCases':
            if (!$visa_type_id) {
                echo json_encode(['error' => 'Visa type ID is required']);
                exit;
            }
            $lodgement_date = $_GET['lodgement_date'] ?? null;
            if (!$lodgement_date) {
                echo json_encode(['error' => 'Lodgement date is required']);
                exit;
            }
            $result = getPriorityCases($visa_type_id, $lodgement_date);
            echo json_encode($result);
            break;
        
        case 'getPriorityRatio':
            if (!$visa_type_id) {
                echo json_encode(['error' => 'Visa type ID is required']);
                exit;
            }
            $lodgement_date = $_GET['lodgement_date'] ?? null;
            if (!$lodgement_date) {
                echo json_encode(['error' => 'Lodgement date is required']);
                exit;
            }
            $result = getPriorityRatio($visa_type_id, $lodgement_date);
            echo json_encode($result);
            break;
        
        case 'getAllocationsRemaining':
            if (!$visa_type_id) {
                echo json_encode(['error' => 'Visa type ID is required']);
                exit;
            }
            $result = getAllocationsRemaining($visa_type_id);
            echo json_encode($result);
            break;
        
        case 'getVisaProcessingPrediction':
            if (!$visa_type_id) {
                echo json_encode(['error' => 'Visa type ID is required']);
                exit;
            }
            $lodgement_date = $_GET['lodgement_date'] ?? null;
            if (!$lodgement_date) {
                echo json_encode(['error' => 'Lodgement date is required']);
                exit;
            }
            $result = getVisaProcessingPrediction($visa_type_id, $lodgement_date);
            echo json_encode($result);
            break;
        
        // Add other function cases here

        default:
            echo json_encode(['error' => 'Invalid function specified']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
} 