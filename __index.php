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
    <title>Visa Processing Timeline Predictor</title>
</head>
<body>
    <div class="container">
        <nav class="nav-header">
            <div class="nav-container">
                <a href="admin.php" class="admin-link">Admin Dashboard</a>
            </div>
        </nav>
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
                                <option value="">Select Year</option>
                            </select>
                        </div>
                        <div class="date-input">
                            <label for="applicationMonth">Month:</label>
                            <select id="applicationMonth" name="applicationMonth" required>
                                <option value="">Select Month</option>
                            </select>
                        </div>
                        <div class="date-input">
                            <label for="applicationDay">Day:</label>
                            <select id="applicationDay" name="applicationDay" required>
                                <option value="">Select Day</option>
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>
    <script>
        // Global variable to store the oldest date
        let globalOldestDate = null;

        // Update days in month when month or year changes
        function updateDays() {
            if (globalOldestDate) {
                updateDayOptions(globalOldestDate);
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

        // Add event listeners for date picker
        document.getElementById('applicationMonth').addEventListener('change', updateDays);
        document.getElementById('applicationYear').addEventListener('change', updateDays);

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

        // Add this to your existing JavaScript
        document.getElementById('visaType').addEventListener('change', function() {
            // Fetch the oldest date for the selected visa type
            fetch('get_oldest_date.php?visa_type=' + this.value)
                .then(response => response.json())
                .then(data => {
                    if (data.oldest_date) {
                        globalOldestDate = new Date(data.oldest_date);
                        updateDateSelectors(globalOldestDate);
                    }
                });
        });

        function updateDateSelectors(oldestDate) {
            const yearSelect = document.getElementById('applicationYear');
            const monthSelect = document.getElementById('applicationMonth');
            const daySelect = document.getElementById('applicationDay');
            
            // Update year options
            const currentYear = new Date().getFullYear();
            const oldestYear = oldestDate.getFullYear();
            yearSelect.innerHTML = '';
            for (let year = currentYear; year >= oldestYear; year--) {
                const option = document.createElement('option');
                option.value = year;
                option.text = year;
                if (year === currentYear) option.selected = true;
                yearSelect.appendChild(option);
            }
            
            // If oldest date year is selected, limit months
            updateMonthOptions(oldestDate);
            updateDayOptions(oldestDate);
        }

        function updateMonthOptions(oldestDate) {
            const yearSelect = document.getElementById('applicationYear');
            const monthSelect = document.getElementById('applicationMonth');
            const selectedYear = parseInt(yearSelect.value);
            const currentDate = new Date();
            
            monthSelect.innerHTML = '';
            const months = [
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'
            ];
            
            let startMonth = 1;
            let endMonth = 12;
            
            // If selected year is oldest year, start from oldest month
            if (selectedYear === oldestDate.getFullYear()) {
                startMonth = oldestDate.getMonth() + 1;
            }
            
            // If selected year is current year, end at current month
            if (selectedYear >= currentDate.getFullYear()) {
                endMonth = currentDate.getMonth() + 1;
            }
            
            for (let i = startMonth; i <= endMonth; i++) {
                const option = document.createElement('option');
                option.value = i;
                option.text = months[i - 1];
                if (i === currentDate.getMonth() + 1 && selectedYear < currentDate.getFullYear()) {
                    option.selected = true;
                } else if (i === endMonth) {
                    option.selected = true;
                }
                monthSelect.appendChild(option);
            }
            
            // Update days after changing months
            updateDays();
        }
        
        function updateDayOptions(oldestDate) {
            const yearSelect = document.getElementById('applicationYear');
            const monthSelect = document.getElementById('applicationMonth');
            const daySelect = document.getElementById('applicationDay');
            
            const selectedYear = parseInt(yearSelect.value);
            const selectedMonth = parseInt(monthSelect.value);
            const currentDate = new Date();
            
            const daysInMonth = new Date(selectedYear, selectedMonth, 0).getDate();
            let startDay = 1;
            let endDay = daysInMonth;
            
            // If this is the oldest year and month, start from oldest day
            if (selectedYear === oldestDate.getFullYear() && 
                selectedMonth === oldestDate.getMonth() + 1) {
                startDay = oldestDate.getDate();
            }
            
            // If this is the current year and month, end at current day
            if ((selectedYear === currentDate.getFullYear() && 
                 selectedMonth === currentDate.getMonth() + 1) || 
                selectedYear > currentDate.getFullYear() || 
                (selectedYear === currentDate.getFullYear() && 
                 selectedMonth > currentDate.getMonth() + 1)) {
                endDay = currentDate.getDate();
            }
            
            daySelect.innerHTML = '';
            for (let day = startDay; day <= endDay; day++) {
                const option = document.createElement('option');
                option.value = day;
                option.text = day;
                if (day === endDay) {
                    option.selected = true;
                }
                daySelect.appendChild(option);
            }
        }

        // Initialize the date picker with the initial visa type's oldest date
        document.addEventListener('DOMContentLoaded', function() {
            const initialVisaType = document.getElementById('visaType').value;
            if (initialVisaType) {
                fetch('get_oldest_date.php?visa_type=' + initialVisaType)
                    .then(response => response.json())
                    .then(data => {
                        if (data.oldest_date) {
                            globalOldestDate = new Date(data.oldest_date);
                            updateDateSelectors(globalOldestDate);
                        }
                    });
            }
        });

        // Update event listeners to use global oldest date
        document.getElementById('applicationYear').addEventListener('change', function() {
            if (globalOldestDate) {
                updateMonthOptions(globalOldestDate);
            }
        });

        document.getElementById('applicationMonth').addEventListener('change', function() {
            if (globalOldestDate) {
                updateDayOptions(globalOldestDate);
            }
        });
    </script>
</body>
</html>
