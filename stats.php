<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

// Get parameters from POST request
$visa_type_id = $_POST['visaType'] ?? null;
$application_year = $_POST['applicationYear'] ?? null;
$application_month = $_POST['applicationMonth'] ?? null;
$application_day = $_POST['applicationDay'] ?? null;

// Initialize $age_stats as null
$age_stats = null;

if (!$visa_type_id) {
    echo '<div class="error-message">Visa type is required</div>';
    exit;
}

// Get visa queue data
$debug_data = debugVisaQueueSummary($visa_type_id);
$result = getVisasOnHand($visa_type_id);

// Get monthly processing data
$monthly_processing = getProcessedByMonth($visa_type_id);

// Get monthly average processing rate
$monthly_averages = getMonthlyAverageProcessingRate($visa_type_id);

// Get 3-month weighted average processing rate
$weighted_average = getWeightedAverageProcessingRate($visa_type_id);

// Get annual allocation
$annual_allocation = getAnnualAllocation($visa_type_id);

// Get cases ahead in queue (using the application date if provided)
$application_date = null;
if ($application_year && $application_month && $application_day) {
    $application_date = sprintf('%04d-%02d-%02d', $application_year, $application_month, $application_day);
}
if ($application_date) {
    $cases_ahead = getCasesAheadInQueue($visa_type_id, $application_date);
}

// Get total processed to date
$total_processed = getTotalProcessedToDate($visa_type_id);

// Get priority cases if application date is provided
if ($application_date) {
    $priority_cases = getPriorityCases($visa_type_id, $application_date);
}

// Get priority ratio if application date is provided
if ($application_date) {
    $priority_ratio = getPriorityRatio($visa_type_id, $application_date);
}

// Get remaining allocations
$allocations_remaining = getAllocationsRemaining($visa_type_id);

// Get visa processing prediction if application date is provided
if ($application_date) {
    error_log("Getting prediction for date: $application_date");
    $prediction = getVisaProcessingPrediction($visa_type_id, $application_date);
    error_log("Prediction result: " . print_r($prediction, true));
}

// Calculate months_away and is_very_overdue at the top level
$months_away = isset($prediction['ninety_percent']) ? 
    (strtotime($prediction['ninety_percent']) - time()) / (30.44 * 24 * 60 * 60) : 
    null;

$is_very_overdue = ($prediction['is_previous_fy'] ?? false) && 
    isset($prediction['ninety_percent']) && 
    (new DateTime($prediction['ninety_percent'])) <= (new DateTime())->add(new DateInterval('P1D'));

// Calculate application age if we have a lodgement date
$application_age = null;
if (isset($prediction['lodgement_date'])) {
    $lodgement_date = new DateTime($prediction['lodgement_date']);
    $today = new DateTime();
    $application_age = $lodgement_date->diff($today);
    error_log("Application age calculated: " . $application_age->y . " years, " . $application_age->m . " months"); // Debug log
}

// Get case age statistics if we have an application date
if ($application_date) {
    $age_stats = getCaseAgeStatistics($visa_type_id, $application_date);
    // Move the debug log here after we have the data
    error_log("Age Stats Data: " . print_r($age_stats, true));
}

