<?php
/**
 * ============================================
 * RELATIVES - ENHANCED SCHEDULE
 * With Time Blocking, Focus Mode & Productivity
 * ============================================
 */

require_once __DIR__ . '/../core/session_boot.php';

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

// Get events for selected date - USING UNIFIED EVENTS TABLE
$events = [];
try {
    $stmt = $db->prepare("
        SELECT
            e.*,
            e.recurrence_rule as repeat_rule,
            u.full_name as added_by_name, u.avatar_color,
            a.full_name as assigned_to_name, a.avatar_color as assigned_color
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
    error_log("Schedule - Get events error: " . $e->getMessage());
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

// Type metadata - Schedule focuses on activities/tasks
$types = [
    'work' => ['icon' => '💼', 'name' => 'Work', 'color' => '#43e97b', 'desc' => 'Professional tasks'],
    'study' => ['icon' => '📚', 'name' => 'Study', 'color' => '#667eea', 'desc' => 'Learning & studying'],
    'church' => ['icon' => '⛪', 'name' => 'Church', 'color' => '#9b59b6', 'desc' => 'Church activities'],
    'event' => ['icon' => '📅', 'name' => 'Event', 'color' => '#3498db', 'desc' => 'General events'],
    'focus' => ['icon' => '🎯', 'name' => 'Focus', 'color' => '#4facfe', 'desc' => 'Deep work mode'],
    'break' => ['icon' => '☕', 'name' => 'Break', 'color' => '#feca57', 'desc' => 'Rest & recharge'],
    'todo' => ['icon' => '✅', 'name' => 'To-Do', 'color' => '#f093fb', 'desc' => 'General tasks']
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
        FROM events
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
        SELECT * FROM events
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
$pageCSS = [
    '/shared/css/aurora.css',
    '/schedule/css/schedule.css'
];
$pageJS = ['/schedule/js/schedule.js'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<!-- Aurora background -->
<div class="aurora" aria-hidden="true">
    <div class="aurora-blob aurora-a"></div>
    <div class="aurora-blob aurora-b"></div>
    <div class="aurora-blob aurora-c"></div>
    <div class="stars stars-a"></div>
    <div class="stars stars-b"></div>
    <div class="shooting-star"></div>
    <div class="aurora-grid"></div>
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
    <div class="hub">

        <!-- Compact page header -->
        <header class="page-head" style="--glow:#f472b6; --page-glow: rgba(244, 114, 182, 0.13)">
            <span class="ph-glyph" aria-hidden="true">⏰</span>
            <div class="ph-titles">
                <h1>Schedule</h1>
                <p class="ph-sub"><?php echo $doneEvents; ?>/<?php echo $totalEvents; ?> done · <?php echo date('D, j M', strtotime($selectedDate)); ?></p>
            </div>
            <div class="ph-actions">
                <button onclick="showQuickAdd()" class="pill-btn primary">
                    <span aria-hidden="true">＋</span> Add event
                </button>
                <button onclick="startFocusMode()" class="pill-btn">
                    <span aria-hidden="true">🎯</span> Focus
                </button>
                <button onclick="toggleBulkMode()" class="pill-btn" id="bulkModeBtn">
                    <span aria-hidden="true">☑️</span> Select
                </button>
                <button onclick="showTemplates()" class="pill-btn">
                    <span aria-hidden="true">📋</span> Templates
                </button>
                <button onclick="showAnalytics()" class="pill-btn">
                    <span aria-hidden="true">📊</span> Analytics
                </button>
                <button onclick="exportSchedule()" class="pill-btn">
                    <span aria-hidden="true">📤</span> Export
                </button>
            </div>
        </header>

        <!-- View Mode Switcher (Styled like notes filter) -->
        <div class="search-filter-section">
            <div class="filter-buttons">
                <button onclick="changeView('day')" class="filter-btn <?php echo $viewMode === 'day' ? 'active' : ''; ?>">
                    <span>📅</span> Day
                </button>
                <button onclick="changeView('week')" class="filter-btn <?php echo $viewMode === 'week' ? 'active' : ''; ?>">
                    <span>🗓️</span> Week
                </button>
                <a href="/schedule/timeline/?date=<?php echo $selectedDate; ?>" class="filter-btn">
                    <span>⏱️</span> Timeline
                </a>
            </div>
        </div>

        <!-- Date Navigator (Responsive with prev/next on sides) -->
        <div class="date-navigator">
            <button class="nav-arrow nav-prev" onclick="changeDate(-1)" title="Previous Day">
                <span class="arrow-icon">←</span>
                <span class="arrow-text">Prev</span>
            </button>

            <div class="date-center" onclick="goToToday()">
                <div class="date-display <?php echo $selectedDate === date('Y-m-d') ? 'today' : ''; ?>">
                    <span class="date-icon">📅</span>
                    <span class="date-value"><?php echo date('M j', strtotime($selectedDate)); ?></span>
                    <span class="date-day"><?php echo date('l', strtotime($selectedDate)); ?></span>
                </div>
                <span class="date-events"><?php echo $totalEvents; ?> events</span>
            </div>

            <button class="nav-arrow nav-next" onclick="changeDate(1)" title="Next Day">
                <span class="arrow-text">Next</span>
                <span class="arrow-icon">→</span>
            </button>
        </div>

        <!-- Date Picker Button (Separate) -->
        <div class="date-picker-row">
            <button class="date-picker-btn" onclick="showDatePicker()">
                <span>🗓️</span>
                <span>Pick Date</span>
            </button>
        </div>

        <!-- Week Stats Bar (Compact) -->
        <div class="week-stats-bar glass-card">
            <div class="week-stats-title">This Week:</div>
            <div class="week-stats-chips">
                <div class="stat-chip study-chip">
                    📚 <?php echo $studyStats['done']; ?>/<?php echo $studyStats['total']; ?>
                </div>
                <div class="stat-chip work-chip">
                    💼 <?php echo $workStats['done']; ?>/<?php echo $workStats['total']; ?>
                </div>
                <div class="stat-chip focus-chip">
                    🎯 <?php echo $focusStats['done']; ?>/<?php echo $focusStats['total']; ?>
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
                                        <img src="<?php echo avatarUrl($event['created_by']); ?>"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                             style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                                        <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center;"><?php echo strtoupper(substr($event['added_by_name'] ?? 'You', 0, 1)); ?></span>
                                    </div>
                                    <span><?php echo htmlspecialchars($event['added_by_name'] ?? 'You'); ?></span>
                                </div>
                                <?php if ($event['assigned_to_name']): ?>
                                    <div class="event-assigned">
                                        →
                                        <div class="author-avatar-mini"
                                             style="background: <?php echo htmlspecialchars($event['assigned_color']); ?>">
                                            <img src="<?php echo avatarUrl($event['assigned_to']); ?>"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                                 style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                                            <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center;"><?php echo strtoupper(substr($event['assigned_to_name'], 0, 1)); ?></span>
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

<!-- Add Event Modal -->
<div id="addEventModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>➕ Add Event</h2>
            <button onclick="closeModal('addEventModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="quickAddForm">
                <div class="form-group">
                    <label>Event Type</label>
                    <select id="eventType" class="form-control">
                        <optgroup label="⏰ Schedule Tasks">
                            <option value="work">💼 Work</option>
                            <option value="study">📚 Study</option>
                            <option value="church">⛪ Church</option>
                            <option value="event">📅 Event</option>
                            <option value="focus">🎯 Focus</option>
                            <option value="break">☕ Break</option>
                            <option value="todo">✅ To-Do</option>
                        </optgroup>
                        <optgroup label="📅 Calendar Events">
                            <option value="birthday">🎂 Birthday</option>
                            <option value="anniversary">💍 Anniversary</option>
                            <option value="holiday">🎉 Holiday</option>
                            <option value="family_event">👨‍👩‍👧‍👦 Family Event</option>
                            <option value="date">❤️ Special Date</option>
                            <option value="reminder">🔔 Reminder</option>
                        </optgroup>
                    </select>
                </div>

                <div class="form-group">
                    <label>Priority</label>
                    <select id="eventPriority" class="form-control">
                        <option value="low">🟢 Low</option>
                        <option value="medium" selected>🟡 Medium</option>
                        <option value="high">🔴 High</option>
                        <option value="urgent">🚨 Urgent</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>What do you want to work on?</label>
                    <input
                        type="text"
                        id="eventTitle"
                        class="form-control"
                        placeholder="e.g., Study math, Work on project..."
                        autocomplete="off"
                        required>
                </div>

                <div class="form-group">
                    <label>Date</label>
                    <input
                        type="date"
                        id="eventDate"
                        class="form-control"
                        value="<?php echo $selectedDate; ?>"
                        required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Start Time</label>
                        <input
                            type="time"
                            id="eventStart"
                            class="form-control"
                            value="<?php echo date('H:00'); ?>"
                            required>
                    </div>
                    <div class="form-group">
                        <label>End Time</label>
                        <input
                            type="time"
                            id="eventEnd"
                            class="form-control"
                            value="<?php echo date('H:00', strtotime('+1 hour')); ?>"
                            required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Color</label>
                    <div class="color-picker">
                        <input type="radio" name="eventColor" value="#667eea" id="add_color1" checked>
                        <label for="add_color1" class="color-option" style="background: #667eea;" title="Purple"></label>

                        <input type="radio" name="eventColor" value="#43e97b" id="add_color2">
                        <label for="add_color2" class="color-option" style="background: #43e97b;" title="Green"></label>

                        <input type="radio" name="eventColor" value="#f093fb" id="add_color3">
                        <label for="add_color3" class="color-option" style="background: #f093fb;" title="Pink"></label>

                        <input type="radio" name="eventColor" value="#feca57" id="add_color4">
                        <label for="add_color4" class="color-option" style="background: #feca57;" title="Yellow"></label>

                        <input type="radio" name="eventColor" value="#4facfe" id="add_color5">
                        <label for="add_color5" class="color-option" style="background: #4facfe;" title="Blue"></label>

                        <input type="radio" name="eventColor" value="#e74c3c" id="add_color6">
                        <label for="add_color6" class="color-option" style="background: #e74c3c;" title="Red"></label>
                    </div>
                </div>

                <div class="form-options-group">
                    <label class="option-toggle">
                        <input type="checkbox" id="enableReminder" onchange="toggleReminderInput()">
                        <span class="toggle-label">🔔 Reminder</span>
                    </label>
                    <input type="number" id="reminderMinutes" placeholder="Minutes before"
                           style="display:none;" min="5" max="1440" step="5"
                           class="form-control form-control-inline">
                </div>

                <div class="form-options-group">
                    <label class="option-toggle">
                        <input type="checkbox" id="enableRecurring" onchange="toggleRecurringInput()">
                        <span class="toggle-label">🔁 Repeat</span>
                    </label>
                    <select id="repeatRule" style="display:none;" class="form-control form-control-inline">
                        <option value="daily">Daily</option>
                        <option value="weekdays">Weekdays</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>

                <div class="form-options-group">
                    <label class="option-toggle">
                        <input type="checkbox" id="focusMode">
                        <span class="toggle-label">🎯 Focus Mode</span>
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" id="addEventSubmitBtn" onclick="saveScheduleEvent()" class="btn btn-primary">Add Event</button>
                    <button type="button" onclick="closeModal('addEventModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Event Modal -->
<div id="editEventModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>✏️ Edit Event</h2>
            <button onclick="closeModal('editEventModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="editEventForm">
                <input type="hidden" id="editEventId">

                <div class="form-group">
                    <label>Title</label>
                    <input type="text" id="editEventTitle" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Date</label>
                    <input type="date" id="editEventDate" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Start Time</label>
                        <input type="time" id="editEventStart" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>End Time</label>
                        <input type="time" id="editEventEnd" class="form-control" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Type</label>
                    <select id="editEventType" class="form-control">
                        <optgroup label="⏰ Schedule Tasks">
                            <option value="work">💼 Work</option>
                            <option value="study">📚 Study</option>
                            <option value="church">⛪ Church</option>
                            <option value="event">📅 Event</option>
                            <option value="focus">🎯 Focus</option>
                            <option value="break">☕ Break</option>
                            <option value="todo">✅ To-Do</option>
                        </optgroup>
                        <optgroup label="📅 Calendar Events">
                            <option value="birthday">🎂 Birthday</option>
                            <option value="anniversary">💍 Anniversary</option>
                            <option value="holiday">🎉 Holiday</option>
                            <option value="family_event">👨‍👩‍👧‍👦 Family Event</option>
                            <option value="date">❤️ Special Date</option>
                            <option value="reminder">🔔 Reminder</option>
                        </optgroup>
                    </select>
                </div>

                <div class="form-group">
                    <label>Priority</label>
                    <select id="editEventPriority" class="form-control">
                        <option value="low">🟢 Low</option>
                        <option value="medium">🟡 Medium</option>
                        <option value="high">🔴 High</option>
                        <option value="urgent">🚨 Urgent</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Assign To</label>
                    <select id="editEventAssign" class="form-control">
                        <option value="">Unassigned</option>
                        <?php foreach ($familyMembers as $member): ?>
                            <option value="<?php echo $member['id']; ?>">
                                <?php echo htmlspecialchars($member['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="editEventNotes" class="form-control" rows="3" placeholder="Optional notes..."></textarea>
                </div>

                <div class="form-group">
                    <label>Color</label>
                    <div class="color-picker">
                        <input type="radio" name="editEventColor" value="#667eea" id="edit_color1" checked>
                        <label for="edit_color1" class="color-option" style="background: #667eea;" title="Purple"></label>

                        <input type="radio" name="editEventColor" value="#43e97b" id="edit_color2">
                        <label for="edit_color2" class="color-option" style="background: #43e97b;" title="Green"></label>

                        <input type="radio" name="editEventColor" value="#f093fb" id="edit_color3">
                        <label for="edit_color3" class="color-option" style="background: #f093fb;" title="Pink"></label>

                        <input type="radio" name="editEventColor" value="#feca57" id="edit_color4">
                        <label for="edit_color4" class="color-option" style="background: #feca57;" title="Yellow"></label>

                        <input type="radio" name="editEventColor" value="#4facfe" id="edit_color5">
                        <label for="edit_color5" class="color-option" style="background: #4facfe;" title="Blue"></label>

                        <input type="radio" name="editEventColor" value="#e74c3c" id="edit_color6">
                        <label for="edit_color6" class="color-option" style="background: #e74c3c;" title="Red"></label>
                    </div>
                </div>

                <div class="form-options-group">
                    <label class="option-toggle">
                        <input type="checkbox" id="editEnableReminder" onchange="toggleEditReminderInput()">
                        <span class="toggle-label">🔔 Reminder</span>
                    </label>
                    <input type="number" id="editReminderMinutes" placeholder="Minutes before"
                           style="display:none;" min="5" max="1440" step="5"
                           class="form-control form-control-inline">
                </div>

                <div class="form-options-group">
                    <label class="option-toggle">
                        <input type="checkbox" id="editEnableRecurring" onchange="toggleEditRecurringInput()">
                        <span class="toggle-label">🔁 Repeat</span>
                    </label>
                    <select id="editRepeatRule" style="display:none;" class="form-control form-control-inline">
                        <option value="daily">Daily</option>
                        <option value="weekdays">Weekdays</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>

                <div class="form-options-group">
                    <label class="option-toggle">
                        <input type="checkbox" id="editFocusMode">
                        <span class="toggle-label">🎯 Focus Mode</span>
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" id="editEventSubmitBtn" onclick="saveScheduleChanges()" class="btn btn-primary">Save Changes</button>
                    <button type="button" onclick="closeModal('editEventModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Date Picker Modal -->
<div id="datePickerModal" class="modal">
    <div class="modal-content modal-small">
        <div class="modal-header">
            <h2>🗓️ Pick Date</h2>
            <button onclick="closeModal('datePickerModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Select Date</label>
                <input type="date" id="datePickerInput" class="form-control" value="<?php echo $selectedDate; ?>">
            </div>
            <div class="modal-actions">
                <button onclick="goToPickedDate()" class="btn btn-primary">Go to Date</button>
                <button onclick="closeModal('datePickerModal')" class="btn btn-secondary">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- Focus Session Modal -->
<div id="focusSessionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>🎯 Start Focus Session</h2>
            <button onclick="closeModal('focusSessionModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form onsubmit="createFocusSession(event)">
                <div class="form-group">
                    <label>What will you focus on?</label>
                    <input type="text" id="focusTitle" class="form-control"
                           placeholder="e.g., Deep work on project" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Duration (minutes)</label>
                        <select id="focusDuration" class="form-control">
                            <option value="25">25 min (Pomodoro)</option>
                            <option value="45">45 min</option>
                            <option value="60" selected>60 min</option>
                            <option value="90">90 min</option>
                            <option value="120">120 min</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Start Time</label>
                        <input type="time" id="focusStart" class="form-control"
                               value="<?php echo date('H:i'); ?>">
                    </div>
                </div>

                <div class="form-options-group">
                    <label class="option-toggle">
                        <input type="checkbox" id="focusBlockNotifications" checked>
                        <span class="toggle-label">🔕 Block Notifications</span>
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Start Focus</button>
                    <button type="button" onclick="closeModal('focusSessionModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Rating Modal -->
<div id="ratingModal" class="modal">
    <div class="modal-content modal-small">
        <div class="modal-header">
            <h2>⭐ Rate Your Session</h2>
            <button onclick="closeModal('ratingModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="ratingEventId">
            <input type="hidden" id="selectedRating">
            <p style="text-align: center; margin-bottom: 20px; color: rgba(255,255,255,0.8);">
                How productive was this session?
            </p>
            <div class="rating-stars">
                <button type="button" onclick="selectRating(1)" class="star-btn">⭐</button>
                <button type="button" onclick="selectRating(2)" class="star-btn">⭐</button>
                <button type="button" onclick="selectRating(3)" class="star-btn">⭐</button>
                <button type="button" onclick="selectRating(4)" class="star-btn">⭐</button>
                <button type="button" onclick="selectRating(5)" class="star-btn">⭐</button>
            </div>
        </div>
    </div>
</div>

<!-- Analytics Modal -->
<div id="analyticsModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h2>📊 Productivity Analytics</h2>
            <button onclick="closeModal('analyticsModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="analyticsContent">
                <div class="analytics-loading">Loading analytics...</div>
            </div>
        </div>
    </div>
</div>

<!-- Templates Modal -->
<div id="templatesModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>📋 Schedule Templates</h2>
            <button onclick="closeModal('templatesModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="templatesContent">
                <div class="templates-loading">Loading templates...</div>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Type Modal -->
<div id="bulkTypeModal" class="modal">
    <div class="modal-content modal-small">
        <div class="modal-header">
            <h2>📁 Change Type</h2>
            <button onclick="closeModal('bulkTypeModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom: 16px;">Select new type for selected events:</p>
            <div class="type-grid">
                <button onclick="applyBulkType('work')" class="type-btn work">💼 Work</button>
                <button onclick="applyBulkType('study')" class="type-btn study">📚 Study</button>
                <button onclick="applyBulkType('church')" class="type-btn church">⛪ Church</button>
                <button onclick="applyBulkType('event')" class="type-btn event">📅 Event</button>
                <button onclick="applyBulkType('focus')" class="type-btn focus">🎯 Focus</button>
                <button onclick="applyBulkType('break')" class="type-btn break">☕ Break</button>
                <button onclick="applyBulkType('todo')" class="type-btn todo">✅ To-Do</button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Assign Modal -->
<div id="bulkAssignModal" class="modal">
    <div class="modal-content modal-small">
        <div class="modal-header">
            <h2>👤 Assign To</h2>
            <button onclick="closeModal('bulkAssignModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom: 16px;">Assign selected events to:</p>
            <div class="assign-grid">
                <?php foreach ($familyMembers as $member): ?>
                    <button onclick="applyBulkAssign(<?php echo $member['id']; ?>)" class="assign-btn">
                        <div class="assign-avatar" style="background: <?php echo htmlspecialchars($member['avatar_color']); ?>">
                            <img src="<?php echo avatarUrl($member['id']); ?>"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                 style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                            <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center;"><?php echo strtoupper(substr($member['full_name'], 0, 1)); ?></span>
                        </div>
                        <span><?php echo htmlspecialchars($member['full_name']); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div id="exportModal" class="modal">
    <div class="modal-content modal-small">
        <div class="modal-header">
            <h2>📤 Export Schedule</h2>
            <button onclick="closeModal('exportModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom: 16px;">Choose export format:</p>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <button onclick="exportAsPDF()" class="btn btn-primary" style="display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px;">
                    <span style="font-size: 1.25rem;">📄</span>
                    <span>Export as PDF</span>
                </button>
                <button onclick="exportAsCSV()" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px;">
                    <span style="font-size: 1.25rem;">📊</span>
                    <span>Export as CSV</span>
                </button>
                <button onclick="exportAsText()" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px;">
                    <span style="font-size: 1.25rem;">📝</span>
                    <span>Export as Text</span>
                </button>
                <button onclick="shareToWhatsApp()" class="btn btn-success" style="display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px; background: #25D366;">
                    <span style="font-size: 1.25rem;">💬</span>
                    <span>Share to WhatsApp</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Week View Modal - Fullscreen -->
<div id="weekViewModal" class="modal">
    <div class="modal-content modal-week-fullscreen">
        <div class="modal-header">
            <h2>🗓️ Week View</h2>
            <button onclick="closeModal('weekViewModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="weekViewContent">
                <div class="week-loading">Loading week view...</div>
            </div>
        </div>
    </div>
</div>

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
<script type="application/json" id="scheduleEventsData"><?php echo json_encode($events); ?></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>