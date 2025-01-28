<?php
include_once '../config/database.php';
include_once '../includes/functions.php';

if (isset($_POST['visaType'])) {
    $visaType = $_POST['visaType'];
    $response = getOldestLodgementDate($visaType);
    echo json_encode($response);
} else {
    echo json_encode(['error' => 'Invalid request']);
}
?> 