<?php
/************************
 * Constants and Configuration
 ************************/
define('IMPORT_TYPES', [
    'processing_times' => [
        'pattern' => '/^processing_times_(\d{4})(\d{2})\.csv$/',
        'example' => 'processing_times_202401.csv',
        'columns' => ['visa_type', 'month', 'year', 'min_days', 'max_days', 'total_processed']
    ],
    'grant_quotas' => [
        'pattern' => '/^grant_quotas_(\d{4})(\d{2})\.csv$/',
        'example' => 'grant_quotas_202401.csv',
        'columns' => ['visa_type', 'year', 'month', 'quota_amount']
    ],
    'processing_stages' => [
        'pattern' => '/^stages_(\d{4})(\d{2})\.csv$/',
        'example' => 'stages_202401.csv',
        'columns' => ['visa_type', 'stage_name', 'avg_days_to_complete']
    ],
    'visa_queue' => [
        'pattern' => '/^(\d+)\.csv$/',
        'example' => '190.csv',
        'columns' => ['Month LOI']
    ]
]);

/************************
 * Admin Functions - Data Import
 * 
 * Functions for handling file uploads and data imports in the admin interface.
 ************************/

/**
 * Handle File Upload
 * 
 * @description Processes uploaded files and validates them before import
 * @param array $file The uploaded file array from $_FILES
 * @return array|string Status message or error
 * @location Admin Panel > Import Data tab
 */
function handleFileUpload($file) {
    $validation = validateImportFile($file);
    
    if (isset($validation['error'])) {
        return $validation['error'];
    }
    
    switch ($validation['type']) {
        case 'visa_queue':
            return processVisaQueueImport($file, $validation['year'], $validation['month']);
        default:
            return "Unsupported import type";
    }
}

/**
 * Validate Import File
 * 
 * @description Validates uploaded CSV files for correct format and content
 * @param array $file The uploaded file array
 * @return array Validation results with status and any errors
 * @location Admin Panel > Import Data tab
 */
function validateImportFile($file) {
    $filename = $file['name'];
    $filesize = $file['size'];
    $filetype = $file['type'];
    
    error_log("Validating file: " . print_r($file, true));
    
    if ($filesize > 5 * 1024 * 1024) {
        return ['error' => 'File too large (max 5MB)'];
    }
    
    $allowed_types = [
        'text/csv',
        'application/csv',
        'application/vnd.ms-excel',
        'text/plain',
        'text/x-csv',
        'application/x-csv'
    ];
    
    if (!in_array($filetype, $allowed_types)) {
        error_log("Invalid file type: " . $filetype);
        error_log("Allowed types: " . implode(', ', $allowed_types));
        return ['error' => 'Only CSV files are allowed. Got type: ' . $filetype];
    }
    
    // Check if filename matches our pattern (e.g., 190.csv)
    if (preg_match('/^(\d+)\.csv$/', $filename, $matches)) {
        $visa_type = $matches[1];
        $current_date = date('Ym'); // Current year and month
        
        return [
            'type' => 'visa_queue',
            'year' => substr($current_date, 0, 4),
            'month' => substr($current_date, 4, 2),
            'visa_type' => $visa_type,
            'expected_columns' => IMPORT_TYPES['visa_queue']['columns']
        ];
    }
    
    return ['error' => 'Invalid filename format. Expected format: {visa_type}.csv (e.g., 190.csv)'];
}

/**
 * Process Visa Queue Import
 * 
 * @description Imports visa queue data from CSV file into database
 * @param array $file_info File information and metadata
 * @param string $delimiter CSV delimiter character
 * @return array Import results with status and statistics
 * @location Admin Panel > Import Data tab
 */
function processVisaQueueImport($file_info, $delimiter) {
    global $conn;
    
    try {
        // Start transaction (mysqli version)
        mysqli_begin_transaction($conn);
        
        // Get visa type ID
        $visaTypeStmt = mysqli_prepare($conn, "SELECT id FROM visa_types WHERE visa_type = ?");
        mysqli_stmt_bind_param($visaTypeStmt, "s", $file_info['visa_type']);
        mysqli_stmt_execute($visaTypeStmt);
        $result = mysqli_stmt_get_result($visaTypeStmt);
        $visaTypeId = mysqli_fetch_column($result);
        
        if (!$visaTypeId) {
            throw new Exception("Invalid visa type: " . $file_info['visa_type']);
        }
        
        // Prepare statements
        $findLodgementIdStmt = mysqli_prepare($conn, "
            SELECT id, first_count_volume 
            FROM visa_lodgements 
            WHERE lodged_month = ? AND visa_type_id = ?
        ");
        
        $findQueueUpdateStmt = mysqli_prepare($conn, "
            SELECT id, queue_count 
            FROM visa_queue_updates 
            WHERE lodged_month_id = ? AND update_month = ?
        ");
        
        $createLodgementStmt = mysqli_prepare($conn, "
            INSERT INTO visa_lodgements (lodged_month, visa_type_id, first_count_volume)
            VALUES (?, ?, ?)
        ");
        
        $createQueueUpdateStmt = mysqli_prepare($conn, "
            INSERT INTO visa_queue_updates (lodged_month_id, update_month, queue_count)
            VALUES (?, ?, ?)
        ");
        
        // Process the file
        $handle = fopen($file_info['tmp_name'], "r");
        if ($handle === false) {
            throw new Exception("Failed to open file");
        }

        $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
        $lodgement_months = array_slice($header, 1); // Skip first column
        
        // Convert date format for lodgement months
        $formatted_months = [];
        foreach ($lodgement_months as $i => $month) {
            $date = DateTime::createFromFormat('d-M-y', $month);
            if (!$date) {
                throw new Exception("Invalid date format in header: $month");
            }
            $formatted_months[$i] = $date->format('Y-m-d');
        }
        
        $stats = [
            'lodgements_created' => 0,
            'queue_updates' => 0,
            'skipped_updates' => 0,
            'rows_processed' => 0
        ];
        
        // First pass: find maximum values for each column
        $max_counts = array_fill(0, count($lodgement_months), 0);
        while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== FALSE) {
            foreach ($lodgement_months as $i => $lodgement_month) {
                $value = isset($data[$i + 1]) ? trim($data[$i + 1]) : '';
                if ($value === '<5') {
                    $value = 0;
                } elseif (is_numeric($value)) {
                    $value = intval($value);
                    $max_counts[$i] = max($max_counts[$i], $value);
                }
            }
        }
        
        // Reset file pointer
        rewind($handle);
        fgetcsv($handle, 0, $delimiter, '"', '\\'); // Skip header
        
        // Second pass: create lodgements and updates
        while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== FALSE) {
            // Convert update month to MySQL format
            $update_month = DateTime::createFromFormat('d-M-y', $data[0]);
            if (!$update_month) {
                throw new Exception("Invalid date format in row: " . $data[0]);
            }
            $update_month = $update_month->format('Y-m-d');
            
            foreach ($lodgement_months as $i => $lodgement_month) {
                $value = isset($data[$i + 1]) ? trim($data[$i + 1]) : '';
                
                if ($value === '<5' || is_numeric($value)) {
                    $queue_count = ($value === '<5') ? 0 : intval($value);
                    
                    // Check if lodgement exists
                    mysqli_stmt_bind_param($findLodgementIdStmt, "ss", $formatted_months[$i], $visaTypeId);
                    mysqli_stmt_execute($findLodgementIdStmt);
                    $existing = mysqli_stmt_get_result($findLodgementIdStmt);
                    $existing_row = mysqli_fetch_assoc($existing);
                    
                    $lodgementId = null;
                    if (!$existing_row) {
                        // Create new lodgement with maximum count for this column
                        mysqli_stmt_bind_param($createLodgementStmt, "ssi", $formatted_months[$i], $visaTypeId, $max_counts[$i]);
                        if (!mysqli_stmt_execute($createLodgementStmt)) {
                            throw new Exception("Failed to create lodgement: " . mysqli_stmt_error($createLodgementStmt));
                        }
                        $lodgementId = mysqli_insert_id($conn);
                        $stats['lodgements_created']++;
                    } else {
                        $lodgementId = $existing_row['id'];
                    }
                    
                    // Validate lodgementId before using it
                    if (!$lodgementId) {
                        throw new Exception("Invalid lodgement ID for month: " . $formatted_months[$i]);
                    }
                    
                    // Check if this update already exists
                    mysqli_stmt_bind_param($findQueueUpdateStmt, "ss", $lodgementId, $update_month);
                    mysqli_stmt_execute($findQueueUpdateStmt);
                    $existing = mysqli_stmt_get_result($findQueueUpdateStmt);
                    $existing_row = mysqli_fetch_assoc($existing);
                    if (!$existing_row) {
                        // Only add new updates
                        mysqli_stmt_bind_param($createQueueUpdateStmt, "sss", $lodgementId, $update_month, $queue_count);
                        mysqli_stmt_execute($createQueueUpdateStmt);
                        $stats['queue_updates']++;
                    } else {
                        // Check if the queue count has changed
                        if ($existing_row['queue_count'] != $queue_count) {
                            // Update the existing record
                            $updateQueueStmt = mysqli_prepare($conn, "
                                UPDATE visa_queue_updates 
                                SET queue_count = ? 
                                WHERE lodged_month_id = ? AND update_month = ?
                            ");
                            mysqli_stmt_bind_param($updateQueueStmt, "iss", $queue_count, $lodgementId, $update_month);
                            mysqli_stmt_execute($updateQueueStmt);
                            $stats['queue_updates']++;
                        } else {
                            $stats['skipped_updates']++;
                        }
                    }
                }
            }
            $stats['rows_processed']++;
        }
        
        fclose($handle);
        mysqli_commit($conn);
        
        return [
            'status' => 'success',
            'message' => sprintf(
                "Successfully imported visa type %s:\n" .
                "- %d rows processed\n" .
                "- %d new lodgements created\n" .
                "- %d new queue updates added\n" .
                "- %d duplicate updates skipped",
                $file_info['visa_type'],
                $stats['rows_processed'],
                $stats['lodgements_created'],
                $stats['queue_updates'],
                $stats['skipped_updates']
            )
        ];
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Import error: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => "Error importing data: " . $e->getMessage()
        ];
    }
}

/**
 * Cleanup Import File
 * 
 * @description Removes temporary import files after processing
 * @param string $file_path Path to the temporary file
 * @return bool True if file was deleted, false otherwise
 * @location Admin Panel > Import Data tab
 */
function cleanupImportFile($file_path) {
    if (file_exists($file_path)) {
        return unlink($file_path);
    }
    return false;
}

/************************
 * Admin Functions - Visa Type Management
 * 
 * Functions for managing visa types in the admin interface.
 ************************/

/**
 * Get All Visa Types
 * 
 * @description Retrieves all visa types from the database
 * @param object $conn Database connection object
 * @return array List of all visa types
 * @location Admin Panel > Manage Visa Types tab
 */
function getAllVisaTypes($conn) {
    $visaTypes = [];
    $query = "SELECT id, visa_type FROM visa_types ORDER BY visa_type";
    $result = mysqli_query($conn, $query);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $visaTypes[] = $row;
        }
        return $visaTypes;
    } else {
        error_log("Error fetching visa types: " . mysqli_error($conn));
        return [];
    }
}

/**
 * Add Visa Type
 * 
 * @description Adds a new visa type to the system
 * @param string $visa_type The visa type code to add
 * @return string Success or error message
 * @location Admin Panel > Manage Visa Types tab > Add New
 */
function addVisaType($visa_type, $conn) {
    try {
        // Check if visa type already exists
        $check_stmt = mysqli_prepare($conn, "SELECT id FROM visa_types WHERE visa_type = ?");
        mysqli_stmt_bind_param($check_stmt, "s", $visa_type);
        mysqli_stmt_execute($check_stmt);
        $result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_fetch_assoc($result)) {
            return [
                'status' => 'error',
                'message' => 'Error: This visa type already exists'
            ];
        }
        
        // Add new visa type
        $stmt = mysqli_prepare($conn, "INSERT INTO visa_types (visa_type) VALUES (?)");
        mysqli_stmt_bind_param($stmt, "s", $visa_type);
        
        if (mysqli_stmt_execute($stmt)) {
            return [
                'status' => 'success',
                'message' => 'Visa type added successfully'
            ];
        } else {
            return [
                'status' => 'error',
                'message' => 'Error adding visa type: ' . mysqli_error($conn)
            ];
        }
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Error adding visa type: ' . $e->getMessage()
        ];
    }
}

