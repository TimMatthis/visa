<?php
$host = 'localhost';
$dbname = 'visas';
$username = 'visa';
$password = 'visa';

try {
    $conn = mysqli_connect($host, $username, $password, $dbname);
    if (!$conn) {
        throw new Exception("Connection failed: " . mysqli_connect_error());
    }
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}
?> 