// Helper function to generate share message based on prediction state
function generateShareMessage($prediction, $application_age = null, $months_away = null) {
    // Get age message if we have application_age
    $ageMessage = null;
    if ($application_age && $application_age instanceof DateInterval) {
        $months = ($application_age->y * 12) + $application_age->m;
        $ageMessage = getAgeMessage($application_age);
    }

    // Directly use emojis in the message
    $shareMessage = "🚀 WhenAmIGoing? Visa Tracker Update 🚀\n\n";
    
    if (($prediction['is_overdue'] ?? false)) {
        $shareMessage .= "⚠️ ATTENTION: Application Requires Review ⚠️\n\n";
        $shareMessage .= "📋 Your application should have been processed as it is at or near the front of the queue.\n";
        $shareMessage .= "⏰ Status: OVERDUE\n";
        if (isset($application_age)) {
            $shareMessage .= "⌛ Processing for: {$application_age->y} years and {$application_age->m} months\n";
        }
        $shareMessage .= "📊 Outstanding cases: " . number_format($prediction['cases_ahead']) . "\n";
        $shareMessage .= "\n🔍 This may indicate a processing delay or technical issue that needs attention.";
    } 
    elseif ($prediction['next_fy'] ?? false) {
        $shareMessage .= "📆 My visa is tracking for next Financial Year (After July 2024) ⭐\n\n";
        $shareMessage .= "📈 Cases ahead: " . number_format($prediction['cases_ahead']) . "\n";
        $shareMessage .= "🎯 Places remaining: " . number_format($prediction['steps']['non_priority_places']) . "\n";
        $shareMessage .= "\n⭐ Track your journey at WhenAmIGoing.com";
    }
    elseif (isset($months_away) && $months_away <= 3) {
        $today = new DateTime();
        $grant_date = new DateTime($prediction['eighty_percent']);
        
        if ($grant_date <= $today) {
            $shareMessage .= "🎉 VISA GRANT IMMINENT! 🎊\n\n";
            $shareMessage .= "📋 Your application is currently being processed\n";
            $shareMessage .= "⚡ Status: Within processing variation window\n";
            $shareMessage .= "👀 Keep an eye on your emails!\n";
        } else {
            // Calculate days and weekends using DateTime::diff
            $interval = $today->diff($grant_date);
            $days_remaining = $interval->days;
            $weekends_remaining = floor($days_remaining / 7);
            
            $shareMessage .= "🎉 Less than 3 months to go! 🎊\n\n";
            $shareMessage .= "⏰ Only {$days_remaining} days until your likely grant date!\n";
            $shareMessage .= "📆 That's {$weekends_remaining} weekends to get ready! 🤗\n\n";
            $shareMessage .= "📋 Planning Dates:\n";
            $shareMessage .= "⭐ Likely (80%): " . date('j F Y', strtotime($prediction['eighty_percent'])) . "\n";
            $shareMessage .= "🌟 Latest (100%): " . date('j F Y', strtotime($prediction['latest_date'])) . "\n\n";
            $shareMessage .= "📊 Cases ahead: " . number_format($prediction['cases_ahead']) . "\n";
            $shareMessage .= "🌟 Time to start planning your move to Australia! 🇦🇺";
        }
    }
    else {
        $today = new DateTime();
        $grant_date = new DateTime($prediction['eighty_percent']);
        
        $shareMessage .= "🎯 On Track This Financial Year ⭐\n\n";
        $shareMessage .= "📆 Recommended Planning Dates:\n";
        $shareMessage .= "⭐ Likely (80%): " . date('j F Y', strtotime($prediction['eighty_percent'])) . "\n";
        $shareMessage .= "🌟 Latest (100%): " . date('j F Y', strtotime($prediction['latest_date'])) . "\n\n";
        $shareMessage .= "📊 Cases ahead: " . number_format($prediction['cases_ahead']) . "\n";
        $shareMessage .= "💫 We recommend using the 80% date for planning";
    }
    
    $shareMessage .= "\n\n🔗 Track your visa timeline at www.WhenAmIGoing.com 🌍";
    
    // Add age message if available
    if ($ageMessage) {
        $shareMessage .= "\n\n" . $ageMessage;
    }
    
    // Encode the message for URL
    return rawurlencode($shareMessage);
}
?>