/**
 * Delete Visa Type
 * 
 * @description Removes a visa type and all associated data
 * @param int $id The ID of the visa type to delete
 * @return array Status and message
 * @location Admin Panel > Manage Visa Types tab > Delete
 */
function deleteVisaType($id, $conn) {
    try {
        // Start transaction
        mysqli_begin_transaction($conn);

        // First delete related records from visa_allocations
        $stmt = mysqli_prepare($conn, "DELETE FROM visa_allocations WHERE visa_type_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);

        // Then delete related records from visa_queue_updates and visa_lodgements
        $stmt = mysqli_prepare($conn, "
            DELETE qu FROM visa_queue_updates qu
            INNER JOIN visa_lodgements l ON qu.lodged_month_id = l.id
            WHERE l.visa_type_id = ?
        ");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare($conn, "DELETE FROM visa_lodgements WHERE visa_type_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);

        // Finally delete the visa type
        $stmt = mysqli_prepare($conn, "DELETE FROM visa_types WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);

        mysqli_commit($conn);
        return [
            'status' => 'success',
            'message' => "Visa type and all related data deleted successfully"
        ];

    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Error deleting visa type: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => "Error deleting visa type: " . $e->getMessage()
        ];
    }
}

/************************
 * Admin Functions - Data Management
 * 
 * Functions for managing and purging data in the admin interface.
 ************************/

/**
 * Validate And Preview Import
 * 
 * @description Previews data before import and validates format
 * @param array $file The uploaded file
 * @param string $selected_visa_type The selected visa type
 * @return array Preview data and validation results
 * @location Admin Panel > Import Data tab > Preview
 */
function validateAndPreviewImport($file, $selected_visa_type) {
    try {
        $filesize = $file['size'];
        $filetype = $file['type'];
        
        if ($filesize > 5 * 1024 * 1024) {
            throw new Exception('File too large (max 5MB)');
        }
        
        $allowed_types = ['text/csv', 'application/csv', 'application/vnd.ms-excel', 'text/plain'];
        if (!in_array($filetype, $allowed_types)) {
            throw new Exception('Only CSV files are allowed');
        }

        // Create uploads directory if it doesn't exist
        $upload_dir = __DIR__ . '/../uploads';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        // Generate a unique filename
        $temp_filename = $upload_dir . '/' . uniqid('import_') . '.csv';
        
        // Move uploaded file to our uploads directory
        if (!move_uploaded_file($file['tmp_name'], $temp_filename)) {
            throw new Exception('Failed to save uploaded file');
        }
        
        // Read the first line to detect delimiter
        $firstLine = file_get_contents($temp_filename, false, null, 0, 1024);
        $delimiter = (strpos($firstLine, ';') !== false) ? ';' : ',';
        
        // Read the first few lines to validate format
        $handle = fopen($temp_filename, "r");
        if ($handle === false) {
            throw new Exception('Failed to open file for reading');
        }
        
        $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
        
        if (!$header) {
            fclose($handle);
            throw new Exception('Unable to read CSV file');
        }
        
        // Validate first column header
        $first_column = trim(strtoupper($header[0]));  // Convert to uppercase and trim
        
        // Remove any special characters (like '>' or extra spaces) and trim again
        $first_column = trim(preg_replace('/[^A-Z\s]/', '', $first_column));
        
        // Allow "MONTH LOI" or "MONTH LODGED" with up to 3 spaces after
        if (!preg_match('/^MONTH\s+(LOI|LODGED\s{0,3})$/', $first_column)) {
            error_log("Header validation failed. Found: '" . $first_column . "'");
            error_log("Header array: " . print_r($header, true));
            throw new Exception('First column must be "Month LOI" or "Month LODGED"');
        }
        
        // Preview first few rows
        $preview_rows = [];
        $row_count = 0;
        $max_preview_rows = 5;  // This limits the preview display
        
        while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== FALSE && $row_count < $max_preview_rows) {
            $data = array_map('trim', $data);
            $preview_rows[] = $data;
            $row_count++;
        }
        
        // Count total rows (including header)
        rewind($handle);
        $total_rows = 0;
        while (fgetcsv($handle, 0, $delimiter, '"', '\\') !== FALSE) {
            $total_rows++;
        }
        
        fclose($handle);
        
        $result = [
            'status' => 'preview',
            'file_info' => [
                'name' => $file['name'],
                'type' => $filetype,
                'size' => $filesize,
                'tmp_name' => $temp_filename,
                'visa_type' => $selected_visa_type,
                'total_rows' => $total_rows - 1,  // Subtract header row
                'delimiter' => $delimiter
            ],
            'header' => $header,
            'preview_rows' => $preview_rows,
            'errors' => []
        ];
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error in validateAndPreviewImport: " . $e->getMessage());
        if (isset($temp_filename) && file_exists($temp_filename)) {
            unlink($temp_filename);  // Clean up on error
        }
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * Purge Visa Data
 * 
 * @description Deletes all data for a specific visa type or all visa types
 * @param int|null $visa_type_id Optional visa type ID. If null, purges all data
 * @return array Status and message about the purge operation
 * @location Admin Panel > Data Management tab
 */
function purgeVisaData($visa_type_id = null) {
    global $conn;
    
    try {
        mysqli_begin_transaction($conn);
        
        if ($visa_type_id) {
            // Delete data for specific visa type
            
            // First delete queue updates
            $query = "
                DELETE vqu FROM visa_queue_updates vqu
                INNER JOIN visa_lodgements vl ON vqu.lodged_month_id = vl.id
                WHERE vl.visa_type_id = ?
            ";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $visa_type_id);
            mysqli_stmt_execute($stmt);
            
            // Then delete lodgements
            $query = "DELETE FROM visa_lodgements WHERE visa_type_id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $visa_type_id);
            mysqli_stmt_execute($stmt);
            
            // Delete allocations
            $query = "DELETE FROM visa_allocations WHERE visa_type_id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $visa_type_id);
            mysqli_stmt_execute($stmt);
            
            $message = "Successfully purged all data for visa type ID: $visa_type_id";
        } else {
            // Delete all data
            mysqli_query($conn, "DELETE FROM visa_queue_updates");
            mysqli_query($conn, "DELETE FROM visa_lodgements");
            mysqli_query($conn, "DELETE FROM visa_allocations");
            
            $message = "Successfully purged all visa data from the system";
        }
        
        mysqli_commit($conn);
        
        return [
            'status' => 'success',
            'message' => $message
        ];
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Error in purgeVisaData: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => "Error purging data: " . $e->getMessage()
        ];
    }
}

/************************
 * Core Functions
 * 
 * These functions provide core functionality for the visa processing system.
 * Each function is documented with its purpose and parameters.
 * 
 * API Access: Each function can be called via URL:
 * /api.php?function=functionName&param1=value1&param2=value2
 ************************/

/**
 * Get Visas On Hand
 * 
 * @description Calculates the total number of visa applications currently in queue
 * @param int $visa_type_id The ID of the visa type to analyze
 * @return int Total number of visas currently in the processing queue
 */
function getVisasOnHand($visa_type_id) {
    global $conn;
    
    try {
        // Get the latest update date
        $query = "
            SELECT SUM(vqu.queue_count) as total_on_hand
            FROM visa_queue_updates vqu
            JOIN visa_lodgements vl ON vqu.lodged_month_id = vl.id
            WHERE vl.visa_type_id = ?
            AND vqu.update_month = (
                SELECT MAX(update_month)
                FROM visa_queue_updates vqu2
                JOIN visa_lodgements vl2 ON vqu2.lodged_month_id = vl2.id
                WHERE vl2.visa_type_id = ?
            )
        ";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'ii', $visa_type_id, $visa_type_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        
        return intval($row['total_on_hand'] ?? 0);
        
    } catch (Exception $e) {
        error_log("Error in getVisasOnHand: " . $e->getMessage());
        return 0;
    }
}

/**
 * Debug Visa Queue Summary
 * 
 * @description Gets detailed queue data for a specific visa type including latest updates and trends
 * @param int $visa_type_id The ID of the visa type to analyze
 * @return array|null Queue summary data including latest update and details
 * @location Admin Panel > Data Summary tab
 */
function debugVisaQueueSummary($visa_type_id) {
    global $conn;
    
    try {
        // Get the latest update for this visa type
        $query = "
            SELECT 
                vl.lodged_month,
                vqu.update_month as latest_update,
                vqu.queue_count
            FROM visa_lodgements vl
            JOIN visa_queue_updates vqu ON vl.id = vqu.lodged_month_id
            WHERE vl.visa_type_id = ?
            ORDER BY vqu.update_month DESC
            LIMIT 1
        ";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $visa_type_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $latest = mysqli_fetch_assoc($result);
        
        if (!$latest) {
            return null;
        }
        
        // Get detailed queue breakdown
        $query = "
            SELECT 
                vl.lodged_month,
                vqu.queue_count,
                vqu.update_month
            FROM visa_lodgements vl
            JOIN visa_queue_updates vqu ON vl.id = vqu.lodged_month_id
            WHERE vl.visa_type_id = ?
            AND vqu.update_month = (
                SELECT MAX(update_month) 
                FROM visa_queue_updates vqu2 
                JOIN visa_lodgements vl2 ON vqu2.lodged_month_id = vl2.id 
                WHERE vl2.visa_type_id = ?
            )
            ORDER BY vl.lodged_month DESC
        ";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $visa_type_id, $visa_type_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $details = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $details[] = $row;
        }
        
        return [
            'latest_update' => $latest['latest_update'],
            'details' => $details
        ];
        
    } catch (Exception $e) {
        error_log("Error in debugVisaQueueSummary: " . $e->getMessage());
        return null;
    }
}

/**
 * Get Visa Processing Rates
 * 
 * @description Calculates processing rates and trends for a visa type
 * @param int $visa_type_id The ID of the visa type to analyze
 * @return array Processing rates including monthly averages and trends
 * @location Admin Panel > Data Summary tab
 */
function getVisaProcessingRates($visa_type_id) {
    global $conn;
    
    try {
        // Get processing rates for the last 3 months
        $query = "
            WITH MonthlyProcessing AS (
                SELECT 
                    DATE_FORMAT(vqu1.update_month, '%Y-%m') as month,
                    COUNT(DISTINCT vl.id) as total_applications,
                    SUM(CASE 
                        WHEN vqu2.queue_count < vqu1.queue_count THEN 1 
                        ELSE 0 
                    END) as processed_applications
                FROM visa_queue_updates vqu1
                JOIN visa_lodgements vl ON vqu1.lodged_month_id = vl.id
                LEFT JOIN visa_queue_updates vqu2 ON vqu1.lodged_month_id = vqu2.lodged_month_id
                    AND vqu2.update_month = DATE_ADD(vqu1.update_month, INTERVAL 1 MONTH)
                WHERE vl.visa_type_id = ?
                GROUP BY DATE_FORMAT(vqu1.update_month, '%Y-%m')
                ORDER BY month DESC
                LIMIT 3
            )
            SELECT 
                month,
                ROUND((processed_applications / total_applications) * 100, 1) as processing_rate,
                total_applications,
                processed_applications
            FROM MonthlyProcessing
            ORDER BY month DESC
        ";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $visa_type_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $rates = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rates[] = [
                'month' => $row['month'],
                'processing_rate' => floatval($row['processing_rate']),
                'total_applications' => intval($row['total_applications']),
                'processed_applications' => intval($row['processed_applications'])
            ];
        }
        
        // Calculate trend (positive if processing rate is increasing)
        $trend = null;
        if (count($rates) >= 2) {
            $trend = $rates[0]['processing_rate'] > $rates[1]['processing_rate'];
        }
        
        return [
            'rates' => $rates,
            'trend' => $trend,
            'latest_rate' => $rates[0]['processing_rate'] ?? null
        ];
        
    } catch (Exception $e) {
        error_log("Error in getVisaProcessingRates: " . $e->getMessage());
        return [
            'rates' => [],
            'trend' => null,
            'latest_rate' => null
        ];
    }
}

