<?php
// Ensure no output buffering conflicts
while (ob_get_level()) ob_end_clean();
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set a custom error log file path
ini_set('error_log', __DIR__ . '/error_log.txt');

// Add basic authentication for admin page
session_start();
error_log("Session ID at start: " . session_id());
error_log("Session data at start: " . print_r($_SESSION, true));

require_once 'config/database.php';
require_once 'includes/functions.php';

// Make sure $conn is available
if (!isset($conn) || $conn === null) {
    die("Database connection failed");
}

// Simple authentication
if (!isset($_SESSION['admin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === 'Australopus12345') { // Change this to a secure password
            session_regenerate_id(true); // Regenerate session ID on successful login
            $_SESSION['admin'] = true;
        } else {
            $error = "Invalid password";
        }
    }
    
    if (!isset($_SESSION['admin'])) {
        include 'admin_login.php';
        exit;
    }
}

$message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST request received: " . print_r($_POST, true));
    error_log("Session data before processing POST: " . print_r($_SESSION, true));
    
    if (isset($_FILES['dataFile'])) {
        if (empty($_POST['visa_type_select'])) {
            $message = ['status' => 'error', 'message' => 'Please select a visa type'];
        } else {
            error_log("Processing file upload");
            $preview = validateAndPreviewImport($_FILES['dataFile'], $_POST['visa_type_select']);
            error_log("Preview result: " . print_r($preview, true));
            
            if ($preview['status'] === 'error') {
                $message = $preview['message'];
                error_log("Upload error: " . $message);
            } else {
                $_SESSION['import_preview'] = $preview;
                error_log("Preview stored in session");
            }
        }
    } elseif (isset($_POST['confirm_import']) && isset($_SESSION['import_preview'])) {
        // Prevent any output and ensure we're only sending JSON
        ob_clean();
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');
        
        try {
            // Process the import using the preview data
            $result = processVisaQueueImport(
                $_SESSION['import_preview']['file_info'],
                $_SESSION['import_preview']['file_info']['delimiter']
            );
            
            // Clean up the temporary file
            cleanupImportFile($_SESSION['import_preview']['file_info']['tmp_name']);
            
            error_log("Import result: " . print_r($result, true));
            unset($_SESSION['import_preview']); // Clear the preview after import
            
            // Send JSON response and exit immediately
            echo json_encode([
                'status' => 'success',
                'message' => $result['message'] ?? 'Import completed successfully',
                'complete' => true
            ]);
            exit();
            
        } catch (Exception $e) {
            error_log("Import error: " . $e->getMessage());
            echo json_encode([
                'status' => 'error',
                'message' => 'Import failed: ' . $e->getMessage(),
                'complete' => true
            ]);
            exit();
        }
    } elseif (isset($_POST['cancel_import']) && isset($_SESSION['import_preview'])) {
        // Clean up on cancel
        cleanupImportFile($_SESSION['import_preview']['file_info']['tmp_name']);
        unset($_SESSION['import_preview']);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } elseif (isset($_POST['add_visa_type'])) {
        $visa_type = $_POST['visa_type'];
        $message = addVisaType($visa_type, $conn);
        error_log("Add visa type result: " . print_r($message, true));
    } elseif (isset($_POST['delete_visa_type'])) {
        $id = $_POST['id'];
        $message = deleteVisaType($id, $conn);
    } elseif (isset($_POST['clear_page'])) {
        clearImportSession();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } elseif (isset($_POST['purge_data'])) {
        if ($_POST['confirmation_code'] !== 'Australopus12345') {
            $message = ['status' => 'error', 'message' => 'Invalid confirmation code. Purge cancelled.'];
        } else {
            $visa_type_id = !empty($_POST['purge_visa_type']) ? $_POST['purge_visa_type'] : null;
            $message = purgeVisaData($visa_type_id);
        }
    } elseif (isset($_POST['update_allocation'])) {
        $visa_type_id = $_POST['visa_type_id'];
        $financial_year = $_POST['financial_year'];
        $allocation_amount = $_POST['allocation_amount'];
        
        if (updateVisaAllocation($visa_type_id, $financial_year, $allocation_amount)) {
            $message = ['status' => 'success', 'message' => 'Visa allocation updated successfully'];
        } else {
            $message = ['status' => 'error', 'message' => 'Error updating visa allocation'];
        }
    }

    error_log("Session data at end: " . print_r($_SESSION, true));
}

