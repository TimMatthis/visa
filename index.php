<?php
// Include the database connection
include_once 'config/database.php';
// Include functions
include_once 'includes/functions.php';

// Debug database connection
if (!isset($conn)) {
    error_log("Database connection not established");
    error_log("PHP version: " . phpversion());
    error_log("MySQL extension loaded: " . (extension_loaded('mysqli') ? 'yes' : 'no'));
} else {
    error_log("Database connection successful");
    // Test a simple query
    $test_query = mysqli_query($conn, "SELECT 1");
    error_log("Test query result: " . ($test_query ? 'successful' : 'failed'));
}

// Debug visa types
error_log("About to fetch visa subclasses");
$visaSubclasses = getVisaSelect($conn);
if (empty($visaSubclasses)) {
    error_log("No visa subclasses returned. Error: " . mysqli_error($conn));
} else {
    error_log("Visa subclasses returned: " . print_r($visaSubclasses, true));
}

// Get the earliest possible date for the selected visa type
error_log("Starting date calculations");
$earliest_date = getOldestLodgementDate($visaSubclasses[0]['code'] ?? null, $conn);
error_log("Earliest date result: " . print_r($earliest_date, true));
$min_date = $earliest_date['oldest_date'] ?? date('Y-m-d', strtotime('-1 year'));
$max_date = date('Y-m-d'); // Today

// Add debug logging
error_log("Date bounds - Min: $min_date, Max: $max_date");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Accurate Australian visa processing time calculator using queue-based predictions. More reliable than traditional percentile-based tools for estimating your visa timeline.">
    <meta name="keywords" content="Australian visa, visa processing time, visa timeline calculator, immigration processing times, Australia immigration, visa queue calculator">
    <meta name="author" content="WhenAmIGoing.com">
    <meta name="robots" content="index, follow">
    
    <!-- Canonical URL -->
    <link rel="canonical" href="https://www.whenamigoing.com" />
    
    <!-- Open Graph Tags for Social Sharing -->
    <meta property="og:title" content="Australia Visa Timeline Calculator - When Am I Going">
    <meta property="og:description" content="Get accurate visa processing predictions based on your actual queue position, not just historical data. Plan your move to Australia with confidence.">
    <meta property="og:image" content="https://www.whenamigoing.com/images/operahouse.jpg">
    <meta property="og:url" content="https://www.whenamigoing.com">
    <meta property="og:type" content="website">
    
    <!-- Twitter Card Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Australia Visa Timeline Calculator">
    <meta name="twitter:description" content="Get accurate visa processing predictions based on your actual queue position. More reliable than traditional percentile-based estimates.">
    <meta name="twitter:image" content="https://www.whenamigoing.com/images/operahouse.jpg">
    
    <!-- Additional SEO Meta Tags -->
    <meta name="application-name" content="When Am I Going">
    <meta name="theme-color" content="#3b82f6">
    
    <!-- Favicon -->
    <link rel="icon" type="image/webp" href="favicons/aus_icon.webp">
    <link rel="shortcut icon" type="image/webp" href="favicons/aus_icon.webp">
    
    <link rel="stylesheet" href="css/style.css">
    <title>Australia Visa Timeline Calculator - When Am I Going</title>

    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-7BJ2GHZFM8"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', 'G-7BJ2GHZFM8');
    </script>
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-4468022517932915"
     crossorigin="anonymous"></script>


</head>

