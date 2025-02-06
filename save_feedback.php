<?php
// Add error logging at the start
error_log("Starting feedback submission process");

include_once 'config/database.php';

// Log database connection status
if (!isset($conn)) {
    error_log("Database connection failed");
} else {
    error_log("Database connection successful");
}

// Log incoming data
$raw_data = file_get_contents('php://input');
error_log("Received raw data: " . $raw_data);

$data = json_decode($raw_data, true);
error_log("Decoded data: " . print_r($data, true));

if (!isset($data['feedback']) || !isset($data['theme'])) {
    error_log("Missing required fields. Data received: " . print_r($data, true));
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$feedback = mysqli_real_escape_string($conn, $data['feedback']);
$theme = mysqli_real_escape_string($conn, $data['theme']);

error_log("Escaped feedback: " . $feedback);
error_log("Escaped theme: " . $theme);

// Check if table exists
$check_table_sql = "SHOW TABLES LIKE 'user_feedback'";
$table_check_result = mysqli_query($conn, $check_table_sql);
error_log("Table check result: " . ($table_check_result ? "Query successful" : "Query failed: " . mysqli_error($conn)));
$table_exists = $table_check_result && mysqli_num_rows($table_check_result) > 0;
error_log("Table exists: " . ($table_exists ? "Yes" : "No"));

if ($table_exists) {
    // Alter existing table to add default value for user_id
    $alter_table_sql = "ALTER TABLE user_feedback MODIFY user_id INT NOT NULL DEFAULT 0";
    if (!mysqli_query($conn, $alter_table_sql)) {
        error_log("Failed to alter table: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Could not modify table structure: ' . mysqli_error($conn)]);
        exit;
    }
    error_log("Table structure updated successfully");
} else {
    error_log("Creating table user_feedback");
    $create_table_sql = "CREATE TABLE user_feedback (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL DEFAULT 0,
        feedback_text TEXT NOT NULL,
        theme VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";

    if (!mysqli_query($conn, $create_table_sql)) {
        error_log("Failed to create table: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Could not create table: ' . mysqli_error($conn)]);
        exit;
    }
    error_log("Table created successfully");
}

// Insert the feedback with explicit user_id
$query = "INSERT INTO user_feedback (user_id, feedback_text, theme) VALUES (0, ?, ?)";
error_log("Preparing query: " . $query);

$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    error_log("Failed to prepare statement: " . mysqli_error($conn));
    echo json_encode(['success' => false, 'message' => 'Could not prepare statement: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, "ss", $feedback, $theme);
error_log("Parameters bound to statement");

$success = mysqli_stmt_execute($stmt);
if (!$success) {
    error_log("Failed to execute statement: " . mysqli_stmt_error($stmt));
    echo json_encode(['success' => false, 'message' => mysqli_stmt_error($stmt)]);
} else {
    error_log("Feedback saved successfully");
    echo json_encode(['success' => true]);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
error_log("Database connection closed");
?> 