// Get all visa types
$visa_types = getAllVisaTypes($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Visa Predictor</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Add this script block in the head -->
    <script>
        // Make switchTab globally available
        function switchTab(tabId) {
            // Clear any existing messages or session data
            fetch('clear_session.php?type=' + tabId)
                .then(() => {
                    console.log('Switching to tab:', tabId);
                    
                    // Hide all tab contents
                    var tabContents = document.querySelectorAll('.tab-content');
                    console.log('Found tab contents:', tabContents.length);
                    
                    tabContents.forEach(function(tab) {
                        tab.style.display = 'none';
                    });
                    
                    // Show the selected tab
                    var selectedTab = document.getElementById(tabId);
                    if (selectedTab) {
                        selectedTab.style.display = 'block';
                    }
                    
                    // Update active state of tab buttons
                    var tabButtons = document.querySelectorAll('.tab-btn');
                    tabButtons.forEach(function(button) {
                        button.classList.remove('active');
                        if (button.getAttribute('onclick').includes(tabId)) {
                            button.classList.add('active');
                        }
                    });
                })
                .catch(error => console.error('Error clearing session:', error));
        };

        // Initialize when the page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Show the first tab by default
            switchTab('import');

            // If there's an import preview, make sure we're on the import tab
            if (document.querySelector('.import-preview-section')) {
                switchTab('import');
            }
        });

        // Make openVisaTab globally available
        window.openVisaTab = function(evt, tabId) {
            // Hide all visa tab content
            var visaTabContents = document.querySelectorAll('.visa-tab-content');
            visaTabContents.forEach(function(content) {
                content.style.display = 'none';
            });

            // Remove active class from all visa tab buttons
            var visaTabButtons = document.querySelectorAll('.visa-tab-btn');
            visaTabButtons.forEach(function(button) {
                button.classList.remove('active');
            });

            // Show the selected tab content and mark button as active
            var selectedTab = document.getElementById(tabId);
            if (selectedTab) {
                selectedTab.style.display = 'block';
            }
            
            // Add active class to clicked button
            if (evt && evt.currentTarget) {
                evt.currentTarget.classList.add('active');
            }

            // Store active visa tab in session storage
            sessionStorage.setItem('activeVisaTab', tabId);
        };

        // Initialize visa tabs when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Restore last active visa tab or show first tab
            var activeVisaTab = sessionStorage.getItem('activeVisaTab');
            if (activeVisaTab) {
                var activeButton = document.querySelector(`[onclick="openVisaTab(event, '${activeVisaTab}')"]`);
                if (activeButton) {
                    openVisaTab({ currentTarget: activeButton }, activeVisaTab);
                }
            } else {
                // Show first visa tab by default
                var firstVisaTab = document.querySelector('.visa-tab-btn');
                if (firstVisaTab) {
                    var firstTabId = firstVisaTab.getAttribute('onclick').match(/'([^']+)'/)[1];
                    openVisaTab({ currentTarget: firstVisaTab }, firstTabId);
                }
            }
        });

        function openTab(event, tabName) {
            var i, tabcontent, tablinks;
            
            // Clear any existing messages or session data when switching tabs
            fetch('clear_session.php?type=' + tabName)
                .catch(error => console.error('Error clearing session:', error));
            
            // Hide all tab contents
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }

            // Remove active class from all tab buttons
            tablinks = document.getElementsByClassName("tab-btn");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].className = tablinks[i].className.replace(" active", "");
            }

            // Show the selected tab and mark it as active
            document.getElementById(tabName).style.display = "block";
            event.currentTarget.className += " active";
        }

        // Initialize the first tab as active
        document.addEventListener('DOMContentLoaded', function() {
            const firstTabButton = document.querySelector('.tab-btn');
            if (firstTabButton) {
                firstTabButton.click();
            }
        });
    </script>
