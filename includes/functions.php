<?php
// Add these constants at the top of functions.php
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

// #1 - handleFileUpload
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

// #2 - getVisaSubclasses
function getVisaSubclasses() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT code, name FROM visa_subclasses WHERE active = 1 ORDER BY code");
        return $stmt->fetchAll();
    } catch(PDOException $e) {
        // Log error and return empty array
        error_log("Error fetching visa subclasses: " . $e->getMessage());
        return [];
    }
}

// #3 - calculatePredictions
function calculatePredictions($visa_subclass, $application_date) {
    // Get processing rates and quotas from database
    // Calculate predictions based on application date and current processing data
    // Return array of predictions with stages, dates, and confidence levels
    
    // Example return format:
    return [
        'Initial Assessment' => [
            'date' => '2024-03-01',
            'confidence' => 85
        ],
        'Document Check' => [
            'date' => '2024-04-15',
            'confidence' => 75
        ],
        'Final Decision' => [
            'date' => '2024-06-30',
            'confidence' => 65
        ]
    ];
}

// #4 - getAllVisaTypes
function getAllVisaTypes() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT id, visa_type FROM visa_types ORDER BY visa_type");
        return $stmt->fetchAll();
    } catch(PDOException $e) {
        error_log("Error fetching visa types: " . $e->getMessage());
        return [];
    }
}

// #5 - addVisaType
function addVisaType($visa_type) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO visa_types (visa_type) VALUES (?)");
        $stmt->execute([$visa_type]);
        return "Visa type added successfully";
    } catch(PDOException $e) {
        if ($e->getCode() == 23000) {
            return "Error: This visa type already exists";
        }
        return "Error adding visa type: " . $e->getMessage();
    }
}

// #6 - deleteVisaType
function deleteVisaType($id) {
    global $pdo;
    try {
        $pdo->beginTransaction();

        // First delete related records from visa_allocations
        $stmt = $pdo->prepare("DELETE FROM visa_allocations WHERE visa_type_id = ?");
        $stmt->execute([$id]);

        // Then delete related records from visa_queue_updates and visa_lodgements
        $stmt = $pdo->prepare("
            DELETE qu FROM visa_queue_updates qu
            INNER JOIN visa_lodgements l ON qu.lodged_month_id = l.id
            WHERE l.visa_type_id = ?
        ");
        $stmt->execute([$id]);

        $stmt = $pdo->prepare("DELETE FROM visa_lodgements WHERE visa_type_id = ?");
        $stmt->execute([$id]);

        // Finally delete the visa type
        $stmt = $pdo->prepare("DELETE FROM visa_types WHERE id = ?");
        $stmt->execute([$id]);

        $pdo->commit();
        return [
            'status' => 'success',
            'message' => "Visa type and all related data deleted successfully"
        ];

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error deleting visa type: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => "Error deleting visa type: " . $e->getMessage()
        ];
    }
}

// #7 - validateImportFile
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

// #8 - processVisaQueueImport
function processVisaQueueImport($file_info, $delimiter) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Get visa type ID
        $visaTypeStmt = $pdo->prepare("SELECT id FROM visa_types WHERE visa_type = ?");
        $visaTypeStmt->execute([$file_info['visa_type']]);
        $visaTypeId = $visaTypeStmt->fetchColumn();
        
        if (!$visaTypeId) {
            throw new Exception("Invalid visa type: " . $file_info['visa_type']);
        }
        
        // Prepare statements
        $findLodgementIdStmt = $pdo->prepare("
            SELECT id, first_count_volume 
            FROM visa_lodgements 
            WHERE lodged_month = ? AND visa_type_id = ?
        ");
        
        $findQueueUpdateStmt = $pdo->prepare("
            SELECT id 
            FROM visa_queue_updates 
            WHERE lodged_month_id = ? AND update_month = ?
        ");
        
        $createLodgementStmt = $pdo->prepare("
            INSERT INTO visa_lodgements (lodged_month, visa_type_id, first_count_volume)
            VALUES (?, ?, ?)
        ");
        
        $createQueueUpdateStmt = $pdo->prepare("
            INSERT INTO visa_queue_updates (lodged_month_id, update_month, queue_count)
            VALUES (?, ?, ?)
        ");
        
        $handle = fopen($file_info['tmp_name'], "r");
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
                    $findLodgementIdStmt->execute([$formatted_months[$i], $visaTypeId]);
                    $existing = $findLodgementIdStmt->fetch();
                    
                    if (!$existing) {
                        // Create new lodgement with maximum count for this column
                        $createLodgementStmt->execute([
                            $formatted_months[$i],
                            $visaTypeId,
                            $max_counts[$i]
                        ]);
                        $lodgementId = $pdo->lastInsertId();
                        $stats['lodgements_created']++;
                    } else {
                        $lodgementId = $existing['id'];
                    }
                    
                    // Check if this update already exists
                    $findQueueUpdateStmt->execute([$lodgementId, $update_month]);
                    if (!$findQueueUpdateStmt->fetch()) {
                        // Only add new updates
                        $createQueueUpdateStmt->execute([
                            $lodgementId,
                            $update_month,
                            $queue_count
                        ]);
                        $stats['queue_updates']++;
                    } else {
                        $stats['skipped_updates']++;
                    }
                }
            }
            $stats['rows_processed']++;
        }
        
        fclose($handle);
        $pdo->commit();
        
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
        $pdo->rollBack();
        error_log("Import error: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => "Error importing data: " . $e->getMessage()
        ];
    }
}