/**
 * Get Processed Visas By Month
 * 
 * @description Calculates how many visas were processed in each update month by comparing
 *              consecutive queue counts and summing the differences when the queue decreases
 * @param int $visa_type_id The ID of the visa type to analyze
 * @param string|null $start_date Optional start date in YYYY-MM-DD format
 * @param string|null $end_date Optional end date in YYYY-MM-DD format
 * @return array Monthly processing totals with details
 * @example 
 *   $processed = getProcessedByMonth(190, '2023-01-01', '2023-12-31');
 * @api_endpoint /api.php?function=getProcessedByMonth&visa_type_id=190&start_date=2023-01-01&end_date=2023-12-31
 */
function getProcessedByMonth($visa_type_id, $start_date = null, $end_date = null) {
    global $conn;
    
    try {
        $params = [$visa_type_id];
        $date_conditions = "";
        
        if ($start_date) {
            $date_conditions .= " AND vqu.update_month >= ?";
            $params[] = $start_date;
        }
        if ($end_date) {
            $date_conditions .= " AND vqu.update_month <= ?";
            $params[] = $end_date;
        }
        
        $query = "
            WITH MonthlySnapshots AS (
                SELECT 
                    vqu.update_month,
                    vqu.lodged_month_id,
                    vqu.queue_count
                FROM visa_queue_updates vqu
                JOIN visa_lodgements vl ON vqu.lodged_month_id = vl.id
                WHERE vl.visa_type_id = ?
                $date_conditions
            ),
            MonthlyProcessing AS (
                SELECT 
                    curr.update_month,
                    curr.lodged_month_id,
                    GREATEST(0, prev.queue_count - curr.queue_count) as processed_count
                FROM MonthlySnapshots curr
                LEFT JOIN MonthlySnapshots prev 
                    ON prev.lodged_month_id = curr.lodged_month_id
                    AND prev.update_month = (
                        SELECT MAX(update_month)
                        FROM MonthlySnapshots
                        WHERE update_month < curr.update_month
                        AND lodged_month_id = curr.lodged_month_id
                    )
            )
            SELECT 
                update_month,
                SUM(processed_count) as total_processed,
                GROUP_CONCAT(
                    CONCAT('Lodged Month ID ', lodged_month_id, ': ', processed_count, ' processed')
                    ORDER BY lodged_month_id
                    SEPARATOR '; '
                ) as details
            FROM MonthlyProcessing
            WHERE processed_count > 0
            GROUP BY update_month
            ORDER BY update_month DESC
        ";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $monthly_totals = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $monthly_totals[] = [
                'update_month' => $row['update_month'],
                'total_processed' => intval($row['total_processed']),
                'details' => $row['details']
            ];
        }
        
        return $monthly_totals;
        
    } catch (Exception $e) {
        error_log("Error in getProcessedByMonth: " . $e->getMessage());
        return [];
    }
}

/**
 * Get Visa Types for Select Dropdown
 * 
 * @description Retrieves all visa types from the database for use in select dropdowns
 * @param object $conn Database connection object
 * @return array Array of visa types with code and name
 */
function getVisaSelect($conn) {
    try {
        $query = "SELECT id as code, visa_type as name FROM visa_types ORDER BY visa_type";
        $result = mysqli_query($conn, $query);
        
        if (!$result) {
            error_log("MySQL Error in getVisaSelect: " . mysqli_error($conn));
            return [];
        }
        
        $visaTypes = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $visaTypes[] = $row;
        }
        
        return $visaTypes;
    } catch (Exception $e) {
        error_log("Error in getVisaSelect: " . $e->getMessage());
        return [];
    }
}

/**
 * Get Monthly Average Processing Rate
 * 
 * @description Calculates the monthly average processing rate for a visa type
 * @param int $visa_type_id The ID of the visa type to analyze
 * @param string|null $start_date Optional start date in YYYY-MM-DD format
 * @param string|null $end_date Optional end date in YYYY-MM-DD format
 * @return array Monthly average processing rates with details
 * @example 
 *   $rates = getMonthlyAverageProcessingRate(190, '2023-01-01', '2023-12-31');
 * @api_endpoint /api.php?function=getMonthlyAverageProcessingRate&visa_type_id=190&start_date=2023-01-01&end_date=2023-12-31
 */
function getMonthlyAverageProcessingRate($visa_type_id, $start_date = null, $end_date = null) {
    global $conn;
    
    try {
        $params = [$visa_type_id];
        $date_conditions = "";
        
        if ($start_date) {
            $date_conditions .= " AND vqu.update_month >= ?";
            $params[] = $start_date;
        }
        if ($end_date) {
            $date_conditions .= " AND vqu.update_month <= ?";
            $params[] = $end_date;
        }
        
        $query = "
            WITH MonthlySnapshots AS (
                SELECT 
                    vqu.update_month,
                    vqu.lodged_month_id,
                    vqu.queue_count
                FROM visa_queue_updates vqu
                JOIN visa_lodgements vl ON vqu.lodged_month_id = vl.id
                WHERE vl.visa_type_id = ?
                $date_conditions
            ),
            MonthlyProcessing AS (
                SELECT 
                    curr.update_month,
                    curr.lodged_month_id,
                    GREATEST(0, prev.queue_count - curr.queue_count) as processed_count
                FROM MonthlySnapshots curr
                LEFT JOIN MonthlySnapshots prev 
                    ON prev.lodged_month_id = curr.lodged_month_id
                    AND prev.update_month = (
                        SELECT MAX(update_month)
                        FROM MonthlySnapshots
                        WHERE update_month < curr.update_month
                        AND lodged_month_id = curr.lodged_month_id
                    )
            ),
            MonthlyTotals AS (
                SELECT 
                    update_month,
                    SUM(processed_count) as total_processed
                FROM MonthlyProcessing
                WHERE processed_count > 0
                GROUP BY update_month
            )
            SELECT 
                update_month,
                total_processed,
                AVG(total_processed) OVER (ORDER BY update_month ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as running_average
            FROM MonthlyTotals
            ORDER BY update_month DESC
        ";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $monthly_averages = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $monthly_averages[] = [
                'update_month' => $row['update_month'],
                'total_processed' => intval($row['total_processed']),
                'running_average' => floatval($row['running_average'])
            ];
        }
        
        return $monthly_averages;
        
    } catch (Exception $e) {
        error_log("Error in getMonthlyAverageProcessingRate: " . $e->getMessage());
        return [];
    }
}

/**
 * Get Weighted Average Processing Rate
 * 
 * @description Calculates the weighted average processing rate for the last three months
 * @param int $visa_type_id The ID of the visa type to analyze
 * @param string|null $start_date Optional start date in YYYY-MM-DD format
 * @param string|null $end_date Optional end date in YYYY-MM-DD format
 * @return float Weighted average processing rate
 * @example 
 *   $weighted_average = getWeightedAverageProcessingRate(190, '2023-01-01', '2023-12-31');
 * @api_endpoint /api.php?function=getWeightedAverageProcessingRate&visa_type_id=190&start_date=2023-01-01&end_date=2023-12-31
 */