</head>
<body>
    <!-- Navigation header -->
    <div class="top-nav">
        <div class="nav-container">
            <a href="index.php" class="admin-link">Home</a>
            <a href="logout.php" class="admin-link">Logout</a>
        </div>
    </div>

    <div id="loading-overlay" style="display: none;">
        <div class="loading-content">
            <div class="spinner"></div>
            <p>Processing Import...</p>
        </div>
    </div>

    <div class="container">
        <h1>Visa Data Management</h1>
        
        <?php if ($message): ?>
            <div class="message <?php echo is_array($message) && isset($message['status']) ? $message['status'] : ''; ?>">
                <?php 
                    if (is_array($message) && isset($message['message'])) {
                        echo nl2br(htmlspecialchars($message['message']));
                    } else {
                        echo htmlspecialchars($message);
                    }
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['import_preview']) && $_SESSION['import_preview']['status'] === 'preview'): ?>
            <section class="import-preview-section">
                <h2>Review Import</h2>
                <div class="summary-card">
                    <div class="summary-item">
                        <label>File Name:</label>
                        <span><?php echo htmlspecialchars($_SESSION['import_preview']['file_info']['name']); ?></span>
                    </div>
                    <div class="summary-item">
                        <label>Visa Type:</label>
                        <span><?php echo htmlspecialchars($_SESSION['import_preview']['file_info']['visa_type']); ?></span>
                    </div>
                    <div class="summary-item">
                        <label>Total Rows:</label>
                        <span><?php echo number_format($_SESSION['import_preview']['file_info']['total_rows']); ?></span>
                    </div>
                    <div class="summary-item">
                        <label>Total Columns:</label>
                        <span><?php echo number_format(count($_SESSION['import_preview']['header'])); ?></span>
                    </div>
                </div>

                <?php if (!empty($_SESSION['import_preview']['errors'])): ?>
                    <div class="validation-warnings">
                        <h4>Validation Warnings</h4>
                        <ul>
                            <?php foreach ($_SESSION['import_preview']['errors'] as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="import-actions">
                    <form method="POST" class="inline-form" onsubmit="return startImport(this);" action="handle_import.php">
                        <input type="hidden" name="confirm_import" value="1">
                        <input type="hidden" name="visa_type" value="<?php echo htmlspecialchars($_SESSION['import_preview']['file_info']['visa_type']); ?>">
                        <input type="hidden" name="file_info" value="<?php echo htmlspecialchars(json_encode($_SESSION['import_preview']['file_info'])); ?>">
                        <input type="hidden" name="preview_data" value="<?php echo htmlspecialchars(json_encode($_SESSION['import_preview'])); ?>">
                        <button type="submit" class="proceed-btn" id="proceed-btn">Proceed with Import</button>
                    </form>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="cancel_import" value="1">
                        <button type="submit" class="cancel-btn" id="cancel-btn">Cancel Import</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('import')">1. Import Data</button>
            <button class="tab-btn" onclick="switchTab('view')">2. View Queue Data</button>
            <button class="tab-btn" onclick="openTab(event, 'manageVisaTypes')">Manage Visa Types</button>
            <button class="tab-btn" onclick="openTab(event, 'dataManagement')">Data Management</button>
            <button class="tab-btn" onclick="openTab(event, 'dataSummary')">Data Summary</button>
            <button class="tab-btn" onclick="openFeedbackTab(this)">User Feedback</button>
        </div>

        <div id="import" class="tab-content" style="display: block;">
            <section class="upload-section">
                <h2>2. Import Processing Data</h2>
                <div class="import-instructions">
                    <h3>File Requirements</h3>
                    <ul>
                        <li>CSV file with queue data</li>
                        <li>First column must be "Month LOI" or "Month LODGED"</li>
                        <li>Dates must be in format: DD-MMM-YY</li>
                        <li>Values must be numbers or "&lt;5"</li>
                    </ul>
                </div>
                
                <form action="" method="POST" enctype="multipart/form-data" class="upload-form">
                    <div class="form-group">
                        <label for="visa_type_select">Select Visa Type:</label>
                        <select name="visa_type_select" id="visa_type_select" required>
                            <option value="">-- Select Visa Type --</option>
                            <?php foreach ($visa_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type['visa_type']); ?>">
                                    Subclass <?php echo htmlspecialchars($type['visa_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="dataFile">Select CSV File:</label>
                        <input type="file" name="dataFile" id="dataFile" accept=".csv" required>
                    </div>
                    <button type="submit" name="import_data" class="primary-btn">Preview Import</button>
                </form>
            </section>
        </div>

        <div id="view" class="tab-content" style="display: none;">
            <section class="data-view-section">
                <h2>3. View Visa Queue Data</h2>
                <form action="" method="GET" class="visa-selector">
                    <div class="form-group">
                        <label for="visa_type">Select Visa Type:</label>
                        <select name="visa_type" onchange="handleVisaTypeChange(this)">
                            <option value="">Select Visa Type</option>
                            <?php foreach ($visa_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>" 
                                        <?php echo isset($_GET['visa_type']) && $_GET['visa_type'] == $type['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['visa_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <!-- Add hidden input for current tab -->
                        <input type="hidden" name="tab" value="view">
                    </div>
                </form>

                <?php if (isset($_GET['visa_type'])): 
                    // Clean up any duplicates first
                    cleanupDuplicateLodgements($_GET['visa_type']);
                    // Then get the queue data
                    $queue_data = getVisaQueueData($_GET['visa_type']);
                    if ($queue_data): ?>
                    <div class="queue-data">
                        <h3>Visa Type <?php echo htmlspecialchars($queue_data['visa_type']); ?> Queue History</h3>
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>Lodgement Month</th>
                                    <th>Initial Count</th>
                                    <th>Latest Count</th>
                                    <th>Movement</th>
                                    <th>Processing Rate</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($queue_data['lodgements'] as $lodgement): 
                                    $latest_count = end($lodgement['updates'])['queue_count'];
                                    $movement = $lodgement['first_count'] - $latest_count;
                                    $movement_class = $movement > 0 ? 'positive' : ($movement < 0 ? 'negative' : 'neutral');
                                    $processing_rate = $lodgement['first_count'] > 0 
                                        ? round((($lodgement['first_count'] - $latest_count) / $lodgement['first_count']) * 100, 1)
                                        : 0;
                                ?>
                                <tr>
                                    <td><?php echo date('M Y', strtotime($lodgement['lodged_month'])); ?></td>
                                    <td><?php echo number_format((float)$lodgement['first_count'], 0); ?></td>
                                    <td><?php echo number_format((float)$lodgement['latest_count'], 0); ?></td>
                                    <td class="<?php echo $movement_class; ?>">
                                        <?php 
                                            echo $movement > 0 ? '+' : '';
                                            echo number_format((float)$movement, 0); 
                                        ?>
                                    </td>
                                    <td>
                                        <div class="progress-bar" style="--progress: <?php echo $processing_rate; ?>%">
                                            <?php echo number_format((float)$processing_rate, 1); ?>%
                                        </div>
                                    </td>
                                    <td>
                                        <button onclick="showHistory('<?php echo $lodgement['lodged_month']; ?>')" 
                                                class="history-btn">View History</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>

        <div id="manageVisaTypes" class="tab-content">
            <section class="admin-section">
                <h2>Manage Visa Types</h2>
                <div class="visa-management">
                    <div class="add-visa-form">
                        <h3>Add New Visa Type</h3>
                        <form action="" method="POST">
                            <div class="form-group">
                                <label for="visa_type">Visa Type:</label>
                                <input type="text" 
                                       name="visa_type" 
                                       id="visa_type"
                                       required 
                                       maxlength="10" 
                                       pattern="[0-9]+" 
                                       placeholder="e.g. 189">
                            </div>
                            <button type="submit" name="add_visa_type" class="btn btn-primary">Add Visa Type</button>
                        </form>
                    </div>

                    <div class="existing-visa-types">
                        <h3>Current Visa Types</h3>
                        <table class="visa-types-table">
                            <thead>
                                <tr>
                                    <th>Visa Type</th>
                                    <th>Current Allocation</th>
                                    <th>Previous Allocations</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($visa_types as $type): 
                                    // Get all allocations for this visa type
                                    $sql = "SELECT financial_year_start, allocation_amount 
                                           FROM visa_allocations 
                                           WHERE visa_type_id = ? 
                                           ORDER BY financial_year_start DESC";
                                    $stmt = $conn->prepare($sql);
                                    $stmt->bind_param('i', $type['id']);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    $allocations = $result->fetch_all(MYSQLI_ASSOC);
                                ?>
                                <tr>
                                    <td>Subclass <?php echo htmlspecialchars($type['visa_type']); ?></td>
                                    <td>
                                        <?php 
                                        $current_fy = getCurrentFinancialYearDates()['fy_start_year']; // Get current FY start year
                                        $current_allocation = array_filter($allocations, function($a) use ($current_fy) {
                                            return $a['financial_year_start'] == $current_fy;
                                        });
                                        
                                        if (!empty($current_allocation)) {
                                            echo number_format(current($current_allocation)['allocation_amount']);
                                        } else {
                                            // Check for the most recent allocation
                                            usort($allocations, function($a, $b) {
                                                return $b['financial_year_start'] - $a['financial_year_start'];
                                            });
                                            
                                            echo !empty($allocations) 
                                                ? number_format($allocations[0]['allocation_amount']) . ' (FY' . $allocations[0]['financial_year_start'] . '-' . ($allocations[0]['financial_year_start'] + 1) % 100 . ')'
                                                : 'Not set';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        foreach ($allocations as $allocation) {
                                            if ($allocation['financial_year_start'] != $current_fy) {
                                                echo sprintf('FY%d-%d: %s<br>', 
                                                    $allocation['financial_year_start'],
                                                    ($allocation['financial_year_start'] + 1) % 100,
                                                    number_format($allocation['allocation_amount'])
                                                );
                                            }
                                        }
                                        ?>
                                    </td>
                                    <td class="action-buttons">
                                        <button type="button" 
                                                onclick="showAllocationModal(<?php echo $type['id']; ?>, '<?php echo $type['visa_type']; ?>')" 
                                                class="btn btn-secondary">
                                            Edit Allocation
                                        </button>
                                        <form action="" method="POST" style="display: inline;">
                                            <input type="hidden" name="id" value="<?php echo $type['id']; ?>">
                                            <button type="submit" 
                                                    name="delete_visa_type" 
                                                    class="btn btn-danger" 
                                                    onclick="return confirm('Are you sure you want to delete this visa type?')">
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>

        <div id="dataManagement" class="tab-content" style="display: none;">
            <section class="admin-section">
                <h2>5. Data Management</h2>
                <div class="warning-box">
                    <p>⚠️ Warning: These actions cannot be undone!</p>
                    <p>You will need to enter the confirmation code to proceed with data purge.</p>
                </div>
                <form action="" method="POST" onsubmit="return confirmPurge(this);" class="purge-form">
                    <div class="form-group">
                        <label for="purge_visa_type">Select Visa Type:</label>
                        <select name="purge_visa_type" id="purge_visa_type">
                            <option value="">All Visa Types</option>
                            <?php foreach ($visa_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>">
                                    Subclass <?php echo htmlspecialchars($type['visa_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="confirmation_code">Enter Confirmation Code:</label>
                        <input type="password" 
                               name="confirmation_code" 
                               id="confirmation_code" 
                               required 
                               placeholder="Enter confirmation code">
                        <small class="warning-text">This action will permanently delete the selected data.</small>
                    </div>
                    <button type="submit" name="purge_data" class="delete-btn">Purge Data</button>
                </form>
            </section>
        </div>

        <div id="dataSummary" class="tab-content" style="display: none;">
            <section class="admin-section">
                <h2>Data Summary</h2>
                
                <!-- Add visa type tabs -->
                <div class="visa-tabs">
                    <?php foreach ($visa_types as $index => $type): ?>
                        <button class="history-btn <?php echo $index === 0 ? 'active' : ''; ?>"
                                onclick="openVisaTab(event, 'visa_<?php echo $type['id']; ?>')">
                            Subclass <?php echo htmlspecialchars($type['visa_type']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- Visa type content -->
                <?php foreach ($visa_types as $index => $type): 
                    $debug_data = debugVisaQueueSummary($type['id']);
                    if ($debug_data && $debug_data['latest_update']):
                ?>
                    <div id="visa_<?php echo $type['id']; ?>" 
                         class="visa-tab-content <?php echo $index === 0 ? 'active' : ''; ?>">
                        <div class="visa-summary-card">
                            <div class="summary-stats">
                                <div class="stat-item highlight">
                                    <label>Visas On Hand:</label>
                                    <span class="large-number">
                                        <?php 
                                        $total = array_sum(array_column($debug_data['details'], 'queue_count'));
                                        echo number_format($total); 
                                        ?>
                                    </span>
                                    <small>As of <?php echo date('d M Y', strtotime($debug_data['latest_update'])); ?></small>
                                </div>
                                
                                
                                
                            </div>
                        </div>
                    </div>
                <?php 
                    else: 
                ?>
                    <div id="visa_<?php echo $type['id']; ?>" 
                         class="visa-tab-content <?php echo $index === 0 ? 'active' : ''; ?>">
                        <div class="no-data">No data available for Subclass <?php echo htmlspecialchars($type['visa_type']); ?></div>
                    </div>
                <?php 
                    endif;
                endforeach; 
                ?>
            </section>
        </div>

        <div id="userFeedback" class="tab-content">
            <section class="admin-section">
                <h2>User Feedback</h2>
                <div id="feedbackContainer" class="feedback-list">
                    <!-- Feedback items will be loaded here -->
                    <div class="modal-loading">
                        <div class="modal-spinner"></div>
                        <div class="modal-loading-text">Loading feedback data...</div>
                    </div>
                </div>
            </section>
        </div>
    </div>
    <script>
    // Visa tab functionality
    function openVisaTab(evt, tabId) {
        // Hide all visa tab content
        const tabContents = document.getElementsByClassName("visa-tab-content");
        for (let content of tabContents) {
            content.style.display = "none";
        }

        // Remove active class from all visa tab buttons
        const tabButtons = document.getElementsByClassName("visa-tab-btn");
        for (let button of tabButtons) {
            button.classList.remove("active");
        }

        // Show the selected tab content and mark button as active
        document.getElementById(tabId).style.display = "block";
        evt.currentTarget.classList.add("active");

        // Store the active visa tab in session storage
        sessionStorage.setItem('activeVisaTab', tabId);
    }

    // Initialize Chart.js if needed
    function initializeChart(queueData) {
        const canvas = document.getElementById('processingTrends');
        if (!canvas) {
            console.log('Chart canvas not found, skipping chart initialization');
            return;
        }
        
        const ctx = canvas.getContext('2d');
        
        // Prepare data
        const datasets = queueData.map(lodgement => ({
            label: `Lodged ${lodgement.lodged_month}`,
            data: lodgement.updates.map(update => ({
                x: update.update_month,
                y: update.queue_count
            })),
            fill: false,
            tension: 0.4
        }));

        new Chart(ctx, {
            type: 'line',
            data: {
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            unit: 'month',
                            displayFormats: {
                                month: 'MMM yyyy'
                            }
                        },
                        title: {
                            display: true,
                            text: 'Update Month'
                        }
                    },
                    y: {
                        title: {
                            display: true,
                            text: 'Queue Count'
                        },
                        beginAtZero: true
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Visa Processing Trends'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${context.parsed.y.toLocaleString()} applications`;
                            }
                        }
                    }
                }
            }
        });
    }

    // Update the form submission handler
    function startImport(form) {
        // Show loading overlay
        const loadingOverlay = document.getElementById('loading-overlay');
        loadingOverlay.style.display = 'flex';
        
        // Create FormData object
        const formData = new FormData(form);
        
        // Add debug logging
        console.log('Sending import request with data:', Object.fromEntries(formData));
        
        // Make the fetch request
        fetch('handle_import.php', {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            const text = await response.text();
            console.log('Raw server response:', text);
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Failed to parse JSON response:', text);
                console.error('Parse error:', e);
                throw new Error('Server returned invalid JSON response');
            }
        })
        .then(data => {
            console.log('Import response:', data);
            if (data.status === 'success') {
                showMessage(data.message || 'Import completed successfully', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } else {
                throw new Error(data.message || 'Import failed');
            }
        })
        .catch(error => {
            console.error('Import error:', error);
            showMessage('Import failed: ' + error.message, 'error');
        })
        .finally(() => {
            loadingOverlay.style.display = 'none';
        });
        
        return false; // Prevent default form submission
    }

    // Add message display function
    function showMessage(message, type = 'success') {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${type}`;
        messageDiv.textContent = message;
        
        // Remove any existing messages
        const existingMessages = document.querySelectorAll('.message');
        existingMessages.forEach(msg => msg.remove());
        
        // Add new message to the top of the container
        const container = document.querySelector('.container');
        container.insertBefore(messageDiv, container.firstChild);
        
        // Auto-remove message after 5 seconds
        setTimeout(() => {
            messageDiv.remove();
        }, 5000);
    }

    <?php if (isset($queue_data) && $queue_data): ?>
        // Add this check before calling initializeChart
        document.addEventListener('DOMContentLoaded', function() {
            const queueData = <?php echo json_encode($queue_data['lodgements'] ?? []); ?>;
            if (queueData && document.getElementById('processingTrends')) {
                initializeChart(queueData);
            }
        });
    <?php endif; ?>
    </script>

    

    <!-- Add this modal HTML just before the closing </body> tag -->
    <div id="historyModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3>Queue History Details</h3>
            <div id="historyContent"></div>
        </div>
    </div>

    <!-- Add this JavaScript just before the closing </body> tag -->
    <script>
    // Add these new functions
    function showHistory(lodgedMonth) {
        const modal = document.getElementById('historyModal');
        const content = document.getElementById('historyContent');
        const span = document.getElementsByClassName("close")[0];
        
        // Get the visa type from the current URL
        const urlParams = new URLSearchParams(window.location.search);
        const visaType = urlParams.get('visa_type');
        
        // Fetch history data
        fetch(`get_history.php?visa_type=${visaType}&lodged_month=${lodgedMonth}`)
            .then(response => response.json())
            .then(response => {
                if (response.status === 'error') {
                    throw new Error(response.message);
                }

                if (!response.data || response.data.length === 0) {
                    content.innerHTML = '<p>No history data available for this period.</p>';
                    modal.style.display = "block";
                    return;
                }

                let html = '<table class="history-table">';
                html += '<thead><tr><th>Update Date</th><th>Queue Count</th><th>Change</th></tr></thead><tbody>';
                
                let previousCount = null;
                response.data.forEach(update => {
                    const change = previousCount !== null 
                        ? update.queue_count - previousCount 
                        : 0;
                        
                    const changeClass = change === 0 ? 'neutral' : (change < 0 ? 'positive' : 'negative');
                    const changeSymbol = change === 0 ? '→' : (change < 0 ? '↓' : '↑');
                    
                    html += `<tr>
                        <td>${new Date(update.update_month).toLocaleDateString('en-AU', { month: 'short', year: 'numeric' })}</td>
                        <td>${update.queue_count.toLocaleString()}</td>
                        <td class="${changeClass}">
                            ${changeSymbol} ${Math.abs(change).toLocaleString()}
                        </td>
                    </tr>`;
                    
                    previousCount = update.queue_count;
                });
                
                html += '</tbody></table>';
                content.innerHTML = html;
                modal.style.display = "block";
            })
            .catch(error => {
                content.innerHTML = `<p class="error">Error loading history: ${error.message}</p>`;
                modal.style.display = "block";
            });
        
        // Close modal when clicking the x
        span.onclick = function() {
            modal.style.display = "none";
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
    }
    </script>

 

    <!-- Edit Visa Allocations Modal -->
    <div id="allocationModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <div class="visa-header">
                <h3>Visa Subclass <span id="modalVisaType"></span> Allocations</h3>
            </div>

            <!-- Add loading indicator -->
            <div class="modal-loading">
                <div class="modal-spinner"></div>
                <div class="modal-loading-text">Loading allocation data...</div>
            </div>

            <!-- Current Allocations Section -->
            <div class="allocation-section current-allocations">
                <h4>Current Allocations</h4>
                <div id="existingAllocations" class="allocations-list">
                    <!-- Populated by JavaScript -->
                </div>
            </div>

            <!-- Add New Allocation Section -->
            <div class="allocation-section new-allocations">
                <h4>Add/Update Allocation</h4>
                <div id="availableYears" class="allocations-list">
                    <!-- Populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Update the JavaScript -->
    <script>
    let visaTypeId = null;

    function showAllocationModal(typeId, visaType) {
        console.log('Opening modal for visa type:', visaType, 'ID:', typeId);
        visaTypeId = typeId;
        const modal = document.getElementById('allocationModal');
        const modalContent = modal.querySelector('.modal-content');
        const loadingElement = modal.querySelector('.modal-loading');
        document.getElementById('modalVisaType').textContent = visaType;
        
        // Show modal and set loading state
        modal.style.display = "block";
        modalContent.classList.add('loading');
        loadingElement.style.display = "block"; // Show loading spinner
        
        // Fetch existing allocations
        fetchAllocations()
            .then(response => {
                console.log('Allocation response:', response);
                modalContent.classList.remove('loading');
                loadingElement.style.display = "none"; // Hide loading spinner
            })
            .catch(error => {
                console.error('Error fetching allocations:', error);
                modalContent.classList.remove('loading');
                loadingElement.style.display = "none"; // Hide loading spinner
                document.getElementById('existingAllocations').innerHTML = 
                    '<div class="error">Error loading allocation data. Please try again.</div>';
            });
    }

    function fetchAllocations() {
        return fetch(`get_allocations.php?visa_type_id=${visaTypeId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Received allocation data:', data);
                if (data.status === 'success') {
                    populateAllocations(data.allocations);
                    populateAvailableYears(data.allocations);
                } else {
                    throw new Error(data.message || 'Failed to fetch allocations');
                }
                return data;
            });
    }

    function populateAllocations(allocations) {
        const container = document.getElementById('existingAllocations');
        container.innerHTML = '';

        // Sort allocations by year descending
        allocations.sort((a, b) => b.financial_year_start - a.financial_year_start);

        allocations.forEach(allocation => {
            const row = document.createElement('div');
            row.className = 'allocation-row existing';
            row.innerHTML = `
                <span class="year-label">FY${allocation.financial_year_start}-${(allocation.financial_year_start + 1) % 100}</span>
                <input type="number" 
                       class="allocation-input"
                       value="${allocation.allocation_amount}"
                       onchange="enableSaveButton(this)"
                       data-original="${allocation.allocation_amount}"
                       data-year="${allocation.financial_year_start}">
                <div>
                    <button class="save-btn" 
                            onclick="saveAllocation(this, ${allocation.financial_year_start})" 
                            disabled>
                        Save
                    </button>
                    <span class="save-status"></span>
                </div>
            `;
            container.appendChild(row);
        });
    }

    function populateAvailableYears(existingAllocations) {
        const container = document.getElementById('availableYears');
        container.innerHTML = '';
        
        const currentYear = new Date().getFullYear();
        const existingYears = existingAllocations.map(a => a.financial_year_start);
        
        // Show last 5 financial years
        for (let i = 0; i < 5; i++) {
            const year = currentYear - i;
            if (!existingYears.includes(year)) {
                const row = document.createElement('div');
                row.className = 'allocation-row';
                row.innerHTML = `
                    <span class="year-label">FY${year}-${(year + 1) % 100}</span>
                    <input type="number" 
                           class="allocation-input"
                           placeholder="Enter allocation"
                           onchange="enableSaveButton(this)"
                           data-year="${year}">
                    <div>
                        <button class="save-btn" 
                                onclick="saveAllocation(this, ${year})" 
                                disabled>
                            Save
                        </button>
                        <span class="save-status"></span>
                    </div>
                `;
                container.appendChild(row);
            }
        }
    }

    function enableSaveButton(input) {
        const btn = input.parentElement.querySelector('.save-btn');
        const originalValue = input.dataset.original;
        
        if (originalValue) {
            // For existing allocations
            btn.disabled = input.value === originalValue;
        } else {
            // For new allocations
            btn.disabled = !input.value;
        }
    }

    function saveAllocation(btn, year) {
        const row = btn.closest('.allocation-row');
        const input = row.querySelector('.allocation-input');
        const statusSpan = row.querySelector('.save-status');
        
        btn.disabled = true;
        statusSpan.className = 'save-status';
        statusSpan.textContent = 'Saving...';

        fetch('save_allocation.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                visa_type_id: visaTypeId,
                year: year,
                amount: parseInt(input.value)
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                statusSpan.className = 'save-status success';
                statusSpan.textContent = '✓ Saved';
                input.dataset.original = input.value;
                
                // Refresh allocations after short delay
                setTimeout(() => {
                    fetchAllocations();
                    statusSpan.textContent = '';
                }, 1500);
            } else {
                throw new Error(data.message || 'Save failed');
            }
        })
        .catch(error => {
            statusSpan.className = 'save-status error';
            statusSpan.textContent = '✗ ' + error.message;
            btn.disabled = false;
        });
    }

    // Close modal functionality
    document.querySelector('#allocationModal .close').onclick = function() {
        document.getElementById('allocationModal').style.display = "none";
    }

    window.onclick = function(event) {
        const modal = document.getElementById('allocationModal');
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }
    </script>

   
    <!-- Add this JavaScript function -->
    <script>
    function confirmPurge(form) {
        const confirmationCode = form.confirmation_code.value;
        const expectedCode = "Australopus12345";
        const visaType = form.purge_visa_type.options[form.purge_visa_type.selectedIndex].text;
        
        if (confirmationCode !== expectedCode) {
            alert("Invalid confirmation code. Purge cancelled.");
            return false;
        }
        
        const message = visaType === "All Visa Types" 
            ? "Are you absolutely sure you want to purge ALL visa data? This action cannot be undone!"
            : `Are you absolutely sure you want to purge data for ${visaType}? This action cannot be undone!`;
            
        return confirm(message);
    }
    </script>

    <!-- Add this JavaScript function to handle the visa type selection -->
    <script>
    function handleVisaTypeChange(selectElement) {
        // Show loading spinner
        const viewSection = document.querySelector('.data-view-section');
        viewSection.innerHTML += `
            <div class="modal-loading" id="queueDataLoading">
                <div class="modal-spinner"></div>
                <div class="modal-loading-text">Loading queue data...</div>
            </div>
        `;
        
        // Stay on current tab
        const currentTab = document.querySelector('.tab-content[style*="block"]').id;
        
        // Add tab to URL
        const url = new URL(window.location.href);
        url.searchParams.set('visa_type', selectElement.value);
        url.searchParams.set('tab', currentTab);
        
        // Navigate to new URL
        window.location.href = url.toString();
    }

    // Function to handle initial load and tab selection
    document.addEventListener('DOMContentLoaded', function() {
        // Get tab from URL
        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');
        
        if (tabParam) {
            switchTab(tabParam);
        }
    });
    </script>

    <script>
    function loadFeedback() {
        const container = document.getElementById('feedbackContainer');
        if (!container) {
            console.error('Feedback container not found');
            return;
        }

        // Show loading state
        container.innerHTML = `
            <div class="modal-loading">
                <div class="modal-spinner"></div>
                <div class="modal-loading-text">Loading feedback data...</div>
            </div>
        `;

        fetch('get_feedback.php')
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                // Log headers for debugging
                console.log('Response headers:', 
                    Array.from(response.headers.entries())
                        .map(([key, value]) => `${key}: ${value}`)
                        .join('\n')
                );
                return response.text().then(text => {
                    // Log raw response for debugging
                    console.log('Raw response length:', text.length);
                    console.log('First 100 characters:', text.substring(0, 100));
                    try {
                        if (!text.trim()) {
                            throw new Error('Empty response from server');
                        }
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Parse error:', e);
                        console.error('Raw response:', text);
                        throw new Error(`Invalid JSON response from server: ${e.message}`);
                    }
                });
            })
            .then(data => {
                console.log('Parsed data:', data);
                if (data.status === 'success') {
                    displayFeedback(data.data);
                } else {
                    throw new Error(data.message || 'Failed to load feedback');
                }
            })
            .catch(error => {
                console.error('Error loading feedback:', error);
                container.innerHTML = `
                    <div class="error-message">
                        Error loading feedback: ${error.message}<br>
                        <small>Check console for more details</small>
                    </div>
                `;
            });
    }

    function displayFeedback(feedback) {
        const container = document.getElementById('feedbackContainer');
        if (!container) {
            console.error('Feedback container not found');
            return;
        }

        if (!feedback || feedback.length === 0) {
            container.innerHTML = '<p class="no-feedback">No feedback available.</p>';
            return;
        }
        
        const feedbackHtml = feedback.map(item => {
            const date = new Date(item.submitted_at).toLocaleDateString();
            const analysisHtml = item.analysis 
                ? `<div class="feedback-analysis">
                     <h4>Analysis:</h4>
                     <pre>${JSON.stringify(item.analysis, null, 2)}</pre>
                   </div>`
                : '';
                
            return `
                <div class="feedback-item">
                    <div class="feedback-header">
                        <span class="feedback-date">${date}</span>
                        ${item.theme ? `<span class="feedback-theme">${item.theme}</span>` : ''}
                    </div>
                    <div class="feedback-text">${item.feedback_text}</div>
                    ${analysisHtml}
                </div>
            `;
        }).join('');
        
        container.innerHTML = feedbackHtml;
    }
    </script>

    <script>
    function openFeedbackTab(button) {
        // Clear any existing messages or session data
        fetch('clear_session.php?type=feedback')
            .then(() => {
                // Create a synthetic event object with the button as currentTarget
                const syntheticEvent = { currentTarget: button };
                // Switch to the feedback tab
                openTab(syntheticEvent, 'userFeedback');
                // Load the feedback data
                loadFeedback();
            })
            .catch(error => console.error('Error clearing session:', error));
    }
    </script>

</body>
</html> 