<div class="stats-grid">
    <?php if (isset($prediction) && !isset($prediction['error'])): ?>
        <?php if (($prediction['is_overdue'] ?? false)): ?>
            <!-- Warning Alert Card - Shows First -->
            <div class="stat-card prediction-highlight overdue-alert">
                <div class="stat-header">
                    <h3>⚠️ Important Notice: Overdue Application</h3>
                </div>
                <div class="stat-body">
                    <div class="overdue-message">
                        <div class="stat-label">Your application should have been processed as it is at or near the front of the queue. This may indicate a processing delay or technical issue that needs attention.</div>
                        <div class="prediction-details">
                            <div class="detail-item">
                                <span class="label">Application Date:</span>
                                <span class="value"><?php echo date('j F Y', strtotime($prediction['lodgement_date'])); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="label">Outstanding Cases:</span>
                                <span class="value"><?php echo number_format($prediction['cases_ahead']); ?></span>
                            </div>
                        </div>
                        <div class="action-recommendation">
                            <p class="urgent-text">Recommended Actions:</p>
                            <ol class="action-steps">
                                <li>Contact your registered migration agent to review your case</li>
                                <li>If you don't have an agent, consider engaging one to help resolve any potential issues</li>
                                <li>Check current processing times on the <a href="https://immi.homeaffairs.gov.au/visas/getting-a-visa/visa-processing-times/global-visa-processing-times" target="_blank" class="processing-times-link">Department's website</a></li>
                            </ol>
                            <div class="important-note">
                                While delays can occur for various reasons, it's important to ensure your application hasn't encountered any technical issues or missed communications.
                            </div>
                            <div class="contact-info">
                                <a href="https://immi.homeaffairs.gov.au/help-support/contact-us" 
                                   target="_blank" 
                                   class="contact-button">
                                    Contact Home Affairs →
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="share-section">
                    <a href="https://wa.me/?text=<?php echo generateShareMessage($prediction, $application_age, $months_away); ?>" 
                       target="_blank" 
                       class="whatsapp-share-btn">
                        <svg class="whatsapp-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.48 2 2 6.48 2 12C2 13.86 2.49 15.59 3.34 17.09L2.1 21.9L7 20.66C8.47 21.47 10.17 21.93 12 21.93C17.52 21.93 22 17.44 22 11.93C22 6.42 17.52 2 12 2ZM8.53 15.92L8.23 15.75C6.98 15.08 6.19 14.17 5.85 13.04C5.5 11.91 5.6 10.65 6.12 9.58C6.65 8.51 7.57 7.69 8.72 7.29C9.87 6.89 11.11 6.94 12.23 7.43C13.34 7.92 14.24 8.82 14.73 9.94C15.22 11.06 15.27 12.3 14.87 13.45C14.47 14.6 13.66 15.52 12.59 16.05C11.52 16.58 10.26 16.67 9.13 16.33L8.91 16.25L6.11 17.13L7 14.38L8.53 15.92Z"/>
                        </svg>
                        Share on WhatsApp
                    </a>
                </div>
            </div>

            <div class="spacer"></div>
        <?php endif; ?>

    

        <!-- Main prediction highlight card -->
        <?php if (!($prediction['is_overdue'] ?? false)): ?>
            <div class="stat-card prediction-highlight <?php 
                if (!($prediction['next_fy'] ?? false) && isset($months_away) && $months_away <= 3) {
                    echo 'celebration-mode';
                }
            ?>">
                <div class="stat-header">
                    <?php if ($prediction['next_fy'] ?? false): ?>
                        <h3>Planning for Your Future Move</h3>
                    <?php elseif (isset($months_away) && $months_away <= 3): ?>
                        <h3>🎉 Get Ready for Australia! 🎉</h3>
                        <canvas id="confetti-canvas"></canvas>
                    <?php else: ?>
                        <h3>On Track This Financial Year</h3>
                    <?php endif; ?>
                </div>
                <div class="stat-body">
                    <?php if ($prediction['next_fy'] ?? false): ?>
                        <!-- Next FY message -->
                        <div class="planning-message">
                            <div class="stat-label">
                                <div class="planning-header">Planning Your Move to Australia 🇦🇺</div>
                                <div class="planning-explanation">
                                    Based on current processing patterns, your visa is likely to be granted in the next Financial Year (starting 1 July 2024). Here's what you need to know:
                                </div>
                            </div>
                            <div class="visa-cap-explanation">
                                <h4>Understanding Visa Processing</h4>
                                <ul>
                                    <li>The Australian Government has set the permanent migration program cap at 195,000 places for 2023-24</li>
                                    <li>Once this cap is reached, remaining applications move to the next program year</li>
                                    <li>Processing isn't strictly first-come-first-served - it depends on how many applications were lodged in previous months</li>
                                </ul>
                            </div>
                            <div class="prediction-details">
                                <div class="detail-item">
                                    <span class="label">Cases Ahead:</span>
                                    <span class="value"><?php echo number_format($prediction['cases_ahead']); ?></span>
                                    <span class="explanation">Total number of applications lodged before yours still waiting</span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Total Places Remaining:</span>
                                    <span class="value"><?php echo number_format($allocations_remaining['remaining']); ?></span>
                                    <span class="explanation">Places left in this year's allocation (<?php echo number_format($allocations_remaining['total_allocation']); ?> total places, <?php echo number_format($allocations_remaining['total_processed']); ?> used)</span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Places Available Before Your Case:</span>
                                    <span class="value"><?php echo number_format($prediction['steps']['non_priority_places']); ?></span>
                                    <span class="explanation">
                                        <?php echo number_format($allocations_remaining['remaining']); ?> total places × 
                                        <?php echo number_format($prediction['steps']['non_priority_ratio'] * 100, 1); ?>% = 
                                        <?php echo number_format($prediction['steps']['non_priority_places']); ?> places. (We use the ratio of cases this year that were processed ahead of yours to determine this factor.)
                                    </span>
                                </div>
                            </div>
                            <div class="next-fy-explanation">
                                <h4>What This Means For You</h4>
                                <p>While your application will likely move to the next program year, this is actually quite common and not a cause for concern. The next program year starts on 1 July 2024, and your application will be well-positioned for processing in the new allocation.</p>
                                <p>Processing times can vary because:</p>
                                <ul>
                                    <li>Each month has different numbers of applications from previous lodgements</li>
                                    <li>The Department processes visas in batches, which can affect timing</li>
                                    <li>Processing speeds often increase at the start of a new program year</li>
                                </ul>
                            </div>
                        </div>
                        <div class="share-section">
                            <a href="https://wa.me/?text=<?php echo generateShareMessage($prediction, $application_age, $months_away); ?>" 
                               target="_blank" 
                               class="whatsapp-share-btn">
                                <svg class="whatsapp-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.48 2 2 6.48 2 12C2 13.86 2.49 15.59 3.34 17.09L2.1 21.9L7 20.66C8.47 21.47 10.17 21.93 12 21.93C17.52 21.93 22 17.44 22 11.93C22 6.42 17.52 2 12 2ZM8.53 15.92L8.23 15.75C6.98 15.08 6.19 14.17 5.85 13.04C5.5 11.91 5.6 10.65 6.12 9.58C6.65 8.51 7.57 7.69 8.72 7.29C9.87 6.89 11.11 6.94 12.23 7.43C13.34 7.92 14.24 8.82 14.73 9.94C15.22 11.06 15.27 12.3 14.87 13.45C14.47 14.6 13.66 15.52 12.59 16.05C11.52 16.58 10.26 16.67 9.13 16.33L8.91 16.25L6.11 17.13L7 14.38L8.53 15.92Z"/>
                                </svg>
                                Share on WhatsApp
                            </a>
                        </div>
                    <?php elseif (isset($months_away) && $months_away <= 3): ?>
                        <!-- Less than 3 months celebration message -->
                        <div class="celebration-message">
                            <div class="stat-number bounce-animation">Less than 3 months to go!</div>
                            <?php
                            $today = new DateTime();
                            $grant_date = new DateTime($prediction['eighty_percent']);
                            
                            // Add validation to ensure we're not showing past dates
                            if ($grant_date <= $today) {
                                // Calculate how far in the past the prediction is
                                $days_past = $today->diff($grant_date)->days;
                                
                                // If prediction is less than 30 days in the past (still within normal variation)
                                if ($days_past <= 30) {
                                    ?>
                                    <div class="countdown-weeks">
                                        <div class="imminent-grant">Your visa grant could arrive any day now! 🎉</div>
                                        <div class="grant-explanation">
                                            While we forecast your grant for <?php echo date('j F', strtotime($prediction['eighty_percent'])); ?>, 
                                            you're still within the normal processing variation window. The Department is likely processing your application, 
                                            and you should keep a close eye on your emails.
                                        </div>
                                    </div>
                                    <?php
                                } else {
                                    // If prediction is more than 30 days in the past
                                    ?>
                                    <div class="countdown-weeks">
                                        Your application is currently being processed and should be granted very soon.
                                    </div>
                                    <?php
                                }
                            } else {
                                $days_remaining = $today->diff($grant_date)->days;
                                $weekends_remaining = floor($days_remaining / 7);
                                ?>
                                <div class="countdown-weeks">
                                    In fact, it's only <?php echo $days_remaining; ?> days until your likely grant date!
                                    <div class="weekends-note">
                                        That's <?php echo $weekends_remaining; ?> more weekends to see your friends and get things ready to go 🤗
                                    </div>
                                </div>
                            <?php
                            }
                            ?>
                            <div class="percentile-dates">
                                <div class="percentile-date latest">
                                    <div class="date-label">Latest Expected (100th percentile)</div>
                                    <div class="date-value"><?php echo date('j F Y', strtotime($prediction['latest_date'])); ?></div>
                                    <div class="date-note">Worst case scenario</div>
                                </div>
                                <div class="percentile-date recommended">
                                    <div class="date-label">Likely Grant Date (80th percentile)</div>
                                    <div class="date-value"><?php echo date('j F Y', strtotime($prediction['eighty_percent'])); ?></div>
                                    <div class="date-note">We recommend using this date for planning</div>
                                </div>
                                <div class="percentile-date optimistic">
                                    <div class="date-label">Optimistic (70th percentile)</div>
                                    <div class="date-value"><?php echo date('j F Y', strtotime($prediction['seventy_percent'])); ?></div>
                                    <div class="date-note">If processing speeds up or you are lucky!</div>
                                </div>
                            </div>
                            <div class="celebration-tips">
                                <p>✈️ Start looking for flights</p>
                                <p>📦 Begin organizing your move</p>
                                <p>🏠 Research accommodation options</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- More than 3 months standard message -->
                        <div class="confidence-message">
                            <div class="stat-number">On track for this year!</div>
                            <div class="stat-label">We're confident your visa will be granted in the Financial Year before the Allocation Cap is reached.</div>
                            <div class="percentile-dates">
                                <div class="percentile-date recommended">
                                    <div class="date-label">Likely Grant Date (90th percentile)</div>
                                    <div class="date-value"><?php echo date('j F Y', strtotime($prediction['ninety_percent'])); ?></div>
                                    <div class="date-note">We recommend using this date for planning</div>
                                </div>
                                <div class="percentile-date optimistic">
                                    <div class="date-label">Optimistic (80th percentile)</div>
                                    <div class="date-value"><?php echo date('j F Y', strtotime($prediction['eighty_percent'])); ?></div>
                                </div>
                                <div class="percentile-date latest">
                                    <div class="date-label">Latest Expected (100th percentile)</div>
                                    <div class="date-value"><?php echo date('j F Y', strtotime($prediction['latest_date'])); ?></div>
                                </div>
                            </div>
                            <div class="queue-details">
                                <p>Your position: <?php echo number_format($prediction['cases_ahead']); ?> cases ahead</p>
                                <p>Places available: <?php echo number_format($prediction['places_remaining']); ?></p>
                                <p>Processing rate: <?php echo number_format($prediction['weighted_average']); ?> per month</p>
                            </div>
                            <div class="preparation-tips">
                                <p>📅 Keep monitoring processing times</p>
                                <p>📋 Ensure all your documents are ready</p>
                                <p>🎯 Start preliminary planning</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Share to WhatsApp and Calculation Details should be inside the main card -->
                <?php if (!($prediction['next_fy'] ?? false)): ?>
                <div class="share-section">
                    <a href="https://wa.me/?text=<?php echo generateShareMessage($prediction, $application_age, $months_away); ?>" 
                       target="_blank" 
                       class="whatsapp-share-btn">
                        <svg class="whatsapp-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.48 2 2 6.48 2 12C2 13.86 2.49 15.59 3.34 17.09L2.1 21.9L7 20.66C8.47 21.47 10.17 21.93 12 21.93C17.52 21.93 22 17.44 22 11.93C22 6.42 17.52 2 12 2ZM8.53 15.92L8.23 15.75C6.98 15.08 6.19 14.17 5.85 13.04C5.5 11.91 5.6 10.65 6.12 9.58C6.65 8.51 7.57 7.69 8.72 7.29C9.87 6.89 11.11 6.94 12.23 7.43C13.34 7.92 14.24 8.82 14.73 9.94C15.22 11.06 15.27 12.3 14.87 13.45C14.47 14.6 13.66 15.52 12.59 16.05C11.52 16.58 10.26 16.67 9.13 16.33L8.91 16.25L6.11 17.13L7 14.38L8.53 15.92Z"/>
                        </svg>
                        Share on WhatsApp
                    </a>
                </div>
                
                <div class="calculation-details">
                    <h4>Calculation Details:</h4>
                    <div class="step-details">
                        <div class="step-item">
                            <span class="label">Queue Shortening Rate:</span>
                            <span class="value"><?php echo number_format($prediction['steps']['priority_percentage'] * 100, 1); ?>%</span>
                            <span class="explanation">
                                The percentage of visas this year that were processed AFTER you lodged
                                <?php if ($prediction['priority_percentage_capped']): ?>
                                    <br><strong>Note:</strong> This rate has been capped at 25% (worst case scenario) from 
                                    <?php echo number_format($prediction['original_priority_percentage'] * 100, 1); ?>% 
                                    to provide a more conservative estimate
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="step-item">
                            <span class="label">Base Processing Rate:</span>
                            <span class="value"><?php echo number_format($prediction['steps']['weighted_average']); ?> per month</span>
                        </div>
                        <div class="step-item">
                            <span class="label">Adjusted processing rate:</span>
                            <span class="value"><?php echo number_format($prediction['steps']['non_priority_rate']); ?> per month</span>
                            <span class="explanation">Considering that <?php echo number_format($prediction['steps']['priority_percentage'] * 100, 1); ?>% of cases were processed after yours, we apply this factor. For every 100 cases granted, <?php echo number_format($prediction['steps']['priority_percentage'] * 100, 1); ?> will be after yours and <?php echo number_format($prediction['steps']['non_priority_ratio'] * 100, 1); ?> before</span>
                        </div>
                        <div class="step-item">
                            <span class="label">Total Cases Ahead:</span>
                            <span class="value"><?php echo number_format($prediction['steps']['total_cases']); ?></span>
                        </div>
                        <div class="step-item">
                            <span class="label">If 80% are processed before yours</span>
                            <span class="value"><?php echo number_format($prediction['steps']['eighty_percentile_cases']); ?></span>
                        </div>
                        <div class="step-item">
                            <span class="label">If 70% are processed before yours</span>
                            <span class="value"><?php echo number_format($prediction['steps']['seventy_percentile_cases']); ?></span>
                        </div>
                        <div class="step-item">
                            <span class="label">Estimated Processing from <?php echo date('F Y', strtotime($debug_data['latest_update'])); ?>:</span>
                            <span class="value"><?php echo number_format($prediction['steps']['months_to_process'], 1); ?> months</span>
                            <span class="explanation">Based on the 80th percentile prediction</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    

    <!-- Current Visa Queue card -->
    <div class="stat-card one-third-card">
        <div class="stat-header">
            <h3>Current Visa Queue</h3>
            <span class="stat-date">As of <?php echo date('j F Y', strtotime($debug_data['latest_update'])); ?></span>
        </div>
        <div class="stat-body">
            <div class="stat-number"><?php echo number_format($result); ?></div>
            <div class="stat-label">Visas Currently in Queue</div>
        </div>
        <div class="stat-footer">
            <div class="stat-note">Total applications being processed across all lodgement months. This is often referred to Visas on-and</div>
        </div>
    </div>

    <div class="stat-card processing-history one-third-card">
        <div class="stat-header">
            <h3>Monthly Processing History</h3>
            <span class="stat-date">Last <?php echo count($monthly_processing); ?> months</span>
        </div>
        <div class="stat-body">
            <div class="processing-list">
                <?php foreach ($monthly_processing as $month): ?>
                    <div class="processing-month">
                        <div class="month-header">
                            <span class="month-label"><?php echo date('F Y', strtotime($month['update_month'])); ?></span>
                            <span class="processed-count"><?php echo number_format($month['total_processed']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="stat-footer">
            <div class="stat-note">Showing monthly visa processing totals</div>
        </div>
    </div>
    <div class="stat-card average-processing-rate one-third-card">
        <div class="stat-header">
            <h3>Monthly Average Processing Rate</h3>
            <span class="stat-date">Last <?php echo count($monthly_averages); ?> months</span>
        </div>
        <div class="stat-body">
            <div class="processing-list">
                <?php foreach ($monthly_averages as $month): ?>
                    <div class="processing-month">
                        <div class="month-header">
                            <span class="month-label"><?php echo date('F Y', strtotime($month['update_month'])); ?></span>
                            <span class="processed-count"><?php echo number_format($month['total_processed']); ?></span>
                            <span class="average-rate">Avg: <?php echo number_format($month['running_average'], 2); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="stat-footer">
            <div class="stat-note">Showing running average processing rate</div>
        </div>
    </div>
               

  

    <div class="stat-card weighted-average-rate one-third-card">
        <div class="stat-header">
            <h3>3-Month Weighted Average Processing Rate</h3>
        </div>
        <div class="stat-body">
            <div class="stat-number"><?php echo number_format($weighted_average, 2); ?></div>
            <div class="stat-label">Weighted Average</div>
        </div>
        <div class="stat-footer">
            <div class="stat-note">Based on the last 3 months - we use this to predict your visa grant date as is more likely to be indicative of the current flow</div>
        </div>
    </div>

    <div class="stat-card annual-allocation one-third-card">
        <div class="stat-header">
            <h3>Annual Allocation</h3>
        </div>
        <div class="stat-body">
            <div class="processing-list">
                <?php foreach ($annual_allocation as $allocation): ?>
                    <div class="processing-month">
                        <div class="month-header">
                            <span class="month-label">FY <?php echo $allocation['financial_year_start']; ?></span>
                            <span class="processed-count"><?php echo number_format($allocation['allocation_amount']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="stat-footer">
            <div class="stat-note">Annual visa allocations</div>
        </div>
    </div>
    
    <?php if (isset($cases_ahead) && !isset($cases_ahead['error'])): ?>
        <div class="stat-card cases-ahead one-third-card">
            <div class="stat-header">
                <h3>Cases Ahead in Queue</h3>
                <span class="stat-date">As of <?php echo date('j F Y', strtotime($cases_ahead['latest_update'])); ?></span>
            </div>
            <div class="stat-body">
                <div class="stat-number"><?php echo number_format($cases_ahead['total_ahead']); ?></div>
                <div class="stat-label">Applications Lodged Before <?php echo date('j F Y', strtotime($cases_ahead['lodgement_date'])); ?></div>
            </div>
            <div class="stat-footer">
                <div class="stat-note">Based on current queue numbers for earlier lodgement months</div>
            </div>
        </div>
    <?php endif; ?>

    <div class="stat-card total-processed one-third-card">
        <div class="stat-header">
            <h3>Total Processed This Year</h3>
            <span class="stat-date"><?php echo $total_processed['financial_year']; ?></span>
        </div>
        <div class="stat-body">
            <div class="stat-number"><?php echo number_format($total_processed['total_processed']); ?></div>
            <div class="stat-label">Visas Processed</div>
        </div>
        <div class="stat-footer">
            <div class="stat-note">Total visas processed in current financial year</div>
        </div>
    </div>

    <?php if (isset($priority_cases) && !isset($priority_cases['error'])): ?>
        <div class="stat-card priority-cases one-third-card">
            <div class="stat-header">
                <h3>Cases granted to applications younger than yours</h3>
                <span class="stat-date"><?php echo $priority_cases['financial_year']; ?></span>
            </div>
            <div class="stat-body">
                <div class="stat-number"><?php echo number_format($priority_cases['total_priority']); ?></div>
                <div class="stat-label">Later Applications Processed</div>
            </div>
            <div class="stat-footer">
                <div class="stat-note">Number of visas processed this year with lodgement dates after <?php echo date('j F Y', strtotime($priority_cases['reference_date'])); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($priority_ratio) && !isset($priority_ratio['error'])): ?>
        <div class="stat-card priority-ratio one-third-card">
            <div class="stat-header">
                <h3>Processing Order Distribution</h3>
                <span class="stat-date"><?php echo $priority_ratio['financial_year']; ?></span>
            </div>
            <div class="stat-body">
                <div class="stat-number"><?php echo number_format($priority_ratio['priority_percentage'], 1); ?>%</div>
                <div class="stat-label">Cases Processed from Later Lodgements</div>
                <div class="ratio-breakdown">
                    <div class="ratio-item">
                        <span class="count"><?php echo number_format($priority_ratio['priority_count']); ?></span>
                        <span class="label">Later Lodgements</span>
                    </div>
                    <div class="ratio-item">
                        <span class="count"><?php echo number_format($priority_ratio['non_priority_count']); ?></span>
                        <span class="label">Earlier Lodgements</span>
                    </div>
                </div>
            </div>
            <div class="stat-footer">
                <div class="stat-note">Shows the distribution of processed cases: those lodged after your date (<?php echo date('j F Y', strtotime($priority_ratio['reference_date'])); ?>) vs. those lodged before</div>
            </div>

        </div>
    <?php endif; ?>

    <?php if (isset($allocations_remaining) && !isset($allocations_remaining['error'])): ?>
        <div class="stat-card allocations-remaining one-third-card">
            <div class="stat-header">
                <h3>Allocations Remaining</h3>
                <span class="stat-date"><?php echo $allocations_remaining['financial_year']; ?></span>
            </div>
            <div class="stat-body">
                <div class="stat-number"><?php echo number_format($allocations_remaining['remaining']); ?></div>
                <div class="stat-label">Places Available</div>
                <div class="ratio-breakdown">
                    <div class="ratio-item">
                        <span class="count"><?php echo number_format($allocations_remaining['total_processed']); ?></span>
                        <span class="label">Used</span>
                    </div>
                    <div class="ratio-item">
                        <span class="count"><?php echo number_format($allocations_remaining['total_allocation']); ?></span>
                        <span class="label">Total</span>
                    </div>
                </div>
                <div class="usage-percentage">
                    <?php echo number_format($allocations_remaining['percentage_used'], 1); ?>% Used
                </div>
            </div>
            <div class="stat-footer">
                <div class="stat-note">Remaining visa allocations for <?php echo $allocations_remaining['financial_year']; ?></div>
            </div>
           
            
        </div>
        
        <?php endif; ?>  
    
    
    <?php endif; ?>

    <?php if (isset($application_date)): ?>
        <?php 
        if (!isset($age_stats['error'])):
        ?>

        <div class="stat-card age-distribution one-third-card">
            <div class="stat-header">
                <h3>Processing Age Distribution</h3>
                <span class="stat-date"><?php echo $age_stats['financial_year']; ?></span>
            </div>
            <div class="stat-body">
                <div class="stats-grid">
                    <div class="stat-column">
                        <h4>Processing Age Distribution</h4>
                        <div class="stat-row">
                            <span class="label">Mean Age at Grant:</span>
                            <span class="value"><?php echo $age_stats['mean_age']; ?> months</span>
                        </div>
                        <div class="stat-row">
                            <span class="label">Most Common Age (Mode):</span>
                            <span class="value"><?php echo $age_stats['modal_age']; ?> months</span>
                        </div>
                        <div class="stat-row">
                            <span class="label">Standard Deviation:</span>
                            <span class="value">±<?php echo $age_stats['std_dev']; ?> months</span>
                        </div>
                        <div class="stat-row">
                            <span class="label">Typical Range (±1 SD):</span>
                            <span class="value"><?php echo $age_stats['std_dev_range']['lower']; ?> to <?php echo $age_stats['std_dev_range']['upper']; ?> months</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="stat-footer">
                <div class="stat-note">Based on cases lodged during <?php echo $allocations_remaining['financial_year']; ?></div>
            </div>  
        </div>

             
        
        <div class="stat-card interpretation one-third-card">
            <div class="stat-body">                    
                <div class="stat-header">
                    <h3>Your Application</h3>
                    <span class="stat-date"><?php echo $application_date = $_POST['applicationDay'] . '-' . $_POST['applicationMonth'] . '-' . $_POST['applicationYear']; ?></span>
                </div>
                <div class="stat-column">
                    <div class="stat-row">
                        <span class="label">Your Application Age:</span>
                        <span class="value"><?php echo $age_stats['reference_case']['age']; ?> months old</span>
                    </div>
                    <div class="stat-row">
                        <span class="label">Compared to Other Cases:</span>
                        <span class="value">Older than <?php echo $age_stats['reference_case']['percentile']; ?>% of recent grants</span>
                    </div>
                </div>

                <div class="interpretation-section">
                    <h4>What Does This Mean?</h4>
                    <div class="interpretation-text">
                        <p class="key-point">
                            Your application is <?php echo $age_stats['reference_case']['age']; ?> months old, which is 
                            <?php echo abs($age_stats['interpretation']['mode_difference']); ?> months older than the 
                            most commonly granted applications (<?php echo $age_stats['modal_age']; ?> months).
                        </p>
                        
                        <div class="important-warning">
                            <h5>⚠️ Important Note About Processing Times</h5>
                            <p>
                                The age of recently granted visas does not predict when your visa will be granted. This is because:
                            </p>
                            <ul>
                                <li>Processing depends on your position in the queue, not just your application's age</li>
                                <li>If the queue grows faster than processing rates, average ages will increase</li>
                                <li>The Department may process applications in various orders based on different priorities</li>
                            </ul>
                            <p>
                                For the most accurate prediction of your grant date, refer to the queue position and processing rate 
                                calculations shown in the prediction card above.
                            </p>
                        </div>

                        <div class="statistical-note">
                            <p>
                                While the average (mean) age at grant is <?php echo $age_stats['mean_age']; ?> months, 
                                this number is pulled higher by older cases and priority processing. The most common 
                                grant age of <?php echo $age_stats['modal_age']; ?> months gives a better picture of 
                                typical processing patterns.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (isset($age_stats) && !isset($age_stats['error'])): ?>
        <div class="stat-card full-width-card">
            <div class="stat-header">
                <h3>Application Age Distribution</h3>
                <span class="stat-date"><?php echo $age_stats['financial_year']; ?></span>
            </div>
            <div class="stat-body chart-container" style="position: relative; height:400px; width:100%">
                <canvas id="ageHistogram"></canvas>
            </div>
            <div class="stat-footer">
                <div class="stat-note">Distribution of application ages at grant time for <?php echo $age_stats['financial_year']; ?></div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing chart...');

            <?php if (isset($age_stats) && !isset($age_stats['error'])): ?>
                console.log('Age stats found:', <?php echo json_encode($age_stats); ?>);
                
                const canvas = document.getElementById('ageHistogram');
                if (!canvas) {
                    console.error('Canvas not found, cannot render chart.');
                    return;
                }
                const ctx = canvas.getContext('2d');
                const distributionData = <?php echo json_encode($age_stats['distribution'] ?? []); ?>;
                console.log('Distribution Data:', distributionData);

                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: distributionData.labels || [],
                        datasets: [{
                            label: 'Number of Applications',
                            data: distributionData.counts || [],
                            backgroundColor: 'rgba(54, 162, 235, 0.5)',
                            borderColor: 'rgb(54, 162, 235)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: { beginAtZero: true, title: { display: true, text: 'Number of Applications' } },
                            x: { title: { display: true, text: 'Age in Months' } }
                        },
                        plugins: {
                            legend: { display: true, position: 'top' },
                            title: { display: true, text: 'Application Age Distribution' }
                        }
                    }
                });
                console.log('Chart created successfully.');
            <?php else: ?>
                // Either $age_stats is not set, or we have an error in $age_stats.
                console.log('No age_stats or error in age_stats. Distribution chart will not render.');
            <?php endif; ?>
        });
        </script>
    <?php endif; ?>

