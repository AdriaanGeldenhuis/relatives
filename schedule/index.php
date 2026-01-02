<?php
/**
 * ============================================
 * RELATIVES - ENHANCED SCHEDULE
 * With Time Blocking, Focus Mode & Productivity
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

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedDate = date('Y-m-d', strtotime($selectedDate));
$viewMode = $_GET['view'] ?? 'day';

// Get family members for assignment
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
    error_log("Schedule - Get family members error: " . $e->getMessage());
    $familyMembers = [];
}

// Get events for selected date
$events = [];
try {
    $stmt = $db->prepare("
        SELECT 
            e.*,
            u.full_name as added_by_name, u.avatar_color,
            a.full_name as assigned_to_name, a.avatar_color as assigned_color
        FROM schedule_events e
        LEFT JOIN users u ON e.added_by = u.id
        LEFT JOIN users a ON e.assigned_to = a.id
        WHERE e.family_id = ? 
        AND DATE(e.starts_at) = ?
        AND e.status != 'cancelled'
        ORDER BY e.starts_at ASC
    ");
    $stmt->execute([$user['family_id'], $selectedDate]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Schedule - Get events error: " . $e->getMessage());
    // If table doesn't exist, show helpful error
    if (strpos($e->getMessage(), "doesn't exist") !== false) {
        die("<h1 style='color:red;text-align:center;margin-top:100px;'>⚠️ Database Error: schedule_events table missing!<br><br>Please run /schedule/setup/create_tables.sql first</h1>");
    }
    $events = [];
}

// Group by type
$eventsByType = [
    'study' => [],
    'work' => [],
    'todo' => [],
    'break' => [],
    'focus' => []
];

foreach ($events as $event) {
    $type = $event['kind'] ?? 'todo';
    if (!isset($eventsByType[$type])) {
        $eventsByType[$type] = [];
    }
    $eventsByType[$type][] = $event;
}

// Type metadata
$types = [
    'study' => ['icon' => '📚', 'name' => 'Study Sessions', 'color' => '#667eea', 'desc' => 'Learning & studying'],
    'work' => ['icon' => '💼', 'name' => 'Work Blocks', 'color' => '#43e97b', 'desc' => 'Professional tasks'],
    'todo' => ['icon' => '✅', 'name' => 'To-Do Tasks', 'color' => '#f093fb', 'desc' => 'General tasks'],
    'break' => ['icon' => '☕', 'name' => 'Break Time', 'color' => '#feca57', 'desc' => 'Rest & recharge'],
    'focus' => ['icon' => '🎯', 'name' => 'Focus Sessions', 'color' => '#4facfe', 'desc' => 'Deep work mode']
];

// Calculate stats
$totalEvents = count($events);
$pendingEvents = count(array_filter($events, fn($e) => $e['status'] === 'pending'));
$doneEvents = count(array_filter($events, fn($e) => $e['status'] === 'done'));
$inProgressEvents = count(array_filter($events, fn($e) => $e['status'] === 'in_progress'));

// Get week stats
$weekStart = date('Y-m-d', strtotime('monday this week', strtotime($selectedDate)));
$weekEnd = date('Y-m-d', strtotime('sunday this week', strtotime($selectedDate)));

$studyStats = ['total' => 0, 'done' => 0, 'minutes' => 0, 'rating' => 0];
$workStats = ['total' => 0, 'done' => 0, 'minutes' => 0, 'rating' => 0];
$focusStats = ['total' => 0, 'done' => 0, 'minutes' => 0, 'rating' => 0];

try {
    $stmt = $db->prepare("
        SELECT 
            kind,
            COUNT(*) as total,
            SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done,
            SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) as total_minutes,
            AVG(productivity_rating) as avg_rating
        FROM schedule_events
        WHERE family_id = ?
        AND DATE(starts_at) BETWEEN ? AND ?
        AND status != 'cancelled'
        GROUP BY kind
    ");
    $stmt->execute([$user['family_id'], $weekStart, $weekEnd]);
    $weekStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($weekStats as $stat) {
        $stats = [
            'total' => $stat['total'],
            'done' => $stat['done'],
            'minutes' => $stat['total_minutes'] ?? 0,
            'rating' => round($stat['avg_rating'] ?? 0, 1)
        ];
        
        if ($stat['kind'] === 'study') $studyStats = $stats;
        elseif ($stat['kind'] === 'work') $workStats = $stats;
        elseif ($stat['kind'] === 'focus') $focusStats = $stats;
    }
} catch (PDOException $e) {
    error_log("Schedule - Get week stats error: " . $e->getMessage());
}

// Get productivity score
$productivityScore = 0;
try {
    $stmt = $db->prepare("
        SELECT productivity_score 
        FROM schedule_productivity 
        WHERE user_id = ? AND date = ?
    ");
    $stmt->execute([$user['id'], $selectedDate]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $productivityScore = $result ? round($result['productivity_score'], 1) : 0;
} catch (PDOException $e) {
    // Table might not exist yet - that's ok
}

// Check for active focus session
$activeFocusSession = null;
try {
    $stmt = $db->prepare("
        SELECT * FROM schedule_events
        WHERE user_id = ?
        AND status = 'in_progress'
        AND focus_mode = 1
        ORDER BY actual_start DESC
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $activeFocusSession = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Ignore
}

$pageTitle = 'Schedule';
$activePage = 'schedule';
$pageCSS = ['/schedule/css/schedule.css?v=' . time()];
$pageJS = ['/schedule/js/schedule.js?v=' . time()];

require_once __DIR__ . '/../shared/components/header.php';
?>

<!-- Animated Background -->
<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<!-- Active Focus Mode Overlay -->
<?php if ($activeFocusSession): ?>
<div id="focusModeOverlay" class="focus-mode-overlay active">
    <div class="focus-mode-content">
        <div class="focus-icon">🎯</div>
        <h2>Focus Mode Active</h2>
        <div class="focus-task"><?php echo htmlspecialchars($activeFocusSession['title']); ?></div>
        <div class="focus-timer" id="focusTimer">00:00</div>
        <div class="focus-pomodoro">
            <span>Pomodoros: <?php echo $activeFocusSession['pomodoro_count']; ?></span>
        </div>
        <div class="focus-actions">
            <button onclick="takeFocusBreak()" class="btn btn-secondary">
                <span class="btn-icon">☕</span>
                <span>Break</span>
            </button>
            <button onclick="endFocusSession(<?php echo $activeFocusSession['id']; ?>)" class="btn btn-success">
                <span class="btn-icon">✓</span>
                <span>Complete</span>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Main Content -->
<main class="main-content">
    <div class="container">
        
        <!-- Hero Section (Same as notes style) -->
        <div class="hero-section">
            <div class="greeting-card">
                <div class="greeting-time"><?php echo date('l, F j, Y'); ?></div>
                <h1 class="greeting-text">
                    <span class="greeting-icon">⏰</span>
                    <span class="greeting-name">My Schedule</span>
                </h1>
                <p class="greeting-subtitle">Smart time management & productivity tracking</p>
                
                <div class="quick-actions">
                    <button onclick="showQuickAdd()" class="quick-action-btn">
                        <span class="qa-icon">➕</span>
                        <span>Add Event</span>
                    </button>
                    <button onclick="startFocusMode()" class="quick-action-btn focus-btn">
                        <span class="qa-icon">🎯</span>
                        <span>Focus Mode</span>
                    </button>
                    <button onclick="toggleBulkMode()" class="quick-action-btn" id="bulkModeBtn">
                        <span class="qa-icon">☑️</span>
                        <span>Bulk</span>
                    </button>
                    <button onclick="showTemplates()" class="quick-action-btn">
                        <span class="qa-icon">📋</span>
                        <span>Templates</span>
                    </button>
                    <button onclick="showAnalytics()" class="quick-action-btn">
                        <span class="qa-icon">📊</span>
                        <span>Analytics</span>
                    </button>
                    <button onclick="exportSchedule()" class="quick-action-btn">
                        <span class="qa-icon">📤</span>
                        <span>Export</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- View Mode Switcher (Styled like notes filter) -->
        <div class="search-filter-section">
            <div class="filter-buttons">
                <button onclick="changeView('day')" class="filter-btn <?php echo $viewMode === 'day' ? 'active' : ''; ?>">
                    <span>📅</span> Day
                </button>
                <button onclick="changeView('week')" class="filter-btn <?php echo $viewMode === 'week' ? 'active' : ''; ?>">
                    <span>🗓️</span> Week
                </button>
                <button onclick="changeView('timeline')" class="filter-btn <?php echo $viewMode === 'timeline' ? 'active' : ''; ?>">
                    <span>⏱️</span> Timeline
                </button>
            </div>
        </div>

        <!-- Date Navigator (Styled like notes) -->
        <div class="notes-stats-bar">
            <div class="stat-item" onclick="changeDate(-1)" style="cursor:pointer;" title="Previous Day">
                <span class="stat-icon">←</span>
                <span class="stat-label">Previous</span>
            </div>
            
            <div class="stat-item <?php echo $selectedDate === date('Y-m-d') ? 'active' : ''; ?>" onclick="goToToday()" style="cursor:pointer;">
                <span class="stat-icon">📅</span>
                <span class="stat-value"><?php echo date('M j', strtotime($selectedDate)); ?></span>
                <span class="stat-label"><?php echo $totalEvents; ?> events</span>
            </div>
            
            <div class="stat-item" onclick="changeDate(1)" style="cursor:pointer;" title="Next Day">
                <span class="stat-icon">→</span>
                <span class="stat-label">Next</span>
            </div>
            
            <div class="stat-item" onclick="showDatePicker()" style="cursor:pointer;" title="Pick Date">
                <span class="stat-icon">🗓️</span>
                <span class="stat-label">Pick Date</span>
            </div>
        </div>

        <!-- Quick Add Form -->
        <div class="quick-add-section">
            <div class="quick-add-card glass-card">
                <form id="quickAddForm" onsubmit="addEvent(event)">
                    <div class="quick-add-content">
                        <input 
                            type="text" 
                            id="eventTitle" 
                            class="form-control" 
                            placeholder="What do you want to work on?"
                            autocomplete="off"
                            required
                            style="flex: 1; min-width: 200px;">
                        
                        <input 
                            type="time" 
                            id="eventStart" 
                            class="form-control" 
                            value="<?php echo date('H:00'); ?>"
                            required
                            style="width: 120px;">
                        
                        <input 
                            type="time" 
                            id="eventEnd" 
                            class="form-control" 
                            value="<?php echo date('H:00', strtotime('+1 hour')); ?>"
                            required
                            style="width: 120px;">
                        
                        <select id="eventType" class="form-control" style="min-width: 140px;">
                            <option value="study">📚 Study</option>
                            <option value="work">💼 Work</option>
                            <option value="todo">✅ To-Do</option>
                            <option value="focus">🎯 Focus</option>
                            <option value="break">☕ Break</option>
                        </select>
                        
                        <button type="submit" class="btn btn-primary">
                            <span class="btn-icon">+</span>
                            <span class="btn-text">Add</span>
                        </button>
                    </div>
                    
                    <div class="quick-add-options">
                        <label class="option-label">
                            <input type="checkbox" id="enableReminder" onchange="toggleReminderInput()">
                            <span>🔔 Reminder</span>
                        </label>
                        <input type="number" id="reminderMinutes" placeholder="Minutes" 
                               style="display:none; width: 100px;" min="5" max="1440" step="5"
                               class="form-control">
                        
                        <label class="option-label">
                            <input type="checkbox" id="enableRecurring" onchange="toggleRecurringInput()">
                            <span>🔁 Repeat</span>
                        </label>
                        <select id="repeatRule" style="display:none; width: 120px;" class="form-control">
                            <option value="daily">Daily</option>
                            <option value="weekdays">Weekdays</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                        
                        <label class="option-label">
                            <input type="checkbox" id="focusMode">
                            <span>🎯 Focus Mode</span>
                        </label>
                    </div>
                </form>

                <!-- Week Stats -->
                <div class="frequent-items">
                    <div class="frequent-title">This Week's Progress:</div>
                    <div class="frequent-chips">
                        <div class="frequent-chip study-chip">
                            📚 Study: <?php echo $studyStats['done']; ?>/<?php echo $studyStats['total']; ?> 
                            (<?php echo floor($studyStats['minutes'] / 60); ?>h <?php echo $studyStats['minutes'] % 60; ?>m)
                            <?php if ($studyStats['rating']): ?>
                                <span class="chip-rating">⭐ <?php echo $studyStats['rating']; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="frequent-chip work-chip">
                            💼 Work: <?php echo $workStats['done']; ?>/<?php echo $workStats['total']; ?> 
                            (<?php echo floor($workStats['minutes'] / 60); ?>h <?php echo $workStats['minutes'] % 60; ?>m)
                            <?php if ($workStats['rating']): ?>
                                <span class="chip-rating">⭐ <?php echo $workStats['rating']; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="frequent-chip focus-chip">
                            🎯 Focus: <?php echo $focusStats['done']; ?>/<?php echo $focusStats['total']; ?> 
                            (<?php echo floor($focusStats['minutes'] / 60); ?>h <?php echo $focusStats['minutes'] % 60; ?>m)
                            <?php if ($focusStats['rating']): ?>
                                <span class="chip-rating">⭐ <?php echo $focusStats['rating']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bulk Actions Bar -->
        <div id="bulkActionsBar" class="bulk-actions-bar glass-card" style="display: none;">
            <div class="bulk-info">
                <span id="bulkSelectedCount">0</span> events selected
            </div>
            <div class="bulk-buttons">
                <button onclick="bulkMarkDone()" class="btn btn-success btn-sm">
                    <span class="btn-icon">✓</span>
                    <span>Mark Done</span>
                </button>
                <button onclick="showBulkTypeModal()" class="btn btn-secondary btn-sm">
                    <span class="btn-icon">📁</span>
                    <span>Change Type</span>
                </button>
                <button onclick="showBulkAssignModal()" class="btn btn-secondary btn-sm">
                    <span class="btn-icon">👤</span>
                    <span>Assign</span>
                </button>
                <button onclick="bulkDelete()" class="btn btn-danger btn-sm">
                    <span class="btn-icon">🗑️</span>
                    <span>Delete</span>
                </button>
                <button onclick="toggleBulkMode()" class="btn btn-secondary btn-sm">
                    <span class="btn-icon">✕</span>
                    <span>Cancel</span>
                </button>
            </div>
        </div>

        <!-- Schedule Events (Styled like notes grid) -->
        <div class="notes-section">
            
            <?php if (empty($events)): ?>
                <div class="empty-state glass-card">
                    <div class="empty-icon">📅</div>
                    <h2>No events scheduled for today</h2>
                    <p>Add events to plan your day and track your productivity</p>
                    <div class="empty-actions">
                        <button onclick="showQuickAdd()" class="btn btn-primary btn-lg">
                            <span class="btn-icon">+</span>
                            <span>Add First Event</span>
                        </button>
                    </div>
                </div>
                
            <?php else: ?>
                
                <!-- Progress Bar -->
                <div class="list-actions glass-card" style="margin-bottom: 24px;">
                    <div class="list-progress">
                        <?php 
                        $percentage = $totalEvents > 0 ? round(($doneEvents / $totalEvents) * 100) : 0;
                        ?>
                        <div class="progress-text">
                            <span class="progress-icon">✓</span>
                            <?php echo $doneEvents; ?> of <?php echo $totalEvents; ?> completed (<?php echo $percentage; ?>%)
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <?php if ($doneEvents > 0): ?>
                            <button onclick="clearDone()" class="btn btn-secondary btn-sm">
                                <span class="btn-icon">🗑️</span>
                                <span>Clear Done</span>
                            </button>
                        <?php endif; ?>
                        <button onclick="exportSchedule()" class="btn btn-secondary btn-sm">
                            <span class="btn-icon">📥</span>
                            <span>Export</span>
                        </button>
                    </div>
                </div>

                <!-- Events Grid (Styled like notes) -->
                <div class="notes-grid">
                    <?php foreach ($events as $event): ?>
                        <?php
                        $startTime = new DateTime($event['starts_at']);
                        $endTime = new DateTime($event['ends_at']);
                        $duration = $startTime->diff($endTime);
                        $totalMinutes = ($duration->h * 60) + $duration->i;
                        
                        $typeColor = $types[$event['kind']]['color'] ?? '#667eea';
                        ?>
                        
                        <div class="note-card event-card <?php echo $event['status']; ?> <?php echo $event['focus_mode'] ? 'focus-event' : ''; ?>" 
                             data-note-id="<?php echo $event['id']; ?>"
                             data-event-id="<?php echo $event['id']; ?>"
                             data-note-type="<?php echo $event['kind']; ?>"
                             data-start="<?php echo $event['starts_at']; ?>"
                             data-end="<?php echo $event['ends_at']; ?>"
                             style="background: <?php echo htmlspecialchars($typeColor); ?>;">
                            
                            <!-- Bulk Select (hidden by default) -->
                            <div class="bulk-select-checkbox" style="display: none;">
                                <input type="checkbox" class="bulk-checkbox" data-event-id="<?php echo $event['id']; ?>">
                            </div>

                            <!-- Header with pin and actions -->
                            <div class="note-header">
                                <div class="event-checkbox">
                                    <input 
                                        type="checkbox" 
                                        id="event_<?php echo $event['id']; ?>"
                                        <?php echo $event['status'] === 'done' ? 'checked' : ''; ?>
                                        onchange="toggleEvent(<?php echo $event['id']; ?>)"
                                        class="checkbox-round">
                                </div>
                                
                                <div class="note-actions">
                                    <?php if ($event['status'] === 'pending' && $event['focus_mode']): ?>
                                        <button onclick="startFocusSession(<?php echo $event['id']; ?>)" 
                                                class="note-action" 
                                                title="Start Focus">
                                            🎯
                                        </button>
                                    <?php endif; ?>
                                    <button onclick="editEvent(<?php echo $event['id']; ?>)" 
                                            class="note-action" 
                                            title="Edit">
                                        ✏️
                                    </button>
                                    <button onclick="duplicateEvent(<?php echo $event['id']; ?>)" 
                                            class="note-action" 
                                            title="Duplicate">
                                        📋
                                    </button>
                                    <button onclick="deleteEvent(<?php echo $event['id']; ?>)" 
                                            class="note-action" 
                                            title="Delete">
                                        🗑️
                                    </button>
                                </div>
                            </div>

                            <!-- Event Title -->
                            <div class="note-title event-title">
                                <?php echo htmlspecialchars($event['title']); ?>
                                <?php if ($event['focus_mode']): ?>
                                    <span class="focus-badge">🎯</span>
                                <?php endif; ?>
                                <?php if ($event['repeat_rule']): ?>
                                    <span class="repeat-badge">🔁</span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Event Details -->
                            <div class="event-details">
                                <div class="event-time">
                                    ⏰ <?php echo $startTime->format('g:i A'); ?> - <?php echo $endTime->format('g:i A'); ?>
                                </div>
                                
                                <div class="event-duration">
                                    ⏱️ <?php echo $duration->h > 0 ? $duration->h . 'h ' : ''; ?><?php echo $duration->i; ?>m
                                </div>
                                
                                <?php if ($event['reminder_minutes']): ?>
                                    <div class="event-reminder">
                                        🔔 <?php echo $event['reminder_minutes']; ?>m before
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($event['productivity_rating']): ?>
                                    <div class="event-rating">
                                        ⭐ <?php echo $event['productivity_rating']; ?>/5
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($event['notes']): ?>
                                <div class="note-body event-notes">
                                    <?php echo nl2br(htmlspecialchars(substr($event['notes'], 0, 100))); ?>
                                    <?php if (strlen($event['notes']) > 100): ?>...<?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Footer -->
                            <div class="note-footer event-footer">
                                <div class="note-author">
                                    <div class="author-avatar-mini" 
                                         style="background: <?php echo htmlspecialchars($event['avatar_color']); ?>">
                                        <?php echo strtoupper(substr($event['added_by_name'] ?? 'You', 0, 1)); ?>
                                    </div>
                                    <span><?php echo htmlspecialchars($event['added_by_name'] ?? 'You'); ?></span>
                                </div>
                                <?php if ($event['assigned_to_name']): ?>
                                    <div class="event-assigned">
                                        → 
                                        <div class="author-avatar-mini" 
                                             style="background: <?php echo htmlspecialchars($event['assigned_color']); ?>">
                                            <?php echo strtoupper(substr($event['assigned_to_name'], 0, 1)); ?>
                                        </div>
                                        <span><?php echo htmlspecialchars($event['assigned_to_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- ALL MODALS GO HERE - keeping existing modal code -->
<!-- ... (keep all existing modals: editEventModal, focusSessionModal, etc.) ... -->

<script>
window.ScheduleApp = {
    userId: <?php echo $user['id']; ?>,
    familyId: <?php echo $user['family_id']; ?>,
    userName: <?php echo json_encode($user['full_name']); ?>,
    selectedDate: '<?php echo $selectedDate; ?>',
    viewMode: '<?php echo $viewMode; ?>',
    allEvents: <?php echo json_encode($events); ?>,
    types: <?php echo json_encode($types); ?>,
    familyMembers: <?php echo json_encode($familyMembers); ?>,
    activeFocusSession: <?php echo $activeFocusSession ? json_encode($activeFocusSession) : 'null'; ?>,
    bulkMode: false,
    selectedItems: new Set(),
    
    API: {
        events: '/schedule/api/events.php'
    }
};
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>