function getWeightedAverageProcessingRate($visa_type_id, $start_date = null, $end_date = null) {
    global $conn;
    
    try {
        $params = [$visa_type_id];
        $date_conditions = "";
        
        if ($start_date) {
            $date_conditions .= " AND vqu.update_month >= ?";
            $params[] = $start_date;
        }
        if ($end_date) {
            $date_conditions .= " AND vqu.update_month <= ?";
            $params[] = $end_date;
        }
        
        $query = "
            WITH MonthlySnapshots AS (
                SELECT 
                    vqu.update_month,
                    vqu.lodged_month_id,
                    vqu.queue_count
                FROM visa_queue_updates vqu
                JOIN visa_lodgements vl ON vqu.lodged_month_id = vl.id
                WHERE vl.visa_type_id = ?
                $date_conditions
            ),
            MonthlyProcessing AS (
                SELECT 
                    curr.update_month,
                    curr.lodged_month_id,
                    GREATEST(0, prev.queue_count - curr.queue_count) as processed_count
                FROM MonthlySnapshots curr
                LEFT JOIN MonthlySnapshots prev 
                    ON prev.lodged_month_id = curr.lodged_month_id
                    AND prev.update_month = (
                        SELECT MAX(update_month)
                        FROM MonthlySnapshots
                        WHERE update_month < curr.update_month
                        AND lodged_month_id = curr.lodged_month_id
                    )
            ),
            MonthlyTotals AS (
                SELECT 
                    update_month,
                    SUM(processed_count) as total_processed
                FROM MonthlyProcessing
                WHERE processed_count > 0
                GROUP BY update_month
                ORDER BY update_month DESC
                LIMIT 3
            )
            SELECT 
                SUM(total_processed) as total_processed,
                COUNT(*) as months_count
            FROM MonthlyTotals
        ";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $row = mysqli_fetch_assoc($result);
        if ($row['months_count'] > 0) {
            return $row['total_processed'] / $row['months_count'];
        } else {
            return 0; // No data available
        }
        
    } catch (Exception $e) {
        error_log("Error in getWeightedAverageProcessingRate: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get Annual Allocation
 * 
 * @description Retrieves the annual allocation for a specific visa type
 * @param int $visa_type_id The ID of the visa type to analyze
 * @return array Annual allocation details
 */
function getAnnualAllocation($visa_type_id) {
    global $conn;
    
    try {
        $fy_dates = getCurrentFinancialYearDates();
        error_log("Getting annual allocation for visa_type_id: $visa_type_id and FY: " . print_r($fy_dates, true));
        
        // First check if the visa type exists
        $check_visa_query = "SELECT visa_type FROM visa_types WHERE id = ?";
        $stmt = mysqli_prepare($conn, $check_visa_query);
        mysqli_stmt_bind_param($stmt, 'i', $visa_type_id);
        mysqli_stmt_execute($stmt);
        $visa_result = mysqli_stmt_get_result($stmt);
        $visa_type = mysqli_fetch_assoc($visa_result);
        
        if (!$visa_type) {
            error_log("Visa type $visa_type_id not found in database");
            return [];
        }
        
        error_log("Found visa type: " . print_r($visa_type, true));
        
        // Debug: Check all allocations for this visa type
        $debug_query = "SELECT * FROM visa_allocations WHERE visa_type_id = ?";
        $stmt = mysqli_prepare($conn, $debug_query);
        mysqli_stmt_bind_param($stmt, 'i', $visa_type_id);
        mysqli_stmt_execute($stmt);
        $debug_result = mysqli_stmt_get_result($stmt);
        $all_allocations = [];
        while ($row = mysqli_fetch_assoc($debug_result)) {
            $all_allocations[] = $row;
        }
        error_log("All allocations for this visa type: " . print_r($all_allocations, true));
        
        // First try to get current FY allocation
        $query = "
            SELECT financial_year_start, allocation_amount
            FROM visa_allocations
            WHERE visa_type_id = ?
            AND financial_year_start = ?
        ";
        
        error_log("Executing query with visa_type_id: $visa_type_id and fy_start_year: {$fy_dates['fy_start_year']}");
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'ii', $visa_type_id, $fy_dates['fy_start_year']);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (!$result) {
            error_log("MySQL Error: " . mysqli_error($conn));
            return [];
        }
        
        $allocations = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $allocations[] = [
                'financial_year_start' => intval($row['financial_year_start']),
                'allocation_amount' => intval($row['allocation_amount'])
            ];
        }
        
        // If no allocations found for current FY, try getting the most recent allocation
        if (empty($allocations)) {
            error_log("No current FY allocation found for visa type $visa_type_id, checking most recent");
            
            $query = "
                SELECT financial_year_start, allocation_amount
                FROM visa_allocations
                WHERE visa_type_id = ?
                ORDER BY financial_year_start DESC
                LIMIT 1
            ";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, 'i', $visa_type_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if (!$result) {
                error_log("MySQL Error in fallback query: " . mysqli_error($conn));
                return [];
            }
            
            while ($row = mysqli_fetch_assoc($result)) {
                $allocations[] = [
                    'financial_year_start' => intval($row['financial_year_start']),
                    'allocation_amount' => intval($row['allocation_amount'])
                ];
            }
        }
        
        // Add debug logging
        error_log("Annual allocation query result for visa type $visa_type_id: " . print_r($allocations, true));
        
        return $allocations;
        
    } catch (Exception $e) {
        error_log("Error in getAnnualAllocation: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return [];
    }
}

/**
 * Get Cases Ahead in Queue
 * 
 * @description Calculates the number of visa applications lodged before a given date
 * @param int $visa_type_id The ID of the visa type to analyze
 * @param string $lodgement_date The lodgement date in YYYY-MM-DD format
 * @return array Cases ahead details including total count and estimated current position
 */
function getCasesAheadInQueue($visa_type_id, $lodgement_date) {
    global $conn;
    
    try {
        // Get the latest update date
        $latest_update_query = "
            SELECT MAX(update_month) as latest_update
            FROM visa_queue_updates vqu
            JOIN visa_lodgements vl ON vqu.lodged_month_id = vl.id
            WHERE vl.visa_type_id = ?
        ";
        
        $stmt = mysqli_prepare($conn, $latest_update_query);
        mysqli_stmt_bind_param($stmt, 'i', $visa_type_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $latest_update = mysqli_fetch_assoc($result)['latest_update'];
        
        if (!$latest_update) {
            return ['error' => 'No queue data available'];
        }

        // Get weighted average processing rate
        $processing_rate = getWeightedAverageProcessingRate($visa_type_id);
        
        // Calculate days since last update
        $last_update_date = new DateTime($latest_update);
        $today = new DateTime();
        $days_since_update = $today->diff($last_update_date)->days;
        $months_since_update = $days_since_update / 30.44; // Average month length
        
        // Original query to get cases ahead as of last update
        // ... [Previous query remains the same until the final SELECT] ...
        
        $query = "
            WITH LatestCounts AS (
                SELECT 
                    vl.lodged_month,
                    vqu.queue_count,
                    vqu.update_month,
                    CASE 
                        WHEN DATE_FORMAT(vl.lodged_month, '%Y-%m') = DATE_FORMAT(?, '%Y-%m') THEN 1
                        ELSE 0
                    END as is_lodgement_month
                FROM visa_lodgements vl
                JOIN visa_queue_updates vqu ON vl.id = vqu.lodged_month_id
                WHERE vl.visa_type_id = ?
                AND vqu.update_month = ?
                AND (
                    vl.lodged_month < ?  -- Previous months
                    OR (
                        DATE_FORMAT(vl.lodged_month, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')  -- Same month
                    )
                )
            )
            SELECT 
                SUM(CASE 
                    WHEN is_lodgement_month = 1 THEN 
                        queue_count * ? / DAY(LAST_DAY(lodged_month))
                    ELSE queue_count 
                END) as total_ahead,
                JSON_ARRAYAGG(
                    JSON_OBJECT(
                        'lodged_month', lodged_month,
                        'queue_count', queue_count,
                        'prorated_count', CASE 
                            WHEN is_lodgement_month = 1 THEN 
                                queue_count * ? / DAY(LAST_DAY(lodged_month))
                            ELSE queue_count 
                        END
                    )
                    ORDER BY lodged_month DESC
                ) as breakdown
            FROM LatestCounts
        ";
        
        // Parse the lodgement date
        $lodgement_datetime = new DateTime($lodgement_date);
        $day_of_month = intval($lodgement_datetime->format('d'));
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'sisssis', 
            $lodgement_date,         // For DATE_FORMAT in is_lodgement_month
            $visa_type_id,           // For visa_type_id
            $latest_update,          // For update_month
            $lodgement_date,         // For < comparison
            $lodgement_date,         // For DATE_FORMAT in OR clause
            $day_of_month,           // For prorating calculation
            $day_of_month           // For prorating in JSON_OBJECT
        );
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        
        if ($row) {
            $total_ahead = intval($row['total_ahead']);
            
            // Calculate estimated current position
            $estimated_processed = round($processing_rate * $months_since_update);
            $estimated_current_ahead = max(0, $total_ahead - $estimated_processed);
            
            $breakdown = json_decode($row['breakdown'], true);
            
            return [
                'total_ahead' => $total_ahead,
                'estimated_current_ahead' => $estimated_current_ahead,
                'latest_update' => $latest_update,
                'days_since_update' => $days_since_update,
                'estimated_processed_since_update' => $estimated_processed,
                'monthly_processing_rate' => $processing_rate,
                'breakdown' => $breakdown,
                'lodgement_date' => $lodgement_date,
                'day_of_month' => $day_of_month,
                'calculation_date' => $today->format('Y-m-d')
            ];
        }
        
        return ['error' => 'No cases found'];
        
    } catch (Exception $e) {
        error_log("Error in getCasesAheadInQueue: " . $e->getMessage());
        return ['error' => 'Internal server error'];
    }
}

/**
 * Get Total Processed To Date
 * 
 * @description Calculates the total number of visas processed in the current financial year
 * @param int $visa_type_id The ID of the visa type to analyze
 * @return array Total processed count and details for current financial year
 */
function getTotalProcessedToDate($visa_type_id) {
    global $conn;
    
    try {
        // Get the processed count from getAllocationsRemaining since it has the correct calculation
        $allocations = getAllocationsRemaining($visa_type_id);
        
        if (isset($allocations['error'])) {
            return $allocations;
        }
        
        // Return the data in the expected format
        return [
            'total_processed' => $allocations['total_processed'],
            'first_update' => null,  // These aren't needed since we're using the allocations data
            'latest_update' => null,
            'financial_year' => $allocations['financial_year'],
            'monthly_breakdown' => safeExplode('; ', $allocations['monthly_breakdown'])
        ];
        
    } catch (Exception $e) {
        error_log("Error in getTotalProcessedToDate: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return ['error' => 'Internal server error'];
    }
}

/**
 * Get Priority Cases
 * 
 * @description Calculates the number of visas processed in current FY with lodgement dates after the given date
 * @param int $visa_type_id The ID of the visa type to analyze
 * @param string $lodgement_date The reference lodgement date in YYYY-MM-DD format
 * @return array Priority cases count and details
 * @example 
 *   $priority_cases = getPriorityCases(190, '2023-07-10');
 * @api_endpoint /api.php?function=getPriorityCases&visa_type_id=190&lodgement_date=2023-07-10
 */
function getPriorityCases($visa_type_id, $lodgement_date) {
    global $conn;
    
    try {
        $fy_dates = getCurrentFinancialYearDates();
        
        $query = "
            WITH MonthlySnapshots AS (
                SELECT 
                    vqu.update_month,
                    vqu.lodged_month_id,
                    vqu.queue_count,
                    vl.lodged_month
                FROM visa_queue_updates vqu
                JOIN visa_lodgements vl ON vqu.lodged_month_id = vl.id
                WHERE vl.visa_type_id = ?
                AND vqu.update_month BETWEEN DATE_SUB(?, INTERVAL 1 MONTH) AND ?
                AND vl.lodged_month > ?
            ),
            ProcessingSummary AS (
                SELECT 
                    curr.update_month,
                    SUM(GREATEST(0, prev.queue_count - curr.queue_count)) as monthly_total
                FROM MonthlySnapshots curr
                LEFT JOIN MonthlySnapshots prev 
                    ON prev.lodged_month_id = curr.lodged_month_id
                    AND prev.update_month = (
                        SELECT MAX(update_month)
                        FROM MonthlySnapshots
                        WHERE update_month < curr.update_month
                        AND lodged_month_id = curr.lodged_month_id
                    )
                WHERE curr.update_month BETWEEN ? AND ?
                GROUP BY curr.update_month
                HAVING monthly_total > 0
            )
            SELECT 
                SUM(monthly_total) as total_priority,
                GROUP_CONCAT(
                    CONCAT(update_month, ': ', monthly_total)
                    ORDER BY update_month
                    SEPARATOR '; '
                ) as monthly_breakdown
            FROM ProcessingSummary
        ";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'isssss', 
            $visa_type_id, 
            $fy_dates['start_date'],  // For DATE_SUB
            $fy_dates['end_date'],    // For MonthlySnapshots BETWEEN end
            $lodgement_date,          // For priority cutoff
            $fy_dates['start_date'],  // For ProcessingSummary BETWEEN start
            $fy_dates['end_date']     // For ProcessingSummary BETWEEN end
        );
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $processed = mysqli_fetch_assoc($result);
        
        return [
            'total_priority' => intval($processed['total_priority']),
            'monthly_breakdown' => safeExplode('; ', $processed['monthly_breakdown']),
            'financial_year' => $fy_dates['fy_label'],
            'reference_date' => $lodgement_date
        ];
        
    } catch (Exception $e) {
        error_log("Error in getPriorityCases: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return ['error' => 'Internal server error'];
    }
}

/**
 * Get Priority Ratio
 * 
 * @description Calculates the ratio of priority cases vs non-priority cases processed in current FY
 * @param int $visa_type_id The ID of the visa type to analyze
 * @param string $lodgement_date The reference lodgement date in YYYY-MM-DD format
 * @return array Priority ratio details including percentages and counts
 * @example 
 *   $priority_ratio = getPriorityRatio(190, '2023-07-10');
 * @api_endpoint /api.php?function=getPriorityRatio&visa_type_id=190&lodgement_date=2023-07-10
 */
function getPriorityRatio($visa_type_id, $lodgement_date) {
    global $conn;
    
    try {
        $fy_dates = getCurrentFinancialYearDates();
        
        $query = "
            WITH MonthlySnapshots AS (
                SELECT 
                    vqu.update_month,
                    vqu.lodged_month_id,
                    vqu.queue_count,
                    vl.lodged_month
                FROM visa_queue_updates vqu
                JOIN visa_lodgements vl ON vqu.lodged_month_id = vl.id
                WHERE vl.visa_type_id = ?
                AND vqu.update_month BETWEEN DATE_SUB(?, INTERVAL 1 MONTH) AND ?
            ),
            ProcessingSummary AS (
                SELECT 
                    curr.update_month,
                    curr.lodged_month,
                    SUM(GREATEST(0, prev.queue_count - curr.queue_count)) as monthly_total
                FROM MonthlySnapshots curr
                LEFT JOIN MonthlySnapshots prev 
                    ON prev.lodged_month_id = curr.lodged_month_id
                    AND prev.update_month = (
                        SELECT MAX(update_month)
                        FROM MonthlySnapshots
                        WHERE update_month < curr.update_month
                        AND lodged_month_id = curr.lodged_month_id
                    )
                WHERE curr.update_month BETWEEN ? AND ?
                GROUP BY curr.update_month, curr.lodged_month
                HAVING monthly_total > 0
            ),
            PriorityTotals AS (
                SELECT 
                    SUM(CASE WHEN lodged_month > ? THEN monthly_total ELSE 0 END) as priority_count,
                    SUM(CASE WHEN lodged_month <= ? THEN monthly_total ELSE 0 END) as non_priority_count,
                    SUM(monthly_total) as total_processed
                FROM ProcessingSummary
            )
            SELECT 
                priority_count,
                non_priority_count,
                total_processed,
                ROUND((priority_count / total_processed) * 100, 1) as priority_percentage,
                ROUND((non_priority_count / total_processed) * 100, 1) as non_priority_percentage
            FROM PriorityTotals
            WHERE total_processed > 0
        ";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'issssss', 
            $visa_type_id, 
            $fy_dates['start_date'],  // For DATE_SUB
            $fy_dates['end_date'],    // For MonthlySnapshots BETWEEN end
            $fy_dates['start_date'],  // For ProcessingSummary BETWEEN start
            $fy_dates['end_date'],    // For ProcessingSummary BETWEEN end
            $lodgement_date,          // For priority cutoff
            $lodgement_date           // For non-priority cutoff
        );
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        
        if ($row && $row['total_processed'] > 0) {
            return [
                'priority_count' => intval($row['priority_count']),
                'non_priority_count' => intval($row['non_priority_count']),
                'total_processed' => intval($row['total_processed']),
                'priority_percentage' => floatval($row['priority_percentage']),
                'non_priority_percentage' => floatval($row['non_priority_percentage']),
                'financial_year' => $fy_dates['fy_label'],
                'reference_date' => $lodgement_date
            ];
        }
        
        return ['error' => 'No processing data available'];
        
    } catch (Exception $e) {
        error_log("Error in getPriorityRatio: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return ['error' => 'Internal server error'];
    }
}

/**
 * Get Allocations Remaining
 * 
 * @description Calculates remaining visa allocations for current financial year
 * @param int $visa_type_id The ID of the visa type to analyze
 * @return array Remaining allocation details
 */
function getAllocationsRemaining($visa_type_id) {
    global $conn;
    
    try {
        $fy_dates = getCurrentFinancialYearDates();
        
        // Get annual allocation for current FY
        $allocation_query = "
            SELECT allocation_amount
            FROM visa_allocations
            WHERE visa_type_id = ?
            AND financial_year_start = ?
        ";
        
        $stmt = mysqli_prepare($conn, $allocation_query);
        mysqli_stmt_bind_param($stmt, 'ii', $visa_type_id, $fy_dates['fy_start_year']);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $allocation = mysqli_fetch_assoc($result);
        
        if (!$allocation) {
            // If no allocation found for current FY, return 0 remaining places
            return [
                'total_allocation' => 0,
                'total_processed' => 0,
                'remaining' => 0,
                'financial_year' => $fy_dates['fy_label'],
                'percentage_used' => 0,
                'monthly_breakdown' => []
            ];
        }
        
        // Get total processed this FY using the same query structure as getTotalProcessedToDate
        $processed_query = "
            WITH MonthlySnapshots AS (
                SELECT 
                    vqu.update_month,
                    vqu.lodged_month_id,
                    vqu.queue_count,
                    vl.lodged_month
                FROM visa_queue_updates vqu
                JOIN visa_lodgements vl ON vqu.lodged_month_id = vl.id
                WHERE vl.visa_type_id = ?
                AND vqu.update_month BETWEEN DATE_SUB(?, INTERVAL 1 MONTH) AND ?
            ),
            ProcessingSummary AS (
                SELECT 
                    curr.update_month,
                    SUM(GREATEST(0, prev.queue_count - curr.queue_count)) as monthly_total
                FROM MonthlySnapshots curr
                LEFT JOIN MonthlySnapshots prev 
                    ON prev.lodged_month_id = curr.lodged_month_id
                    AND prev.update_month = (
                        SELECT MAX(update_month)
                        FROM MonthlySnapshots
                        WHERE update_month < curr.update_month
                        AND lodged_month_id = curr.lodged_month_id
                    )
                WHERE curr.update_month BETWEEN ? AND ?
                GROUP BY curr.update_month
                HAVING monthly_total > 0
            )
            SELECT 
                SUM(monthly_total) as total_processed,
                GROUP_CONCAT(
                    CONCAT(update_month, ': ', monthly_total)
                    ORDER BY update_month
                    SEPARATOR '; '
                ) as monthly_breakdown
            FROM ProcessingSummary
        ";
        
        $stmt = mysqli_prepare($conn, $processed_query);
        mysqli_stmt_bind_param($stmt, 'issss', 
            $visa_type_id, 
            $fy_dates['start_date'],  // For DATE_SUB
            $fy_dates['end_date'],    // For MonthlySnapshots BETWEEN end
            $fy_dates['start_date'],  // For ProcessingSummary BETWEEN start
            $fy_dates['end_date']     // For ProcessingSummary BETWEEN end
        );
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $processed = mysqli_fetch_assoc($result);
        
        $total_allocation = intval($allocation['allocation_amount']);
        $total_processed = intval($processed['total_processed']);
        $remaining = max(0, $total_allocation - $total_processed);
        
        // Add debug logging
        error_log("Allocations calculation: Total={$total_allocation}, Processed={$total_processed}, Remaining={$remaining}");
        error_log("Monthly processing breakdown: " . $processed['monthly_breakdown']);
        
        // Add type checking and debug logging
        $monthly_breakdown = $processed['monthly_breakdown'] ?? '';
        error_log("Monthly breakdown type: " . gettype($monthly_breakdown));
        error_log("Monthly breakdown value: " . var_export($monthly_breakdown, true));
        
        $breakdown_array = [];
        if (is_string($monthly_breakdown) && strlen(trim($monthly_breakdown)) > 0) {
            try {
                $breakdown_array = safeExplode('; ', $monthly_breakdown);
                error_log("Successfully split breakdown into " . count($breakdown_array) . " parts");
            } catch (Exception $e) {
                error_log("Error splitting breakdown: " . $e->getMessage());
                $breakdown_array = [];
            }
        } else {
            error_log("Monthly breakdown was not a valid string");
        }

        return [
            'total_allocation' => $total_allocation,
            'total_processed' => $total_processed,
            'remaining' => $remaining,
            'financial_year' => $fy_dates['fy_label'],
            'percentage_used' => $total_allocation > 0 ? 
                round(($total_processed / $total_allocation) * 100, 1) : 0,
            'monthly_breakdown' => $breakdown_array
        ];
        
    } catch (Exception $e) {
        error_log("Error in getAllocationsRemaining: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return ['error' => 'Internal server error'];
    }
}

/**
 * Get Visa Processing Prediction
 * 
 * @description Calculates estimated processing time based on queue position and processing rates
 * @param int $visa_type_id The ID of the visa type to analyze
 * @param string $lodgement_date The reference lodgement date in YYYY-MM-DD format
 * @return array Prediction details including estimated dates and confidence levels
 */
function getVisaProcessingPrediction($visa_type_id, $lodgement_date) {
    global $conn;
    
    try {
        // Get required data
        $cases_ahead = getCasesAheadInQueue($visa_type_id, $lodgement_date);
        $allocations = getAllocationsRemaining($visa_type_id);
        $priority_ratio = getPriorityRatio($visa_type_id, $lodgement_date);
        $fy_dates = getCurrentFinancialYearDates();
        $weighted_average = getWeightedAverageProcessingRate($visa_type_id);
        
        // Check if application is from previous financial year
        $lodgement_date_obj = new DateTime($lodgement_date);
        $current_fy_start = new DateTime($fy_dates['start_date']);
        $is_previous_fy = $lodgement_date_obj < $current_fy_start;
        
        // Get case age statistics to check modal age
        $age_stats = getCaseAgeStatistics($visa_type_id, $lodgement_date);

        // Calculate if application is significantly older than mean age
        $is_overdue = false;
        if ($age_stats && !isset($age_stats['error'])) {
            $mean_age = $age_stats['mean_age'];
            $current_age = $age_stats['reference_case']['age'];
            $std_dev = $age_stats['std_dev'];
            
            // Check if application is more than 1 standard deviation older than mean age
            $age_threshold = $mean_age + $std_dev;
            $is_overdue = $current_age > $age_threshold;
            
            error_log("Overdue check: Current age: $current_age, Mean age: $mean_age, Std Dev: $std_dev, Threshold: $age_threshold, Is overdue: " . ($is_overdue ? 'true' : 'false'));
        }

        // Validate required data
        if (isset($cases_ahead['error']) || isset($allocations['error']) || isset($priority_ratio['error'])) {
            return ['error' => 'Insufficient data for prediction'];
        }

        // Calculate non-priority places remaining
        $priority_percentage = $priority_ratio['priority_percentage'] / 100;
        $non_priority_ratio = 1 - $priority_percentage;
        $non_priority_places = round($allocations['remaining'] * $non_priority_ratio);
        
        // Check if processing likely in next FY - ONLY if not overdue
        if (!$is_overdue && $cases_ahead['total_ahead'] > $non_priority_places) {
            // Calculate base rate
            $base_rate = $weighted_average * $non_priority_ratio;
            error_log("Initial base rate for next FY case: $base_rate");
            
            // Apply 80% reduction for previous FY applications
            $non_priority_rate = $is_previous_fy ? ($weighted_average * 0.8) : $weighted_average;
            error_log("Non-priority rate before minimum check: $non_priority_rate");
            
            // Apply minimum rate after reduction
            $min_rate = 100;
            $non_priority_rate = max($non_priority_rate, $min_rate);
            error_log("Final non-priority rate after minimum check: $non_priority_rate");
            
            // Add debug logging
            error_log("Next FY calculation details:");
            error_log("- Base rate: $base_rate");
            error_log("- Non-priority rate: $non_priority_rate");
            error_log("- Weighted average: $weighted_average");
            error_log("- Priority percentage: " . ($priority_percentage * 100) . "%");
            
            return [
                'next_fy' => true,
                'message' => 'Processing likely in next financial year. A prediction can only be made after the budget is announced as the Visa allocations will be set in the budget.',
                'cases_ahead' => $cases_ahead['total_ahead'],
                'places_remaining' => $non_priority_places,
                'last_update' => $cases_ahead['latest_update'],
                'current_fy_start' => $fy_dates['start_date'],
                'weighted_average' => $weighted_average,
                'non_priority_rate' => $non_priority_rate,
                'total_cases' => $cases_ahead['total_ahead'],
                'eighty_percentile_cases' => round($cases_ahead['total_ahead'] * 0.8),
                'seventy_percentile_cases' => round($cases_ahead['total_ahead'] * 0.7),
                'months_to_process' => null,  // Cannot predict for next FY
                'steps' => [
                    'priority_percentage' => $priority_percentage,
                    'non_priority_ratio' => $non_priority_ratio,
                    'non_priority_places' => $non_priority_places,
                    'weighted_average' => $weighted_average,
                    'non_priority_rate' => $non_priority_rate,
                    'base_rate' => $base_rate
                ]
            ];
        }
        
        // Get processing rate data
        $weighted_average = getWeightedAverageProcessingRate($visa_type_id);
        if (!$weighted_average) {
            error_log("Weighted average is zero or null");
            return ['error' => 'Unable to calculate processing rate'];
        }
        
        // Add detailed debug logging
        error_log("Rate calculation details:");
        error_log("Weighted average processing rate: $weighted_average");
        error_log("Priority percentage: " . ($priority_ratio['priority_percentage']) . "%");
        error_log("Non-priority ratio: $non_priority_ratio");
        
        // Calculate base rate
        $base_rate = $weighted_average * $non_priority_ratio;
        error_log("Initial base rate: $base_rate");
        
        // Cap priority percentage at 25% for worst case scenario
        $original_priority_percentage = $priority_percentage;
        if ($priority_percentage > 0.25) {
            error_log("Priority percentage capped from " . ($priority_percentage * 100) . "% to 25%");
            $priority_percentage = 0.25;
            $non_priority_ratio = 0.75; // 1 - 0.25
        }
        
        // Apply 80% reduction for previous FY applications
        $non_priority_rate = $is_previous_fy ? ($weighted_average * 0.8) : $weighted_average;
        error_log("Final non-priority rate: $non_priority_rate (is_previous_fy: " . ($is_previous_fy ? 'true' : 'false') . ")");
        
        // Apply minimum rate after reduction
        $min_rate = 100;
        $non_priority_rate = max($non_priority_rate, $min_rate);
        error_log("Base rate after minimum applied: $non_priority_rate");
        
        if ($non_priority_rate <= 0) {
            error_log("Non-priority rate calculation resulted in zero or negative: $non_priority_rate");
            return ['error' => 'Unable to calculate valid processing rate'];
        }
        
        // Calculate cases for different percentiles
        $total_cases = $cases_ahead['total_ahead'];
        $ninety_percentile_cases = ceil($total_cases * 0.9); // 90% of cases ahead
        $eighty_percentile_cases = ceil($total_cases * 0.8); // 80% of cases ahead
        $seventy_percentile_cases = ceil($total_cases * 0.7); // 70% of cases ahead
        
        // Calculate months to process for each scenario
        $months_to_process = $total_cases / $non_priority_rate;
        $months_to_ninety = $ninety_percentile_cases / $non_priority_rate;
        $months_to_eighty = $eighty_percentile_cases / $non_priority_rate;  // This is the one we use for display
        $months_to_seventy = $seventy_percentile_cases / $non_priority_rate;
        
        // Add debug logging
        error_log("Processing time calculation:");
        error_log("80th percentile cases: $eighty_percentile_cases");
        error_log("Non-priority rate: $non_priority_rate");
        error_log("Months to process (80th): $months_to_eighty");
        
        // Calculate days for each scenario
        $days_to_process = ceil($months_to_process * 30.44);
        $days_to_ninety = ceil($months_to_ninety * 30.44);
        $days_to_eighty = ceil($months_to_eighty * 30.44);
        $days_to_seventy = ceil($months_to_seventy * 30.44);
        
        // Calculate prediction dates
        $latest_update = new DateTime($cases_ahead['latest_update']);
        
        $latest_date = clone $latest_update;
        $latest_date->add(new DateInterval("P{$days_to_process}D"));
        
        $ninety_percent = clone $latest_update;
        $ninety_percent->add(new DateInterval("P{$days_to_ninety}D"));
        
        $eighty_percent = clone $latest_update;
        $eighty_percent->add(new DateInterval("P{$days_to_eighty}D"));
        
        $seventy_percent = clone $latest_update;
        $seventy_percent->add(new DateInterval("P{$days_to_seventy}D"));
        
        // Check if application is from previous financial year and prediction is very soon
        $today = new DateTime();
        $tomorrow = (clone $today)->add(new DateInterval('P1D'));
        $prediction_date = new DateTime($ninety_percent->format('Y-m-d'));
        $application_date = new DateTime($lodgement_date);
        $application_age = $today->diff($application_date);
        
        $is_very_overdue = $is_previous_fy && $prediction_date <= $tomorrow;
        
        // In the return array, add age statistics information
        return [
            'next_fy' => false,
            'is_overdue' => $is_overdue,
            'age_stats' => [
                'current_age' => $current_age ?? null,
                'mean_age' => $mean_age ?? null,  // Changed from modal_age
                'std_dev' => $std_dev ?? null,
                'age_threshold' => $age_threshold ?? null
            ],
            'latest_date' => $latest_date->format('Y-m-d'),
            'ninety_percent' => $ninety_percent->format('Y-m-d'),
            'eighty_percent' => $eighty_percent->format('Y-m-d'),
            'seventy_percent' => $seventy_percent->format('Y-m-d'),
            'cases_ahead' => $total_cases,
            'places_remaining' => $non_priority_places,
            'last_update' => $cases_ahead['latest_update'],
            'weighted_average' => $weighted_average,
            'is_previous_fy' => $is_previous_fy,
            'is_very_overdue' => $is_very_overdue,
            'current_fy_start' => $fy_dates['start_date'],
            'lodgement_date' => $lodgement_date,
            'priority_percentage_capped' => $original_priority_percentage > 0.25,
            'original_priority_percentage' => $original_priority_percentage,
            'application_age' => [
                'years' => $application_age->y,
                'months' => $application_age->m,
                'total_months' => ($application_age->y * 12) + $application_age->m
            ],
            'steps' => [
                'priority_percentage' => $priority_percentage,
                'non_priority_ratio' => $non_priority_ratio,
                'non_priority_places' => $non_priority_places,
                'non_priority_rate' => round($non_priority_rate, 1),
                'base_rate' => round($weighted_average, 1),
                'weighted_average' => round($weighted_average, 1),
                'months_to_process' => $months_to_eighty,  // Changed from $months_to_process
                'total_cases' => $total_cases,
                'eighty_percentile_cases' => $eighty_percentile_cases,
                'seventy_percentile_cases' => $seventy_percentile_cases,
                'rate_adjustment' => $is_previous_fy ? '80% of base rate' : 'Standard rate',
                'overdue_status' => $is_very_overdue ? 'Check Recommended' : 'On Track'
            ]
        ];
        
    } catch (Exception $e) {
        error_log("Error in getVisaProcessingPrediction: " . $e->getMessage());
        return ['error' => 'Internal server error'];
    }
}

/**
 * Get Financial Year Dates
 * 
 * @description Calculates financial year dates considering data is reported in arrears
 * @return array Start and end dates for the current financial year
 */
function getCurrentFinancialYearDates() {
    $current_date = new DateTime();
    $current_year = (int)$current_date->format('Y');
    $current_month = (int)$current_date->format('m');
    
    // If we're in July or later, FY starts this year
    $fy_start_year = ($current_month >= 7) ? $current_year : $current_year - 1;
    
    return [
        'start_date' => sprintf('%d-07-01', $fy_start_year),  // July
        'end_date' => sprintf('%d-06-30', $fy_start_year + 1),  // June next year
        'fy_start_year' => $fy_start_year,
        'fy_label' => sprintf('FY%d-%d', $fy_start_year, ($fy_start_year + 1) % 100)
    ];
}

/**
 * Get Oldest Lodgement Date
 * 
 * @description Retrieves the oldest lodged_month for a given visa type ID
 * @param int $visa_type_id The ID of the visa type
 * @return array Oldest lodged_month details
 */
function getOldestLodgementDate($visa_type_id) {
    global $conn;
    
    try {
        $query = "
            SELECT MIN(lodged_month) as oldest_date
            FROM visa_lodgements
            WHERE visa_type_id = ?
        ";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'i', $visa_type_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);

        if ($row && $row['oldest_date']) {
            error_log("Found oldest date for visa type ID $visa_type_id: " . $row['oldest_date']);
            return ['oldest_date' => $row['oldest_date']];
        } else {
            error_log("No lodgement dates found for visa type ID $visa_type_id");
            return ['error' => 'No data found'];
        }
    } catch (Exception $e) {
        error_log("Error in getOldestLodgementDate: " . $e->getMessage());
        return ['error' => 'Internal server error'];
    }
}

/**
 * Get Age Message
 * 
 * @description Returns a humorous message based on application age
 * @param object $interval DateInterval object containing the application age
 * @return string|null Humorous message or null if no milestone matches
 */
function getAgeMessage($interval) {
    $months = $interval->y * 12 + $interval->m;
    
    $messages = [
        3 => "Did you know that the world's fastest growing plant, bamboo, can grow up to 3 feet in a day? Your visa application is 3 months old—if it were bamboo, it would be a whole forest by now! 🎍",
        6 => "A honeybee colony produces up to 100 pounds of honey in 6 months! 🍯 Your visa application is as old as half a year's worth of sweet, golden progress.",
        9 => "It takes 9 months to build the tallest skyscraper ever constructed in record time! 🏗️ Your visa application is now the age of a miracle in modern engineering.",
        12 => "Happy anniversary to your visa application! 🎉 It has lasted as long as the time between two Olympic Games! 🏅",
        15 => "It took Michelangelo 4 years to paint the Sistine Chapel, meaning your visa application is already 1/3rd of the way there! 🎨",
        18 => "In 18 months, the Mars Rover traveled over 12 miles on the Red Planet! 🚀 Your visa application has been waiting long enough to go sightseeing on Mars.",
        21 => "Did you know that it took 21 months to build the Eiffel Tower? 🇫🇷 If your visa were a landmark, it would be halfway done!",
        24 => "You could have completed 4 full university semesters in the time you've been waiting! 🎓",
        27 => "It takes 27 months to train an astronaut for a mission to space. 🧑‍🚀 Your visa application is as old as a future space explorer's training!",
        30 => "A blue whale calf doubles in size within its first 30 months! 🐋 Your visa application has been waiting as long as a whale has been growing massive.",
        33 => "At 33 months, your application is older than the average time it takes for an elephant to give birth! 🐘",
        36 => "Your visa application is as old as the longest championship chess match ever played (36 months)! ♟️",
        40 => "The Great Wall of China took centuries to build, but in 40 months, significant sections were completed! 🏯",
        44 => "In 44 months, you could have walked around the entire Earth at a leisurely pace! 🌍🚶",
        48 => "It took 48 months to build the Titanic. Let's hope your visa has a happier journey! 🚢",
        52 => "In 52 months, a tree could have grown tall enough to provide shade for a picnic. 🌳",
        56 => "A scientist could have completed an entire PhD dissertation in the time your visa has been waiting! 🎓",
        60 => "Your visa application is now 5 years old—the same age as a child starting school! 📚🎒"
    ];
    
    
    // Find the closest milestone without going over
    $milestone = 0;
    foreach (array_keys($messages) as $month) {
        if ($months >= $month) {
            $milestone = $month;
        } else {
            break;
        }
    }
    
    return $milestone > 0 ? $messages[$milestone] : null;
}

// Add this helper function at the top of functions.php
function safeExplode($delimiter, $string) {
    if (!is_string($string)) {
        error_log("Attempted to explode non-string value: " . var_export($string, true));
        error_log("Called from: " . debug_backtrace()[0]['file'] . ":" . debug_backtrace()[0]['line']);
        return [];
    }
    return explode($delimiter, $string);
}

/**
 * Get Visa Queue Data
 * 
 * @description Retrieves the queue history for a specific visa type
 * @param int $visa_type_id The ID of the visa type to get queue data for
 * @return array|false Queue data including lodgement history and updates
 */
function getVisaQueueData($visa_type_id) {
    global $conn;
    
    try {
        // First get the visa type details
        $visa_query = "SELECT visa_type FROM visa_types WHERE id = ?";
        $stmt = mysqli_prepare($conn, $visa_query);
        mysqli_stmt_bind_param($stmt, "i", $visa_type_id);
        mysqli_stmt_execute($stmt);
        $visa_result = mysqli_stmt_get_result($stmt);
        $visa_type = mysqli_fetch_assoc($visa_result);

        if (!$visa_type) {
            return false;
        }

        // Get all lodgement months and their updates
        $query = "
            SELECT 
                vl.id,
                vl.lodged_month,
                vl.first_count_volume as first_count,
                JSON_ARRAYAGG(
                    JSON_OBJECT(
                        'update_month', vqu.update_month,
                        'queue_count', vqu.queue_count
                    )
                ) as updates
            FROM visa_lodgements vl
            LEFT JOIN visa_queue_updates vqu ON vl.id = vqu.lodged_month_id
            WHERE vl.visa_type_id = ?
            GROUP BY vl.id, vl.lodged_month, vl.first_count_volume
            ORDER BY vl.lodged_month DESC
        ";

        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $visa_type_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        $lodgements = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $row['updates'] = json_decode($row['updates'], true);
            $lodgements[] = $row;
        }

        return [
            'visa_type' => $visa_type['visa_type'],
            'lodgements' => $lodgements
        ];

    } catch (Exception $e) {
        error_log("Error in getVisaQueueData: " . $e->getMessage());
        return false;
    }
}

/**
 * Get the current length of the queue
 * 
 * @return int Number of messages in the queue
 */
function get_queue_length() {
    global $db;
    
    $sql = "SELECT COUNT(*) as count FROM message_queue";
    $result = $db->query($sql);
    $row = $result->fetch_assoc();
    
    return (int)$row['count'];
}

/**
 * Get Case Age Statistics
 * 
 * @description Calculates statistical measures for visa processing ages in current FY
 * @param int $visa_type_id The ID of the visa type to analyze
 * @param string|null $lodgement_date Optional reference date for comparison
 * @return array Statistical measures including mean, mode, standard deviation, and variance
 */
function getCaseAgeStatistics($visa_type_id, $lodgement_date = null) {
    global $conn;
    
    try {
        $fy_dates = getCurrentFinancialYearDates();
        
        // Modified query to ensure we're only counting actual grants (where queue count decreases)
        $query = "
            WITH MonthlyChanges AS (
                -- Get all month-to-month changes where count decreased (indicating grants)
                SELECT 
                    vl.lodged_month,
                    curr.update_month as grant_month,
                    prev.queue_count - curr.queue_count as grants_count,
                    TIMESTAMPDIFF(MONTH, vl.lodged_month, curr.update_month) as age_at_grant
                FROM visa_queue_updates curr
                JOIN visa_lodgements vl ON curr.lodged_month_id = vl.id
                LEFT JOIN visa_queue_updates prev 
                    ON prev.lodged_month_id = curr.lodged_month_id
                    AND prev.update_month = (
                        SELECT MAX(update_month)
                        FROM visa_queue_updates
                        WHERE update_month < curr.update_month
                        AND lodged_month_id = curr.lodged_month_id
                    )
                WHERE vl.visa_type_id = ?
                AND curr.update_month BETWEEN ? AND ?
                AND curr.queue_count < COALESCE(prev.queue_count, curr.queue_count + 1)
            ),
            Numbers AS (
                SELECT 1 as n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL 
                SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL 
                SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
                UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL 
                SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL 
                SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20
                UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL 
                SELECT 24 UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL 
                SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29 UNION ALL SELECT 30
                UNION ALL SELECT 31 UNION ALL SELECT 32 UNION ALL SELECT 33 UNION ALL 
                SELECT 34 UNION ALL SELECT 35 UNION ALL SELECT 36 UNION ALL 
                SELECT 37 UNION ALL SELECT 38 UNION ALL SELECT 39 UNION ALL SELECT 40
                UNION ALL SELECT 41 UNION ALL SELECT 42 UNION ALL SELECT 43 UNION ALL 
                SELECT 44 UNION ALL SELECT 45 UNION ALL SELECT 46 UNION ALL 
                SELECT 47 UNION ALL SELECT 48 UNION ALL SELECT 49 UNION ALL SELECT 50
            ),
            ProcessedCases AS (
                -- Expand each change into individual cases
                SELECT 
                    mc.lodged_month,
                    mc.grant_month,
                    mc.age_at_grant
                FROM MonthlyChanges mc
                JOIN Numbers n ON n.n <= mc.grants_count
            ),
            AgeStats AS (
                SELECT 
                    AVG(age_at_grant) as mean_age,
                    STDDEV(age_at_grant) as std_dev,
                    VARIANCE(age_at_grant) as variance,
                    COUNT(*) as total_cases,
                    MIN(age_at_grant) as min_age,
                    MAX(age_at_grant) as max_age
                FROM ProcessedCases
            ),
            ModeCTE AS (
                SELECT 
                    age_at_grant,
                    COUNT(*) as frequency
                FROM ProcessedCases
                GROUP BY age_at_grant
                ORDER BY COUNT(*) DESC, age_at_grant ASC
                LIMIT 1
            )
            SELECT 
                a.*,
                m.age_at_grant as modal_age,
                GROUP_CONCAT(DISTINCT pc.age_at_grant ORDER BY pc.age_at_grant) as all_ages
            FROM AgeStats a
            CROSS JOIN ModeCTE m
            CROSS JOIN (SELECT DISTINCT age_at_grant FROM ProcessedCases) pc
            GROUP BY a.mean_age, a.std_dev, a.variance, a.total_cases, 
                     a.min_age, a.max_age, m.age_at_grant
        ";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'iss', 
            $visa_type_id,
            $fy_dates['start_date'],
            $fy_dates['end_date']
        );
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $stats = mysqli_fetch_assoc($result);
        
        if (!$stats || $stats['total_cases'] == 0) {
            return ['error' => 'No processing data available for this visa type'];
        }

        // Debug log to check raw values
        error_log("Raw stats: " . print_r($stats, true));
        
        // Calculate mode from all_ages (as a backup verification)
        $ages = array_map('intval', explode(',', $stats['all_ages']));
        $age_counts = array_count_values($ages);
        arsort($age_counts);
        $calculated_mode = key($age_counts);
        
        // Use the directly calculated mode from SQL if available, otherwise use the backup
        $mode = $stats['modal_age'] ?? $calculated_mode;
        
        // Calculate one standard deviation range
        $mean = floatval($stats['mean_age']);
        $std_dev = floatval($stats['std_dev']);
        $std_dev_lower = $mean - $std_dev;
        $std_dev_upper = $mean + $std_dev;
        
        // Calculate percentile of reference case if provided
        $percentile = null;
        $reference_age = null;
        if ($lodgement_date) {
            // Use TIMESTAMPDIFF for consistent age calculation
            $query = "SELECT TIMESTAMPDIFF(MONTH, ?, CURRENT_DATE) as reference_age";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "s", $lodgement_date);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($result);
            $reference_age = $row['reference_age'];
            
            // Debug log
            error_log("Reference case age calculation: Lodgement date: $lodgement_date, Age: $reference_age months");
            
            // Calculate percentile
            $cases_older = 0;
            foreach ($ages as $age) {
                if ($age > $reference_age) {
                    $cases_older++;
                }
            }
            $percentile = (($cases_older) / count($ages)) * 100;
        }
        
        // Prepare statistical summary
        $stats_summary = [
            'mean_age' => round($mean, 1),
            'modal_age' => $mode,
            'std_dev' => round($std_dev, 1),
            'variance' => round(floatval($stats['variance']), 1),
            'std_dev_range' => [
                'lower' => round($std_dev_lower, 1),
                'upper' => round($std_dev_upper, 1)
            ],
            'age_range' => [
                'min' => intval($stats['min_age']),
                'max' => intval($stats['max_age'])
            ],
            'total_cases' => intval($stats['total_cases']),
            'financial_year' => $fy_dates['fy_label']
        ];
        
        // Add reference case comparison if provided
        if ($lodgement_date) {
            $stats_summary['reference_case'] = [
                'age' => $reference_age,
                'percentile' => round($percentile, 1),
                'std_deviations_from_mean' => round(($reference_age - $mean) / $std_dev, 1),
                'comparison_to_mode' => $reference_age - $mode // Add comparison to mode
            ];
            
            // Updated interpretation focusing on mode
            $stats_summary['interpretation'] = [
                'age_comparison' => $reference_age > $mode ? 'older' : 'younger',
                'mode_difference' => abs($reference_age - $mode),
                'deviation_significance' => abs(($reference_age - $mean) / $std_dev) > 2 ? 'significant' : 'normal',
                'percentile_interpretation' => $percentile > 75 ? 'high' : ($percentile < 25 ? 'low' : 'moderate'),
                'use_mode_explanation' => true // Flag to show mode explanation
            ];
        }
        
        // Get age distribution data for chart
        $distribution_query = "
            WITH MonthlyProcessed AS (
                SELECT 
                    vl.lodged_month,
                    curr.update_month,
                    GREATEST(0, prev.queue_count - curr.queue_count) as processed_count
                FROM visa_queue_updates curr
                JOIN visa_lodgements vl ON curr.lodged_month_id = vl.id
                LEFT JOIN visa_queue_updates prev 
                    ON prev.lodged_month_id = curr.lodged_month_id
                    AND prev.update_month = (
                        SELECT MAX(update_month)
                        FROM visa_queue_updates
                        WHERE update_month < curr.update_month
                        AND lodged_month_id = curr.lodged_month_id
                    )
                WHERE vl.visa_type_id = ?
                AND curr.update_month BETWEEN ? AND ?
                AND prev.queue_count IS NOT NULL
            ),
            ProcessedCases AS (
                -- This should match the logic in getTotalProcessedToDate
                SELECT 
                    TIMESTAMPDIFF(MONTH, lodged_month, update_month) as age_at_grant,
                    processed_count as count
                FROM MonthlyProcessed
                WHERE processed_count > 0
            ),
            AgeStats AS (
                SELECT 
                    age_at_grant,
                    SUM(count) as total_count
                FROM ProcessedCases
                GROUP BY age_at_grant
            )
            SELECT 
                age_at_grant as age,
                total_count as count
            FROM AgeStats
            ORDER BY age_at_grant;
        ";

        $stmt = mysqli_prepare($conn, $distribution_query);
        mysqli_stmt_bind_param($stmt, 'iss', 
            $visa_type_id,
            $fy_dates['start_date'],
            $fy_dates['end_date']
        );
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        $distribution_data = [
            'labels' => [],
            'counts' => [],
        ];

        // Get the total processed count first
        $total_processed = getTotalProcessedToDate($visa_type_id);

        // Debug: Log the raw distribution data
        $debug_data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $distribution_data['labels'][] = $row['age'];
            $distribution_data['counts'][] = intval($row['count']);
            $debug_data[] = $row;
        }
        error_log("Age distribution raw data: " . print_r($debug_data, true));
        error_log("Total cases in distribution: " . array_sum($distribution_data['counts']));

        // Add distribution data to the stats summary
        $stats_summary['distribution'] = $distribution_data;
        
        // After executing the query, add this debug log:
        $total_histogram_count = array_sum($distribution_data['counts']);
        error_log("Histogram total count: $total_histogram_count should match total processed: " . $total_processed['total_processed']);

        // Format the data for the chart - using the raw data directly
        $stats_summary['histogram_data'] = array_map(function($age, $count) {
            return [
                'start' => intval($age),
                'end' => intval($age),
                'count' => intval($count)
            ];
        }, $distribution_data['labels'], $distribution_data['counts']);
        
        return $stats_summary;
        
    } catch (Exception $e) {
        error_log("Error in getCaseAgeStatistics: " . $e->getMessage());
        return ['error' => 'Internal server error'];
    }
}

