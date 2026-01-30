<?php
/**
 * Holiday Traveling - Trip View
 * Tabbed interface showing plan, stays, food, budget, wallet, expenses, voting, calendar
 */
$daysCount = ht_trip_duration($trip['start_date'], $trip['end_date']);
$isUpcoming = strtotime($trip['start_date']) > time();
$isActive = $trip['status'] === 'active';
$isPast = strtotime($trip['end_date']) < strtotime('today');
$isImminent = isset($tripStatus) && $tripStatus === 'imminent';
$statusDisplay = $tripStatus ?? 'upcoming';
?>

<div class="ht-trip-view" data-trip-id="<?php echo $trip['id']; ?>">
    <?php if ($isImminent): ?>
    <!-- Late Mode Banner -->
    <div class="ht-late-mode-alert">
        <span class="ht-late-alert-icon">⏰</span>
        <div class="ht-late-alert-content">
            <strong>Trip starts in less than 24 hours!</strong>
            <p>Make sure you're packed and ready. Check your packing list and wallet items.</p>
        </div>
        <div class="ht-late-alert-actions">
            <a href="/holiday_traveling/packing_view.php?id=<?php echo $trip['id']; ?>" class="ht-btn ht-btn-primary ht-btn-sm">
                Check Packing List
            </a>
        </div>
    </div>
    <?php elseif ($statusDisplay === 'active'): ?>
    <!-- Active Trip Banner -->
    <div class="ht-active-trip-alert">
        <span class="ht-active-alert-icon">🎉</span>
        <div class="ht-active-alert-content">
            <strong>You're on your trip!</strong>
            <p>Enjoy <?php echo htmlspecialchars($trip['destination']); ?>! Don't forget to track expenses.</p>
        </div>
    </div>
    <?php elseif ($statusDisplay === 'completed'): ?>
    <!-- Completed Trip Banner -->
    <div class="ht-completed-trip-alert">
        <span class="ht-completed-alert-icon">✅</span>
        <div class="ht-completed-alert-content">
            <strong>Trip completed!</strong>
            <p>Hope you had a great time. Settle up expenses with your group.</p>
        </div>
        <div class="ht-completed-alert-actions">
            <a href="/holiday_traveling/expenses_view.php?id=<?php echo $trip['id']; ?>" class="ht-btn ht-btn-outline ht-btn-sm">
                View Expenses
            </a>
        </div>
    </div>
    <?php elseif (isset($daysUntil) && $daysUntil > 0 && $daysUntil <= 7): ?>
    <!-- Countdown Banner -->
    <div class="ht-countdown-banner">
        <span class="ht-countdown-number"><?php echo $daysUntil; ?></span>
        <span class="ht-countdown-label">day<?php echo $daysUntil > 1 ? 's' : ''; ?> until your trip!</span>
    </div>
    <?php endif; ?>

    <!-- Trip Header -->
    <div class="ht-trip-header">
        <div class="ht-trip-header-top">
            <a href="/holiday_traveling/" class="ht-back-link">← Back to Trips</a>
            <div class="ht-trip-actions-menu">
                <button class="ht-menu-trigger" aria-label="Trip actions">⋮</button>
                <div class="ht-menu-dropdown">
                    <a href="/holiday_traveling/trip_edit.php?id=<?php echo $trip['id']; ?>" class="ht-menu-item">Edit</a>
                    <a href="/holiday_traveling/trip_share.php?id=<?php echo $trip['id']; ?>" class="ht-menu-item">Share</a>
                    <a href="/holiday_traveling/trip_duplicate.php?id=<?php echo $trip['id']; ?>" class="ht-menu-item">Duplicate</a>
                    <button class="ht-menu-item ht-menu-item-danger" data-action="delete" data-trip-id="<?php echo $trip['id']; ?>">Delete</button>
                </div>
            </div>
        </div>

        <div class="ht-trip-header-main">
            <div class="ht-trip-header-info">
                <h1 class="ht-trip-destination-title"><?php echo htmlspecialchars($trip['destination']); ?></h1>
                <p class="ht-trip-title-sub"><?php echo htmlspecialchars($trip['title']); ?></p>
                <div class="ht-trip-header-meta">
                    <?php echo ht_status_badge($trip['status']); ?>
                    <span class="ht-meta-item">
                        <span class="ht-meta-icon">📅</span>
                        <?php echo ht_format_date($trip['start_date']); ?> - <?php echo ht_format_date($trip['end_date']); ?>
                    </span>
                    <span class="ht-meta-item">
                        <span class="ht-meta-icon">⏱️</span>
                        <?php echo $daysCount; ?> day<?php echo $daysCount > 1 ? 's' : ''; ?>
                    </span>
                    <span class="ht-meta-item">
                        <span class="ht-meta-icon">👥</span>
                        <?php echo $trip['travelers_count']; ?> traveler<?php echo $trip['travelers_count'] > 1 ? 's' : ''; ?>
                    </span>
                </div>
            </div>

            <?php if ($canEdit): ?>
            <div class="ht-trip-header-actions">
                <a href="/holiday_traveling/trip_edit.php?id=<?php echo $trip['id']; ?>" class="ht-btn ht-btn-outline ht-btn-sm">
                    Edit Trip
                </a>
                <a href="/holiday_traveling/trip_share.php?id=<?php echo $trip['id']; ?>" class="ht-btn ht-btn-outline ht-btn-sm">
                    Share
                </a>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($activePlan && isset($activePlan['_version'])): ?>
        <div class="ht-plan-version-bar">
            <span class="ht-version-label">Plan Version:</span>
            <select id="planVersionSelect" class="ht-version-select">
                <?php foreach ($planVersions as $v): ?>
                <option value="<?php echo $v['version_number']; ?>" <?php echo $v['version_number'] == $activePlan['_version'] ? 'selected' : ''; ?>>
                    v<?php echo $v['version_number']; ?> - <?php echo ht_format_date($v['created_at'], 'M j, g:i A'); ?>
                    (<?php echo $v['created_by']; ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($canEdit): ?>
            <button id="restoreVersionBtn" class="ht-btn ht-btn-outline ht-btn-xs" style="display: none;">
                Restore This Version
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tab Navigation -->
    <div class="ht-tabs">
        <div class="ht-tabs-nav">
            <button class="ht-tab-btn active" data-tab="plan">
                <span class="ht-tab-icon">📋</span>
                <span class="ht-tab-label">Plan</span>
            </button>
            <button class="ht-tab-btn" data-tab="stays">
                <span class="ht-tab-icon">🏨</span>
                <span class="ht-tab-label">Stays</span>
            </button>
            <button class="ht-tab-btn" data-tab="food">
                <span class="ht-tab-icon">🍽️</span>
                <span class="ht-tab-label">Food</span>
            </button>
            <button class="ht-tab-btn" data-tab="budget">
                <span class="ht-tab-icon">💰</span>
                <span class="ht-tab-label">Budget</span>
            </button>
            <button class="ht-tab-btn" data-tab="wallet">
                <span class="ht-tab-icon">📱</span>
                <span class="ht-tab-label">Wallet</span>
            </button>
            <button class="ht-tab-btn" data-tab="expenses">
                <span class="ht-tab-icon">🧾</span>
                <span class="ht-tab-label">Expenses</span>
            </button>
        </div>

        <!-- Tab Content: Plan -->
        <div class="ht-tab-content active" data-tab="plan">
            <?php if (!$activePlan): ?>
            <!-- No Plan Yet -->
            <div class="ht-empty-tab">
                <div class="ht-empty-tab-icon">🤖</div>
                <h3>No Plan Yet</h3>
                <p>Let AI create a personalized travel plan for your trip</p>
                <?php if ($canEdit): ?>
                <button id="generatePlanBtn" class="ht-btn ht-btn-primary">
                    <span class="ht-btn-icon">✨</span>
                    Generate AI Plan
                </button>
                <p class="ht-ai-remaining">
                    <?php echo $aiRemaining; ?> AI requests remaining this hour
                </p>
                <?php endif; ?>
            </div>
            <?php else: ?>

            <!-- Reality Check Scores -->
            <?php if (isset($activePlan['reality_check'])): ?>
            <div class="ht-reality-check">
                <h3 class="ht-section-title-sm">Reality Check</h3>
                <div class="ht-scores-grid">
                    <?php
                    $scores = [
                        'packed_score' => ['label' => 'Packed', 'icon' => '📦'],
                        'cost_score' => ['label' => 'Cost', 'icon' => '💵'],
                        'travel_time_score' => ['label' => 'Travel Time', 'icon' => '🚗'],
                        'kid_friendly_score' => ['label' => 'Kid Friendly', 'icon' => '👶']
                    ];
                    foreach ($scores as $key => $info):
                        $score = $activePlan['reality_check'][$key] ?? 0;
                        $scoreClass = $score >= 7 ? 'good' : ($score >= 4 ? 'medium' : 'low');
                    ?>
                    <div class="ht-score-item">
                        <span class="ht-score-icon"><?php echo $info['icon']; ?></span>
                        <div class="ht-score-bar">
                            <div class="ht-score-fill ht-score-<?php echo $scoreClass; ?>" style="width: <?php echo $score * 10; ?>%"></div>
                        </div>
                        <span class="ht-score-value"><?php echo $score; ?>/10</span>
                        <span class="ht-score-label"><?php echo $info['label']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($activePlan['reality_check']['notes'])): ?>
                <div class="ht-reality-notes">
                    <?php foreach ($activePlan['reality_check']['notes'] as $note): ?>
                    <p class="ht-reality-note">💡 <?php echo htmlspecialchars($note); ?></p>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Itinerary -->
            <div class="ht-itinerary">
                <h3 class="ht-section-title-sm">Daily Itinerary</h3>
                <?php if (!empty($activePlan['itinerary'])): ?>
                <div class="ht-days-list">
                    <?php foreach ($activePlan['itinerary'] as $day): ?>
                    <div class="ht-day-card" data-day="<?php echo $day['day'] ?? ''; ?>">
                        <div class="ht-day-header">
                            <span class="ht-day-number">Day <?php echo $day['day'] ?? '?'; ?></span>
                            <?php if (!empty($day['date'])): ?>
                            <span class="ht-day-date"><?php echo ht_format_date($day['date'], 'D, M j'); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ht-day-sections">
                            <?php if (!empty($day['morning'])): ?>
                            <div class="ht-day-section">
                                <span class="ht-time-label">🌅 Morning</span>
                                <ul class="ht-activity-list">
                                    <?php foreach ($day['morning'] as $activity): ?>
                                    <li class="ht-activity-item">
                                        <?php echo htmlspecialchars(is_array($activity) ? ($activity['name'] ?? $activity['title'] ?? json_encode($activity)) : $activity); ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($day['afternoon'])): ?>
                            <div class="ht-day-section">
                                <span class="ht-time-label">☀️ Afternoon</span>
                                <ul class="ht-activity-list">
                                    <?php foreach ($day['afternoon'] as $activity): ?>
                                    <li class="ht-activity-item">
                                        <?php echo htmlspecialchars(is_array($activity) ? ($activity['name'] ?? $activity['title'] ?? json_encode($activity)) : $activity); ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($day['evening'])): ?>
                            <div class="ht-day-section">
                                <span class="ht-time-label">🌙 Evening</span>
                                <ul class="ht-activity-list">
                                    <?php foreach ($day['evening'] as $activity): ?>
                                    <li class="ht-activity-item">
                                        <?php echo htmlspecialchars(is_array($activity) ? ($activity['name'] ?? $activity['title'] ?? json_encode($activity)) : $activity); ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($day['buffers'])): ?>
                            <div class="ht-day-buffers">
                                <span class="ht-buffer-label">⏳ Buffers:</span>
                                <?php foreach ($day['buffers'] as $buffer): ?>
                                <span class="ht-buffer-item"><?php echo htmlspecialchars($buffer); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($day['backup_weather'])): ?>
                            <div class="ht-backup-weather">
                                <span class="ht-backup-label">🌧️ Rain backup:</span>
                                <?php foreach ($day['backup_weather'] as $backup): ?>
                                <span class="ht-backup-item"><?php echo htmlspecialchars($backup); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="ht-no-content">No itinerary available</p>
                <?php endif; ?>
            </div>

            <!-- Safety & Tips -->
            <?php if (!empty($activePlan['safety_and_local_tips'])): ?>
            <div class="ht-safety-tips">
                <h3 class="ht-section-title-sm">Safety & Local Tips</h3>
                <ul class="ht-tips-list">
                    <?php foreach ($activePlan['safety_and_local_tips'] as $tip): ?>
                    <li class="ht-tip-item"><?php echo htmlspecialchars($tip); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Tab Content: Stays -->
        <div class="ht-tab-content" data-tab="stays">
            <?php if (!$activePlan || empty($activePlan['stay_options'])): ?>
            <div class="ht-empty-tab">
                <div class="ht-empty-tab-icon">🏨</div>
                <h3>No Accommodation Options</h3>
                <p>Generate a plan to see accommodation recommendations</p>
            </div>
            <?php else: ?>
            <div class="ht-stays-grid">
                <?php foreach ($activePlan['stay_options'] as $stay): ?>
                <div class="ht-stay-card ht-stay-<?php echo $stay['tier'] ?? 'comfort'; ?>">
                    <div class="ht-stay-tier">
                        <?php
                        $tierIcons = ['budget' => '💰', 'comfort' => '⭐', 'treat' => '✨'];
                        echo $tierIcons[$stay['tier'] ?? 'comfort'] ?? '🏨';
                        ?>
                        <?php echo ucfirst($stay['tier'] ?? 'Comfort'); ?>
                    </div>
                    <h4 class="ht-stay-type"><?php echo htmlspecialchars($stay['type'] ?? 'Accommodation'); ?></h4>
                    <p class="ht-stay-area">📍 <?php echo htmlspecialchars($stay['area'] ?? 'Area not specified'); ?></p>
                    <p class="ht-stay-price">
                        <?php echo ht_format_currency($stay['price_per_night'] ?? 0, $trip['budget_currency']); ?>/night
                    </p>
                    <?php if (!empty($stay['notes'])): ?>
                    <p class="ht-stay-notes"><?php echo htmlspecialchars($stay['notes']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($stay['pros'])): ?>
                    <div class="ht-stay-pros">
                        <?php foreach ($stay['pros'] as $pro): ?>
                        <span class="ht-pro-item">✅ <?php echo htmlspecialchars($pro); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($stay['cons'])): ?>
                    <div class="ht-stay-cons">
                        <?php foreach ($stay['cons'] as $con): ?>
                        <span class="ht-con-item">⚠️ <?php echo htmlspecialchars($con); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab Content: Food -->
        <div class="ht-tab-content" data-tab="food">
            <?php if (!$activePlan || empty($activePlan['food_plan'])): ?>
            <div class="ht-empty-tab">
                <div class="ht-empty-tab-icon">🍽️</div>
                <h3>No Food Plan</h3>
                <p>Generate a plan to see food recommendations</p>
            </div>
            <?php else: ?>
            <div class="ht-food-plan">
                <?php foreach ($activePlan['food_plan'] as $dayFood): ?>
                <div class="ht-food-day">
                    <h4 class="ht-food-day-title">Day <?php echo $dayFood['day'] ?? '?'; ?></h4>
                    <div class="ht-meals">
                        <?php if (!empty($dayFood['breakfast'])): ?>
                        <div class="ht-meal">
                            <span class="ht-meal-label">🌅 Breakfast</span>
                            <div class="ht-meal-options">
                                <?php foreach ($dayFood['breakfast'] as $opt): ?>
                                <span class="ht-meal-option"><?php echo htmlspecialchars(is_array($opt) ? ($opt['name'] ?? json_encode($opt)) : $opt); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($dayFood['lunch'])): ?>
                        <div class="ht-meal">
                            <span class="ht-meal-label">☀️ Lunch</span>
                            <div class="ht-meal-options">
                                <?php foreach ($dayFood['lunch'] as $opt): ?>
                                <span class="ht-meal-option"><?php echo htmlspecialchars(is_array($opt) ? ($opt['name'] ?? json_encode($opt)) : $opt); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($dayFood['dinner'])): ?>
                        <div class="ht-meal">
                            <span class="ht-meal-label">🌙 Dinner</span>
                            <div class="ht-meal-options">
                                <?php foreach ($dayFood['dinner'] as $opt): ?>
                                <span class="ht-meal-option"><?php echo htmlspecialchars(is_array($opt) ? ($opt['name'] ?? json_encode($opt)) : $opt); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab Content: Budget -->
        <div class="ht-tab-content" data-tab="budget">
            <div class="ht-budget-overview">
                <h3 class="ht-section-title-sm">Trip Budget</h3>
                <div class="ht-budget-tiers">
                    <?php if ($trip['budget_min']): ?>
                    <div class="ht-budget-tier">
                        <span class="ht-budget-tier-label">Minimum</span>
                        <span class="ht-budget-tier-value"><?php echo ht_format_currency($trip['budget_min'], $trip['budget_currency']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($trip['budget_comfort']): ?>
                    <div class="ht-budget-tier ht-budget-tier-main">
                        <span class="ht-budget-tier-label">Comfortable</span>
                        <span class="ht-budget-tier-value"><?php echo ht_format_currency($trip['budget_comfort'], $trip['budget_currency']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($trip['budget_max']): ?>
                    <div class="ht-budget-tier">
                        <span class="ht-budget-tier-label">Maximum</span>
                        <span class="ht-budget-tier-value"><?php echo ht_format_currency($trip['budget_max'], $trip['budget_currency']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($activePlan && !empty($activePlan['budget_breakdown'])): ?>
            <div class="ht-budget-breakdown">
                <h3 class="ht-section-title-sm">Estimated Breakdown</h3>
                <div class="ht-breakdown-list">
                    <?php
                    $breakdownLabels = [
                        'stay_total' => ['label' => 'Accommodation', 'icon' => '🏨'],
                        'food_estimate' => ['label' => 'Food & Drinks', 'icon' => '🍽️'],
                        'activities_estimate' => ['label' => 'Activities', 'icon' => '🎯'],
                        'transport_estimate' => ['label' => 'Transport', 'icon' => '🚗'],
                        'buffer_estimate' => ['label' => 'Buffer/Misc', 'icon' => '💵']
                    ];
                    $total = 0;
                    foreach ($activePlan['budget_breakdown'] as $key => $amount):
                        $total += (float) $amount;
                        if (!isset($breakdownLabels[$key])) continue;
                    ?>
                    <div class="ht-breakdown-item">
                        <span class="ht-breakdown-icon"><?php echo $breakdownLabels[$key]['icon']; ?></span>
                        <span class="ht-breakdown-label"><?php echo $breakdownLabels[$key]['label']; ?></span>
                        <span class="ht-breakdown-value"><?php echo ht_format_currency($amount, $trip['budget_currency']); ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div class="ht-breakdown-total">
                        <span class="ht-breakdown-label">Estimated Total</span>
                        <span class="ht-breakdown-value"><?php echo ht_format_currency($total, $trip['budget_currency']); ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($activePlan && !empty($activePlan['transport'])): ?>
            <div class="ht-transport-info">
                <h3 class="ht-section-title-sm">Transport</h3>
                <p class="ht-transport-recommended">
                    <strong>Recommended:</strong> <?php echo htmlspecialchars(ucfirst($activePlan['transport']['recommended'] ?? 'Mix')); ?>
                </p>
                <?php if (!empty($activePlan['transport']['notes'])): ?>
                <ul class="ht-transport-notes">
                    <?php foreach ($activePlan['transport']['notes'] as $note): ?>
                    <li><?php echo htmlspecialchars($note); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab Content: Wallet -->
        <div class="ht-tab-content" data-tab="wallet">
            <div class="ht-wallet-header">
                <h3 class="ht-section-title-sm">Travel Wallet</h3>
                <?php if ($canEdit): ?>
                <a href="/holiday_traveling/wallet_view.php?id=<?php echo $trip['id']; ?>" class="ht-btn ht-btn-primary ht-btn-sm">
                    Manage Wallet
                </a>
                <?php endif; ?>
            </div>
            <p class="ht-wallet-description">
                Store tickets, bookings, QR codes, and important documents for offline access.
            </p>
            <div class="ht-wallet-preview" id="walletPreview">
                <p class="ht-loading">Loading wallet items...</p>
            </div>
        </div>

        <!-- Tab Content: Expenses -->
        <div class="ht-tab-content" data-tab="expenses">
            <div class="ht-expenses-header">
                <h3 class="ht-section-title-sm">Expense Tracking</h3>
                <?php if ($canEdit): ?>
                <a href="/holiday_traveling/expenses_view.php?id=<?php echo $trip['id']; ?>" class="ht-btn ht-btn-primary ht-btn-sm">
                    Manage Expenses
                </a>
                <?php endif; ?>
            </div>
            <p class="ht-expenses-description">
                Track spending and split costs with travel companions.
            </p>
            <div class="ht-expenses-preview" id="expensesPreview">
                <p class="ht-loading">Loading expenses...</p>
            </div>
        </div>
    </div>
</div>


<!-- AI Loading Overlay -->
<div id="aiLoadingOverlay" class="ht-ai-loading" style="display: none;">
    <div class="ht-ai-loading-content">
        <div class="ht-ai-spinner"></div>
        <h3 class="ht-ai-loading-title" id="aiLoadingTitle">Processing...</h3>
        <p class="ht-ai-loading-text" id="aiLoadingText">AI is working on your request</p>
    </div>
</div>



<script>
    // Pass trip data to JS
    window.HT = window.HT || {};
    window.HT.tripId = <?php echo $trip['id']; ?>;
    window.HT.tripData = <?php echo json_encode([
        'id' => $trip['id'],
        'destination' => $trip['destination'],
        'start_date' => $trip['start_date'],
        'end_date' => $trip['end_date'],
        'travelers_count' => $trip['travelers_count'],
        'budget_currency' => $trip['budget_currency'],
        'status' => $trip['status']
    ]); ?>;
    window.HT.canEdit = <?php echo $canEdit ? 'true' : 'false'; ?>;
    window.HT.hasPlan = <?php echo $activePlan ? 'true' : 'false'; ?>;

    // Dropdown menu toggle
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.ht-menu-trigger').forEach(function(trigger) {
            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                const menu = this.closest('.ht-trip-actions-menu') || this.closest('.ht-actions-menu');
                const dropdown = menu.querySelector('.ht-menu-dropdown');
                const isActive = menu.classList.contains('active');

                // Close all other dropdowns
                document.querySelectorAll('.ht-trip-actions-menu.active, .ht-actions-menu.active').forEach(function(m) {
                    m.classList.remove('active');
                });

                // Toggle this dropdown
                if (!isActive) {
                    const buttonRect = this.getBoundingClientRect();
                    dropdown.style.top = buttonRect.bottom + 8 + 'px';
                    dropdown.style.left = buttonRect.left + 'px';
                    dropdown.style.right = 'auto';
                    menu.classList.add('active');
                }
            });
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function() {
            document.querySelectorAll('.ht-trip-actions-menu.active, .ht-actions-menu.active').forEach(function(m) {
                m.classList.remove('active');
            });
        });
    });
</script>