// #9 - validateAndPreviewImport
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
        $max_preview_rows = 5;
        
        while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== FALSE && $row_count < $max_preview_rows) {
            // Clean the data
            $data = array_map('trim', $data);  // Trim all values
            $preview_rows[] = $data;
            $row_count++;
        }
        
        // Count total rows
        $total_rows = $row_count;
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
                'tmp_name' => $temp_filename,  // Use our new temporary file
                'visa_type' => $selected_visa_type,
                'total_rows' => $total_rows,
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

// #10 - validateRow
function validateRow($data, $type) {
    switch ($type) {
        case 'visa_queue':
            // Validate date format in first column
            if (!preg_match('/^\d{2}-[A-Za-z]{3}-\d{2}$/', $data[0])) {
                return ['error' => 'Invalid date format in first column. Expected: DD-MMM-YY'];
            }
            
            // Validate all numeric values and <5 in subsequent columns
            for ($i = 1; $i < count($data); $i++) {
                $value = trim($data[$i]);
                if (!empty($value)) {
                    if ($value !== '<5' && !preg_match('/^\d+$/', $value)) {
                        return ['error' => "Invalid queue count in column $i: '{$value}'. Must be a whole number or '<5'"];
                    }
                    // Convert '<5' to 0 during validation
                    if ($value === '<5') {
                        $data[$i] = '0';
                    }
                }
            }
            
            return ['data' => $data];
    }
    return ['error' => 'Unknown import type'];
}