/**
 * Get Historical and Projected Grant Ages
 * 
 * @param int $visa_type_id The visa type ID
 * @return array Historical and projected grant age data
 */
function getGrantAgeProjection($visa_type_id) {
    global $conn;
    
    try {
        // Get queue metrics using Little's Law
        $queue_metrics = calculateLittlesLaw($visa_type_id, $application_date ?? null);
        if (isset($queue_metrics['error'])) {
            throw new Exception($queue_metrics['error']);
        }

        // Get existing data
        $allocations = getAllocationsRemaining($visa_type_id);
        $total_processed = getTotalProcessedToDate($visa_type_id);
        $processing_rate = $queue_metrics['processing_rate']; // Use Little's Law processing rate
        
        // Calculate remaining places before cap
        $remaining_places = $allocations['remaining'];
        $total_allocation = $allocations['total_allocation'];
        
        // Get historical modal ages by month
        $historical_query = "
            WITH MonthlyProcessing AS (
                SELECT 
                    vl.lodged_month,
                    curr.update_month,
                    GREATEST(0, prev.queue_count - curr.queue_count) as processed_count,
                    TIMESTAMPDIFF(MONTH, vl.lodged_month, curr.update_month) as age_at_grant
                FROM visa_queue_updates curr
                JOIN visa_lodgements vl ON curr.lodged_month_id = vl.id
                LEFT JOIN visa_queue_updates prev 
                    ON prev.lodged_month_id = curr.lodged_month_id
                    AND prev.update_month = (
                        SELECT MAX(update_month)
                        FROM visa_queue_updates
                        WHERE update_month < curr.update_month
                        AND lodged_month_id = curr.lodged_month_id
                    )
                WHERE vl.visa_type_id = ?
                AND prev.queue_count IS NOT NULL
            ),
            MonthlyTotals AS (
                SELECT 
                    DATE_FORMAT(update_month, '%Y-%m-01') as month,
                    SUM(processed_count) as total_processed,
                    SUM(SUM(processed_count)) OVER (ORDER BY DATE_FORMAT(update_month, '%Y-%m-01')) as running_total
                FROM MonthlyProcessing
                GROUP BY DATE_FORMAT(update_month, '%Y-%m-01')
            ),
            AgeGroups AS (
                SELECT 
                    DATE_FORMAT(update_month, '%Y-%m-01') as month,
                    age_at_grant,
                    SUM(processed_count) as count_at_age,
                    ROW_NUMBER() OVER (
                        PARTITION BY DATE_FORMAT(update_month, '%Y-%m-01') 
                        ORDER BY SUM(processed_count) DESC
                    ) as rn
                FROM MonthlyProcessing
                GROUP BY 
                    DATE_FORMAT(update_month, '%Y-%m-01'),
                    age_at_grant
            )
            SELECT 
                mt.month,
                ag.age_at_grant as modal_age,
                mt.total_processed as grants,
                mt.running_total
            FROM MonthlyTotals mt
            LEFT JOIN AgeGroups ag ON mt.month = ag.month AND ag.rn = 1
            ORDER BY mt.month DESC
        ";

        // Execute query and get historical data
        $stmt = mysqli_prepare($conn, $historical_query);
        mysqli_stmt_bind_param($stmt, 'i', $visa_type_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $historical_data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $historical_data[] = [
                'month' => $row['month'],
                'modal_age' => intval($row['modal_age']),
                'grants' => intval($row['grants']),
                'running_total' => intval($row['running_total']),
                'allocation_status' => number_format(round($row['running_total'])) . " / " . number_format($total_allocation)
            ];
        }

        // Get current queue state with age profile
        $queue_query = "
            WITH CurrentQueue AS (
                SELECT 
                    vl.lodged_month,
                    vqu.queue_count,
                    TIMESTAMPDIFF(MONTH, vl.lodged_month, CURRENT_DATE) as current_age
                FROM visa_lodgements vl
                JOIN visa_queue_updates vqu ON vl.id = vqu.lodged_month_id
                WHERE vl.visa_type_id = ?
                AND vqu.update_month = (
                    SELECT MAX(update_month)
                    FROM visa_queue_updates
                    WHERE lodged_month_id = vl.id
                )
                AND vqu.queue_count > 0
                ORDER BY lodged_month ASC
            )
            SELECT 
                current_age,
                SUM(queue_count) as total_in_age_group
            FROM CurrentQueue
            GROUP BY current_age
            ORDER BY current_age ASC";  // Order by age ascending to process oldest first

        $stmt = mysqli_prepare($conn, $queue_query);
        mysqli_stmt_bind_param($stmt, 'i', $visa_type_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        // Build queue data
        $queue_data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $queue_data[] = [
                'age' => intval($row['current_age']),
                'count' => intval($row['total_in_age_group'])
            ];
        }

        // Project next 6 months using Little's Law metrics
        $projected_data = [];
        $current_date = new DateTime();
        $current_date->modify('first day of this month');
        
        $cap_reached = false;
        $running_total = $total_processed['total_processed'];
        $remaining_queue = $queue_data;
        
        // Calculate expected waiting time per Little's Law
        $waiting_time = $queue_metrics['waiting_time'];
        $arrival_rate = $queue_metrics['arrival_rate'];
        $initial_modal_age = $queue_data[0]['age'] ?? 0;

        // Calculate the relationship between modal age and waiting time
        $age_wait_ratio = $initial_modal_age / $waiting_time;

        for ($i = 0; $i < 6; $i++) {
            $projection_month = clone $current_date;
            $projection_month->add(new DateInterval("P{$i}M"));
            
            if (!$cap_reached) {
                // Age the entire queue by one month
                $aged_queue = [];
                foreach ($remaining_queue as $group) {
                    $aged_queue[] = [
                        'age' => $group['age'] + 1,
                        'count' => $group['count']
                    ];
                }

                // Add new arrivals at age 0
                $aged_queue[] = [
                    'age' => 0,
                    'count' => round($arrival_rate)
                ];

                // Calculate how many visas will be processed this month
                $month_processing = min(
                    $processing_rate,
                    $total_allocation - $running_total
                );

                if ($month_processing > 0) {
                    // Process oldest applications first
                    usort($aged_queue, function($a, $b) {
                        return $b['age'] - $a['age'];  // Sort by age descending
                    });

                    $processed_cases = [];  // Track ages of processed cases
                    $remaining_to_process = $month_processing;
                    $updated_queue = [];

                    foreach ($aged_queue as $group) {
                        if ($remaining_to_process <= 0) {
                            // No more processing needed, keep remaining cases
                            $updated_queue[] = $group;
                            continue;
                        }

                        $to_process = min($remaining_to_process, $group['count']);
                        $remaining = $group['count'] - $to_process;

                        // Record the ages of processed cases
                        if ($to_process > 0) {
                            $processed_cases[] = [
                                'age' => $group['age'],
                                'count' => $to_process
                            ];
                        }

                        if ($remaining > 0) {
                            $updated_queue[] = [
                                'age' => $group['age'],
                                'count' => $remaining
                            ];
                        }

                        $remaining_to_process -= $to_process;
                    }

                    // Calculate modal age of processed cases
                    usort($processed_cases, function($a, $b) {
                        return $b['count'] - $a['count'];  // Sort by count descending
                    });

                    $modal_processing_age = $processed_cases[0]['age'] ?? 0;
                    $total_processed = array_sum(array_column($processed_cases, 'count'));
                    $remaining_queue = $updated_queue;
                    $running_total += $total_processed;

                    $projected_data[] = [
                        'month' => $projection_month->format('Y-m-d'),
                        'modal_age' => $modal_processing_age,  // Most common age of processed cases
                        'grants' => $total_processed,
                        'queue_size' => array_sum(array_column($updated_queue, 'count')),
                        'allocation_status' => number_format(round($running_total)) . " / " . number_format($total_allocation),
                        'expected_wait' => round(array_sum(array_column($updated_queue, 'count')) / $processing_rate, 1),
                        'system_utilization' => round($queue_metrics['utilization'], 1)
                    ];

                    if ($running_total >= $total_allocation) {
                        $cap_reached = true;
                    }
                }
            }
            
            if ($cap_reached) {
                // Even when cap is reached, age the remaining applications
                $remaining_queue = array_map(function($item) {
                    return [
                        'age' => $item['age'] + 1,
                        'count' => $item['count']
                    ];
                }, $remaining_queue);

                usort($remaining_queue, function($a, $b) {
                    return $b['count'] - $a['count'];
                });

                // Make sure we include all the same keys as non-cap-reached scenario
                $projected_data[] = [
                    'month' => $projection_month->format('Y-m-d'),
                    'modal_age' => $remaining_queue[0]['age'] ?? 0,
                    'grants' => 0,
                    'queue_size' => array_sum(array_column($remaining_queue, 'count')),
                    'allocation_status' => number_format($total_allocation) . " / " . number_format($total_allocation),
                    'expected_wait' => round(array_sum(array_column($remaining_queue, 'count')) / $processing_rate, 1),
                    'system_utilization' => round($queue_metrics['utilization'], 1),
                    'note' => 'Processing suspended until next FY'
                ];
            }
        }

        return [
            'historical' => $historical_data,
            'projected' => $projected_data,
            'processing_rate' => $processing_rate,
            'queue_metrics' => $queue_metrics,
            'allocation_info' => [
                'total_allocation' => $total_allocation,
                'remaining_places' => $remaining_places,
                'cap_reached' => $cap_reached
            ]
        ];
    } catch (Exception $e) {
        error_log("Error in getGrantAgeProjection: " . $e->getMessage());
        return ['error' => 'Internal server error'];
    }
}

