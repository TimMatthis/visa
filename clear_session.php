<?php
session_start();
require_once 'includes/functions.php';

$type = $_GET['type'] ?? null;
clearAdminSession($type);

echo json_encode(['status' => 'success']);
?> 