// #11 - getVisaQueueData
function getVisaQueueData($visa_type_id, $limit = 12) {
    global $pdo;
    
    try {
        // Get basic visa type info
        $visaStmt = $pdo->prepare("
            SELECT visa_type 
            FROM visa_types 
            WHERE id = ?
        ");
        $visaStmt->execute([$visa_type_id]);
        $visa_info = $visaStmt->fetch(PDO::FETCH_ASSOC);
        
        // Get unique lodgement months
        $lodgementStmt = $pdo->prepare("
            SELECT DISTINCT l.id, l.lodged_month, l.first_count_volume
            FROM visa_lodgements l
            WHERE l.visa_type_id = ?
            ORDER BY l.lodged_month DESC
            LIMIT ?
        ");
        $lodgementStmt->execute([$visa_type_id, $limit]);
        $lodgements = $lodgementStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get update history for each lodgement
        $updateStmt = $pdo->prepare("
            SELECT update_month, queue_count
            FROM visa_queue_updates
            WHERE lodged_month_id = ?
            ORDER BY update_month DESC
        ");
        
        $data = [
            'visa_type' => $visa_info['visa_type'],
            'lodgements' => []
        ];
        
        foreach ($lodgements as $lodgement) {
            $updateStmt->execute([$lodgement['id']]);
            $updates = $updateStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $data['lodgements'][] = [
                'lodged_month' => $lodgement['lodged_month'],
                'first_count' => $lodgement['first_count_volume'],
                'updates' => $updates
            ];
        }
        
        return $data;
        
    } catch (PDOException $e) {
        error_log("Error fetching visa queue data: " . $e->getMessage());
        return null;
    }
}

// #12 - clearImportSession
function clearImportSession() {
    if (isset($_SESSION['import_preview'])) {
        unset($_SESSION['import_preview']);
    }
}

// #13 - purgeVisaData
function purgeVisaData($visa_type_id = null) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        if ($visa_type_id) {
            // Delete specific visa type data
            $stmt = $pdo->prepare("
                DELETE qu FROM visa_queue_updates qu
                INNER JOIN visa_lodgements l ON qu.lodged_month_id = l.id
                WHERE l.visa_type_id = ?
            ");
            $stmt->execute([$visa_type_id]);
            
            $stmt = $pdo->prepare("
                DELETE FROM visa_lodgements 
                WHERE visa_type_id = ?
            ");
            $stmt->execute([$visa_type_id]);
        } else {
            // Delete all data
            $pdo->exec("DELETE FROM visa_queue_updates");
            $pdo->exec("DELETE FROM visa_lodgements");
        }
        
        $pdo->commit();
        return [
            'status' => 'success',
            'message' => $visa_type_id ? 
                "Successfully purged data for visa type ID: $visa_type_id" :
                "Successfully purged all visa data"
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Purge error: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => "Error purging data: " . $e->getMessage()
        ];
    }
}

// #14 - cleanupImportFile
function cleanupImportFile($filename) {
    if ($filename && file_exists($filename)) {
        unlink($filename);
    }
}

// #15 - getVisaTypesSummary
function getVisaTypesSummary() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT 
                vt.visa_type,
                COUNT(DISTINCT l.id) as total_lodgements,
                MAX(qu.update_month) as latest_update,
                SUM(l.first_count_volume) as total_applications,
                (1 - SUM(CASE 
                    WHEN qu.update_month = (
                        SELECT MAX(update_month) 
                        FROM visa_queue_updates qu2 
                        WHERE qu2.lodged_month_id = l.id
                    )
                    THEN qu.queue_count 
                    ELSE 0 
                END) / SUM(l.first_count_volume)) * 100 as processing_rate
            FROM visa_types vt
            LEFT JOIN visa_lodgements l ON l.visa_type_id = vt.id
            LEFT JOIN visa_queue_updates qu ON qu.lodged_month_id = l.id
            GROUP BY vt.id, vt.visa_type
            ORDER BY vt.visa_type
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error getting visa types summary: " . $e->getMessage());
        return [];
    }
}

// #16 - hasVisaTypeData
function hasVisaTypeData($visa_type_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM visa_lodgements 
            WHERE visa_type_id = ?
        ");
        $stmt->execute([$visa_type_id]);
        return $stmt->fetchColumn() > 0;
        
    } catch (PDOException $e) {
        error_log("Error checking visa type data: " . $e->getMessage());
        return false;
    }
}

// #17 - getVisaQueueSummary
function getVisaQueueSummary($visa_type_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            WITH LatestUpdate AS (
                SELECT MAX(update_month) as latest_update
                FROM visa_queue_updates qu
                JOIN visa_lodgements l ON l.id = qu.lodged_month_id
                WHERE l.visa_type_id = ?
            )
            SELECT 
                SUM(qu.queue_count) as total_on_hand,
                lu.latest_update as last_updated,
                MIN(l.lodged_month) as oldest_case
            FROM visa_queue_updates qu
            JOIN visa_lodgements l ON l.id = qu.lodged_month_id
            CROSS JOIN LatestUpdate lu
            WHERE l.visa_type_id = ?
            AND qu.update_month = lu.latest_update
        ");
        
        $stmt->execute([$visa_type_id, $visa_type_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total_on_hand' => (int)$result['total_on_hand'],
            'last_updated' => $result['last_updated'],
            'oldest_case' => $result['oldest_case']
        ];
        
    } catch (PDOException $e) {
        error_log("Error getting visa queue summary: " . $e->getMessage());
        return null;
    }
}

