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
$visas_on_hand = getVisasOnHand($debug_data['details']);

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
    $prediction = getVisaProcessingPrediction($visa_type_id, $application_date);
}

// Generate the Visas on Hand card
?>
<div class="stats-grid">
    <?php if (isset($prediction) && !isset($prediction['error'])): ?>
        <?php 
        // Calculate months_away before using it
        $months_away = !$prediction['next_fy'] ? 
            (strtotime($prediction['ninety_percent']) - time()) / (30 * 24 * 60 * 60) : 
            999; // Large number for next FY cases
        ?>
        <div class="stat-card prediction-highlight <?php echo ($months_away <= 3) ? 'celebration-mode' : ''; ?>">
            <div class="stat-header">
                <?php if (!$prediction['next_fy']): ?>
                    <?php if ($months_away <= 3): ?>
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
                    <?php if ($months_away <= 3): ?>
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
        </div>
    <?php endif; ?>

    <div class="stat-card">
        <div class="stat-header">
            <h3>Current Visa Queue</h3>
            <span class="stat-date">As of <?php echo date('j F Y', strtotime($debug_data['latest_update'])); ?></span>
        </div>
        <div class="stat-body">
            <div class="stat-number"><?php echo number_format($visas_on_hand); ?></div>
            <div class="stat-label">Visas Currently in Queue</div>
        </div>
        <div class="stat-footer">
            <div class="stat-note">Total applications being processed across all lodgement months</div>
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
                <h3>Priority Processing Ratio</h3>
                <span class="stat-date"><?php echo $priority_ratio['financial_year']; ?></span>
            </div>
            <div class="stat-body">
                <div class="stat-number"><?php echo number_format($priority_ratio['priority_percentage'], 1); ?>%</div>
                <div class="stat-label">Priority Processing Rate</div>
                <div class="ratio-breakdown">
                    <div class="ratio-item">
                        <span class="count"><?php echo number_format($priority_ratio['priority_count']); ?></span>
                        <span class="label">Priority</span>
                    </div>
                    <div class="ratio-item">
                        <span class="count"><?php echo number_format($priority_ratio['non_priority_count']); ?></span>
                        <span class="label">Non-Priority</span>
                    </div>
                </div>
            </div>
            <div class="stat-footer">
                <div class="stat-note">Percentage of visas processed this year with lodgement dates after <?php echo date('j F Y', strtotime($priority_ratio['reference_date'])); ?></div>
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
                            <span class="label">Priority Rate:</span>
                            <span class="value"><?php echo number_format($prediction['steps']['priority_percentage'] * 100, 1); ?>%</span>
                        </div>
                        <div class="step-item">
                            <span class="label">Non-Priority Rate:</span>
                            <span class="value"><?php echo number_format($prediction['steps']['non_priority_ratio'] * 100, 1); ?>%</span>
                        </div>
                        <?php if (!$prediction['next_fy']): ?>
                            <div class="step-item">
                                <span class="label">Base Processing Rate:</span>
                                <span class="value"><?php echo number_format($prediction['steps']['weighted_average']); ?> per month</span>
                            </div>
                            <div class="step-item">
                                <span class="label">Non-Priority Processing Rate:</span>
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