function calculateProcessingDistribution($queue, $target_processing, $avg_processing_age) {
    // Sort queue by age
    usort($queue, function($a, $b) {
        return $b['age'] - $a['age'];
    });

    // Calculate normal distribution around average processing age
    $std_dev = 3; // Standard deviation of 3 months
    $distribution = [];
    $total_weight = 0;

    foreach ($queue as $group) {
        // Calculate probability based on normal distribution
        $z_score = ($group['age'] - $avg_processing_age) / $std_dev;
        $probability = exp(-0.5 * pow($z_score, 2)) / ($std_dev * sqrt(2 * M_PI));
        
        // Adjust probability based on age (older cases get higher priority)
        $age_factor = 1 + ($group['age'] / $avg_processing_age);
        $weight = $probability * $age_factor;
        
        $distribution[$group['age']] = $weight;
        $total_weight += $weight;
    }

    // Normalize distribution to match target processing
    $processing_distribution = [];
    foreach ($distribution as $age => $weight) {
        $processing_distribution[$age] = round(($weight / $total_weight) * $target_processing);
    }

    return $processing_distribution;
}

function calculateAverageProcessingAge($visa_type_id) {
    global $conn;
    
    // Query to get average processing age from recent grants
    $query = "
        WITH ProcessedCases AS (
            SELECT 
                TIMESTAMPDIFF(MONTH, vl.lodged_month, vqu.update_month) as processing_age,
                prev.queue_count - vqu.queue_count as processed_count
            FROM visa_queue_updates vqu
            JOIN visa_lodgements vl ON vqu.lodged_month_id = vl.id
            JOIN visa_queue_updates prev ON prev.lodged_month_id = vl.id
            WHERE vl.visa_type_id = ?
            AND vqu.update_month > DATE_SUB(CURRENT_DATE, INTERVAL 3 MONTH)
            AND prev.update_month = (
                SELECT MAX(update_month)
                FROM visa_queue_updates
                WHERE update_month < vqu.update_month
                AND lodged_month_id = vl.id
            )
            AND prev.queue_count > vqu.queue_count
        )
        SELECT 
            AVG(processing_age) as avg_age
        FROM ProcessedCases";

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $visa_type_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    return round($row['avg_age'] ?? 20, 1); // Default to 20 months if no data
}