</div>

<!-- Move Chart.js to head with defer -->
</div>

<!-- Simplify the chart JS logic: remove 'alert()' calls and the re-check loop.  -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Initializing chart...');

    <?php if (isset($age_stats) && !isset($age_stats['error'])): ?>
        console.log('Age stats found:', <?php echo json_encode($age_stats); ?>);
        
        const canvas = document.getElementById('ageHistogram');
        if (!canvas) {
            console.error('Canvas not found, cannot render chart.');
            return;
        }
        const ctx = canvas.getContext('2d');
        const distributionData = <?php echo json_encode($age_stats['distribution'] ?? []); ?>;
        console.log('Distribution Data:', distributionData);

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: distributionData.labels || [],
                datasets: [{
                    label: 'Number of Applications',
                    data: distributionData.counts || [],
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgb(54, 162, 235)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Number of Applications' } },
                    x: { title: { display: true, text: 'Age in Months' } }
                },
                plugins: {
                    legend: { display: true, position: 'top' },
                    title: { display: true, text: 'Application Age Distribution' }
                }
            }
        });
        console.log('Chart created successfully.');
    <?php else: ?>
        // Either $age_stats is not set, or we have an error in $age_stats.
        console.log('No age_stats or error in age_stats. Distribution chart will not render.');
    <?php endif; ?>
});
</script>

