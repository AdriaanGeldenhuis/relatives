<?php
/**
 * Holiday Traveling - Dashboard View
 * Shows trip list and quick actions
 */
?>

<div class="ht-dashboard">
    <!-- Page Header -->
    <div class="ht-page-header">
        <div class="ht-page-header-content">
            <h1 class="ht-page-title">
                <span class="ht-title-icon">✈️</span>
                Holiday & Traveling
            </h1>
            <p class="ht-page-subtitle">Plan your perfect getaway with AI assistance</p>
        </div>
        <a href="/holiday_traveling/trip_create.php" class="ht-btn ht-btn-primary ht-btn-create">
            <span class="ht-btn-icon">+</span>
            <span class="ht-btn-text">New Trip</span>
        </a>
    </div>

    <?php if (!empty($activeTrips)): ?>
    <!-- Active Trip Banner -->
    <div class="ht-section">
        <h2 class="ht-section-title">
            <span class="ht-section-icon">🌟</span>
            Active Trip
        </h2>
        <div class="ht-active-trip-banner">
            <?php $trip = reset($activeTrips); ?>
            <div class="ht-active-trip-card">
                <div class="ht-active-trip-header">
                    <h3 class="ht-active-trip-destination"><?php echo htmlspecialchars($trip['destination']); ?></h3>
                    <?php echo ht_status_badge($trip['status']); ?>
                </div>
                <p class="ht-active-trip-title"><?php echo htmlspecialchars($trip['title']); ?></p>
                <div class="ht-active-trip-meta">
                    <span class="ht-meta-item">
                        <span class="ht-meta-icon">📅</span>
                        <?php echo ht_format_date($trip['start_date']); ?> - <?php echo ht_format_date($trip['end_date']); ?>
                    </span>
                    <span class="ht-meta-item">
                        <span class="ht-meta-icon">👥</span>
                        <?php echo $trip['travelers_count']; ?> traveler<?php echo $trip['travelers_count'] > 1 ? 's' : ''; ?>
                    </span>
                    <span class="ht-meta-item">
                        <span class="ht-meta-icon">📍</span>
                        Day <?php echo ht_trip_duration($trip['start_date'], date('Y-m-d')); ?> of <?php echo ht_trip_duration($trip['start_date'], $trip['end_date']); ?>
                    </span>
                </div>
                <div class="ht-active-trip-actions">
                    <a href="/holiday_traveling/trip_view.php?id=<?php echo $trip['id']; ?>" class="ht-btn ht-btn-secondary">View Plan</a>
                    <a href="/holiday_traveling/wallet_view.php?id=<?php echo $trip['id']; ?>" class="ht-btn ht-btn-outline">Wallet</a>
                    <a href="/holiday_traveling/expenses_view.php?id=<?php echo $trip['id']; ?>" class="ht-btn ht-btn-outline">Expenses</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick Stats -->
    <div class="ht-stats-grid">
        <div class="ht-stat-card">
            <span class="ht-stat-icon">🗓️</span>
            <span class="ht-stat-value"><?php echo count($upcomingTrips); ?></span>
            <span class="ht-stat-label">Upcoming</span>
        </div>
        <div class="ht-stat-card">
            <span class="ht-stat-icon">✅</span>
            <span class="ht-stat-value"><?php echo count(array_filter($trips, fn($t) => $t['status'] === 'completed')); ?></span>
            <span class="ht-stat-label">Completed</span>
        </div>
        <div class="ht-stat-card">
            <span class="ht-stat-icon">📝</span>
            <span class="ht-stat-value"><?php echo count(array_filter($trips, fn($t) => $t['status'] === 'draft')); ?></span>
            <span class="ht-stat-label">Drafts</span>
        </div>
    </div>

    <?php if (!empty($upcomingTrips)): ?>
    <!-- Upcoming Trips -->
    <div class="ht-section">
        <h2 class="ht-section-title">
            <span class="ht-section-icon">🗓️</span>
            Upcoming Trips
        </h2>
        <div class="ht-trips-list">
            <?php foreach ($upcomingTrips as $trip): ?>
            <div class="ht-trip-card" data-trip-id="<?php echo $trip['id']; ?>">
                <div class="ht-trip-card-header">
                    <div class="ht-trip-destination">
                        <h3><?php echo htmlspecialchars($trip['destination']); ?></h3>
                        <?php echo ht_status_badge($trip['status']); ?>
                    </div>
                </div>
                <p class="ht-trip-title"><?php echo htmlspecialchars($trip['title']); ?></p>
                <div class="ht-trip-meta">
                    <span class="ht-meta-item">
                        <span class="ht-meta-icon">📅</span>
                        <?php echo ht_format_date($trip['start_date'], 'M j'); ?> - <?php echo ht_format_date($trip['end_date'], 'M j, Y'); ?>
                    </span>
                    <span class="ht-meta-item">
                        <span class="ht-meta-icon">⏱️</span>
                        <?php echo ht_trip_duration($trip['start_date'], $trip['end_date']); ?> days
                    </span>
                    <span class="ht-meta-item">
                        <span class="ht-meta-icon">👥</span>
                        <?php echo $trip['travelers_count']; ?>
                    </span>
                </div>
                <?php if ($trip['budget_comfort']): ?>
                <div class="ht-trip-budget">
                    <span class="ht-budget-label">Budget:</span>
                    <span class="ht-budget-value"><?php echo ht_format_currency($trip['budget_comfort'], $trip['budget_currency']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($trip['active_plan_version']): ?>
                <div class="ht-trip-plan-indicator">
                    <span class="ht-plan-badge">📋 Plan v<?php echo $trip['active_plan_version']; ?></span>
                </div>
                <?php else: ?>
                <div class="ht-trip-plan-indicator">
                    <span class="ht-plan-badge ht-plan-badge-empty">No plan yet</span>
                </div>
                <?php endif; ?>
                <a href="/holiday_traveling/trip_view.php?id=<?php echo $trip['id']; ?>" class="ht-trip-card-link">
                    View Details →
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($pastTrips)): ?>
    <!-- Past Trips -->
    <div class="ht-section">
        <h2 class="ht-section-title">
            <span class="ht-section-icon">📚</span>
            Past Trips
        </h2>
        <div class="ht-trips-list ht-trips-list-compact">
            <?php foreach ($pastTrips as $trip): ?>
            <div class="ht-trip-card ht-trip-card-past" data-trip-id="<?php echo $trip['id']; ?>">
                <div class="ht-trip-card-header">
                    <div class="ht-trip-destination">
                        <h3><?php echo htmlspecialchars($trip['destination']); ?></h3>
                        <?php echo ht_status_badge($trip['status']); ?>
                    </div>
                </div>
                <p class="ht-trip-title"><?php echo htmlspecialchars($trip['title']); ?></p>
                <div class="ht-trip-meta">
                    <span class="ht-meta-item">
                        <span class="ht-meta-icon">📅</span>
                        <?php echo ht_format_date($trip['start_date'], 'M Y'); ?>
                    </span>
                </div>
                <a href="/holiday_traveling/trip_view.php?id=<?php echo $trip['id']; ?>" class="ht-trip-card-link">
                    View Trip →
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($trips)): ?>
    <!-- Empty State -->
    <div class="ht-empty-state">
        <div class="ht-empty-icon">🌴</div>
        <h2 class="ht-empty-title">No trips yet</h2>
        <p class="ht-empty-description">
            Start planning your next adventure! Create a trip and let AI help you build the perfect itinerary.
        </p>
        <a href="/holiday_traveling/trip_create.php" class="ht-btn ht-btn-primary ht-btn-lg">
            <span class="ht-btn-icon">+</span>
            Create Your First Trip
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="ht-modal" style="display: none;">
    <div class="ht-modal-backdrop"></div>
    <div class="ht-modal-content">
        <h3 class="ht-modal-title">Delete Trip?</h3>
        <p class="ht-modal-text">Are you sure you want to delete this trip? This action cannot be undone.</p>
        <div class="ht-modal-actions">
            <button class="ht-btn ht-btn-outline" data-action="cancel">Cancel</button>
            <button class="ht-btn ht-btn-danger" data-action="confirm">Delete</button>
        </div>
    </div>
</div>

<script>
// Dropdown menu toggle on click
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
                // Position dropdown on the left side of the trip card
                const card = this.closest('.ht-trip-card');
                const cardRect = card.getBoundingClientRect();
                const buttonRect = this.getBoundingClientRect();
                dropdown.style.top = buttonRect.top + 'px';
                dropdown.style.left = (cardRect.left - dropdown.offsetWidth - 8) + 'px';
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
