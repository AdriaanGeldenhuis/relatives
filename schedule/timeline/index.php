<?php
/**
 * ============================================
 * RELATIVES - TIMELINE VIEW
 * Visual time-based schedule representation
 * Shows start, end, and duration of events
 * ============================================
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    header('Location: /login.php');
    exit;
}

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedDate = date('Y-m-d', strtotime($selectedDate));

// Timeline settings
$timelineStart = 5; // Start at 5 AM
$timelineEnd = 23;  // End at 11 PM
$totalHours = $timelineEnd - $timelineStart;

// Get family members
try {
    $stmt = $db->prepare("
        SELECT id, full_name, avatar_color
        FROM users
        WHERE family_id = ? AND status = 'active'
        ORDER BY full_name
    ");
    $stmt->execute([$user['family_id']]);
    $familyMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $familyMembers = [];
}

// Get events for selected date
$events = [];
try {
    $stmt = $db->prepare("
        SELECT
            e.*,
            e.recurrence_rule as repeat_rule,
            u.full_name as added_by_name, u.avatar_color,
            a.full_name as assigned_to_name, a.avatar_color as assigned_color,
            TIMESTAMPDIFF(MINUTE, e.starts_at, e.ends_at) as duration_minutes
        FROM events e
        LEFT JOIN users u ON e.created_by = u.id
        LEFT JOIN users a ON e.assigned_to = a.id
        WHERE e.family_id = ?
        AND DATE(e.starts_at) = ?
        AND e.status != 'cancelled'
        ORDER BY e.starts_at ASC
    ");
    $stmt->execute([$user['family_id'], $selectedDate]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $events = [];
}

// Type metadata
$types = [
    'work' => ['icon' => '💼', 'name' => 'Work', 'color' => '#43e97b'],
    'study' => ['icon' => '📚', 'name' => 'Study', 'color' => '#667eea'],
    'church' => ['icon' => '⛪', 'name' => 'Church', 'color' => '#9b59b6'],
    'event' => ['icon' => '📅', 'name' => 'Event', 'color' => '#3498db'],
    'focus' => ['icon' => '🎯', 'name' => 'Focus', 'color' => '#4facfe'],
    'break' => ['icon' => '☕', 'name' => 'Break', 'color' => '#feca57'],
    'todo' => ['icon' => '✅', 'name' => 'To-Do', 'color' => '#f093fb'],
    'birthday' => ['icon' => '🎂', 'name' => 'Birthday', 'color' => '#ff6b6b'],
    'anniversary' => ['icon' => '💍', 'name' => 'Anniversary', 'color' => '#ff9ff3'],
    'holiday' => ['icon' => '🎉', 'name' => 'Holiday', 'color' => '#feca57'],
    'family_event' => ['icon' => '👨‍👩‍👧‍👦', 'name' => 'Family', 'color' => '#00d2d3'],
    'date' => ['icon' => '❤️', 'name' => 'Date', 'color' => '#ee5a6f'],
    'reminder' => ['icon' => '🔔', 'name' => 'Reminder', 'color' => '#f39c12']
];

// Calculate total time scheduled and done
$totalScheduledMinutes = 0;
$totalDoneMinutes = 0;
foreach ($events as $event) {
    $duration = $event['duration_minutes'] ?? 0;
    $totalScheduledMinutes += $duration;
    if ($event['status'] === 'done') {
        $totalDoneMinutes += $duration;
    }
}

// Format duration helper
function formatDuration($minutes) {
    if ($minutes < 60) {
        return $minutes . 'm';
    }
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return $mins > 0 ? "{$hours}h {$mins}m" : "{$hours}h";
}

// Current time for progress indicator
$currentHour = (int)date('G');
$currentMinute = (int)date('i');
$isToday = ($selectedDate === date('Y-m-d'));

$pageTitle = 'Timeline';
$activePage = 'schedule';
$cacheVersion = '10.1.0';
$pageCSS = ['/schedule/timeline/css/timeline.css?v=' . $cacheVersion];
$pageJS = ['/schedule/timeline/js/timeline.js?v=' . $cacheVersion];

require_once __DIR__ . '/../../shared/components/header.php';
?>

<!-- Animated Background -->
<div class="bg-animation">
    <div class="bg-gradient"></div>
</div>

<!-- Main Content -->
<main class="main-content">
    <div class="container">

        <!-- Hero Section -->
        <div class="hero-section">
            <div class="greeting-card">
                <div class="greeting-time"><?php echo date('l, F j, Y', strtotime($selectedDate)); ?></div>
                <h1 class="greeting-text">
                    <span class="greeting-icon">📊</span>
                    <span class="greeting-name">Timeline View</span>
                </h1>
                <p class="greeting-subtitle">Visual overview of your day from start to finish</p>

                <!-- Date Navigation -->
                <div class="date-nav">
                    <button onclick="changeDate(-1)" class="date-nav-btn" title="Previous Day">
                        <span>◀</span>
                    </button>
                    <button onclick="goToToday()" class="date-nav-today <?php echo $isToday ? 'active' : ''; ?>">
                        Today
                    </button>
                    <button onclick="changeDate(1)" class="date-nav-btn" title="Next Day">
                        <span>▶</span>
                    </button>
                </div>

                <!-- Quick Stats -->
                <div class="timeline-stats">
                    <div class="stat-card">
                        <span class="stat-icon">📋</span>
                        <span class="stat-value"><?php echo count($events); ?></span>
                        <span class="stat-label">Events</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon">⏱️</span>
                        <span class="stat-value"><?php echo formatDuration($totalScheduledMinutes); ?></span>
                        <span class="stat-label">Scheduled</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon">✅</span>
                        <span class="stat-value"><?php echo formatDuration($totalDoneMinutes); ?></span>
                        <span class="stat-label">Completed</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon">📈</span>
                        <span class="stat-value"><?php echo $totalScheduledMinutes > 0 ? round(($totalDoneMinutes / $totalScheduledMinutes) * 100) : 0; ?>%</span>
                        <span class="stat-label">Progress</span>
                    </div>
                </div>

                <!-- View Toggle -->
                <div class="view-toggle">
                    <a href="/schedule/?date=<?php echo $selectedDate; ?>" class="view-btn">
                        <span>📅</span> Schedule
                    </a>
                    <a href="/schedule/timeline/?date=<?php echo $selectedDate; ?>" class="view-btn active">
                        <span>📊</span> Timeline
                    </a>
                </div>
            </div>
        </div>

        <!-- Timeline Container -->
        <div class="timeline-container glass-card">

            <!-- Timeline Header -->
            <div class="timeline-header">
                <div class="timeline-title">
                    <span class="title-icon">🕐</span>
                    <span>Day Timeline</span>
                    <?php if ($isToday): ?>
                        <span class="current-time-badge"><?php echo date('H:i'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="timeline-legend">
                    <span class="legend-item"><span class="legend-dot done"></span> Done</span>
                    <span class="legend-item"><span class="legend-dot pending"></span> Pending</span>
                    <span class="legend-item"><span class="legend-dot in-progress"></span> In Progress</span>
                </div>
            </div>

            <!-- Timeline Grid -->
            <div class="timeline-grid" id="timelineGrid">

                <!-- Time Labels -->
                <div class="time-labels">
                    <?php for ($hour = $timelineStart; $hour <= $timelineEnd; $hour++): ?>
                        <div class="time-label" style="top: <?php echo (($hour - $timelineStart) / $totalHours) * 100; ?>%">
                            <span><?php echo sprintf('%02d:00', $hour); ?></span>
                        </div>
                    <?php endfor; ?>
                </div>

                <!-- Timeline Track -->
                <div class="timeline-track">

                    <!-- Hour Lines -->
                    <?php for ($hour = $timelineStart; $hour <= $timelineEnd; $hour++): ?>
                        <div class="hour-line <?php echo $hour === $currentHour && $isToday ? 'current-hour' : ''; ?>"
                             style="top: <?php echo (($hour - $timelineStart) / $totalHours) * 100; ?>%">
                        </div>
                    <?php endfor; ?>

                    <!-- Events -->
                    <?php if (empty($events)): ?>
                        <div class="no-events">
                            <div class="no-events-icon">📭</div>
                            <p>No events scheduled for this day</p>
                            <a href="/schedule/?date=<?php echo $selectedDate; ?>" class="add-event-link">
                                + Add Event
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($events as $index => $event): ?>
                            <?php
                            $startTime = strtotime($event['starts_at']);
                            $endTime = strtotime($event['ends_at']);
                            $startHour = (int)date('G', $startTime);
                            $startMin = (int)date('i', $startTime);
                            $endHour = (int)date('G', $endTime);
                            $endMin = (int)date('i', $endTime);

                            // Calculate position and height
                            $startPos = max(0, (($startHour - $timelineStart) + ($startMin / 60)) / $totalHours * 100);
                            $endPos = min(100, (($endHour - $timelineStart) + ($endMin / 60)) / $totalHours * 100);
                            $height = max(3, $endPos - $startPos); // Minimum 3% height

                            $type = $event['kind'] ?? 'todo';
                            $typeInfo = $types[$type] ?? $types['todo'];
                            $duration = $event['duration_minutes'] ?? 0;
                            $status = $event['status'] ?? 'pending';
                            ?>
                            <div class="timeline-event <?php echo $status; ?> <?php echo $event['focus_mode'] ? 'focus-event' : ''; ?>"
                                 style="top: <?php echo $startPos; ?>%; height: <?php echo $height; ?>%; background: <?php echo $typeInfo['color']; ?>20; border-left-color: <?php echo $typeInfo['color']; ?>;"
                                 data-event-id="<?php echo $event['id']; ?>"
                                 onclick="showEventDetails(<?php echo $event['id']; ?>)">

                                <div class="event-content">
                                    <div class="event-title">
                                        <span class="event-icon"><?php echo $typeInfo['icon']; ?></span>
                                        <span class="event-name"><?php echo htmlspecialchars($event['title']); ?></span>
                                        <?php if ($event['focus_mode']): ?>
                                            <span class="focus-badge">🎯</span>
                                        <?php endif; ?>
                                        <?php if ($event['repeat_rule']): ?>
                                            <span class="repeat-badge">🔁</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="event-status-indicator <?php echo $status; ?>">
                                    <?php if ($status === 'done'): ?>
                                        ✓
                                    <?php elseif ($status === 'in_progress'): ?>
                                        ▶
                                    <?php else: ?>
                                        ○
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Event List (Alternative view) -->
        <div class="event-list-section glass-card">
            <div class="section-header">
                <span class="section-icon">📋</span>
                <span class="section-title">Event Details</span>
            </div>

            <?php if (empty($events)): ?>
                <div class="empty-list">
                    <p>No events to display</p>
                </div>
            <?php else: ?>
                <div class="event-list">
                    <?php foreach ($events as $event): ?>
                        <?php
                        $type = $event['kind'] ?? 'todo';
                        $typeInfo = $types[$type] ?? $types['todo'];
                        $startTime = strtotime($event['starts_at']);
                        $endTime = strtotime($event['ends_at']);
                        $duration = $event['duration_minutes'] ?? 0;
                        $status = $event['status'] ?? 'pending';
                        ?>
                        <div class="event-list-item <?php echo $status; ?>" onclick="showEventDetails(<?php echo $event['id']; ?>)">
                            <div class="event-list-time">
                                <div class="start-time"><?php echo date('H:i', $startTime); ?></div>
                                <div class="time-arrow">↓</div>
                                <div class="end-time"><?php echo date('H:i', $endTime); ?></div>
                            </div>

                            <div class="event-list-color" style="background: <?php echo $typeInfo['color']; ?>;"></div>

                            <div class="event-list-content">
                                <div class="event-list-title">
                                    <?php echo $typeInfo['icon']; ?> <?php echo htmlspecialchars($event['title']); ?>
                                </div>
                                <div class="event-list-meta">
                                    <span class="duration-badge">⏱️ <?php echo formatDuration($duration); ?></span>
                                    <?php if ($event['assigned_to_name']): ?>
                                        <span class="assigned-badge">👤 <?php echo htmlspecialchars($event['assigned_to_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="event-list-status">
                                <?php if ($status === 'done'): ?>
                                    <span class="status-done">✅ Done</span>
                                <?php elseif ($status === 'in_progress'): ?>
                                    <span class="status-progress">▶️ Active</span>
                                <?php else: ?>
                                    <span class="status-pending">⏳ Pending</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</main>

<!-- Event Details Modal -->
<div id="eventDetailModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalEventTitle">Event Details</h2>
            <button onclick="closeModal('eventDetailModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body" id="modalEventBody">
            <!-- Content loaded dynamically -->
        </div>
        <div class="modal-actions">
            <a href="#" id="editEventLink" class="btn btn-primary">✏️ Edit</a>
            <button onclick="closeModal('eventDetailModal')" class="btn btn-secondary">Close</button>
        </div>
    </div>
</div>

<script>
    // Pass events data to JavaScript
    window.timelineEvents = <?php echo json_encode($events); ?>;
    window.selectedDate = '<?php echo $selectedDate; ?>';
    window.eventTypes = <?php echo json_encode($types); ?>;
</script>

<?php require_once __DIR__ . '/../../shared/components/footer.php'; ?>