function calculateLittlesLaw($visa_type_id, $application_date = null) {
    global $conn;
    
    try {
        // Get current queue length (L)
        $queue_length = getVisasOnHand($visa_type_id);
        
        // Get cases ahead if application date is provided
        $cases_ahead = 0;
        if ($application_date) {
            $cases_ahead = getCasesAheadInQueue($visa_type_id, $application_date);
            $cases_ahead = $cases_ahead['estimated_current_ahead'] ?? $queue_length;
        }
        
        // Calculate arrival rate (λ) - average new applications per month
        $arrival_query = "
            WITH MonthlyArrivals AS (
                SELECT 
                    DATE_FORMAT(lodged_month, '%Y-%m-01') as month,
                    SUM(first_count_volume) as new_applications
                FROM visa_lodgements
                WHERE visa_type_id = ?
                AND lodged_month >= DATE_SUB(CURRENT_DATE, INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(lodged_month, '%Y-%m-01')
            )
            SELECT AVG(new_applications) as avg_arrival_rate
            FROM MonthlyArrivals";
        
        $stmt = mysqli_prepare($conn, $arrival_query);
        mysqli_stmt_bind_param($stmt, 'i', $visa_type_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $arrival_rate = mysqli_fetch_assoc($result)['avg_arrival_rate'];
        
        // Get processing rate (μ) - average processed per month
        $processing_rate = getWeightedAverageProcessingRate($visa_type_id);
        
        // Calculate waiting time based on cases ahead (if available)
        $waiting_time = $application_date ? 
            $cases_ahead / $processing_rate : 
            $queue_length / $processing_rate;
        
        // Calculate system utilization (ρ)
        $utilization = $arrival_rate / $processing_rate;
        
        $metrics = [
            'queue_length' => $queue_length,
            'cases_ahead' => $cases_ahead,
            'arrival_rate' => round($arrival_rate, 2),
            'processing_rate' => round($processing_rate, 2),
            'waiting_time' => round($waiting_time, 1),
            'utilization' => round($utilization * 100, 1),
            'is_stable' => $utilization < 1,
            'expected_growth' => $arrival_rate > $processing_rate ? 
                round(($arrival_rate - $processing_rate) * 12, 0) : 0
        ];
        
        if ($utilization > 1) {
            $metrics['queue_growth_rate'] = $arrival_rate - $processing_rate;
            $metrics['backlog_months'] = ceil($queue_length / ($processing_rate - $arrival_rate));
        }
        
        return $metrics;
        
    } catch (Exception $e) {
        error_log("Error in calculateLittlesLaw: " . $e->getMessage());
        return ['error' => 'Error calculating queue metrics'];
    }
}

// Continue with other core functions...
?> 