// #18 - debugVisaQueueSummary
function debugVisaQueueSummary($visa_type_id) {
    global $pdo;
    
    try {
        // Get the latest update date
        $stmt = $pdo->prepare("
            SELECT MAX(update_month) as latest_update
            FROM visa_queue_updates qu
            JOIN visa_lodgements l ON l.id = qu.lodged_month_id
            WHERE l.visa_type_id = ?
        ");
        $stmt->execute([$visa_type_id]);
        $latest_update = $stmt->fetchColumn();
        
        // Get all queue counts for the latest update
        $stmt = $pdo->prepare("
            SELECT 
                l.lodged_month,
                qu.queue_count,
                qu.update_month
            FROM visa_queue_updates qu
            JOIN visa_lodgements l ON l.id = qu.lodged_month_id
            WHERE l.visa_type_id = ? 
            AND qu.update_month = ?
            ORDER BY l.lodged_month
        ");
        $stmt->execute([$visa_type_id, $latest_update]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'latest_update' => $latest_update,
            'details' => $details
        ];
    } catch (PDOException $e) {
        error_log("Error in debugVisaQueueSummary: " . $e->getMessage());
        return null;
    }
}

// #19 - getVisaProcessingRates
function getVisaProcessingRates($visa_type_id) {
    global $pdo;
    
    try {
        // Get allocations first
        $allocations = getVisaAllocations($visa_type_id);
        $current_allocation = $allocations ? $allocations['current_allocation'] : 0;
        $previous_allocation = $allocations ? $allocations['previous_allocation'] : 0;

        $stmt = $pdo->prepare("
            WITH ConsecutiveUpdates AS (
                SELECT 
                    qu1.update_month as current_month,
                    qu2.update_month as previous_month,
                    qu1.queue_count as current_count,
                    qu2.queue_count as previous_count,
                    l.lodged_month,
                    qu1.lodged_month_id,
                    YEAR(qu1.update_month) as processing_year
                FROM visa_queue_updates qu1
                JOIN visa_queue_updates qu2 ON qu1.lodged_month_id = qu2.lodged_month_id
                JOIN visa_lodgements l ON l.id = qu1.lodged_month_id
                WHERE l.visa_type_id = ?
                AND qu2.update_month = (
                    SELECT MAX(update_month)
                    FROM visa_queue_updates qu3
                    WHERE qu3.lodged_month_id = qu1.lodged_month_id
                    AND qu3.update_month < qu1.update_month
                )
            ),
            ProcessingRates AS (
                SELECT 
                    current_month,
                    previous_month,
                    processing_year,
                    l.lodged_month,
                    SUM(previous_count - current_count) as visas_processed
                FROM ConsecutiveUpdates cu
                JOIN visa_lodgements l ON l.id = cu.lodged_month_id
                GROUP BY current_month, previous_month, processing_year, l.lodged_month
            ),
            YearlyStats AS (
                SELECT 
                    processing_year,
                    SUM(CASE WHEN visas_processed > 0 THEN visas_processed ELSE 0 END) as yearly_total,
                    COUNT(*) as months_in_year,
                    AVG(CASE WHEN visas_processed > 0 THEN visas_processed ELSE 0 END) as yearly_average
                FROM ProcessingRates
                GROUP BY processing_year
            ),
            LastThreeMonths AS (
                SELECT AVG(CASE WHEN visas_processed > 0 THEN visas_processed ELSE 0 END) as recent_average
                FROM (
                    SELECT visas_processed
                    FROM ProcessingRates
                    ORDER BY current_month DESC
                    LIMIT 3
                ) recent
            ),
            RunningAverages AS (
                SELECT 
                    pr.*,
                    AVG(CASE WHEN pr2.visas_processed > 0 THEN pr2.visas_processed ELSE 0 END) OVER (
                        ORDER BY pr.current_month
                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                    ) as ytd_average
                FROM ProcessingRates pr
                JOIN ProcessingRates pr2 
                    ON pr2.current_month <= pr.current_month
                    AND pr2.processing_year = pr.processing_year
                GROUP BY pr.current_month, pr.previous_month, pr.processing_year, pr.visas_processed, pr.lodged_month
            )
            SELECT 
                ra.*,
                ys.yearly_total,
                ys.yearly_average,
                ltm.recent_average as last_three_months_average,
                ? as annual_allocation,
                ? as previous_allocation,
                2400 as priority_processed,
                20 as priority_percentage,
                ? - ys.yearly_total as remaining_quota
            FROM RunningAverages ra
            JOIN YearlyStats ys ON ys.processing_year = ra.processing_year
            CROSS JOIN LastThreeMonths ltm
            ORDER BY ra.current_month DESC
        ");
        
        $stmt->execute([
            $visa_type_id,           // For the first WHERE clause
            $current_allocation,      // For annual_allocation
            $previous_allocation,     // For previous_allocation
            $current_allocation      // For remaining_quota calculation
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error getting visa processing rates: " . $e->getMessage());
        return null;
    }
}

// #20 - getCurrentFinancialYear
function getCurrentFinancialYear() {
    $currentMonth = (int)date('m');
    $currentYear = (int)date('Y');
    
    // If we're in July-December, FY started this year
    // If we're in January-June, FY started last year
    return ($currentMonth >= 7) ? $currentYear : $currentYear - 1;
}

// #21 - getVisaAllocation
function getVisaAllocation($visa_type) {
    global $pdo;
    
    error_log("Getting allocation for visa type: " . $visa_type);
    
    // Get the current financial year
    $current_year = date('m') >= 7 ? date('Y') : date('Y') - 1;
    error_log("Current financial year: " . $current_year);
    
    // Get visa type ID
    $stmt = $pdo->prepare("SELECT id FROM visa_types WHERE visa_type = ?");
    $stmt->execute([$visa_type]);
    $visa_type_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("Visa type data: " . print_r($visa_type_data, true));
    
    if (!$visa_type_data) {
        error_log("No visa type found for: " . $visa_type);
        return null;
    }
    
    // Get allocation for current financial year
    $stmt = $pdo->prepare("
        SELECT allocation_amount, financial_year_start 
        FROM visa_allocations 
        WHERE visa_type_id = ? AND financial_year_start = ?
        ORDER BY financial_year_start DESC 
        LIMIT 1
    ");
    
    $stmt->execute([$visa_type_data['id'], $current_year]);
    $current_allocation = $stmt->fetch(PDO::FETCH_ASSOC);
    error_log("Current allocation: " . print_r($current_allocation, true));
    
    // Get previous year's allocation
    $prev_year = $current_year - 1;
    $stmt->execute([$visa_type_data['id'], $prev_year]);
    $previous_allocation = $stmt->fetch(PDO::FETCH_ASSOC);
    error_log("Previous allocation: " . print_r($previous_allocation, true));
    
    $result = [
        'current' => $current_allocation ? $current_allocation['allocation_amount'] : 0,
        'previous' => $previous_allocation ? $previous_allocation['allocation_amount'] : 0,
        'financial_year' => $current_year
    ];
    
    error_log("Returning allocation data: " . print_r($result, true));
    return $result;
}

// #22 - updateVisaAllocation
function updateVisaAllocation($visa_type_id, $financial_year_start, $allocation_amount) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO visa_allocations 
                (visa_type_id, financial_year_start, allocation_amount)
            VALUES 
                (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                allocation_amount = VALUES(allocation_amount)
        ");
        
        $stmt->execute([$visa_type_id, $financial_year_start, $allocation_amount]);
        return true;
        
    } catch (PDOException $e) {
        error_log("Error updating visa allocation: " . $e->getMessage());
        return false;
    }
}

// #23 - getAllVisaAllocations
function getAllVisaAllocations($financial_year_start = null) {
    global $pdo;
    
    try {
        if (!$financial_year_start) {
            $financial_year_start = getCurrentFinancialYear();
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                va.*,
                vt.visa_type,
                CONCAT(va.financial_year_start, '-', va.financial_year_start + 1) as financial_year
            FROM visa_allocations va
            JOIN visa_types vt ON vt.id = va.visa_type_id
            WHERE va.financial_year_start = ?
            ORDER BY vt.visa_type
        ");
        
        $stmt->execute([$financial_year_start]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error getting all visa allocations: " . $e->getMessage());
        return [];
    }
}

// #24 - getVisaAllocations
function getVisaAllocations($visa_type_id) {
    global $pdo;
    $currentFY = getCurrentFinancialYear();
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                current.allocation_amount as current_allocation,
                prev.allocation_amount as previous_allocation
            FROM visa_allocations current
            LEFT JOIN visa_allocations prev ON 
                prev.visa_type_id = current.visa_type_id AND 
                prev.financial_year_start = current.financial_year_start - 1
            WHERE current.visa_type_id = ? 
            AND current.financial_year_start = ?
        ");
        
        $stmt->execute([$visa_type_id, $currentFY]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting visa allocations: " . $e->getMessage());
        return null;
    }
}

// #25 - getProcessedVisasAge
function getProcessedVisasAge($visa_type_id) {
    global $pdo;
    
    try {
        // Get the latest month's processing data
        $stmt = $pdo->prepare("
            WITH ConsecutiveUpdates AS (
                SELECT 
                    qu1.update_month as processing_month,
                    l.lodged_month,
                    (qu2.queue_count - qu1.queue_count) as visas_processed
                FROM visa_queue_updates qu1
                JOIN visa_queue_updates qu2 ON qu1.lodged_month_id = qu2.lodged_month_id
                JOIN visa_lodgements l ON l.id = qu1.lodged_month_id
                WHERE l.visa_type_id = ?
                AND qu2.update_month = (
                    SELECT MAX(update_month)
                    FROM visa_queue_updates qu3
                    WHERE qu3.lodged_month_id = qu1.lodged_month_id
                    AND qu3.update_month < qu1.update_month
                )
                AND qu1.update_month = (
                    SELECT MAX(update_month)
                    FROM visa_queue_updates
                    WHERE visa_type_id = ?
                )
            )
            SELECT 
                processing_month,
                lodged_month,
                visas_processed,
                TIMESTAMPDIFF(MONTH, lodged_month, processing_month) as age_in_months
            FROM ConsecutiveUpdates
            WHERE visas_processed > 0
            ORDER BY lodged_month
        ");
        
        $stmt->execute([$visa_type_id, $visa_type_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error analyzing processed visas age: " . $e->getMessage());
        return null;
    }
}

// #26 - getMonthlyVisaAges
function getMonthlyVisaAges($visa_type_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            WITH ProcessedVisas AS (
                SELECT 
                    qu1.update_month as processing_month,
                    l.lodged_month,
                    (qu2.queue_count - qu1.queue_count) as visas_processed,
                    TIMESTAMPDIFF(MONTH, l.lodged_month, qu1.update_month) as age_in_months
                FROM visa_queue_updates qu1
                JOIN visa_queue_updates qu2 ON qu1.lodged_month_id = qu2.lodged_month_id
                JOIN visa_lodgements l ON l.id = qu1.lodged_month_id
                WHERE l.visa_type_id = ?
                AND qu2.update_month = (
                    SELECT MAX(update_month)
                    FROM visa_queue_updates qu3
                    WHERE qu3.lodged_month_id = qu1.lodged_month_id
                    AND qu3.update_month < qu1.update_month
                )
                AND (qu2.queue_count - qu1.queue_count) > 0
            ),
            MonthlyAverages AS (
                SELECT 
                    processing_month,
                    SUM(visas_processed) as total_processed,
                    SUM(visas_processed * age_in_months) / SUM(visas_processed) as average_age,
                    MIN(age_in_months) as youngest_visa,
                    MAX(age_in_months) as oldest_visa,
                    (SELECT 
                        CASE 
                            -- If we have 3 or more months of data, use last 3 months
                            WHEN EXISTS (
                                SELECT 1 FROM ProcessedVisas p3 
                                WHERE p3.processing_month <= pv1.processing_month 
                                AND p3.processing_month >= DATE_SUB(pv1.processing_month, INTERVAL 2 MONTH)
                                LIMIT 3
                            ) THEN (
                                SELECT SUM(total_processed) / 3
                                FROM (
                                    SELECT total_processed
                                    FROM ProcessedVisas p4 
                                    WHERE p4.processing_month <= pv1.processing_month 
                                    AND p4.processing_month >= DATE_SUB(pv1.processing_month, INTERVAL 2 MONTH)
                                    GROUP BY processing_month
                                    ORDER BY processing_month DESC
                                    LIMIT 3
                                ) last_three
                            )
                            -- If we have 2 months of data
                            WHEN EXISTS (
                                SELECT 1 FROM ProcessedVisas p3 
                                WHERE p3.processing_month <= pv1.processing_month 
                                AND p3.processing_month >= DATE_SUB(pv1.processing_month, INTERVAL 1 MONTH)
                                LIMIT 2
                            ) THEN (
                                SELECT SUM(total_processed) / 2
                                FROM (
                                    SELECT total_processed
                                    FROM ProcessedVisas p4 
                                    WHERE p4.processing_month <= pv1.processing_month 
                                    AND p4.processing_month >= DATE_SUB(pv1.processing_month, INTERVAL 1 MONTH)
                                    GROUP BY processing_month
                                    ORDER BY processing_month DESC
                                    LIMIT 2
                                ) last_two
                            )
                            -- If we only have 1 month of data
                            ELSE total_processed
                        END
                     FROM ProcessedVisas pv1
                     WHERE pv1.processing_month = pv1.processing_month
                    ) as weighted_average
                FROM ProcessedVisas pv1
                GROUP BY processing_month
                ORDER BY processing_month DESC
            )
            SELECT * FROM MonthlyAverages
        ");
        
        $stmt->execute([$visa_type_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error calculating monthly visa ages: " . $e->getMessage());
        return null;
    }
}
?> 