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
</head>

<body>
    <!-- Add this right after the body tag -->
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>
    
    <!-- Add site name banner -->
    <div class="site-banner">
        <div class="site-banner-content">
            <a href="/" class="site-name">WWW.WHENAMIGOING.COM - Realistic VISA predictions</a>
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
        </div>

        <!-- Predictor Tab -->
        <div id="predictor" class="main-tab-content active">
            <div class="warm-message">
                <p><strong>Welcome to the Visa Processing Timeline Predictor</strong> for non-priority Australian visa applicants.

Select your visa type and application date to get an estimated processing timeline based on queue position and past trends.</p>
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
    <script>
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
            
            monthSelect.innerHTML = '';
            const startMonth = selectedYear === minDate.getFullYear() ? minDate.getMonth() : 0;
            const endMonth = selectedYear === maxDate.getFullYear() ? maxDate.getMonth() : 11;
            
            for (let month = startMonth; month <= endMonth; month++) {
                const option = document.createElement('option');
                option.value = month + 1;
                option.text = new Date(2000, month).toLocaleString('default', { month: 'long' });
                monthSelect.appendChild(option);
            }
            
            updateDayOptions(minDate, maxDate);
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
                }, 500); // Small delay to ensure content is loaded
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
            
            // Switch to stats tab
            openTab('stats');
            
            // Show loading indicator
            const loadingIndicator = document.querySelector('.loading-indicator');
            const statsContent = document.getElementById('statsContent');
            loadingIndicator.style.display = 'flex';
            statsContent.innerHTML = '';

            // Get form data
            const formData = new FormData(event.target);
            
            // Make AJAX request
            fetch('stats.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                loadingIndicator.style.display = 'none';
                statsContent.innerHTML = data;
                
                // Add event listener for chart data
                window.addEventListener('chartDataReady', function(e) {
                    console.log('Chart data ready event received:', e.detail);
                    if (e.detail) {
                        window.chartData = e.detail;
                        initializeChart();
                    }
                }, { once: true });
            })
            .catch(error => {
                loadingIndicator.style.display = 'none';
                statsContent.innerHTML = '<div class="error-message">Error loading results. Please try again.</div>';
                console.error('Error:', error);
            });

            return false;
        }

        // Function to trigger confetti
        function celebrateVisa() {
            const canvas = document.getElementById('confetti-canvas');
            const myConfetti = confetti.create(canvas, {
                resize: true,
                useWorker: true
            });

            // Fire multiple bursts of confetti
            const duration = 3 * 1000; // Changed from 15 to 3 seconds
            const animationEnd = Date.now() + duration;
            const defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 0 };

            function randomInRange(min, max) {
                return Math.random() * (max - min) + min;
            }

            const interval = setInterval(function() {
                const timeLeft = animationEnd - Date.now();

                if (timeLeft <= 0) {
                    return clearInterval(interval);
                }

                const particleCount = 50 * (timeLeft / duration);
                
                // Since they fall down, start a bit higher than random
                myConfetti({
                    ...defaults,
                    particleCount,
                    origin: { x: randomInRange(0.1, 0.9), y: Math.random() - 0.2 }
                });
                myConfetti({
                    ...defaults,
                    particleCount,
                    origin: { x: randomInRange(0.1, 0.9), y: Math.random() - 0.2 }
                });
            }, 250);
        }

        // Check if we should trigger celebration
        document.addEventListener('DOMContentLoaded', function() {
            const celebrationMessage = document.querySelector('.celebration-message');
            if (celebrationMessage) {
                celebrateVisa();
            }
        });

        // Add header slider functionality
        document.addEventListener('DOMContentLoaded', function() {
            const slides = document.querySelectorAll('.header-slide');
            let currentSlide = 0;

            function showSlide(index) {
                slides.forEach(slide => slide.classList.remove('active'));
                slides[index].classList.add('active');
            }

            function nextSlide() {
                currentSlide = (currentSlide + 1) % slides.length;
                showSlide(currentSlide);
            }

            // Show first slide
            showSlide(0);

            // Change slide every 5 seconds
            setInterval(nextSlide, 5000);
        });

        // Replace the existing initializeChart function with this updated version
        function initializeChart() {
            const canvas = document.getElementById('ageHistogram');
            if (!canvas) {
                console.error('Canvas element not found');
                return;
            }

            if (typeof Chart === 'undefined') {
                console.error('Chart.js not loaded');
                return;
            }

            if (!window.chartData) {
                console.error('Chart data not available');
                return;
            }

            try {
                // Clear any existing chart
                const existingChart = Chart.getChart(canvas);
                if (existingChart) {
                    existingChart.destroy();
                }

                const ctx = canvas.getContext('2d');
                const chart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: window.chartData.labels,
                        datasets: [{
                            label: 'Number of Applications',
                            data: window.chartData.counts,
                            backgroundColor: 'rgba(54, 162, 235, 0.5)',
                            borderColor: 'rgb(54, 162, 235)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
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
                                    text: 'Age in Months'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            },
                            title: {
                                display: true,
                                text: 'Application Age Distribution'
                            }
                        }
                    }
                });
                console.log('Chart created successfully');
            } catch (error) {
                console.error('Error creating chart:', error);
            }
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
</body>
</html>
