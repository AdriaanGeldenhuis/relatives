<?php
/**
 * ============================================
 * RELATIVES - HOLIDAY TRAVELING
 * Styled exactly like Weather/Schedule pages
 * ============================================
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    header('Location: /login.php');
    exit;
}

// Get family members for the travel planner
$familyMembers = [];
try {
    $stmt = $db->prepare("
        SELECT id, full_name, avatar_color
        FROM users
        WHERE family_id = ?
        ORDER BY full_name
    ");
    $stmt->execute([$user['family_id']]);
    $familyMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Family members fetch error: ' . $e->getMessage());
}

// Get existing travel plans
$travelPlans = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM holiday_travels
        WHERE family_id = ?
        ORDER BY start_date ASC
    ");
    $stmt->execute([$user['family_id']]);
    $travelPlans = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist yet, that's okay
    error_log('Travel plans fetch error: ' . $e->getMessage());
}

$pageTitle = 'Holiday Traveling';
$activePage = 'holiday_traveling';
$cacheVersion = '10.0.0';
$pageCSS = ['/holiday_traveling/css/holiday.css?v=' . $cacheVersion];
$pageJS = ['/holiday_traveling/js/holiday.js?v=' . $cacheVersion];

require_once __DIR__ . '/../shared/components/header.php';
?>

<!-- Animated Background -->
<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<!-- Main Content -->
<main class="main-content">
    <div class="container">

        <!-- Hero Section -->
        <div class="hero-section">
            <div class="greeting-card">
                <div class="greeting-time"><?php echo date('l, F j, Y'); ?></div>
                <h1 class="greeting-text">
                    <span class="greeting-icon">✈️</span>
                    <span class="greeting-name">Holiday Traveling</span>
                </h1>
                <p class="greeting-subtitle">Plan your family adventures together</p>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <button onclick="HolidayPlanner.getInstance().openNewTripModal()" class="quick-action-btn">
                        <span class="qa-icon">➕</span>
                        <span>New Trip</span>
                    </button>
                    <button onclick="HolidayPlanner.getInstance().showCalendarView()" class="quick-action-btn">
                        <span class="qa-icon">📅</span>
                        <span>Calendar</span>
                    </button>
                    <button onclick="HolidayPlanner.getInstance().showMapView()" class="quick-action-btn">
                        <span class="qa-icon">🗺️</span>
                        <span>Map</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Upcoming Trips Section -->
        <div class="notes-section">
            <div class="section-header">
                <h2 class="section-title">
                    <span>🌴</span> Upcoming Trips
                </h2>
                <div class="filter-buttons">
                    <button class="filter-btn active" data-filter="upcoming" onclick="HolidayPlanner.getInstance().filterTrips('upcoming')">
                        <span>📆</span> Upcoming
                    </button>
                    <button class="filter-btn" data-filter="past" onclick="HolidayPlanner.getInstance().filterTrips('past')">
                        <span>📜</span> Past
                    </button>
                    <button class="filter-btn" data-filter="all" onclick="HolidayPlanner.getInstance().filterTrips('all')">
                        <span>📋</span> All
                    </button>
                </div>
            </div>

            <div id="tripsGrid" class="notes-grid">
                <?php if (empty($travelPlans)): ?>
                <div class="empty-state">
                    <div class="empty-icon">✈️</div>
                    <h3>No trips planned yet</h3>
                    <p>Start planning your next family adventure!</p>
                    <button onclick="HolidayPlanner.getInstance().openNewTripModal()" class="primary-btn">
                        <span>➕</span> Plan a Trip
                    </button>
                </div>
                <?php else: ?>
                    <?php foreach ($travelPlans as $trip): ?>
                    <div class="note-card trip-card" data-trip-id="<?php echo $trip['id']; ?>">
                        <div class="trip-header">
                            <span class="trip-icon">🏖️</span>
                            <h3 class="trip-title"><?php echo htmlspecialchars($trip['destination'] ?? 'Unknown'); ?></h3>
                        </div>
                        <div class="trip-dates">
                            <span>📅</span>
                            <?php echo date('M j', strtotime($trip['start_date'])); ?> - <?php echo date('M j, Y', strtotime($trip['end_date'])); ?>
                        </div>
                        <div class="trip-actions">
                            <button onclick="HolidayPlanner.getInstance().viewTrip(<?php echo $trip['id']; ?>)" class="trip-btn">View</button>
                            <button onclick="HolidayPlanner.getInstance().editTrip(<?php echo $trip['id']; ?>)" class="trip-btn">Edit</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Travel Ideas Section -->
        <div class="notes-section">
            <div class="section-header">
                <h2 class="section-title">
                    <span>💡</span> Travel Ideas
                </h2>
            </div>
            <div class="ideas-grid">
                <div class="idea-card" onclick="HolidayPlanner.getInstance().exploreDest('beach')">
                    <span class="idea-icon">🏖️</span>
                    <span class="idea-text">Beach</span>
                </div>
                <div class="idea-card" onclick="HolidayPlanner.getInstance().exploreDest('mountain')">
                    <span class="idea-icon">⛰️</span>
                    <span class="idea-text">Mountains</span>
                </div>
                <div class="idea-card" onclick="HolidayPlanner.getInstance().exploreDest('city')">
                    <span class="idea-icon">🏙️</span>
                    <span class="idea-text">City</span>
                </div>
                <div class="idea-card" onclick="HolidayPlanner.getInstance().exploreDest('safari')">
                    <span class="idea-icon">🦁</span>
                    <span class="idea-text">Safari</span>
                </div>
            </div>
        </div>

        <!-- Family Travelers Section -->
        <div class="notes-section">
            <div class="section-header">
                <h2 class="section-title">
                    <span>👨‍👩‍👧‍👦</span> Travelers
                </h2>
            </div>
            <div class="travelers-grid">
                <?php foreach ($familyMembers as $member): ?>
                <div class="traveler-card">
                    <div class="traveler-avatar" style="background: <?php echo htmlspecialchars($member['avatar_color'] ?? '#667eea'); ?>">
                        <?php echo strtoupper(substr($member['full_name'] ?? '?', 0, 1)); ?>
                    </div>
                    <span class="traveler-name"><?php echo htmlspecialchars($member['full_name'] ?? 'Unknown'); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</main>

<!-- New Trip Modal -->
<div id="newTripModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Plan New Trip</h2>
            <button onclick="HolidayPlanner.getInstance().closeModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="newTripForm">
                <div class="form-group">
                    <label for="destination">Destination</label>
                    <input type="text" id="destination" name="destination" placeholder="Where are you going?" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="startDate">Start Date</label>
                        <input type="date" id="startDate" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label for="endDate">End Date</label>
                        <input type="date" id="endDate" name="end_date" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" placeholder="Add any details..."></textarea>
                </div>
                <div class="form-group">
                    <label>Who's going?</label>
                    <div class="travelers-select">
                        <?php foreach ($familyMembers as $member): ?>
                        <label class="traveler-checkbox">
                            <input type="checkbox" name="travelers[]" value="<?php echo $member['id']; ?>">
                            <span class="traveler-chip" style="--color: <?php echo htmlspecialchars($member['avatar_color'] ?? '#667eea'); ?>">
                                <?php echo htmlspecialchars($member['full_name']); ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="primary-btn full-width">
                    <span>✈️</span> Create Trip
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Pass data to JavaScript -->
<script>
window.FAMILY_MEMBERS = <?php echo json_encode($familyMembers); ?>;
window.TRAVEL_PLANS = <?php echo json_encode($travelPlans); ?>;
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
