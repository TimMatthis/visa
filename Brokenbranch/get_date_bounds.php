<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$visa_type = $_GET['visa_type'] ?? null;
if (!$visa_type) {
    echo json_encode(['error' => 'Visa type required']);
    exit;
}

$earliest_date = getOldestLodgementDate($visa_type);
echo json_encode([
    'min_date' => $earliest_date['oldest_date'] ?? date('Y-m-d', strtotime('-1 year')),
    'max_date' => date('Y-m-d')
]); 