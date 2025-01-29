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

// Generate the Visas on Hand card
?>
<div class="stats-grid">
    <?php if (isset($prediction) && !isset($prediction['error'])): ?>
        <?php
        error_log("Prediction data: " . print_r($prediction, true));
        // Calculate months away first
        $months_away = null;
        $is_very_overdue = $prediction['is_very_overdue'] ?? false;
        if (!$prediction['next_fy']) {
            $today = new DateTime();
            $prediction_date = new DateTime($prediction['ninety_percent']);
            $months_away = ($prediction_date->getTimestamp() - $today->getTimestamp()) / (30 * 24 * 60 * 60);
            
            // Use application age from prediction data
            $application_age = (object)[
                'y' => $prediction['application_age']['years'],
                'm' => $prediction['application_age']['months']
            ];
            error_log("Months away: $months_away");
            error_log("Is very overdue: " . ($is_very_overdue ? 'true' : 'false'));
        }
        ?>
        <div class="stat-card prediction-highlight <?php 
            echo (!$prediction['next_fy'] && $months_away <= 3 && !$is_very_overdue) ? 'celebration-mode' : ''; 
            echo $is_very_overdue ? 'overdue-alert' : '';
        ?>">
            <div class="stat-header">
                <?php if (!$prediction['next_fy']): ?>
                    <?php if ($is_very_overdue): ?>
                        <h3>⚠️ Application Status Alert</h3>
                    <?php elseif ($months_away <= 3): ?>
                        <h3>🎉 Get Ready for Australia! 🎉</h3>
                        <canvas id="confetti-canvas"></canvas>
                    <?php else: ?>
                        <h3>Your Visa Journey</h3>
                    <?php endif; ?>
                <?php else: ?>
                    <h3>Planning for Your Future Move</h3>
                <?php endif; ?>
            </div>
            <div class="stat-body">
                <?php if (!$prediction['next_fy']): ?>
                    <?php if ($is_very_overdue): ?>
                        <div class="alert-message">
                            <div class="stat-number">A Quick Check May Be Helpful</div>
                            <?php
                            $ageMessage = getAgeMessage($application_age);
                            if ($ageMessage): ?>
                                <div class="application-age">
                                    <p>Your application is <?php echo $application_age->y; ?> years and <?php echo $application_age->m; ?> months old.</p>
                                    <p class="age-message"><?php echo $ageMessage; ?></p>
                                </div>
                            <?php endif; ?>
                            <div class="stat-label">
                                We notice your application has been in process longer than typical.
                                While this isn't necessarily a concern, it might be worth doing a gentle check-in.
                            </div>
                            <div class="action-steps">
                                <h4>Suggested Next Steps:</h4>
                                <p>📞 Contact Home Affairs to check your application status</p>
                                <p>📋 Review your ImmiAccount for any requests or messages</p>
                                <p>👥 Consult with your migration agent if you have one</p>
                            </div>
                            <div class="contact-info">
                                <p>For Your Reference:</p>
                                <a href="https://immi.homeaffairs.gov.au/help-support/contact-us" 
                                   target="_blank" 
                                   class="contact-link">
                                    Visit Contact Page →
                                </a>
                            </div>
                        </div>
                    <?php elseif ($months_away <= 3): ?>
                        <div class="celebration-message">
                            <div class="stat-number bounce-animation">Less than 3 months to go!</div>
                            <?php
                            $today = new DateTime();
                            $grant_date = new DateTime($prediction['ninety_percent']);
                            $days_remaining = $today->diff($grant_date)->days;
                            $weekends_remaining = floor($days_remaining / 7);
                            ?>
                            <div class="countdown-weeks">
                                In fact, it's only <?php echo $days_remaining; ?> days until your likely grant date!
                                <div class="weekends-note">
                                    That's <?php echo $weekends_remaining; ?> more weekends to see your friends and get things ready to go 🤗
                                </div>
                            </div>
                            <div class="stat-label">Time to start packing! Here's when you could receive your visa:</div>
                            
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

                            <div class="celebration-tips">
                                <p>✈️ Start looking for flights</p>
                                <p>📦 Begin organizing your move</p>
                                <p>🏠 Research accommodation options</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="positive-message">
                            <div class="stat-number">On track for this year!</div>
                            <div class="stat-label">We're 90% confident your visa will be granted by <?php echo date('j F Y', strtotime($prediction['ninety_percent'])); ?>.</div>
                            <div class="preparation-tips">
                                <p>📅 Keep monitoring processing times</p>
                                <p>📋 Ensure all your documents are ready</p>
                                <p>🎯 Start preliminary planning</p>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="planning-message">
                        <div class="stat-label">Your visa is expected to be processed in the next financial year</div>
                        <div class="future-planning">
                            <div class="fy-summary">
                                <div class="fy-numbers">
                                    <div class="number-item">
                                        <span class="number"><?php echo number_format($prediction['cases_ahead']); ?></span>
                                        <span class="label">Cases ahead of you</span>
                                    </div>
                                    <div class="number-item">
                                        <span class="number"><?php echo number_format($prediction['places_remaining']); ?></span>
                                        <span class="label">Places remaining this year</span>
                                    </div>
                                </div>
                                <p class="fy-explanation">The Australian Government sets new visa allocations each financial year (July-June). The 2024-25 program has <?php echo number_format(185000); ?> total places, with <?php echo number_format(132200); ?> allocated to skilled visas.</p>
                            </div>
                            <div class="planning-tips">
                                <h4>While You Wait:</h4>
                                <p>📚 Review your documents and ensure everything is up to date</p>
                                <p>💼 Continue gaining relevant work experience</p>
                                <p>📋 Keep your details current in ImmiAccount</p>
                            </div>
                            <div class="next-steps">
                                <p>Stay informed about migration planning levels:</p>
                                <a href="https://immi.homeaffairs.gov.au/what-we-do/migration-program-planning-levels" 
                                   target="_blank" 
                                   class="planning-link">
                                    View Official Migration Program Details →
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (!$prediction['next_fy']): ?>
                <div class="share-section">
                    <?php
                    // Format the prediction message
                    $shareMessage = "🇦🇺 My Australian Visa Update:\n\n";
                    
                    if ($is_very_overdue) {
                        $shareMessage .= "⚠️ My visa application has been processing for {$application_age->y} years and {$application_age->m} months.\n";
                    } elseif ($months_away <= 3) {
                        $shareMessage .= "🎉 Expected grant in less than 3 months!\n";
                        $shareMessage .= "📅 Likely grant date: " . date('j F Y', strtotime($prediction['ninety_percent'])) . "\n";
                        $shareMessage .= "🗓️ Only {$days_remaining} days to go!\n";
                    } else {
                        $shareMessage .= "📅 Expected grant date: " . date('j F Y', strtotime($prediction['ninety_percent'])) . "\n";
                    }
                    
                    $shareMessage .= "\nCheck your visa timeline at www.WhenAmIGoing.com";
                    
                    // Encode the message for WhatsApp URL
                    $encodedMessage = urlencode($shareMessage);
                    ?>
                    
                    <a href="https://wa.me/?text=<?php echo $encodedMessage; ?>" 
                       target="_blank" 
                       class="whatsapp-share-btn">
                        <svg class="whatsapp-icon" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                        </svg>
                        Share on WhatsApp
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

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
                <h3>Priority Cases</h3>
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

    <?php if (isset($prediction) && !isset($prediction['error'])): ?>
        <div class="stat-card processing-prediction">
            <div class="stat-header">
                <h3>Processing Time Prediction</h3>
                <span class="stat-date">Based on data as of <?php echo date('j F Y', strtotime($prediction['last_update'])); ?></span>
            </div>
            <div class="stat-body">
                <?php if ($prediction['next_fy']): ?>
                    <div class="next-fy-message">
                        <div class="stat-label"><?php echo $prediction['message']; ?></div>
                        <div class="prediction-details">
                            <div class="detail-item">
                                <span class="label">Cases Ahead:</span>
                                <span class="value"><?php echo number_format($prediction['cases_ahead']); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="label">Places Remaining:</span>
                                <span class="value"><?php echo number_format($prediction['places_remaining']); ?></span>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="prediction-dates">
                        <div class="date-item latest">
                            <span class="date"><?php echo date('j F Y', strtotime($prediction['latest_date'])); ?></span>
                            <span class="label">Latest (<?php echo number_format($prediction['steps']['total_cases']); ?> cases)</span>
                        </div>
                        <div class="date-item ninety">
                            <span class="date"><?php echo date('j F Y', strtotime($prediction['ninety_percent'])); ?></span>
                            <span class="label">90th Percentile (<?php echo number_format($prediction['steps']['ninety_percentile_cases']); ?> cases)</span>
                        </div>
                        <div class="date-item eighty">
                            <span class="date"><?php echo date('j F Y', strtotime($prediction['eighty_percent'])); ?></span>
                            <span class="label">80th Percentile (<?php echo number_format($prediction['steps']['eighty_percentile_cases']); ?> cases)</span>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="prediction-steps">
                    <h4>Calculation Details:</h4>
                    <div class="step-details">
                        <div class="step-item">
                            <span class="label">Queue Shortening Rate:</span>
                            <span class="value"><?php echo number_format($prediction['steps']['priority_percentage'] * 100, 1); ?>%</span>
                        </div>
                        <div class="step-item">
                            <span class="label">Queue Position Rate:</span>
                            <span class="value"><?php echo number_format($prediction['steps']['non_priority_ratio'] * 100, 1); ?>%</span>
                        </div>
                        <?php if (!$prediction['next_fy']): ?>
                            <div class="step-item">
                                <span class="label">Base Processing Rate:</span>
                                <span class="value"><?php echo number_format($prediction['steps']['weighted_average']); ?> per month</span>
                            </div>
                            <div class="step-item">
                                <span class="label">Processing Rate for Older Cases:</span>
                                <span class="value"><?php echo number_format($prediction['steps']['non_priority_rate']); ?> per month</span>
                            </div>
                            <div class="step-item">
                                <span class="label">Total Cases Ahead:</span>
                                <span class="value"><?php echo number_format($prediction['steps']['total_cases']); ?></span>
                            </div>
                            <div class="step-item">
                                <span class="label">90th Percentile Cases:</span>
                                <span class="value"><?php echo number_format($prediction['steps']['ninety_percentile_cases']); ?></span>
                            </div>
                            <div class="step-item">
                                <span class="label">80th Percentile Cases:</span>
                                <span class="value"><?php echo number_format($prediction['steps']['eighty_percentile_cases']); ?></span>
                            </div>
                            <div class="step-item">
                                <span class="label">Estimated Processing Time:</span>
                                <span class="value"><?php echo number_format($prediction['steps']['months_to_process'], 1); ?> months</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="stat-footer">
                <div class="stat-note">
                    Prediction based on current processing rates and queue position. 
                    Actual processing times may vary.
                </div>
            </div>
        </div>
    <?php endif; ?>
</div> 