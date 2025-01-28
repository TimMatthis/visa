<?php
// Include the database connection
include_once 'config/database.php';
// Include functions
include_once 'includes/functions.php';

// Debug database connection
if (!isset($conn)) {
    error_log("Database connection not established");
} else {
    error_log("Database connection successful");
}

// Debug visa types
$visaSubclasses = getVisaSelect($conn);
error_log("Visa subclasses returned: " . print_r($visaSubclasses, true));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
    <title>When Am I Going - Visa Processing Timeline Predictor</title>
</head>
<body>
    <!-- Add site name banner -->
    <div class="site-banner">
        <div class="site-banner-content">
            <a href="/" class="site-name">www.WhenAmIGoing.com</a>
        </div>
    </div>

    <header class="header-container">
        <div class="header-slider">
            <div class="header-slide" style="background-image: url('https://images.unsplash.com/photo-1524820197278-540916411e20?auto=format&fit=crop&q=80')"></div>
            <div class="header-slide" style="background-image: url('https://images.unsplash.com/photo-1548296404-93c7694b2f91?auto=format&fit=crop&q=80')"></div>
            <div class="header-slide" style="background-image: url('https://images.unsplash.com/photo-1614787635162-d96af1980854?auto=format&fit=crop&q=80')"></div>
        </div>
        <div class="header-content">
            <h1 class="header-title">Built by an Applicant, for Applicants</h1>
            <p class="header-subtitle">We understand the frustration of ever-changing predictions. Our aim is to make ours accurate, realistic, and trustworthy.</p>
        </div>
    </header>

    <main class="container">
        <h1>Visa Processing Timeline Predictor</h1>

        <!-- Add tab buttons -->
        <div class="main-tabs">
            <button class="main-tab-btn active" onclick="openTab('predictor')">Predictor</button>
            <button class="main-tab-btn" onclick="openTab('stats')">Stats</button>
        </div>

        <!-- Predictor Tab -->
        <div id="predictor" class="main-tab-content active">
            <p>Welcome to the Visa Processing Timeline Predictor for non-priority Australian visa applicants. Please select your visa type and the date you applied to get an estimated processing timeline.</p>
            
            <form id="predictionForm" onsubmit="return submitPrediction(event)" class="predictions-section">
                <div class="form-group">
                    <label for="visaType">Select Visa Type:</label>
                    <select id="visaType" name="visaType" required>
                        <option value="">Select a visa type</option>
                        <?php
                        $visaSubclasses = getVisaSelect($conn);
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
                                <?php
                                $currentYear = date('Y');
                                for ($year = $currentYear; $year >= 2020; $year--) {
                                    $selected = ($year == 2023) ? 'selected' : '';
                                    echo "<option value='$year' $selected>$year</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="date-input">
                            <label for="applicationMonth">Month:</label>
                            <select id="applicationMonth" name="applicationMonth" required>
                                <?php
                                $months = [
                                    1 => 'January', 2 => 'February', 3 => 'March',
                                    4 => 'April', 5 => 'May', 6 => 'June',
                                    7 => 'July', 8 => 'August', 9 => 'September',
                                    10 => 'October', 11 => 'November', 12 => 'December'
                                ];
                                foreach ($months as $num => $name) {
                                    $selected = ($num == 7) ? 'selected' : '';
                                    echo "<option value='$num' $selected>$name</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="date-input">
                            <label for="applicationDay">Day:</label>
                            <select id="applicationDay" name="applicationDay" required>
                                <?php
                                for ($day = 1; $day <= 31; $day++) {
                                    $selected = ($day == 10) ? 'selected' : '';
                                    echo "<option value='$day' $selected>$day</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>

                <button type="submit">Calculate</button>
            </form>
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
        // Update days in month when month or year changes
        function updateDays(minDate = null, maxDate = null) {
            const year = parseInt(document.getElementById('applicationYear').value);
            const month = parseInt(document.getElementById('applicationMonth').value);
            const daySelect = document.getElementById('applicationDay');
            
            // Get current selections before updating
            const currentSelection = daySelect.value;
            
            const daysInMonth = new Date(year, month, 0).getDate();
            let startDay = 1;
            let endDay = daysInMonth;

            // If we have a minDate and we're in the minimum month/year, enforce minimum day
            if (minDate && year === minDate.getFullYear() && month === (minDate.getMonth() + 1)) {
                startDay = minDate.getDate();
            }

            // If we have a maxDate and we're in the maximum month/year, enforce maximum day
            if (maxDate && year === maxDate.getFullYear() && month === (maxDate.getMonth() + 1)) {
                endDay = maxDate.getDate();
            }

            // Clear and repopulate days
            daySelect.innerHTML = '';
            for (let day = startDay; day <= endDay; day++) {
                const option = document.createElement('option');
                option.value = day;
                option.text = day;
                
                // If this day matches the current selection and it's within valid range, select it
                if (day === parseInt(currentSelection) && day >= startDay && day <= endDay) {
                    option.selected = true;
                }
                daySelect.appendChild(option);
            }

            // If no day is selected, select the first available day
            if (!daySelect.value) {
                daySelect.value = startDay;
            }

            // Validate the selected date is within bounds
            const selectedDate = new Date(year, month - 1, daySelect.value);
            if (minDate && selectedDate < minDate) {
                // If selected date is before minDate, set to minDate
                daySelect.value = minDate.getDate();
            }
            if (maxDate && selectedDate > maxDate) {
                // If selected date is after maxDate, set to maxDate
                daySelect.value = maxDate.getDate();
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
            
            // Update breadcrumb
            const visaSelect = document.getElementById('visaType');
            const visaText = visaSelect.options[visaSelect.selectedIndex].text;
            const year = document.getElementById('applicationYear').value;
            const month = document.getElementById('applicationMonth').value;
            const day = document.getElementById('applicationDay').value;
            
            document.getElementById('visaTypeBreadcrumb').textContent = `Visa ${visaText}`;
            document.getElementById('applicationDateBreadcrumb').textContent = 
                `Applied on ${formatDate(year, month, day)}`;
            document.getElementById('statsBreadcrumb').style.display = 'block';

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
                // Hide loading indicator and show results
                loadingIndicator.style.display = 'none';
                statsContent.innerHTML = data;
            })
            .catch(error => {
                loadingIndicator.style.display = 'none';
                statsContent.innerHTML = '<div class="error-message">Error loading results. Please try again.</div>';
                console.error('Error:', error);
            });

            return false;
        }

        // Add event listener for visa type change
        document.getElementById('visaType').addEventListener('change', function() {
            const visaType = this.value;
            if (visaType) {
                fetchOldestLodgementDate(visaType);
            }
        });

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

        // Fetch the oldest lodgement date from the server
        function fetchOldestLodgementDate(visaType) {
            fetch('api/get_oldest_lodgement_date.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({ 'visaType': visaType })
            })
            .then(response => response.json())
            .then(data => {
                if (data.oldest_date) {
                    const oldestDate = new Date(data.oldest_date);
                    const today = new Date();
                    updateDatePickerLimits(oldestDate, today);
                } else {
                    console.error('Error fetching oldest date:', data.error);
                }
            })
            .catch(error => console.error('Error:', error));
        }

        // Update the date picker limits based on oldest date and today
        function updateDatePickerLimits(minDate, maxDate) {
            const yearSelect = document.getElementById('applicationYear');
            const monthSelect = document.getElementById('applicationMonth');
            
            // Update year options
            yearSelect.innerHTML = '';
            for (let year = minDate.getFullYear(); year <= maxDate.getFullYear(); year++) {
                const option = document.createElement('option');
                option.value = year;
                option.text = year;
                if (year === maxDate.getFullYear()) {
                    option.selected = true;
                }
                yearSelect.appendChild(option);
            }

            // Update month options
            updateMonthOptions(minDate, maxDate);

            // Add event listeners for year and month changes
            yearSelect.addEventListener('change', function() {
                updateMonthOptions(minDate, maxDate);
            });
            monthSelect.addEventListener('change', function() {
                updateDays(minDate, maxDate);
            });

            // Initial update of days
            updateDays(minDate, maxDate);
        }

        // Update month options based on selected year
        function updateMonthOptions(minDate, maxDate) {
            const yearSelect = document.getElementById('applicationYear');
            const monthSelect = document.getElementById('applicationMonth');
            const selectedYear = parseInt(yearSelect.value);
            
            const months = [
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'
            ];

            let startMonth = 0;
            let endMonth = 11;

            if (selectedYear === minDate.getFullYear()) {
                startMonth = minDate.getMonth();
            }
            if (selectedYear === maxDate.getFullYear()) {
                endMonth = maxDate.getMonth();
            }

            monthSelect.innerHTML = '';
            for (let i = startMonth; i <= endMonth; i++) {
                const option = document.createElement('option');
                option.value = i + 1;
                option.text = months[i];
                if (i === endMonth) {
                    option.selected = true;
                }
                monthSelect.appendChild(option);
            }

            updateDays(minDate, maxDate);
        }

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
    </script>

    <!-- Add this before </body> -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-links">
                <a href="admin.php" class="admin-link">Admin Dashboard</a>
            </div>
            <div class="footer-attribution">
                Designed by <a href="http://www.nextzero.co.za" target="_blank" rel="noopener noreferrer">BinaryBushbaby</a> © 2025
            </div>
        </div>
    </footer>
</body>
</html>
