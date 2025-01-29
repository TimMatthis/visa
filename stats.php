<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

// Get parameters from POST request
$visa_type_id = $_POST['visaType'] ?? null;
$application_year = $_POST['applicationYear'] ?? null;
$application_month = $_POST['applicationMonth'] ?? null;
$application_day = $_POST['applicationDay'] ?? null;

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
}

// Helper function to generate share message based on prediction state
function generateShareMessage($prediction, $application_age = null, $months_away = null) {
    $shareMessage = "🌏 WhenAmIGoing? Visa Tracker Update\n\n";
    
    if (($prediction['is_overdue'] ?? false)) {
        $shareMessage .= "⚠️ ATTENTION: Application Requires Review\n\n";
        $shareMessage .= "📋 Your application should have been processed as it is at or near the front of the queue.\n";
        $shareMessage .= "⏰ Status: OVERDUE\n";
        if (isset($application_age)) {
            $shareMessage .= "⌛ Processing for: {$application_age->y} years and {$application_age->m} months\n";
        }
        $shareMessage .= "📊 Outstanding cases: " . number_format($prediction['cases_ahead']) . "\n";
        $shareMessage .= "\n🔍 This may indicate a processing delay or technical issue that needs attention.";
    } 
    elseif ($prediction['next_fy'] ?? false) {
        $shareMessage .= "🗓️ My visa is tracking for next Financial Year (After July 2024)\n";
        $shareMessage .= "📊 Cases ahead: " . number_format($prediction['cases_ahead']) . "\n";
        $shareMessage .= "🎯 Places remaining: " . number_format($prediction['steps']['non_priority_places']) . "\n";
    }
    elseif (isset($months_away) && $months_away <= 3) {
        // Check if the predicted date is in the past
        $today = new DateTime();
        $grant_date = new DateTime($prediction['eighty_percent']);
        
        if ($grant_date <= $today) {
            $shareMessage .= "🎉 VISA GRANT IMMINENT!\n\n";
            $shareMessage .= "📋 Your application is currently being processed\n";
            $shareMessage .= "⚡ Status: Within processing variation window\n";
            $shareMessage .= "👀 Keep an eye on your emails!\n";
        } else {
            $days_remaining = ceil($months_away * 30.44);
            $weekends_remaining = floor($days_remaining / 7);
            
            $shareMessage .= "🎉 Less than 3 months to go!\n\n";
            $shareMessage .= "⏰ It's only {$days_remaining} days until your likely grant date!\n";
            $shareMessage .= "🗓️ That's {$weekends_remaining} weekends to get ready! 🤗\n\n";
            $shareMessage .= "📅 Planning Dates:\n";
            $shareMessage .= "• Likely (80%): " . date('j F Y', strtotime($prediction['eighty_percent'])) . " - Recommended for planning\n";
            $shareMessage .= "• Latest (100%): " . date('j F Y', strtotime($prediction['latest_date'])) . " - Worst case scenario\n\n";
            $shareMessage .= "📊 Cases ahead: " . number_format($prediction['cases_ahead']) . "\n";
            $shareMessage .= "✨ Time to start planning your move to Australia!";
        }
    }
    else {
        $shareMessage .= "🎯 On Track This Financial Year\n\n";
        $shareMessage .= "📅 Recommended Planning Dates:\n";
        $shareMessage .= "• Likely (80%): " . date('j F Y', strtotime($prediction['eighty_percent'])) . "\n";
        $shareMessage .= "• Latest (100%): " . date('j F Y', strtotime($prediction['latest_date'])) . "\n\n";
        $shareMessage .= "📊 Cases ahead: " . number_format($prediction['cases_ahead']) . "\n";
        $shareMessage .= "💫 We recommend using the 80% date for planning";
    }
    
    $shareMessage .= "\nTrack your visa timeline at www.WhenAmIGoing.com";
    
    return urlencode($shareMessage);
}

// Generate the Visas on Hand card
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
                    <a href="https://wa.me/?text=<?php echo generateShareMessage($prediction, $application_age); ?>" 
                       target="_blank" 
                       class="whatsapp-share-btn">
                        <svg class="whatsapp-icon" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
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
                            <a href="https://wa.me/?text=<?php echo generateShareMessage($prediction); ?>" 
                               target="_blank" 
                               class="whatsapp-share-btn">
                                <svg class="whatsapp-icon" viewBox="0 0 24 24">
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
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
                        <divclass="stat-label">We're confident your visa will be granted in the Financial Year before the Allocation Cap is reached.</div>
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
            
                <?php if (!($prediction['next_fy'] ?? false)): ?>
                <div class="share-section">
                    <a href="https://wa.me/?text=<?php echo generateShareMessage($prediction); ?>" 
                       target="_blank" 
                       class="whatsapp-share-btn">
                        <svg class="whatsapp-icon" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
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
    <div class="stat-card">
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

    <div class="stat-card processing-history">
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

    <div class="stat-card average-processing-rate">
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

    <div class="stat-card weighted-average-rate">
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

    <div class="stat-card annual-allocation">
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
        <div class="stat-card cases-ahead">
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

    <div class="stat-card total-processed">
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
        <div class="stat-card priority-cases">
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
        <div class="stat-card priority-ratio">
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
        <div class="stat-card allocations-remaining">
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
</div> 