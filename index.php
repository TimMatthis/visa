<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

// Initialize variables
$message = '';
$predictions = [];

// Fetch visa subclasses from database
$visa_types = getAllVisaTypes(); // Make sure this function exists and works
if (!$visa_types) {
    $message = 'Error: Unable to fetch visa types';
}

// Add error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Near the top of the file, after database connection
$prediction = null;
if (isset($_GET['visa_type'])) {
    $visa_type_id = $_GET['visa_type'];
    $prediction = calculateVisaPrediction($visa_type_id);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>When Will My Visa Be Granted? - Visa Processing Timeline Predictor</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        .loading-indicator {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .loading-spinner {
            width: 40px;
            height: 40px;
            margin: 0 auto 1rem;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-indicator p {
            color: #64748b;
            font-size: 1.1rem;
        }
        
        .lodgement-header {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .lodgement-header h2 {
            color: #2d3748;
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }
        
        .lodgement-details {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .lodgement-details .label {
            color: #64748b;
            font-weight: 500;
        }
        
        .lodgement-details .value {
            color: #2d3748;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .allocation-buffer {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin: 1rem 0 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .allocation-buffer.positive {
            background: #f0fdf4;
            border: 1px solid #86efac;
        }

        .allocation-buffer.negative {
            background: #fef2f2;
            border: 1px solid #fca5a5;
        }

        .allocation-buffer h3 {
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            margin: -1rem -1rem 1rem -1rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .allocation-buffer h3:after {
            content: "▼";
            font-size: 0.8em;
            transition: transform 0.3s ease;
        }

        .allocation-buffer.collapsed h3:after {
            transform: rotate(-90deg);
        }

        .allocation-buffer .buffer-details {
            transition: max-height 0.3s ease-out;
            overflow: hidden;
            max-height: 1000px; /* Adjust based on content */
        }

        .allocation-buffer.collapsed .buffer-details {
            max-height: 0;
        }

        .buffer-details {
            display: grid;
            gap: 1.5rem;
        }

        .buffer-calculation {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            display: grid;
            gap: 0.75rem;
        }

        .calc-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .calc-row:last-child {
            border-bottom: none;
        }

        .calc-row.total {
            border-top: 2px solid #e2e8f0;
            margin-top: 0.5rem;
            padding-top: 1rem;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .calc-row .label {
            color: #64748b;
        }

        .calc-row .value {
            font-family: monospace;
            font-size: 1.1rem;
        }

        .buffer-summary {
            font-size: 1.1rem;
            line-height: 1.6;
            padding: 1rem;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.5);
        }

        .allocation-buffer.positive .buffer-summary {
            color: #047857;
        }

        .allocation-buffer.negative .buffer-summary {
            color: #b91c1c;
        }

        .timeline-analysis {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin: 1rem 0 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .timeline-analysis.positive {
            background: #f0fdf4;
            border: 1px solid #86efac;
        }

        .timeline-analysis.negative {
            background: #fef2f2;
            border: 1px solid #fca5a5;
        }

        .timeline-analysis.neutral {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
        }

        .timeline-analysis h3 {
            font-size: 1.2rem;
            margin-bottom: 1rem;
            color: #1e293b;
        }

        .timeline-details {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .timeline-prediction {
            padding: 1rem;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.5);
            margin-top: 1rem;
        }

        .timeline-prediction p {
            margin-bottom: 1rem;
            line-height: 1.6;
        }

        .date-range {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-top: 1rem;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .date-separator {
            color: #64748b;
            font-weight: normal;
        }

        .timeline-analysis.positive .date-range {
            color: #047857;
        }

        .timeline-analysis.negative .date-range {
            color: #b91c1c;
        }

        .timeline-analysis.neutral .date-range {
            color: #1e293b;
        }

        .no-data {
            text-align: center;
            color: #64748b;
            font-style: italic;
            padding: 1rem;
        }

        .visa-form {
            max-width: 600px;
            margin: 0 auto;
            padding: 2rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #374151;
            font-weight: 500;
        }

        .form-group select,
        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 1rem;
        }

        .submit-btn {
            width: 100%;
            padding: 0.75rem;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .submit-btn:hover {
            background: #1d4ed8;
        }

        /* Flatpickr custom styles */
        .flatpickr-input {
            background: white !important;
        }

        .flatpickr-calendar {
            box-shadow: 0 4px 6px rgba(0,0,0,0.1) !important;
        }

        .error-message {
            background-color: #fef2f2;
            border: 1px solid #fca5a5;
            color: #dc2626;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 8px;
            text-align: center;
        }

        .rate-highlight {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .rate-highlight label {
            display: block;
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .rate-highlight .rate-number {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .rate-highlight .rate-number.positive {
            color: #059669;
        }

        .rate-highlight .rate-number.negative {
            color: #dc2626;
        }

        .tooltip-trigger {
            position: relative;
            cursor: pointer;
        }

        .tooltip-trigger .info-icon {
            font-size: 0.8em;
            color: #6b7280;
            margin-left: 4px;
        }

        .tooltip-trigger:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #1f2937;
            color: white;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
            white-space: pre;
            z-index: 10;
            min-width: 200px;
            text-align: left;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .monthly-ages {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .monthly-ages h4 {
            margin-bottom: 1rem;
            color: #1e293b;
        }

        .monthly-ages-table {
            overflow-x: auto;
        }

        .monthly-ages-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .monthly-ages-table th,
        .monthly-ages-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .monthly-ages-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #64748b;
        }

        .monthly-ages-table tr:hover {
            background: #f8fafc;
        }

        .prediction-explanation {
            padding: 1rem 0;
        }

        .step-container {
            margin: 1.5rem 0;
            padding: 1.25rem;
            background: #f8fafc;
            border-radius: 6px;
            border-left: 4px solid #64748b;
        }

        .step-container h4 {
            color: #1e293b;
            font-size: 1.1rem;
            margin-bottom: 0.75rem;
        }

        .step-container ul {
            list-style: none;
            padding-left: 1rem;
            margin: 0.75rem 0;
        }

        .step-container li {
            color: #475569;
            margin: 0.5rem 0;
            position: relative;
        }

        .step-container li:before {
            content: "•";
            color: #64748b;
            position: absolute;
            left: -1rem;
        }

        .step-conclusion {
            margin-top: 1rem;
            padding: 0.75rem;
            border-radius: 4px;
            font-weight: 500;
        }

        .step-conclusion.positive {
            background: #f0fdf4;
            color: #166534;
            border-left: 3px solid #16a34a;
        }

        .step-conclusion.negative {
            background: #fef2f2;
            color: #991b1b;
            border-left: 3px solid #dc2626;
        }

        .info-banner {
            background: #f8fafc;
            border-left: 4px solid #3b82f6;
            padding: 1rem 1.25rem;
            margin-bottom: 2rem;
            border-radius: 6px;
            color: #1e293b;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .info-banner strong {
            color: #1e40af;
            display: block;
            margin-bottom: 0.5rem;
        }

        .collapsible-card h3 {
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            margin: -1rem -1rem 1rem -1rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .collapsible-card h3:after {
            content: "▼";
            font-size: 0.8em;
            transition: transform 0.3s ease;
        }

        .collapsible-card.collapsed h3:after {
            transform: rotate(-90deg);
        }

        .collapsible-card .card-content {
            transition: max-height 0.3s ease-out;
            overflow: hidden;
            max-height: 2000px; /* Adjust based on content */
        }

        .collapsible-card.collapsed .card-content {
            max-height: 0;
        }

        .result-highlight {
            margin: 1.5rem -1.5rem -1.5rem;
            padding: 1.5rem;
            text-align: center;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .result-highlight.positive {
            background: #ecfdf5;
        }

        .result-highlight.negative {
            background: #fef2f2;
        }

        .result-status {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .result-status.positive {
            color: #059669;
        }

        .result-status.negative {
            color: #dc2626;
        }

        .result-icon {
            font-size: 2rem;
        }

        .result-numbers {
            font-size: 0.9rem;
            color: #64748b;
            margin-bottom: 1rem;
        }

        .result-message {
            font-size: 1.1rem;
            line-height: 1.6;
            max-width: 600px;
            margin: 0 auto;
        }

        .result-message.positive {
            color: #065f46;
        }

        .result-message.negative {
            color: #991b1b;
        }

        .trend-analysis {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 8px;
            font-size: 1.1rem;
            line-height: 1.4;
        }

        .trend-analysis.positive {
            background-color: #f0fdf4;
            border: 1px solid #86efac;
            color: #166534;
        }

        .trend-analysis.negative {
            background-color: #fef2f2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }

        .trend-icon {
            margin-right: 0.5rem;
        }

        .debug-calculations {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 8px;
            margin: 1rem 0;
            font-family: monospace;
        }
        
        .debug-calculations pre {
            background: #fff;
            padding: 1rem;
            border-radius: 4px;
            overflow-x: auto;
            max-height: 200px;
        }
        
        .debug-calculations h4 {
            margin: 1.5rem 0 0.5rem;
            color: #2d3748;
        }
        
        .debug-calculations ul {
            list-style: none;
            padding: 0;
        }
        
        .debug-calculations li {
            margin: 0.5rem 0;
            padding: 0.5rem;
            background: #fff;
            border-radius: 4px;
        }

        /* Add this CSS to ensure proper positioning */
        .predictor-section {
            display: flex;
            flex-direction: column; /* Ensure items stack vertically */
            justify-content: flex-start; /* Align items to the start */
            margin-top: 2rem; /* Add some space above */
        }

        /* Add this CSS to hide the predictor section initially */
        .predictor-section {
            display: none; /* Hide by default */
        }
        .predictor-section.active {
            display: flex; /* Show when active */
        }
    </style>
</head>
<body>
    <nav class="nav-header">
        <div class="nav-container">
            <a href="admin.php" class="admin-link">Admin Panel</a>
        </div>
    </nav>

    <div class="container">
        <h1>Visa Processing Timeline Predictor</h1>
        <p class="subtitle">Get accurate predictions based on current processing data</p>

        <!-- Add tab buttons -->
        <div class="main-tabs">
            <button class="main-tab-btn active" onclick="openMainTab(event, 'predictor')">Processing Predictor</button>
            <button class="main-tab-btn" onclick="openMainTab(event, 'statistics')">Processing Statistics</button>
        </div>

        <!-- Predictor Tab -->
        <div id="predictor" class="main-tab-content active">
            <section class="predictions-section predictor-section active">
                <h2>Select Your Visa Details</h2>
                <form id="visa-details-form" onsubmit="handleFormSubmit(event)" class="visa-form">
                    <div class="info-banner">
                        <strong>Important Note:</strong>
                        This tool is designed to help non-priority applicants understand when they might get their visa. 
                        We do not provide predictions for priority cases as they typically have shorter processing times.
                        <p style="margin-top: 0.75rem; font-size: 0.9rem; color: #475569;">
                            This is a free service that aims to provide forward-looking predictions based on current processing data, 
                            unlike other resources (including official government estimates) which are typically backward-looking. 
                            While we strive for accuracy, these predictions are estimates only.
                        </p>
                    </div>
                    <div class="form-group">
                        <label for="visa_subclass">Visa Subclass:</label>
                        <select name="visa_subclass" id="visa_subclass" required>
                            <option value="">Select Visa Subclass</option>
                            <?php foreach ($visa_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type['visa_type']); ?>"
                                        <?php echo (!hasVisaTypeData($type['id']) ? 'disabled' : ''); ?>>
                                    Subclass <?php echo htmlspecialchars($type['visa_type']); ?>
                                    <?php echo (!hasVisaTypeData($type['id']) ? ' (No data available)' : ''); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="application_date">Application Date:</label>
                        <input type="text" id="application_date" name="application_date" required 
                               placeholder="Select application date">
                    </div>

                    <button type="submit" class="submit-btn">View Processing Statistics</button>
                </form>
            </section>

            <!-- First prediction section -->
            <div class="predictor-section">
                <h2>When is my Visa likely to be processed?</h2>
                <div id="prediction-result-main">
                    <?php if ($prediction): ?>
                        <script>
                            document.getElementById('prediction-result-main').innerHTML = 
                                generatePredictionHTML(<?php echo json_encode($prediction); ?>);
                        </script>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Statistics Tab -->
        <div id="statistics" class="main-tab-content">
            <div id="visa-statistics" class="statistics-section">
                <div id="lodgement-info" class="lodgement-header" style="display: none;">
                    <h2>Your Visa Application</h2>
                    <div class="lodgement-details">
                        <span class="label">Lodgement Date:</span>
                        <span id="selected-lodgement-date" class="value"></span>
                    </div>
                </div>
                <div id="loading-stats" class="loading-indicator" style="display: none;">
                    <div class="loading-spinner"></div>
                    <p>Loading visa processing statistics...</p>
                </div>
                <div class="select-visa-message">
                    Please select a visa subclass from the predictor tab to view statistics
                </div>
            </div>
        </div>
    </div>

    <div id="debug-info" class="debug-section"></div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize flatpickr with specific options
        flatpickr("#application_date", {
            dateFormat: "Y-m-d",
            maxDate: "today",
            defaultDate: "2023-07-10",
            altInput: true,
            altFormat: "F j, Y",
            allowInput: true
        });
    });

    function handleFormSubmit(event) {
        event.preventDefault();
        
        const visaType = document.getElementById('visa_subclass').value;
        const applicationDate = document.getElementById('application_date').value;
        
        console.log('Submitting form with:', { visaType, applicationDate });
        
        if (!visaType || !applicationDate) {
            alert('Please select both visa type and application date');
            return;
        }

        // Update the lodgement info display with the selected visa type
        document.getElementById('lodgement-info').style.display = 'block';
        document.getElementById('selected-lodgement-date').textContent = 
            new Date(applicationDate).toLocaleDateString('en-US', { 
                day: 'numeric', 
                month: 'long', 
                year: 'numeric' 
            });
        
        // Append the selected visa type
        const visaTypeText = document.getElementById('visa_subclass').options[document.getElementById('visa_subclass').selectedIndex].text;
        document.getElementById('selected-lodgement-date').textContent += ` (Visa Type: ${visaTypeText})`;
        
        // Switch to statistics tab
        const statsTab = document.querySelector('[onclick="openMainTab(event, \'statistics\')"]');
        openMainTab({ currentTarget: statsTab }, 'statistics');
        
        // Load visa stats
        loadVisaStats(visaType, applicationDate);
    }

    function openMainTab(evt, tabName) {
        const tabContents = document.getElementsByClassName("main-tab-content");
        for (let content of tabContents) {
            content.classList.remove("active");
            content.style.display = "none";  // Add explicit display none
        }

        const tabButtons = document.getElementsByClassName("main-tab-btn");
        for (let button of tabButtons) {
            button.classList.remove("active");
        }

        const activeTab = document.getElementById(tabName);
        activeTab.style.display = "block";
        activeTab.classList.add("active");

        // Show the predictor section if the predictor tab is clicked
        if (tabName === 'predictor') {
            const predictorSection = activeTab.querySelector('.predictor-section');
            predictorSection.classList.add('active'); // Show the predictor section
        } else {
            const predictorSection = document.querySelector('.predictor-section');
            predictorSection.classList.remove('active'); // Hide the predictor section
        }

        evt.currentTarget.classList.add("active");
    }

    // Add these debug functions at the top of your script section
    function debugLog(section, data) {
        console.log(`[${section}]`, data);
    }

    // Update loadVisaStats with more detailed logging
    function loadVisaStats(visaType, applicationDate) {
        debugLog('loadVisaStats', { visaType, applicationDate });
        
        if (!visaType) {
            debugLog('loadVisaStats', 'No visa type provided');
            return;
        }

        const statsContainer = document.getElementById('visa-statistics');
        const loadingIndicator = document.getElementById('loading-stats');
        const selectMessage = document.querySelector('.select-visa-message');
        const lodgementInfo = document.getElementById('lodgement-info');

        // Show loading, hide select message
        loadingIndicator.style.display = 'block';
        selectMessage.style.display = 'none';

        // Construct URL with both parameters
        const url = `get_visa_statistics.php?visa_type=${encodeURIComponent(visaType)}&application_date=${encodeURIComponent(applicationDate)}`;
        debugLog('loadVisaStats', `Fetching from URL: ${url}`);

        fetch(url)
            .then(response => {
                debugLog('loadVisaStats', `Response status: ${response.status}`);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                debugLog('loadVisaStats', 'Received data:', data);
                loadingIndicator.style.display = 'none';
                
                if (!data || data.error) {
                    const errorMessage = data?.error || 'No data available';
                    debugLog('loadVisaStats', `Error: ${errorMessage}`);
                    statsContainer.innerHTML = `
                        ${lodgementInfo.outerHTML}
                        <div class="error-message">${errorMessage}</div>
                    `;
                    return;
                }
                
                try {
                    debugLog('loadVisaStats', 'Generating stats HTML');
                    const statsHtml = generateStatsHTML(data, applicationDate);
                    statsContainer.innerHTML = `
                        ${lodgementInfo.outerHTML}
                        ${statsHtml}
                    `;
                } catch (error) {
                    debugLog('loadVisaStats', `Error generating stats: ${error.message}`);
                    console.error('Error generating stats HTML:', error);
                    statsContainer.innerHTML = `
                        ${lodgementInfo.outerHTML}
                        <div class="error-message">Error processing visa statistics: ${error.message}</div>
                    `;
                }
            })
            .catch(error => {
                debugLog('loadVisaStats', `Fetch error: ${error.message}`);
                loadingIndicator.style.display = 'none';
                console.error('Fetch error:', error);
                statsContainer.innerHTML = `
                    ${lodgementInfo.outerHTML}
                    <div class="error-message">
                        An error occurred while loading the statistics: ${error.message}
                    </div>
                `;
            });
    }

    function generateStatsHTML(data, applicationDate) {
        debugLog('generateStatsHTML', 'Starting with data:', data);
        
        // Validate required data
        if (!data) {
            throw new Error('No data provided');
        }

        if (!data.annual_allocation) {
            debugLog('generateStatsHTML', 'No allocation data available');
            throw new Error('No allocation data available for this visa subclass');
        }

        // Calculate buffer
        const annualAllocation = Number(data.annual_allocation || 0);
        const yearlyTotal = data.processing_rates ? data.processing_rates.reduce((total, rate) => {
            if (rate.processing_year === data.financial_year) {
                return total + Math.abs(rate.visas_processed);
            }
            return total;
        }, 0) : 0;

        const totalAhead = Number(data.queue_position?.before || 0);
        const priorityAllocation = Math.round(annualAllocation * 0.20); // 20% priority allocation
        const remainingNonPriorityPlaces = annualAllocation - yearlyTotal - priorityAllocation;
        const buffer = remainingNonPriorityPlaces - totalAhead;

        debugLog('generateStatsHTML', {
            annualAllocation,
            yearlyTotal,
            priorityAllocation,
            remainingNonPriorityPlaces,
            totalAhead,
            buffer
        });

        // Generate buffer text
        let bufferText = "";
        if (data.queue_position) {
            if (remainingNonPriorityPlaces > totalAhead) {
                bufferText = `There are ${remainingNonPriorityPlaces.toLocaleString()} non-priority places remaining after accounting for granted visas and priority allocation. With ${totalAhead.toLocaleString()} applications ahead, it is very likely you will get your visa this FY`;
            } else {
                bufferText = `There are only ${remainingNonPriorityPlaces.toLocaleString()} non-priority places remaining after accounting for granted visas and priority allocation. With ${totalAhead.toLocaleString()} applications ahead, you may not receive your visa this FY unless processing rates increase`;
            }
        }

        // Calculate estimated processing timeline
        let timelineText = "";
        let timelineClass = "neutral";
        
        debugLog('Timeline Calculation', {
            hasQueuePosition: !!data.queue_position,
            lastThreeMonthsAverage: data.last_three_months_average,
            monthlyRate: Number(data.last_three_months_average || 0),
            totalAhead,
            priorityAllocation
        });
        
        if (data.queue_position && data.last_three_months_average) {
            const totalToProcess = totalAhead + priorityAllocation;
            const monthlyRate = Number(data.last_three_months_average || 0);
            
            debugLog('Timeline Details', {
                totalToProcess,
                monthlyRate,
                monthsToProcess: Math.ceil(totalToProcess / monthlyRate),
                lastUpdateDate: data.last_updated
            });
            
            if (monthlyRate > 0) {
                const monthsToProcess = Math.ceil(totalToProcess / monthlyRate);
                const lastUpdateDate = new Date(data.last_updated);
                const estimatedDate = new Date(lastUpdateDate);
                estimatedDate.setMonth(estimatedDate.getMonth() + monthsToProcess);
                
                const earliestDate = new Date(estimatedDate);
                earliestDate.setMonth(estimatedDate.getMonth() - 1);
                
                const latestDate = new Date(estimatedDate);
                latestDate.setMonth(estimatedDate.getMonth() + 2);

                timelineText = `
                    <div class="timeline-details">
                        <div class="calc-row">
                            <span class="label">Applications Ahead:</span>
                            <span class="value">${totalAhead.toLocaleString()}</span>
                        </div>
                        <div class="calc-row">
                            <span class="label">Priority Cases:</span>
                            <span class="value">+${priorityAllocation.toLocaleString()}</span>
                        </div>
                        <div class="calc-row">
                            <span class="label">Total to Process:</span>
                            <span class="value">${totalToProcess.toLocaleString()}</span>
                        </div>
                        <div class="calc-row">
                            <span class="label">Monthly Processing Rate:</span>
                            <span class="value">${monthlyRate.toLocaleString()}</span>
                        </div>
                        <div class="calc-row total">
                            <span class="label">Estimated Processing Time:</span>
                            <span class="value">${monthsToProcess} months</span>
                        </div>
                    </div>
                    <div class="timeline-prediction">
                        <p>Based on the current processing rate of ${monthlyRate.toLocaleString()} visas per month, 
                        and with ${totalToProcess.toLocaleString()} applications to process before yours, 
                        we estimate your visa may be processed between:</p>
                        <div class="date-range">
                            <span class="earliest-date">${earliestDate.toLocaleDateString('en-US', { 
                                month: 'long', year: 'numeric'
                            })}</span>
                            <span class="date-separator">and</span>
                            <span class="latest-date">${latestDate.toLocaleDateString('en-US', { 
                                month: 'long', year: 'numeric'
                            })}</span>
                        </div>
                    </div>
                `;
                
                // Determine timeline class based on months to process
                if (monthsToProcess <= 6) {
                    timelineClass = "positive";
                } else if (monthsToProcess > 12) {
                    timelineClass = "negative";
                }
            }
        }

        // First, let's prepare the monthly breakdown
        const monthlyBreakdown = data.queue_position?.monthly_counts?.map(count => {
            if (count.is_application_month) {
                return `${new Date(count.lodged_month).toLocaleDateString('en-US', { month: 'short', year: 'numeric' })}: ${count.queue_count.toLocaleString()}\n${count.explanation}`;
            }
            return `${new Date(count.lodged_month).toLocaleDateString('en-US', { month: 'short', year: 'numeric' })}: ${count.queue_count.toLocaleString()}`;
        }).join('\n') || '';

        // Add an explanation at the top of the tooltip
        const tooltipContent = `Monthly Breakdown of Applications Ahead:\n${monthlyBreakdown}`;

        // Calculate monthly processing data
        let monthlyProcessingData = '';
        if (data.processing_rates) {
            const processingByMonth = data.processing_rates
                .filter(rate => rate.processing_year === data.financial_year)
                .map(rate => ({
                    month: new Date(rate.current_month).toLocaleDateString('en-US', { month: 'short', year: 'numeric' }),
                    processed: Math.abs(rate.visas_processed),
                    runningTotal: 0,
                    monthlyAverage: 0
                }))
                .reverse(); // Put in chronological order

            // Calculate running totals and averages
            let runningTotal = 0;
            let monthNumber = 0;
            processingByMonth.forEach(month => {
                monthNumber++;
                runningTotal += month.processed;
                month.runningTotal = runningTotal;
                month.monthlyAverage = Math.round(runningTotal / monthNumber);
            });

            // Create debug HTML
            monthlyProcessingData = `
                <div class="debug-section collapsible-card" id="processing-debug">
                    <h3 onclick="toggleCard('processing-debug')">Processing Rate Analysis (Debug)</h3>
                    <div class="card-content">
                        <table style="width: 100%; border-collapse: collapse; margin: 1rem 0;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 0.5rem; border-bottom: 2px solid #e2e8f0;">Month</th>
                                    <th style="text-align: right; padding: 0.5rem; border-bottom: 2px solid #e2e8f0;">Visas Processed</th>
                                    <th style="text-align: right; padding: 0.5rem; border-bottom: 2px solid #e2e8f0;">Running Total</th>
                                    <th style="text-align: right; padding: 0.5rem; border-bottom: 2px solid #e2e8f0;">Monthly Average</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${processingByMonth.map(month => `
                                    <tr>
                                        <td style="padding: 0.5rem; border-bottom: 1px solid #e2e8f0;">${month.month}</td>
                                        <td style="text-align: right; padding: 0.5rem; border-bottom: 1px solid #e2e8f0;">${month.processed.toLocaleString()}</td>
                                        <td style="text-align: right; padding: 0.5rem; border-bottom: 1px solid #e2e8f0;">${month.runningTotal.toLocaleString()}</td>
                                        <td style="text-align: right; padding: 0.5rem; border-bottom: 1px solid #e2e8f0;">${month.monthlyAverage.toLocaleString()}/month</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }

        return `
            <div class="visa-summary-card">
                <div class="summary-stats">
                    <div class="prediction-summary ${timelineClass} collapsible-card" id="timeline-understanding">
                        <h3 onclick="toggleCard('timeline-understanding')">Understanding Your Visa Timeline</h3>
                        <div class="card-content">
                            <div class="prediction-explanation">
                                <p>We analyze your visa timeline in two steps:</p>
                                
                                <div class="step-container">
                                    <h4>Step 1: Available Places This Year</h4>
                                    <p>We first check if there are enough visa places remaining in this financial year:</p>
                                    <ul>
                                        <li>Start with the total annual allocation (${Number(annualAllocation).toLocaleString()} places)</li>
                                        <li>Subtract visas already granted (${Number(yearlyTotal).toLocaleString()} places)</li>
                                        <li>Reserve places for priority applications (${Number(priorityAllocation).toLocaleString()} places)</li>
                                        <li>Compare remaining places (${Number(remainingNonPriorityPlaces).toLocaleString()}) with applications ahead of you (${totalAhead.toLocaleString()})</li>
                                    </ul>
                                    
                                </div>

                                <div class="step-container">
                                    <h4>Step 2: Processing Timeline</h4>
                                    <p>If there are enough places, we then estimate when your application will reach the front of the queue:</p>
                                    ${timelineText || `
                                        <p class="no-data">Currently unable to calculate processing timeline due to insufficient data.</p>
                                    `}
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="allocation-buffer ${buffer > 0 ? 'positive' : 'negative'} collapsible-card" id="allocation-buffer">
                        <h3 onclick="toggleCard('allocation-buffer')">Will my Visa be granted this Financial Year?</h3>
                        <div class="card-content">
                            <div class="buffer-details">
                                <div class="buffer-calculation">
                                    <div class="result-highlight ${remainingNonPriorityPlaces > totalAhead ? 'positive' : 'negative'}">
                                        <div class="result-status ${remainingNonPriorityPlaces > totalAhead ? 'positive' : 'negative'}">
                                            ${remainingNonPriorityPlaces > totalAhead ? 
                                                '<span class="result-icon">🎉</span> GOOD NEWS!' : 
                                                '<span class="result-icon">⚠️</span> IMPORTANT UPDATE'}
                                        </div>
                                        <div class="result-numbers">
                                            Available Places: ${Number(remainingNonPriorityPlaces).toLocaleString()} / Applications Ahead: ${totalAhead.toLocaleString()}
                                        </div>
                                        <div class="result-message ${remainingNonPriorityPlaces > totalAhead ? 'positive' : 'negative'}">
                                            ${remainingNonPriorityPlaces > totalAhead ? 
                                                `Based on current allocation numbers, there are <strong>${remainingNonPriorityPlaces.toLocaleString()}</strong> visa places available for non-priority applications this financial year. With <strong><span class="tooltip-trigger" data-tooltip="${tooltipContent}" title="Click to see monthly breakdown">${totalAhead.toLocaleString()}<span class="info-icon">ⓘ</span></span></strong> applications ahead of yours, you have a very good chance of receiving your visa this financial year.` :
                                                `Currently there are <strong>${remainingNonPriorityPlaces.toLocaleString()}</strong> visa places remaining for non-priority applications this financial year. With <strong><span class="tooltip-trigger" data-tooltip="${tooltipContent}" title="Click to see monthly breakdown">${totalAhead.toLocaleString()}<span class="info-icon">ⓘ</span></span></strong> applications ahead of yours, you may need to prepare for the possibility of waiting until next financial year unless processing rates increase significantly.`
                                            }
                                        </div>
                                    </div>
                                </div>
                                <p class="buffer-summary">${bufferText}</p>
                                
                                <!-- Move the processing-stats-grid here -->
                                <div class="processing-stats-grid">
                                    <div class="stat-box">
                                        <label>Annual Allocation</label>
                                        <span class="value">
                                            ${Number(data.annual_allocation || 0).toLocaleString()}
                                            ${data.previous_allocation ? `
                                                <small class="previous-allocation">
                                                    (${Number(data.previous_allocation).toLocaleString()} previous year)
                                                </small>
                                            ` : ''}
                                        </span>
                                        <small>Total places for FY${data.financial_year}-${Number(data.financial_year) + 1}</small>
                                    </div>

                                    <div class="stat-box">
                                        <label>Visas Processed</label>
                                        <span class="value">${Number(yearlyTotal).toLocaleString()}</span>
                                        <small>Total processed this financial year</small>
                                    </div>

                                    <div class="stat-box highlight-secondary">
                                        <label>Places Remaining</label>
                                        <span class="value">
                                            ${Number(remainingNonPriorityPlaces).toLocaleString()}
                                            <small style="font-size: 0.8em; color: #666;">
                                                (${Number(annualAllocation).toLocaleString()} - ${Number(yearlyTotal).toLocaleString()})
                                            </small>
                                        </span>
                                        <small>Visas left for this financial year</small>
                                    </div>

                                    ${data.queue_position ? `
                                        <div class="stat-box highlight-secondary">
                                            <label>Applications Ahead</label>
                                            <span class="value tooltip-trigger" data-tooltip="${tooltipContent}" title="Click to see monthly breakdown">
                                                ${totalAhead.toLocaleString()}
                                                <span class="info-icon">ⓘ</span>
                                            </span>
                                            <small>Total applications ahead of you</small>
                                        </div>

                                        <div class="stat-box highlight-secondary">
                                            <label>Priority Cases</label>
                                            <span class="value">${priorityAllocation.toLocaleString()}</span>
                                            <small>Estimated priority cases ahead of you</small>
                                        </div>

                                        <div class="stat-box">
                                            <label>Visas On Hand</label>
                                            <span class="value">${Number(data.total_on_hand).toLocaleString()}</span>
                                            <small>As of ${new Date(data.last_updated).toLocaleDateString('en-US', { 
                                                day: 'numeric', month: 'short', year: 'numeric' 
                                            })}</small>
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="timeline-analysis ${timelineClass} collapsible-card" id="processing-timeline">
                        <h3 onclick="toggleCard('processing-timeline')">When is my Visa likely to be processed?</h3>
                        <div class="card-content">
                            ${timelineText || `
                                <p class="no-data">Unable to calculate timeline prediction. 
                                Insufficient processing data available.</p>
                            `}
                            
                            <!-- Add monthly ages table here -->
                            ${generateMonthlyAgesHTML(data.monthly_ages)}
                        </div>
                    </div>

                    ${generateProcessingRatesHTML(data.processing_rates)}
                </div>
            </div>
        `;
    }

    function calculateAverageVisaAge(rates) {
        if (!rates || !rates.length) return 0;
        
        // Get the most recent processing period
        const latestRate = rates[0];
        const currentMonth = new Date(latestRate.current_month);
        
        // Calculate ages of processed visas
        let totalAge = 0;
        let totalProcessed = 0;
        
        rates.forEach(rate => {
            // Only look at the latest month's processing
            if (rate.current_month === latestRate.current_month && rate.visas_processed > 0) {
                const lodgementDate = new Date(rate.lodged_month);
                const ageInMonths = (currentMonth.getFullYear() - lodgementDate.getFullYear()) * 12 + 
                                   (currentMonth.getMonth() - lodgementDate.getMonth());
                
                totalAge += (ageInMonths * rate.visas_processed);
                totalProcessed += rate.visas_processed;
            }
        });
        
        const averageAge = totalProcessed > 0 ? Math.round(totalAge / totalProcessed) : 0;
        
        debugLog('calculateAverageVisaAge', {
            processingMonth: currentMonth,
            totalAge,
            totalProcessed,
            averageAge
        });
        
        return averageAge;
    }

    function generateProcessingRatesHTML(rates) {
        return '';  // Return empty string to remove the rates list
    }

    function generateMonthlyAgesHTML(monthlyAges) {
        if (!monthlyAges || !monthlyAges.length) return '';

        // Sort months with newest first
        const sortedMonths = [...monthlyAges].sort((a, b) => 
            new Date(b.processing_month) - new Date(a.processing_month)
        );

        // Calculate trend by comparing last 3 months
        const last3Months = sortedMonths.slice(0, 3);
        const trend = last3Months[0].weighted_average - last3Months[2].weighted_average;
        const trendPercentage = ((trend / last3Months[2].weighted_average) * 100).toFixed(1);
        
        const trendAnalysis = trend > 0 ? 
            `<div class="trend-analysis positive">
                <span class="trend-icon">📈</span> Processing rate has increased by ${trendPercentage}% over the last 3 months, suggesting potential shorter waiting times.
            </div>` :
            `<div class="trend-analysis negative">
                <span class="trend-icon">📉</span> Processing rate has decreased by ${Math.abs(trendPercentage)}% over the last 3 months, suggesting potential longer waiting times.
            </div>`;

        // Calculate the moving average for each month
        const processedMonths = sortedMonths.map((month, index) => {
            // Calculate the sum of visas processed from the current month backwards
            let totalVisas = 0;
            let monthsCount = 0;

            for (let i = index; i < sortedMonths.length; i++) {
                // Ensure we're adding numbers, not concatenating strings
                totalVisas += Number(sortedMonths[i].total_processed);
                monthsCount++;
            }

            // Calculate the average by dividing the total by the number of months considered
            const averageRate = totalVisas / monthsCount;

            // Return the month data with the calculated moving average and debug info
            return {
                ...month,
                moving_average: averageRate,
                debugInfo: `Total Visas: ${totalVisas}, Months Count: ${monthsCount}, Average Rate: ${averageRate}`
            };
        });

        return `
            <div class="monthly-ages collapsible-card" id="monthly-analysis">
                <h3 onclick="toggleCard('monthly-analysis')">Monthly Processing Age Analysis</h3>
                <div class="card-content">
                    ${trendAnalysis}
                    <div class="monthly-ages-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Processing Month</th>
                                    <th>Visas Processed</th>
                                    <th>Average Age</th>
                                    <th>Age Range</th>
                                    <th>Monthly Average YTD</th>
                                    <th>3-Month Average</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${processedMonths.map(month => `
                                    <tr>
                                        <td>${new Date(month.processing_month).toLocaleDateString('en-US', { 
                                            month: 'short', 
                                            year: 'numeric'
                                        })}</td>
                                        <td>${month.total_processed.toLocaleString()}</td>
                                        <td>${Math.round(month.average_age)} months</td>
                                        <td>${month.youngest_visa} - ${month.oldest_visa} months</td>
                                        <td>${Math.round(month.moving_average).toLocaleString()}</td>
                                        <td>${month.weighted_average ? `${Math.round(month.weighted_average)}` : 'N/A'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    }

    // Add this helper function
    function getCurrentFinancialYear() {
        const now = new Date();
        const currentMonth = now.getMonth() + 1; // JavaScript months are 0-based
        const currentYear = now.getFullYear();
        return (currentMonth >= 7) ? currentYear : currentYear - 1;
    }

    function toggleCard(cardId) {
        const card = document.getElementById(cardId);
        card.classList.toggle('collapsed');
    }

    function generatePredictionHTML(data) {
        if (data.status === 'error') {
            return `<p class="no-data">${data.message}</p>`;
        }

        if (data.status === 'not_possible') {
            return `
                <div class="prediction-result negative">
                    <h3>Processing Timeline Prediction</h3>
                    <div class="prediction-message">
                        ${data.message}
                        <ul>
                            <li>Total places remaining: ${data.details.total_remaining.toLocaleString()}</li>
                            <li>Reserved for priority cases*: ${Math.round(data.details.priority_quota).toLocaleString()}</li>
                            <li>Available for non-priority: ${Math.round(data.details.non_priority_places).toLocaleString()}</li>
                            <li>Applications ahead of you: ${data.details.applications_ahead.toLocaleString()}</li>
                        </ul>
                        <p>* Priority quota is currently estimated at 20% of remaining places.</p>
                        <p>Please check back when next year's quota is announced.</p>
                    </div>
                </div>
            `;
        }

        return `
            <div class="prediction-result positive">
                <h3>Processing Timeline Prediction</h3>
                <div class="prediction-date">
                    Estimated processing date: ${new Date(data.predicted_date).toLocaleDateString()}
                </div>
                <div class="prediction-details">
                    <h4>Calculation breakdown:</h4>
                    <ul>
                        <li>Recent processing rate: ${Math.round(data.calculation.three_month_average).toLocaleString()} visas per month</li>
                        <li>Adjusted for priority cases (-20%)*: ${Math.round(data.calculation.adjusted_rate).toLocaleString()} visas per month</li>
                        <li>Applications ahead of you: ${data.calculation.total_ahead.toLocaleString()}</li>
                        <li>Estimated processing time: ${data.calculation.months_to_process} months</li>
                        <li>Last processing date: ${new Date(data.calculation.last_update).toLocaleDateString()}</li>
                    </ul>
                    <p>* Priority processing allocation is currently estimated at 20%.</p>
                </div>
            </div>
        `;
    }
    </script>
</body>
</html> 