<body>
    <!-- Add site name banner -->
    <div class="site-banner">
        <div class="site-banner-content">
            <a href="/" class="site-name">WWW.WHENAMIGOING.COM - Realistic VISA predictions</a>
            <a href="#" class="last-updated-link" onclick="openChangelogModal(event)">Last Updated: Feb 3, 2025</a>
            <button class="feedback-btn" onclick="openFeedbackModal(event)">Tell us what you want to see</button>
        </div>
    </div>

    <header class="header-container">
        <div class="header-slider">
            <div class="header-slide" style="background-image: url('images/uluru.jpg')"></div>
            <div class="header-slide" style="background-image: url('images/oceanroad.jpg')"></div>
            <div class="header-slide" style="background-image: url('images/operahouse.jpg')"></div>
        </div>
        <div class="header-content">
            <div class="header-text-container">
                <h1 class="header-title">Australia Visa Timeline Calculator</h1>
                <p class="header-subtitle">Built by an Applicant, for Applicants.</p>
            </div>
        </div>
    </header>

    <main class="container">
        <!-- Add new explanation section -->
        

        <h1>Visa Processing Timeline Predictor</h1>

        <!-- Add tab buttons -->
        <div class="main-tabs">
            <button class="main-tab-btn active" onclick="openTab('predictor')">YOUR APPLICATION</button>
            <button class="main-tab-btn" onclick="openTab('stats')">PREDICTION</button>
            <button class="main-tab-btn" onclick="openTab('visualizations')">VISUALIZATIONS</button>
        </div>

        <!-- Predictor Tab -->
        <div id="predictor" class="main-tab-content active">
            <div class="warm-message">
                <p><strong>Welcome to the Visa Processing Timeline Predictor</strong> for non-priority Australian visa applicants. Select your visa type and application date to get an estimated processing timeline based on queue position and past trends.</p>
            </div>

            <div class="warning-message">
                <p><strong>Important Notice:</strong> We have recently observed changes in visa granting patterns. While cases from November 2023 are being granted in addition to the historical FIFO queue, this appears to be negatively impacting processing times for applications lodged between June and October 2023. This pattern suggests:</p>
                <ul>
                    <li>Potentially shorter wait times for November 2023 applicants</li>
                    <li>Possible delays for June-October 2023 applicants</li>
                    <li>No significant net benefit to overall processing times across all applications</li>
                </ul>
                <p>This new pattern will be reflected in our forecasts from around February 20th. The full impact is currently difficult to model as the pattern is still emerging.</p>
            </div>
          
            <form id="predictionForm" onsubmit="return submitPrediction(event)" class="predictions-section">
                <div class="form-group">
                    <label for="visaType">Select Visa Type:</label>
                    <select id="visaType" name="visaType" required>
                        <option value="" selected>Select a visa type</option>
                        <?php
                        if (empty($visaSubclasses)) {
                            error_log("No visa subclasses returned from getVisaSelect(). Connection status: " . ($conn ? "Connected" : "Not connected"));
                            echo "<option value=''>Error loading visa types</option>";
                        }
                        foreach ($visaSubclasses as $subclass) {
                            echo "<option value='{$subclass['code']}'>{$subclass['name']}</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group date-picker-group">
                    <label>Application Date:</label>
                    <div class="date-inputs">
                        <div class="date-input">
                            <label for="applicationYear">Year:</label>
                            <select id="applicationYear" name="applicationYear" required>
                                <!-- Options will be populated by JavaScript -->
                            </select>
                        </div>
                        <div class="date-input">
                            <label for="applicationMonth">Month:</label>
                            <select id="applicationMonth" name="applicationMonth" required>
                                <!-- Options will be populated by JavaScript -->
                            </select>
                        </div>
                        <div class="date-input">
                            <label for="applicationDay">Day:</label>
                            <select id="applicationDay" name="applicationDay" required>
                                <!-- Options will be populated by JavaScript -->
                            </select>
                        </div>
                    </div>
                </div>

                <button type="submit">Calculate</button>
            </form>

            <!-- Moved methodology comparison section here -->
            <section class="methodology-comparison">
                <h2>Why Our Predictions Are Different</h2>
                
                <div class="comparison-cards">
                    <div class="comparison-card traditional">
                        <div class="card-header">
                            <h3>Traditional Processing Time Tools</h3>
                            <div class="method-icon">📊</div>
                        </div>
                        <div class="card-content">
                            <p>Most tools, including the <a href="https://immi.homeaffairs.gov.au/visas/getting-a-visa/visa-processing-times/global-visa-processing-times" target="_blank" rel="noopener noreferrer" class="govt-link">official government calculator</a>, show:</p>
                            <ul>
                                <li>Ages of recently granted visas</li>
                                <li>50th and 90th processing time percentiles of recently granted visas</li>
                                <li>This is not a forecast, just a reporting statistic</li>
                            </ul>
                            <div class="limitation-note">
                                <p><strong>Key Limitation:</strong><br>These statistics are backward-looking. They show how long past applicants waited but do not predict future wait times. Since they ignore the number of applications ahead in the queue, they change frequently and should not be used as a forecast. They tend to change repeatedly</p>
                            </div>
                        </div>
                    </div>

                    <div class="comparison-card our-method">
                        <div class="card-header">
                            <h3>Our Queue-Based Approach</h3>
                            <div class="method-icon">🎯</div>
                        </div>
                        <div class="card-content">
                            <p>We provide more accurate predictions by analyzing:</p>
                            <ul>
                                <li>Your actual position in the queue</li>
                                <li>Current processing rates</li>
                                <li>Annual processing caps</li>
                            </ul>
                            <div class="advantage-note">
                                <p><strong>Key Advantage:</strong> <br>Tells you if you are likely to be processed this Financial year considering the legislative caps. <br> Determines when you will reach the front of the queue based on a weighted average processing rate and queue length.<br>For more information on the migration program planning levels, visit the <a href="https://immi.homeaffairs.gov.au/what-we-do/migration-program-planning-levels" target="_blank" rel="noopener noreferrer">Australian Government Department of Home Affairs</a>.</p>
                            </div>
                        </div>
                    </div>
                </div>

                
            </section>
        </div>

        <!-- Stats Tab -->
        <div id="stats" class="main-tab-content">
            <div class="breadcrumb" id="statsBreadcrumb" style="display: none;">
                <span id="visaTypeBreadcrumb"></span> • 
                <span id="applicationDateBreadcrumb"></span>
            </div>
            <div class="loading-indicator" style="display: none;">
                <div class="spinner"></div>
                <p>Calculating visa processing timeline...</p>
            </div>
            <div id="statsContent"></div>
        </div>

        <!-- Visualizations Tab -->
        <div id="visualizations" class="main-tab-content">
            <div class="breadcrumb" id="visualizationsBreadcrumb" style="display: none;">
                <span id="visaTypeVizBreadcrumb"></span> • 
                <span id="applicationDateVizBreadcrumb"></span>
            </div>
            
            <div class="visualization-container">
                <div class="chart-card">
                    <h3>Your Position in Queue</h3>
                    <p class="chart-description">
                        Shows your current position relative to all applications in the queue. The green section represents your application, 
                        with red showing applications ahead of you and blue showing those behind. This helps visualize your place in line.
                    </p>
                    <div class="chart-container">
                        <canvas id="queueProgressChart"></canvas>
                    </div>
                </div>
            
                <div class="chart-card">
                    <h3>Processing Breakdown</h3>
                    <p class="chart-description">
                        Displays the ratio of processed vs unprocessed applications by lodgement month. Green bars show completed applications, 
                        while red indicates pending cases. Note: Processing rates can vary significantly month to month due to departmental priorities.
                    </p>
                    <div class="chart-container">
                        <canvas id="processingBreakdownChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3>Queue Position Visualization</h3>
                    <p class="chart-description">
                        Shows the monthly distribution of pending visa applications, with your month highlighted in green. 
                        Applications are processed in order of lodgement date (except for priority cases). Large volumes of 
                        applications lodged before yours will directly impact your waiting time. When the number of cases 
                        exceeds the monthly processing rate, average processing times increase for all subsequent applications.
                    </p>
                    <div class="chart-container">
                        <canvas id="queuePositionChart"></canvas>
                    </div>
                    <div class="queue-legend">
                        <div class="legend-item">
                            <div class="legend-color your-position"></div>
                            <span>Your Position</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color other-applications"></div>
                            <span>Other Applications</span>
                        </div>
                    </div>
                </div>      
            
            
                <div class="chart-card">
                    <h3>Monthly Processing Rate</h3>
                    <p class="chart-description">
                        Tracks how many visas are processed each month. The trend line indicates processing capacity over time. 
                        Caveat: Past processing rates don't guarantee future performance and can be affected by policy changes or resource allocation.
                    </p>
                    <div class="chart-container">
                        <canvas id="processingRateChart"></canvas>
                    </div>
                </div>

               

                <div class="chart-card">
                    <h3>Processing Time Trends</h3>
                    <p class="chart-description">
                        Shows how processing times have changed over recent months. The line represents the most common (modal) processing time. 
                        Important: Individual cases may vary significantly from these averages based on case complexity and other factors.
                    </p>
                    <div class="chart-container">
                        <canvas id="processingTimeChart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <h3>Forecast Processing Times</h3>
                    <p class="chart-description">
                        Projects future processing times based on current trends, queue size, and processing rates. 
                        Caution: Forecasts become less reliable further into the future and don't account for unexpected policy changes or resource adjustments.
                    </p>
                    <div class="chart-container">
                        <canvas id="forecastChart"></canvas>
                    </div>
                </div>

               

                
            </div>
        </div>

        <!-- Value Cards -->
        <section class="value-cards">
            <div class="card">
                <h3>Track Your Visa Application Progress</h3>
                <p>Stay informed about every step of your visa journey with our accurate tracking system.</p>
            </div>
            <div class="card">
                <h3>Realistic, Accurate Predictions</h3>
                <p>Get trustworthy timeline predictions based on real application data.</p>
            </div>
            <div class="card">
                <h3>Plan Your Journey with Confidence</h3>
                <p>Make informed decisions with our reliable visa processing insights.</p>
            </div>
            <div class="card">
                <h3>Free, Accurate, and Honest Updates</h3>
                <p>Access transparent and up-to-date information at no cost.</p>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation"></script>
    <script>
        // Add these variables at the start of your script to store chart instances
        let processingRateChartInstance = null;
        let queuePositionChartInstance = null;
        let processingTimeChartInstance = null;
        let forecastChartInstance = null;
        let processingBreakdownChartInstance = null;
        let queueProgressChartInstance = null;

        // Add these at the start of your script
        const maxDate = new Date('<?php echo $max_date; ?>');
        const minDate = new Date('<?php echo $min_date; ?>');
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize date picker with bounds
            initializeDatePicker(minDate, maxDate);
            
            // Set initial values to the middle of the range
            const midDate = new Date((minDate.getTime() + maxDate.getTime()) / 2);
            document.getElementById('applicationYear').value = midDate.getFullYear();
            document.getElementById('applicationMonth').value = midDate.getMonth() + 1;
            updateMonthOptions(minDate, maxDate);
            updateDayOptions(minDate, maxDate);
            document.getElementById('applicationDay').value = midDate.getDate();
            
            // Add visa type change handler
            document.getElementById('visaType').addEventListener('change', function() {
                updateDateBounds(this.value);
            });

            const slides = document.querySelectorAll('.header-slide');
            let currentSlide = 0;

            // Function to show a specific slide
            function showSlide(index) {
                slides.forEach(slide => {
                    slide.style.opacity = '0';
                    slide.classList.remove('active');
                });
                slides[index].style.opacity = '1';
                slides[index].classList.add('active');
            }

            // Function to move to next slide
            function nextSlide() {
                currentSlide = (currentSlide + 1) % slides.length;
                showSlide(currentSlide);
            }

            // Show first slide immediately
            showSlide(0);

            // Start the slideshow
            setInterval(nextSlide, 5000); // Change slide every 5 seconds
        });

        function updateDateBounds(visaTypeId) {
            // Fetch new bounds when visa type changes
            fetch('get_date_bounds.php?visa_type=' + visaTypeId)
                .then(response => response.json())
                .then(data => {
                    if (!data.error) {
                        const newMinDate = new Date(data.min_date);
                        initializeDatePicker(newMinDate, maxDate);
                    }
                });
        }

        function initializeDatePicker(minDate, maxDate) {
            const yearSelect = document.getElementById('applicationYear');
            const monthSelect = document.getElementById('applicationMonth');
            const daySelect = document.getElementById('applicationDay');
            
            // Update year options
            yearSelect.innerHTML = '';
            for (let year = minDate.getFullYear(); year <= maxDate.getFullYear(); year++) {
                const option = document.createElement('option');
                option.value = year;
                option.text = year;
                yearSelect.appendChild(option);
            }

            // Set initial month options
            updateMonthOptions(minDate, maxDate);

            // Add event listeners
            yearSelect.addEventListener('change', function() {
                updateMonthOptions(minDate, maxDate);
            });
            monthSelect.addEventListener('change', function() {
                updateDayOptions(minDate, maxDate);
            });
        }

        function updateMonthOptions(minDate, maxDate) {
            const yearSelect = document.getElementById('applicationYear');
            const monthSelect = document.getElementById('applicationMonth');
            const selectedYear = parseInt(yearSelect.value);
            const previousMonth = monthSelect.value; // Store current selection
            
            monthSelect.innerHTML = '';
            const startMonth = selectedYear === minDate.getFullYear() ? minDate.getMonth() : 0;
            const endMonth = selectedYear === maxDate.getFullYear() ? maxDate.getMonth() : 11;
            
            for (let month = startMonth; month <= endMonth; month++) {
                const option = document.createElement('option');
                option.value = month + 1;
                option.text = new Date(2000, month).toLocaleString('default', { month: 'long' });
                monthSelect.appendChild(option);
            }
            
            // Try to restore previous month selection if it's still valid
            if (previousMonth) {
                const monthValue = parseInt(previousMonth);
                if (monthValue >= startMonth + 1 && monthValue <= endMonth + 1) {
                    monthSelect.value = previousMonth;
                }
            }
            
            updateDayOptions(minDate, maxDate);
            updateTabAvailability();
        }

        function updateDayOptions(minDate, maxDate) {
            const yearSelect = document.getElementById('applicationYear');
            const monthSelect = document.getElementById('applicationMonth');
            const daySelect = document.getElementById('applicationDay');
            const selectedYear = parseInt(yearSelect.value);
            const selectedMonth = parseInt(monthSelect.value) - 1;
            
            const daysInMonth = new Date(selectedYear, selectedMonth + 1, 0).getDate();
            let startDay = 1;
            let endDay = daysInMonth;
            
            if (selectedYear === minDate.getFullYear() && selectedMonth === minDate.getMonth()) {
                startDay = minDate.getDate();
            }
            if (selectedYear === maxDate.getFullYear() && selectedMonth === maxDate.getMonth()) {
                endDay = maxDate.getDate();
            }
            
            daySelect.innerHTML = '';
            for (let day = startDay; day <= endDay; day++) {
                const option = document.createElement('option');
                option.value = day;
                option.text = day;
                daySelect.appendChild(option);
            }
        }

        // Tab functionality
        function openTab(tabName) {
            // Check if trying to open stats or visualizations tab
            if ((tabName === 'stats' || tabName === 'visualizations') && 
                document.querySelector(`[onclick="openTab('${tabName}')"]`).classList.contains('disabled')) {
                return; // Don't open the tab if it's disabled
            }

            const tabContents = document.getElementsByClassName('main-tab-content');
            const tabButtons = document.getElementsByClassName('main-tab-btn');

            // Hide all tab contents and remove active class from buttons
            for (let content of tabContents) {
                content.classList.remove('active');
            }
            for (let button of tabButtons) {
                button.classList.remove('active');
            }

            // Show selected tab and mark button as active
            document.getElementById(tabName).classList.add('active');
            document.querySelector(`[onclick="openTab('${tabName}')"]`).classList.add('active');

            // If switching to stats tab, check for celebration
            if (tabName === 'stats') {
                setTimeout(() => {
                    const celebrationMessage = document.querySelector('.celebration-message');
                    if (celebrationMessage) {
                        celebrateVisa();
                    }
                }, 500);
            }
        }

        // Format date for breadcrumb
        function formatDate(year, month, day) {
            const months = [
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'
            ];
            return `${day} ${months[month-1]} ${year}`;
        }

        // Form submission handler
        function submitPrediction(event) {
            event.preventDefault();
            
            // Switch to stats tab first
            openTab('stats');
            
            // Show loading indicators
            const loadingIndicator = document.querySelector('.loading-indicator');
            const statsContent = document.getElementById('statsContent');
            loadingIndicator.style.display = 'flex';
            statsContent.innerHTML = '';
            
            // Get form data
            const formData = new FormData(event.target);
            
            // Update breadcrumbs
            const visaSelect = document.getElementById('visaType');
            const visaText = visaSelect.options[visaSelect.selectedIndex].text;
            const year = document.getElementById('applicationYear').value;
            const month = document.getElementById('applicationMonth').value;
            const day = document.getElementById('applicationDay').value;
            const formattedDate = formatDate(year, month, day);
            
            // Update all breadcrumbs
            ['visaTypeBreadcrumb', 'visaTypeVizBreadcrumb'].forEach(id => {
                const element = document.getElementById(id);
                if (element) element.textContent = `Visa ${visaText}`;
            });
            
            ['applicationDateBreadcrumb', 'applicationDateVizBreadcrumb'].forEach(id => {
                const element = document.getElementById(id);
                if (element) element.textContent = `Applied on ${formattedDate}`;
            });
            
            ['statsBreadcrumb', 'visualizationsBreadcrumb'].forEach(id => {
                const element = document.getElementById(id);
                if (element) element.style.display = 'block';
            });

            // Make AJAX requests
            Promise.all([
                // Stats request
                fetch('stats.php', {
                    method: 'POST',
                    body: formData
                }).then(response => {
                    if (!response.ok) throw new Error('Stats request failed');
                    return response.text();
                }),
                
                // Visualization data request
                fetch('get_visualization_data.php', {
                    method: 'POST',
                    body: formData
                }).then(response => {
                    if (!response.ok) throw new Error('Visualization data request failed');
                    return response.json();
                })
            ])
            .then(([statsHtml, vizData]) => {
                console.log('Queue Position Data:', vizData.queuePosition);
                // Hide loading indicator
                loadingIndicator.style.display = 'none';
                
                // Update stats content
                statsContent.innerHTML = statsHtml;
                
                // Update visualizations
                if (vizData) {
                    // Destroy existing charts
                    if (processingRateChartInstance) {
                        processingRateChartInstance.destroy();
                    }
                    if (queuePositionChartInstance) {
                        queuePositionChartInstance.destroy();
                    }
                    if (processingTimeChartInstance) {
                        processingTimeChartInstance.destroy();
                    }
                    if (forecastChartInstance) {
                        forecastChartInstance.destroy();
                    }
                    if (processingBreakdownChartInstance) {
                        processingBreakdownChartInstance.destroy();
                    }
                    if (queueProgressChartInstance) {
                        queueProgressChartInstance.destroy();
                    }
                    
                    // Create new charts
                    createProcessingRateChart(vizData.processingRate);
                    createQueuePositionChart(vizData.queuePosition);
                    createProcessingTimeChart(vizData.processingTime);
                    createForecastChart(vizData.forecast);
                    createProcessingBreakdownChart(vizData.processingBreakdown);
                    createQueueProgressChart(vizData.queueProgress);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                loadingIndicator.style.display = 'none';
                statsContent.innerHTML = `
                    <div class="error-message">
                        Error loading results. Please try again.<br>
                        <small>${error.message}</small>
                    </div>`;
            });

            return false;
        }

        function createProcessingRateChart(data) {
            const ctx = document.getElementById('processingRateChart').getContext('2d');
            processingRateChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Processing Rate',
                        data: data.values,
                        borderColor: 'rgb(75, 192, 192)',
                        tension: 0.1,
                        fill: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Monthly Visa Processing Rate'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Visas Processed'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Month'
                            }
                        }
                    }
                }
            });
        }

        function createQueuePositionChart(data) {
            const ctx = document.getElementById('queuePositionChart').getContext('2d');
            
            // Get the application date from the form
            const year = document.getElementById('applicationYear').value;
            const month = document.getElementById('applicationMonth').value;
            const applicationMonth = `${year}-${month.padStart(2, '0')}`;
            
            // Filter out any invalid dates and find the application month index
            const validData = {
                labels: data.labels.filter(label => label !== 'Invalid Date'),
                queueSizes: data.queueSizes.filter((_, index) => data.labels[index] !== 'Invalid Date')
            };
            
            // Find the index of the application month
            const applicationMonthIndex = validData.labels.findIndex(label => {
                const [monthStr, yearStr] = label.split(' ');
                const monthDate = new Date(`${monthStr} 1, ${yearStr}`);
                const appDate = new Date(applicationMonth + '-01');
                return monthDate.getMonth() === appDate.getMonth() && 
                       monthDate.getFullYear() === appDate.getFullYear();
            });

            queuePositionChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: validData.labels,
                    datasets: [{
                        label: 'Monthly Queue Size',
                        data: validData.queueSizes,
                        backgroundColor: validData.labels.map((_, index) => 
                            index === applicationMonthIndex 
                                ? 'rgba(34, 197, 94, 0.6)'  // Green for application month
                                : 'rgba(54, 162, 235, 0.3)'  // Blue for other months
                        ),
                        borderColor: validData.labels.map((_, index) => 
                            index === applicationMonthIndex 
                                ? 'rgba(34, 197, 94, 1)'    // Green border for application month
                                : 'rgba(54, 162, 235, 1)'    // Blue border for other months
                        ),
                        borderWidth: validData.labels.map((_, index) => 
                            index === applicationMonthIndex ? 3 : 1  // Thicker border for application month
                        )
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: [`Monthly Visa Queue Size`, 
                                   `Application Date: ${formatDate(year, month, document.getElementById('applicationDay').value)}`],
                            font: {
                                size: 14,
                                weight: 'bold'
                            },
                            padding: 20
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    const isApplicationMonth = context.dataIndex === applicationMonthIndex;
                                    return [
                                        `Queue Size: ${Math.round(context.raw).toLocaleString()} applications`,
                                        isApplicationMonth ? '📌 Your Application Month' : ''
                                    ].filter(Boolean);
                                }
                            }
                        },
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Applications'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Lodgement Month'
                            }
                        }
                    }
                }
            });

            // Add a label for the application month if found
            if (applicationMonthIndex !== -1) {
                queuePositionChartInstance.options.plugins.annotation = {
                    annotations: {
                        applicationLabel: {
                            type: 'label',
                            xValue: applicationMonthIndex,
                            yValue: validData.queueSizes[applicationMonthIndex],
                            backgroundColor: 'rgba(34, 197, 94, 0.9)',
                            content: '📌 Your Application Month',
                            font: {
                                size: 14,
                                weight: 'bold'
                            },
                            color: 'white',
                            padding: 8,
                            yAdjust: -20,
                            borderRadius: 4
                        }
                    }
                };
                queuePositionChartInstance.update();
            }
        }

        function createProcessingTimeChart(data) {
            const ctx = document.getElementById('processingTimeChart').getContext('2d');
            processingTimeChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Most Common Processing Time',
                        data: data.modalAges,
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.1)',
                        tension: 0.1,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Visa Processing Time Trends'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: ${context.raw} months`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Processing Time (Months)'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Month'
                            }
                        }
                    }
                }
            });
        }

        function createForecastChart(data) {
            const ctx = document.getElementById('forecastChart').getContext('2d');
            
            forecastChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Projected Grant Age',
                            data: data.projectedAges,
                            borderColor: 'rgb(59, 130, 246)',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 2,
                            tension: 0.1,
                            yAxisID: 'y',
                            order: 1
                        },
                        {
                            label: 'Queue Size',
                            data: data.queueSizes,
                            borderColor: 'rgb(234, 88, 12)',
                            backgroundColor: 'rgba(234, 88, 12, 0.1)',
                            borderWidth: 2,
                            tension: 0.1,
                            yAxisID: 'y1',
                            order: 2
                        },
                        {
                            label: 'Processing Rate',
                            data: data.processingRates,
                            borderColor: 'rgb(22, 163, 74)',
                            backgroundColor: 'rgba(22, 163, 74, 0.1)',
                            borderWidth: 2,
                            tension: 0.1,
                            yAxisID: 'y2',
                            order: 3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Projected Grant Ages and Queue Metrics'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    const label = context.dataset.label;
                                    const value = context.raw;
                                    if (label === 'Projected Grant Age') {
                                        return `${label}: ${value} months`;
                                    } else if (label === 'Queue Size') {
                                        return `${label}: ${value.toLocaleString()} cases`;
                                    } else {
                                        return `${label}: ${value ? value.toLocaleString() : 'N/A'} per month`;
                                    }
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Grant Age (Months)'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Queue Size'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        },
                        y2: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Processing Rate'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            });
        }

        function createProcessingBreakdownChart(data) {
            const ctx = document.getElementById('processingBreakdownChart').getContext('2d');
            
            processingBreakdownChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Processed Cases',
                            data: data.processed,
                            backgroundColor: 'rgba(34, 197, 94, 0.6)', // Green
                            borderColor: 'rgb(34, 197, 94)',
                            borderWidth: 1,
                            stack: 'Stack 0'
                        },
                        {
                            label: 'Unprocessed Cases',
                            data: data.unprocessed,
                            backgroundColor: 'rgba(239, 68, 68, 0.4)', // Red
                            borderColor: 'rgb(239, 68, 68)',
                            borderWidth: 1,
                            stack: 'Stack 0'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Processing Status by Lodgement Month',
                            font: {
                                size: 16,
                                weight: 'bold'
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    const label = context.dataset.label;
                                    const value = context.raw;
                                    return `${label}: ${value.toLocaleString()} cases`;
                                },
                                footer: function(tooltipItems) {
                                    const total = tooltipItems.reduce((sum, item) => sum + item.raw, 0);
                                    const processed = tooltipItems[0].raw;
                                    const percentage = ((processed / total) * 100).toFixed(1);
                                    return `Processing Rate: ${percentage}%`;
                                }
                            }
                        },
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        x: {
                            stacked: true,
                            title: {
                                display: true,
                                text: 'Lodgement Month'
                            }
                        },
                        y: {
                            stacked: true,
                            title: {
                                display: true,
                                text: 'Number of Cases'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }

        function createQueueProgressChart(data) {
            const ctx = document.getElementById('queueProgressChart').getContext('2d');
            
            // Calculate the segments for the horizontal bar
            const totalQueue = data.total_queue;
            const ahead = data.ahead;
            const behind = data.behind;

            queueProgressChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Queue Position'],
                    datasets: [
                        {
                            label: 'Applications Ahead',
                            data: [ahead],
                            backgroundColor: 'rgba(255, 192, 203, 0.5)', // Light pink
                            borderColor: 'rgba(255, 192, 203, 1)',
                            borderWidth: 1,
                            barPercentage: 0.8,
                        },
                        {
                            label: 'Applications Behind',
                            data: [behind],
                            backgroundColor: 'rgba(173, 216, 230, 0.5)', // Light blue
                            borderColor: 'rgba(173, 216, 230, 1)',
                            borderWidth: 1,
                            barPercentage: 0.8,
                        }
                    ]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Your Position in Current Queue',
                            font: {
                                size: 16,
                                weight: 'bold'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    if (context.dataset.label === 'Applications Ahead') {
                                        return `${context.dataset.label}: ${ahead.toLocaleString()}`;
                                    } else {
                                        return `${context.dataset.label}: ${behind.toLocaleString()}`;
                                    }
                                },
                                afterBody: function() {
                                    return [`Your Position: ${data.position.toLocaleString()} of ${totalQueue.toLocaleString()} total applications`];
                                }
                            }
                        },
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                generateLabels: function(chart) {
                                    const defaultLabels = Chart.defaults.plugins.legend.labels.generateLabels(chart);
                                    // Add custom legend item for the green line
                                    defaultLabels.push({
                                        text: 'Your Position',
                                        fillStyle: 'rgba(34, 197, 94, 1)',
                                        strokeStyle: 'rgba(34, 197, 94, 1)',
                                        lineWidth: 2,
                                        hidden: false,
                                        index: defaultLabels.length
                                    });
                                    return defaultLabels;
                                }
                            }
                        },
                        annotation: {
                            annotations: {
                                line1: {
                                    type: 'line',
                                    xMin: ahead,
                                    xMax: ahead,
                                    yMin: -0.3,
                                    yMax: 0.3,
                                    borderColor: 'rgba(34, 197, 94, 1)', // Green color
                                    borderWidth: 3,
                                    label: {
                                        content: `Your Position: ${data.position.toLocaleString()}`,
                                        enabled: true,
                                        position: 'top',
                                        backgroundColor: 'rgba(34, 197, 94, 0.9)',
                                        color: 'white',
                                        padding: 8,
                                        borderRadius: 4,
                                        font: {
                                            weight: 'bold'
                                        }
                                    }
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            stacked: true,
                            title: {
                                display: true,
                                text: 'Number of Applications'
                            },
                            grid: {
                                display: true
                            }
                        },
                        y: {
                            stacked: true,
                            display: false
                        }
                    }
                }
            });
        }

        // Add these changes after the openTab function
        function updateTabAvailability() {
            const visaType = document.getElementById('visaType').value;
            const year = document.getElementById('applicationYear').value;
            const month = document.getElementById('applicationMonth').value;
            const day = document.getElementById('applicationDay').value;
            
            const predictionTab = document.querySelector('[onclick="openTab(\'stats\')"]');
            const visualizationTab = document.querySelector('[onclick="openTab(\'visualizations\')"]');
            
            // Check if all required fields are filled
            const isComplete = visaType && year && month && day;
            
            // Update tab appearance and functionality
            [predictionTab, visualizationTab].forEach(tab => {
                if (!isComplete) {
                    tab.classList.add('disabled');
                    tab.style.opacity = '0.5';
                    tab.style.cursor = 'not-allowed';
                    // Add tooltip
                    tab.title = 'Please select visa type and application date first';
                } else {
                    tab.classList.remove('disabled');
                    tab.style.opacity = '1';
                    tab.style.cursor = 'pointer';
                    tab.title = '';
                }
            });
        }

        // Add event listeners for form fields
        document.addEventListener('DOMContentLoaded', function() {
            // ... existing DOMContentLoaded code ...

            // Add change event listeners to all form fields
            ['visaType', 'applicationYear', 'applicationMonth', 'applicationDay'].forEach(id => {
                document.getElementById(id).addEventListener('change', updateTabAvailability);
            });

            // Initial tab availability check
            updateTabAvailability();
        });

        // Add these functions to your existing script section
        function openChangelogModal(event) {
            event.preventDefault();
            document.getElementById('changelogModal').style.display = 'block';
            document.body.style.overflow = 'hidden'; // Prevent scrolling when modal is open
        }

        function closeChangelogModal() {
            document.getElementById('changelogModal').style.display = 'none';
            document.body.style.overflow = 'auto'; // Restore scrolling
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const changelogModal = document.getElementById('changelogModal');
            const feedbackModal = document.getElementById('feedbackModal');
            if (event.target == changelogModal) {
                closeChangelogModal();
            }
            if (event.target == feedbackModal) {
                closeFeedbackModal();
            }
        }

        // Close modal on escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeChangelogModal();
            }
        });

        function openFeedbackModal(event) {
            event.preventDefault();
            document.getElementById('feedbackModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeFeedbackModal() {
            document.getElementById('feedbackModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function submitFeedback(event) {
            event.preventDefault();
            
            const feedbackText = document.getElementById('feedbackText').value;
            const feedbackTheme = document.getElementById('feedbackTheme').value;
            
            // Show loading state
            const submitButton = event.target.querySelector('button[type="submit"]');
            const originalButtonText = submitButton.textContent;
            submitButton.textContent = 'Submitting...';
            submitButton.disabled = true;
            
            fetch('save_feedback.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    feedback: feedbackText,
                    theme: feedbackTheme
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showFeedbackMessage('Thank you for your feedback!', 'success');
                    closeFeedbackModal();
                    document.getElementById('feedbackForm').reset();
                } else {
                    showFeedbackMessage('Error: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showFeedbackMessage('Error submitting feedback. Please try again.', 'error');
            })
            .finally(() => {
                // Reset button state
                submitButton.textContent = originalButtonText;
                submitButton.disabled = false;
            });
        }

        function showFeedbackMessage(message, type) {
            const existingMessage = document.querySelector('.feedback-confirmation');
            if (existingMessage) {
                existingMessage.remove();
            }

            const messageElement = document.createElement('div');
            messageElement.className = `feedback-confirmation ${type}`;
            messageElement.textContent = message;

            document.body.appendChild(messageElement);

            // Add to CSS for error styling
            const style = document.createElement('style');
            style.textContent = `
                .feedback-confirmation.error {
                    background-color: #fee2e2;
                    border: 1px solid #ef4444;
                    color: #dc2626;
                }
            `;
            document.head.appendChild(style);

            setTimeout(() => {
                messageElement.style.animation = 'fadeOut 0.3s ease-out';
                setTimeout(() => messageElement.remove(), 300);
            }, 5000);
        }
    </script>

    <!-- Add this before </body> -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-attribution">
                Designed by <a href="http://www.nextzero.co.za" target="_blank" rel="noopener noreferrer">BinaryBushbaby</a> © 2025
            </div>
            <div class="disclaimer">
                <p><strong>Legal Disclaimer:</strong> This website provides estimates based on historical visa processing data. These predictions are for guidance only and should not be considered official or guaranteed processing times.</p>
            </div>
            <div class="footer-links">
                <a href="admin.php" class="admin-link">Admin Dashboard</a>
            </div>
        </div>
    </footer>

    <!-- Changelog Modal -->
    <div id="changelogModal" class="modal">
        <div class="modal-content changelog-modal">
            <span class="close" onclick="closeChangelogModal()">&times;</span>
            <h2>Changelog</h2>
            <div class="changelog-entries">
                <div class="changelog-entry">
                    <div class="changelog-date">February 3, 2025</div>
                    <ul>
                        <li>Enhanced visualization system with new interactive charts</li>
                        <li>Added forecasted mean processing rate calculations</li>
                        <li>Implemented real-time queue position tracking</li>
                        <li>Updated processing time prediction algorithm</li>
                        <li>Added new queue position visualization showing applications ahead and behind</li>
                        <li>Improved accuracy of processing rate calculations</li>
                    </ul>
                </div>
                
                <div class="changelog-entry">
                    <div class="changelog-date">January 15, 2025</div>
                    <ul>
                        <li>Initial release</li>
                        <li>Imported YTD FOI data to 31 December 2024</li>
                        <li>Implemented queue-based prediction system</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Feedback Modal -->
    <div id="feedbackModal" class="modal">
        <div class="modal-content feedback-modal">
            <span class="close" onclick="closeFeedbackModal()">&times;</span>
            <h2>Share Your Feedback</h2>
            <form id="feedbackForm" onsubmit="submitFeedback(event)">
                <div class="feedback-input-group">
                    <label for="feedbackText">What would you like to see?</label>
                    <textarea id="feedbackText" name="feedbackText" required></textarea>
                </div>
                <div class="feedback-input-group">
                    <label for="feedbackTheme">Theme:</label>
                    <select id="feedbackTheme" name="feedbackTheme" required>
                        <option value="">Select a theme</option>
                        <option value="feature_request">New Feature Request</option>
                        <option value="visualization">Visualization Improvement</option>
                        <option value="usability">Usability Enhancement</option>
                        <option value="data_request">Additional Data Request</option>
                        <option value="bug_report">Bug Report</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <button type="submit" class="submit-feedback-btn">Submit Feedback</button>
            </form>
        </div>
    </div>
</body>
</html>
