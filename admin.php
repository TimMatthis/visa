<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

// Add basic authentication for admin page
session_start();

// Simple authentication
if (!isset($_SESSION['admin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === 'admin123') { // Change this to a secure password
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
    error_log("FILES received: " . print_r($_FILES, true));
    
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
        error_log("Processing confirmed import");
        
        try {
            // Process the import using the preview data
            $message = processVisaQueueImport(
                $_SESSION['import_preview']['file_info'],
                $_SESSION['import_preview']['file_info']['delimiter']
            );
            
            // Clean up the temporary file
            cleanupImportFile($_SESSION['import_preview']['file_info']['tmp_name']);
            
            error_log("Import result: " . print_r($message, true));
            unset($_SESSION['import_preview']); // Clear the preview after import
            
            // Add success flag to trigger JS notification
            $message['complete'] = true;
            
        } catch (Exception $e) {
            $message = [
                'status' => 'error',
                'message' => 'Import failed: ' . $e->getMessage(),
                'complete' => true
            ];
        }
    } elseif (isset($_POST['cancel_import']) && isset($_SESSION['import_preview'])) {
        // Clean up on cancel
        cleanupImportFile($_SESSION['import_preview']['file_info']['tmp_name']);
        unset($_SESSION['import_preview']);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } elseif (isset($_POST['add_visa_type'])) {
        $visa_type = $_POST['visa_type'];
        $message = addVisaType($visa_type);
    } elseif (isset($_POST['delete_visa_type'])) {
        $id = $_POST['id'];
        $message = deleteVisaType($id);
    } elseif (isset($_POST['clear_page'])) {
        clearImportSession();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } elseif (isset($_POST['purge_data'])) {
        $visa_type_id = !empty($_POST['purge_visa_type']) ? $_POST['purge_visa_type'] : null;
        $message = purgeVisaData($visa_type_id);
    } elseif (isset($_POST['update_allocation'])) {
        $visa_type_id = $_POST['allocation_visa_type'];
        $financial_year = $_POST['financial_year'];
        $allocation_amount = $_POST['allocation_amount'];
        
        if (updateVisaAllocation($visa_type_id, $financial_year, $allocation_amount)) {
            $message = ['status' => 'success', 'message' => 'Visa allocation updated successfully'];
        } else {
            $message = ['status' => 'error', 'message' => 'Error updating visa allocation'];
        }
    }
}

// Get all visa types
$visa_types = getAllVisaTypes();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Visa Predictor</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div id="loading-overlay" style="display: none;">
        <div class="loading-content">
            <div class="spinner"></div>
            <p>Processing Import...</p>
        </div>
    </div>

    <nav class="nav-header">
        <div class="nav-container">
            <a href="index.php" class="admin-link">Back to Site</a>
           
 <form method="POST" style="display: inline;">
                <button type="submit" name="clear_page" class="admin-link">Clear Page</button>
            </form>
           

            <a href="logout.php" class="admin-link">Logout</a>
        </div>
    </nav>

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
                    <form method="POST" class="inline-form" onsubmit="return startImport(this);">
                        <input type="hidden" name="confirm_import" value="1">
                        <button type="submit" class="proceed-btn" id="proceed-btn">Proceed with Import</button>
                    </form>
                    <form method="POST" class="inline-form">
                        <button type="submit" class="cancel-btn" id="cancel-btn">Cancel Import</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <div class="tabs">
            <button class="tab-btn active" onclick="openTab(event, 'import')">1. Import Data</button>
            <button class="tab-btn" onclick="openTab(event, 'view')">2. View Queue Data</button>
            <button class="tab-btn" onclick="openTab(event, 'manage')">3. Manage Visa Types</button>
            <button class="tab-btn" onclick="openTab(event, 'settings')">4. Data Management</button>
            <button class="tab-btn" onclick="openTab(event, 'summary')">5. Data Summary</button>
        </div>

        <div id="import" class="tab-content active">
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

        <div id="view" class="tab-content">
            <section class="data-view-section">
                <h2>3. View Visa Queue Data</h2>
                <form action="" method="GET" class="visa-selector">
                    <div class="form-group">
                        <label for="visa_type">Select Visa Type:</label>
                        <select name="visa_type" onchange="this.form.submit()">
                            <option value="">Select Visa Type</option>
                            <?php foreach ($visa_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>" 
                                        <?php echo isset($_GET['visa_type']) && $_GET['visa_type'] == $type['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['visa_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>

                <?php if (isset($_GET['visa_type'])): 
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
                                    <th>Processing Rate</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($queue_data['lodgements'] as $lodgement): 
                                    $latest_count = end($lodgement['updates'])['queue_count'];
                                    $processing_rate = $lodgement['first_count'] > 0 
                                        ? round((($lodgement['first_count'] - $latest_count) / $lodgement['first_count']) * 100, 1)
                                        : 0;
                                ?>
                                <tr>
                                    <td><?php echo date('M Y', strtotime($lodgement['lodged_month'])); ?></td>
                                    <td><?php echo number_format($lodgement['first_count']); ?></td>
                                    <td><?php echo number_format($latest_count); ?></td>
                                    <td>
                                        <div class="progress-bar" style="--progress: <?php echo $processing_rate; ?>%">
                                            <?php echo $processing_rate; ?>%
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
                    <div class="graph-section">
                        <h3>Processing Trends</h3>
                        <div class="chart-container">
                            <canvas id="processingTrends"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>

        <div id="manage" class="tab-content">
            <section class="admin-section">
                <h2>4. Manage Visa Types</h2>
                <div class="visa-types-container">
                    <div class="add-visa-type">
                        <h3>Add New Visa Type</h3>
                        <form action="" method="POST">
                <div class="form-group">
                                <label for="visa_type">Visa Type:</label>
                                <input type="text" name="visa_type" required maxlength="10" pattern="[0-9]+" placeholder="e.g. 189">
                                <button type="submit" name="add_visa_type">Add Visa Type</button>
                            </div>
                        </form>
                    </div>

                    <div class="existing-visa-types">
                        <h3>Current Visa Types</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Visa Type</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($visa_types as $type): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($type['visa_type']); ?></td>
                                    <td>
                                        <form action="" method="POST" style="display: inline;">
                                            <input type="hidden" name="id" value="<?php echo $type['id']; ?>">
                                            <button type="submit" name="delete_visa_type" class="delete-btn">Delete</button>
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

        <div id="settings" class="tab-content">
            <section class="admin-section">
                <h2>5. Data Management</h2>
                <div class="warning-box">
                    <p>⚠️ Warning: These actions cannot be undone!</p>
                </div>
                <form action="" method="POST" onsubmit="return confirmPurge(this);">
                    <div class="form-group">
                        <label for="purge_visa_type">Select Visa Type:</label>
                        <select name="purge_visa_type">
                            <option value="">All Visa Types</option>
                            <?php foreach ($visa_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>">
                                    <?php echo htmlspecialchars($type['visa_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="purge_data" class="delete-btn">Purge Data</button>
            </form>
        </section>
        </div>

        <div id="summary" class="tab-content">
            <section class="admin-section">
                <h2>Data Summary</h2>
                
                <!-- Add visa type tabs -->
                <div class="visa-tabs">
                    <?php foreach ($visa_types as $index => $type): ?>
                        <button class="visa-tab-btn <?php echo $index === 0 ? 'active' : ''; ?>"
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
                                
                                <div class="queue-position-calculator">
                                    <label for="application_date_<?php echo $type['id']; ?>">Enter Your Application Date:</label>
                                    <input type="date" 
                                           id="application_date_<?php echo $type['id']; ?>" 
                                           class="application-date"
                                           data-visa-type="<?php echo $type['id']; ?>"
                                           max="<?php echo date('Y-m-d'); ?>">
                                    
                                    <div class="queue-position-visual" id="queue_visual_<?php echo $type['id']; ?>">
                                        <!-- Will be populated by JavaScript -->
                                    </div>
                                </div>
                                
                                <?php 
                                $processing_rates = getVisaProcessingRates($type['id']);
                                if ($processing_rates): 
                                    $latest_rate = reset($processing_rates);
                                    $trend = $latest_rate['visas_processed'] > $latest_rate['last_three_months_average'];
                                ?>
                                    <div class="processing-rates">
                                        <h4>Processing Rate Analysis</h4>
                                        <div class="processing-stats-grid">
                                            <div class="stat-box">
                                                <label>Year to Date Processing</label>
                                                <span class="value"><?php echo number_format($latest_rate['yearly_total']); ?></span>
                                                <small>Total visas processed this year</small>
                                            </div>
                                            
                                            <div class="stat-box">
                                                <label>Annual Processing Rate</label>
                                                <span class="value"><?php echo number_format($latest_rate['yearly_average'], 0); ?></span>
                                                <small>Average visas per month</small>
                                            </div>
                                            
                                            <div class="stat-box">
                                                <label>Recent Processing Rate</label>
                                                <span class="value"><?php echo number_format($latest_rate['last_three_months_average'], 0); ?></span>
                                                <small>Last 3 months average</small>
                                            </div>
                                            
                                            <div class="stat-box">
                                                <label>Annual Allocation</label>
                                                <span class="value"><?php echo number_format($latest_rate['annual_allocation']); ?></span>
                                                <small>Total places for <?php echo date('Y'); ?></small>
                                            </div>
                                            
                                            <div class="stat-box">
                                                <label>Priority Processing</label>
                                                <span class="value">
                                                    <?php echo number_format($latest_rate['priority_processed']); ?>
                                                    <small class="percentage">(<?php echo $latest_rate['priority_percentage']; ?>%)</small>
                                                </span>
                                                <small>Priority visas processed</small>
                                            </div>
                                            
                                            <div class="stat-box">
                                                <label>Remaining Quota</label>
                                                <span class="value"><?php echo number_format($latest_rate['remaining_quota']); ?></span>
                                                <small>Places available this year</small>
                                            </div>
                                        </div>

                                        <div class="rate-highlight">
                                            <label>Last Month's Processing:</label>
                                            <div class="rate-info">
                                                <span class="rate-number <?php echo $latest_rate['visas_processed'] > 0 ? 'positive' : 'negative'; ?>">
                                                    <?php echo number_format(abs($latest_rate['visas_processed'])); ?>
                                                    visas processed
                                                </span>
                                                <div class="trend-indicator <?php echo $trend ? 'improving' : 'slowing'; ?>">
                                                    <span class="trend-arrow"><?php echo $trend ? '↑' : '↓'; ?></span>
                                                    <span class="trend-text">
                                                        Processing is <?php echo $trend ? 'accelerating' : 'slowing down'; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <small>
                                                Between <?php echo date('M Y', strtotime($latest_rate['previous_month'])); ?> 
                                                and <?php echo date('M Y', strtotime($latest_rate['current_month'])); ?>
                                            </small>
                                        </div>
                                        <div class="rates-list">
                                            <?php foreach ($processing_rates as $rate): ?>
                                                <div class="rate-item">
                                                    <div class="rate-period">
                                                        <span class="period">
                                                            <?php echo date('M', strtotime($rate['previous_month'])); ?> → 
                                                            <?php echo date('M Y', strtotime($rate['current_month'])); ?>
                                                        </span>
                                                        <small class="period-label">Reporting Period</small>
                                                    </div>
                                                    <div class="rate-details">
                                                        <div class="rate-numbers">
                                                            <span class="count <?php echo $rate['visas_processed'] > 0 ? 'positive' : 'negative'; ?>">
                                                                <?php echo number_format(abs($rate['visas_processed'])); ?>
                                                            </span>
                                                            <small class="count-label">Visas Processed</small>
                                                        </div>
                                                        <div class="rate-average">
                                                            <span class="ytd-average" title="Running average up to this month">
                                                                <?php echo number_format(abs($rate['ytd_average']), 0); ?>
                                                            </span>
                                                            <small class="average-label">Monthly Average</small>
                                                        </div>
                                                        <?php if ($rate === $latest_rate): ?>
                                                            <span class="trend-arrow <?php echo $trend ? 'improving' : 'slowing'; ?>">
                                                                <?php echo $trend ? '↑' : '↓'; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($debug_data['details'])): ?>
                                    <div class="queue-details">
                                        <h4>Queue Breakdown</h4>
                                        <div class="queue-list">
                                            <?php foreach ($debug_data['details'] as $detail): ?>
                                                <div class="queue-item">
                                                    <span class="month"><?php echo date('M Y', strtotime($detail['lodged_month'])); ?></span>
                                                    <span class="count"><?php echo number_format($detail['queue_count']); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
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
    </div>
    <script>
    function initializeChart(queueData) {
        const ctx = document.getElementById('processingTrends').getContext('2d');
        
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

    <?php if (isset($queue_data) && $queue_data): ?>
        initializeChart(<?php echo json_encode($queue_data['lodgements']); ?>);
    <?php endif; ?>

    function confirmImport(form) {
        // Trigger file selection
        form.dataFile.click();
        return false;
    }

    // Handle file selection
    document.querySelector('input[name="dataFile"]').addEventListener('change', function(e) {
        if (this.files[0].name !== this.form.original_file.value) {
            alert('Please select the same file that was previewed.');
            return false;
        }
        this.form.submit();
    });

    function confirmPurge(form) {
        const visaType = form.purge_visa_type.value;
        const message = visaType ? 
            `Are you sure you want to purge all data for visa type ${visaType}?` :
            'Are you sure you want to purge ALL visa data? This cannot be undone!';
        
        return confirm(message);
    }

    function openTab(evt, tabName) {
        // Hide all tab content
        const tabContents = document.getElementsByClassName("tab-content");
        for (let content of tabContents) {
            content.classList.remove("active");
        }

        // Remove active class from all tab buttons
        const tabButtons = document.getElementsByClassName("tab-btn");
        for (let button of tabButtons) {
            button.classList.remove("active");
        }

        // Show the selected tab content and mark button as active
        document.getElementById(tabName).classList.add("active");
        evt.currentTarget.classList.add("active");

        // Store the active tab in session storage
        sessionStorage.setItem('activeTab', tabName);
    }

    // Set the default tab or restore last active tab
    document.addEventListener('DOMContentLoaded', function() {
        const activeTab = sessionStorage.getItem('activeTab') || 'import';
        const activeButton = document.querySelector(`[onclick="openTab(event, '${activeTab}')"]`);
        if (activeButton) {
            openTab({ currentTarget: activeButton }, activeTab);
        }
        
        // If there's an import preview, switch to import tab
        if (document.querySelector('.import-preview-section')) {
            openTab({ currentTarget: document.querySelector('[onclick="openTab(event, \'import\')"]')}, 'import');
        }
    });

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

    // Add this to the DOMContentLoaded event listener
    document.addEventListener('DOMContentLoaded', function() {
        // ... existing code ...
        
        // Set default visa tab or restore last active visa tab
        const activeVisaTab = sessionStorage.getItem('activeVisaTab');
        if (activeVisaTab) {
            const activeVisaButton = document.querySelector(`[onclick="openVisaTab(event, '${activeVisaTab}')"]`);
            if (activeVisaButton) {
                openVisaTab({ currentTarget: activeVisaButton }, activeVisaTab);
            }
        }
    });
    </script>

    <style>
    .warning-box {
        background-color: #fff3cd;
        border: 1px solid #ffeeba;
        color: #856404;
        padding: 1rem;
        margin-bottom: 1rem;
        border-radius: 4px;
    }

    .delete-btn {
        background-color: #dc3545;
        color: white;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 4px;
        cursor: pointer;
    }

    .delete-btn:hover {
        background-color: #c82333;
    }

    .processing-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .stat-box {
        background: white;
        padding: 1.5rem;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        text-align: center;
    }

    .stat-box label {
        color: #64748b;
        font-size: 0.9rem;
        font-weight: 500;
        display: block;
        margin-bottom: 0.5rem;
    }

    .stat-box .value {
        font-size: 1.8rem;
        font-weight: 700;
        color: #0369a1;
        display: block;
    }

    .stat-box small {
        color: #64748b;
        font-size: 0.8rem;
        display: block;
        margin-top: 0.5rem;
    }

    .stat-box .percentage {
        display: inline;
        font-size: 1rem;
        color: #059669;
        margin-left: 0.5rem;
    }

    .stat-box:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        transition: all 0.2s ease;
    }

    .rate-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem;
        background: #f8fafc;
        border-radius: 8px;
        transition: background-color 0.2s ease;
    }

    .rate-period {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .period-label {
        color: #64748b;
        font-size: 0.8rem;
    }

    .rate-details {
        display: flex;
        align-items: center;
        gap: 2rem;
    }

    .rate-numbers, .rate-average {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.25rem;
    }

    .count-label, .average-label {
        color: #64748b;
        font-size: 0.8rem;
        white-space: nowrap;
    }

    .ytd-average {
        font-size: 1.1rem;
        font-weight: 600;
        color: #64748b;
    }

    .trend-arrow {
        font-size: 1.2rem;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }

    .trend-arrow.improving {
        background: #ecfdf5;
        color: #059669;
    }

    .trend-arrow.slowing {
        background: #fef2f2;
        color: #dc2626;
    }
    </style>
</body>
</html> 