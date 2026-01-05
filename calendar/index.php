<?php
/**
 * RELATIVES - CALENDAR - WITH NOTIFICATIONS
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/NotificationManager.php';
require_once __DIR__ . '/../core/NotificationTriggers.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    header('Location: /login.php');
    exit;
}

// Get current month/year or selected
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('m');

// Validate
$year = (int)$year;
$month = (int)$month;

if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

$currentDate = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
$monthName = date('F Y', strtotime($currentDate));

// Voice prefill detection
$voicePrefillContent = '';
if (isset($_GET['new']) && $_GET['new'] == '1' && isset($_GET['content'])) {
    $voicePrefillContent = trim($_GET['content']);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $triggers = new NotificationTriggers($db);
        
        switch ($_POST['action']) {
            
            case 'create_event':
                $title = trim($_POST['title'] ?? '');
                $notes = trim($_POST['notes'] ?? '');
                $startsAt = $_POST['starts_at'] ?? '';
                $endsAt = $_POST['ends_at'] ?? '';
                $allDay = (int)($_POST['all_day'] ?? 0);
                $color = $_POST['color'] ?? '#3498db';
                $reminderMinutes = (int)($_POST['reminder_minutes'] ?? 0);
                $kind = $_POST['kind'] ?? 'other';
                $recurrenceRule = $_POST['recurrence_rule'] ?? null;

                if (empty($title)) {
                    throw new Exception('Event title is required');
                }

                if (empty($startsAt)) {
                    throw new Exception('Start date/time is required');
                }

                // Validate kind - Calendar focuses on dates/occasions
                $validKinds = ['birthday', 'anniversary', 'holiday', 'family_event', 'date', 'reminder', 'event', 'other'];
                if (!in_array($kind, $validKinds)) {
                    $kind = 'event';
                }

                $stmt = $db->prepare("
                    INSERT INTO events
                    (family_id, user_id, created_by, kind, title, notes, starts_at, ends_at,
                     all_day, color, status, reminder_minutes, recurrence_rule)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
                ");
                $stmt->execute([
                    $user['family_id'],
                    $user['id'],
                    $user['id'],
                    $kind,
                    $title,
                    $notes,
                    $startsAt,
                    $endsAt ?: $startsAt,
                    $allDay,
                    $color,
                    $reminderMinutes > 0 ? $reminderMinutes : null,
                    $recurrenceRule ?: null
                ]);
                $eventId = $db->lastInsertId();

                // Handle recurring events
                if ($recurrenceRule && in_array($recurrenceRule, ['daily', 'weekly', 'weekdays', 'monthly', 'yearly'])) {
                    $occurrences = $recurrenceRule === 'yearly' ? 5 : 10;
                    $baseDate = new DateTime($startsAt);
                    $baseEndDate = new DateTime($endsAt ?: $startsAt);
                    $duration = $baseEndDate->getTimestamp() - $baseDate->getTimestamp();

                    for ($i = 1; $i <= $occurrences; $i++) {
                        $nextDate = clone $baseDate;

                        switch ($recurrenceRule) {
                            case 'daily':
                                $nextDate->modify("+{$i} day");
                                break;
                            case 'weekdays':
                                $daysAdded = 0;
                                $tempDate = clone $baseDate;
                                while ($daysAdded < $i) {
                                    $tempDate->modify('+1 day');
                                    if ($tempDate->format('N') < 6) {
                                        $daysAdded++;
                                    }
                                }
                                $nextDate = $tempDate;
                                break;
                            case 'weekly':
                                $nextDate->modify("+{$i} week");
                                break;
                            case 'monthly':
                                $nextDate->modify("+{$i} month");
                                break;
                            case 'yearly':
                                $nextDate->modify("+{$i} year");
                                break;
                        }

                        $newStartsAt = $nextDate->format('Y-m-d H:i:s');
                        $newEndsAt = (clone $nextDate)->modify("+{$duration} seconds")->format('Y-m-d H:i:s');

                        $stmt = $db->prepare("
                            INSERT INTO events
                            (family_id, user_id, created_by, kind, title, notes, starts_at, ends_at,
                             all_day, color, status, reminder_minutes, recurrence_rule, recurrence_parent_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
                        ");
                        $stmt->execute([
                            $user['family_id'],
                            $user['id'],
                            $user['id'],
                            $kind,
                            $title,
                            $notes,
                            $newStartsAt,
                            $newEndsAt,
                            $allDay,
                            $color,
                            $reminderMinutes > 0 ? $reminderMinutes : null,
                            $recurrenceRule,
                            $eventId
                        ]);
                    }
                }
                
                // SEND NOTIFICATION
                try {
                    $triggers->onEventCreated(
                        $eventId,
                        $user['id'],
                        $user['family_id'],
                        $title,
                        $startsAt
                    );
                } catch (Exception $e) {
                    error_log('Event notification error: ' . $e->getMessage());
                }
                
                echo json_encode(['success' => true, 'event_id' => $eventId]);
                exit;
    
            case 'update_event':
                $eventId = (int)$_POST['event_id'];
                $title = trim($_POST['title'] ?? '');
                $notes = trim($_POST['notes'] ?? '');
                $eventLocation = trim($_POST['location'] ?? '');
                $startsAt = $_POST['starts_at'] ?? '';
                $endsAt = $_POST['ends_at'] ?? '';
                $allDay = (int)($_POST['all_day'] ?? 0);
                $color = $_POST['color'] ?? '#3498db';
                $reminderMinutes = isset($_POST['reminder_minutes']) ? (int)$_POST['reminder_minutes'] : null;
                $kind = $_POST['kind'] ?? null;
                $recurrenceRule = $_POST['recurrence_rule'] ?? null;

                // Get original event for change detection
                $stmt = $db->prepare("
                    SELECT title, starts_at, ends_at, kind, reminder_minutes, recurrence_rule
                    FROM events
                    WHERE id = ? AND family_id = ?
                ");
                $stmt->execute([$eventId, $user['family_id']]);
                $originalEvent = $stmt->fetch(PDO::FETCH_ASSOC);

                // Use original values if not provided
                if ($kind === null) {
                    $kind = $originalEvent['kind'] ?? 'other';
                }

                $stmt = $db->prepare("
                    UPDATE events
                    SET title = ?, notes = ?, location = ?, starts_at = ?, ends_at = ?,
                        all_day = ?, color = ?, reminder_minutes = ?,
                        kind = ?, recurrence_rule = ?, updated_at = NOW()
                    WHERE id = ? AND family_id = ?
                ");
                $stmt->execute([
                    $title,
                    $notes,
                    $eventLocation,
                    $startsAt,
                    $endsAt ?: $startsAt,
                    $allDay,
                    $color,
                    $reminderMinutes > 0 ? $reminderMinutes : null,
                    $kind,
                    $recurrenceRule ?: null,
                    $eventId,
                    $user['family_id']
                ]);
                
                // SEND NOTIFICATION IF CHANGED
                if ($originalEvent) {
                    $changes = [];
                    if ($originalEvent['title'] !== $title) {
                        $changes[] = 'title';
                    }
                    if ($originalEvent['starts_at'] !== $startsAt) {
                        $changes[] = 'time';
                    }
                    
                    if (!empty($changes)) {
                        try {
                            $triggers->onEventUpdated(
                                $eventId,
                                $user['id'],
                                $user['family_id'],
                                $title,
                                implode(', ', $changes) . ' changed'
                            );
                        } catch (Exception $e) {
                            error_log('Event update notification error: ' . $e->getMessage());
                        }
                    }
                }
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'delete_event':
                $eventId = (int)$_POST['event_id'];
                
                $stmt = $db->prepare("DELETE FROM events WHERE id = ? AND family_id = ?");
                $stmt->execute([$eventId, $user['family_id']]);
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'get_events_for_month':
                $requestedYear = (int)$_POST['year'];
                $requestedMonth = (int)$_POST['month'];

                $startDate = "$requestedYear-" . str_pad($requestedMonth, 2, '0', STR_PAD_LEFT) . "-01";
                $endDate = date('Y-m-t', strtotime($startDate));

                $stmt = $db->prepare("
                    SELECT e.id, e.family_id, e.user_id, e.kind, e.title, e.notes,
                           e.starts_at, e.ends_at, e.all_day, e.color, e.status,
                           e.reminder_minutes, e.created_at, e.updated_at,
                           u.full_name, u.avatar_color, 'events' as source
                    FROM events e
                    LEFT JOIN users u ON e.user_id = u.id
                    WHERE e.family_id = ?
                      AND DATE(e.starts_at) >= ?
                      AND DATE(e.starts_at) <= ?
                      AND e.status != 'cancelled'
                    ORDER BY e.starts_at ASC
                ");
                $stmt->execute([$user['family_id'], $startDate, $endDate]);
                $events = $stmt->fetchAll();

                echo json_encode(['success' => true, 'events' => $events]);
                exit;
                
            case 'get_events_for_range':
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';

                if (empty($startDate) || empty($endDate)) {
                    throw new Exception('Date range required');
                }

                $stmt = $db->prepare("
                    SELECT e.id, e.family_id, e.user_id, e.kind, e.title, e.notes,
                           e.starts_at, e.ends_at, e.all_day, e.color, e.status,
                           e.reminder_minutes, e.created_at, e.updated_at,
                           u.full_name, u.avatar_color, 'events' as source
                    FROM events e
                    LEFT JOIN users u ON e.user_id = u.id
                    WHERE e.family_id = ?
                      AND DATE(e.starts_at) >= ?
                      AND DATE(e.starts_at) <= ?
                      AND e.status != 'cancelled'
                    ORDER BY e.starts_at ASC
                ");
                $stmt->execute([$user['family_id'], $startDate, $endDate]);
                $events = $stmt->fetchAll();

                echo json_encode(['success' => true, 'events' => $events]);
                exit;
                
            case 'get_events_for_day':
                $date = $_POST['date'] ?? '';

                if (empty($date)) {
                    throw new Exception('Date required');
                }

                $stmt = $db->prepare("
                    SELECT e.id, e.family_id, e.user_id, e.kind, e.title, e.notes,
                           e.starts_at, e.ends_at, e.all_day, e.color, e.status,
                           e.reminder_minutes, e.created_at, e.updated_at,
                           u.full_name, u.avatar_color, 'events' as source
                    FROM events e
                    LEFT JOIN users u ON e.user_id = u.id
                    WHERE e.family_id = ?
                      AND DATE(e.starts_at) = ?
                      AND e.status != 'cancelled'
                    ORDER BY e.starts_at ASC
                ");
                $stmt->execute([$user['family_id'], $date]);
                $events = $stmt->fetchAll();

                echo json_encode(['success' => true, 'events' => $events]);
                exit;
                
            case 'move_event':
                $eventId = (int)$_POST['event_id'];
                $newDate = $_POST['new_date'] ?? '';
                
                if (empty($newDate)) {
                    throw new Exception('New date required');
                }
                
                $stmt = $db->prepare("
                    SELECT starts_at, ends_at 
                    FROM events 
                    WHERE id = ? AND family_id = ?
                ");
                $stmt->execute([$eventId, $user['family_id']]);
                $event = $stmt->fetch();
                
                if (!$event) {
                    throw new Exception('Event not found');
                }
                
                $oldDate = date('Y-m-d', strtotime($event['starts_at']));
                $oldTime = date('H:i:s', strtotime($event['starts_at']));
                $endTime = date('H:i:s', strtotime($event['ends_at']));
                
                $newStartsAt = "$newDate $oldTime";
                $newEndsAt = "$newDate $endTime";
                
                $stmt = $db->prepare("
                    UPDATE events 
                    SET starts_at = ?, ends_at = ?, updated_at = NOW()
                    WHERE id = ? AND family_id = ?
                ");
                $stmt->execute([$newStartsAt, $newEndsAt, $eventId, $user['family_id']]);
                
                echo json_encode(['success' => true]);
                exit;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Get events for current month (unified events table)
$startDate = date('Y-m-01', strtotime($currentDate));
$endDate = date('Y-m-t', strtotime($currentDate));

$stmt = $db->prepare("
    SELECT e.id, e.family_id, e.user_id, e.kind, e.title, e.notes,
           e.starts_at, e.ends_at, e.all_day, e.color, e.status,
           e.reminder_minutes, e.created_at, e.updated_at,
           u.full_name, u.avatar_color, 'events' as source
    FROM events e
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.family_id = ?
      AND DATE(e.starts_at) >= ?
      AND DATE(e.starts_at) <= ?
      AND e.status != 'cancelled'
    ORDER BY e.starts_at ASC
");
$stmt->execute([$user['family_id'], $startDate, $endDate]);
$events = $stmt->fetchAll();

// Get upcoming events (next 7 days - unified events table)
$stmt = $db->prepare("
    SELECT e.id, e.family_id, e.user_id, e.kind, e.title, e.notes,
           e.starts_at, e.ends_at, e.all_day, e.color, e.status,
           e.reminder_minutes, u.full_name, u.avatar_color, 'events' as source
    FROM events e
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.family_id = ?
      AND e.starts_at >= NOW()
      AND e.starts_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
      AND e.status NOT IN ('cancelled', 'done')
    ORDER BY e.starts_at ASC
    LIMIT 10
");
$stmt->execute([$user['family_id']]);
$upcomingEvents = $stmt->fetchAll();

// Calendar helpers
function getCalendarDays($year, $month) {
    $firstDay = mktime(0, 0, 0, $month, 1, $year);
    $daysInMonth = date('t', $firstDay);
    $dayOfWeek = date('w', $firstDay);
    
    $days = [];
    
    $prevMonth = $month - 1;
    $prevYear = $year;
    if ($prevMonth < 1) {
        $prevMonth = 12;
        $prevYear--;
    }
    $daysInPrevMonth = date('t', mktime(0, 0, 0, $prevMonth, 1, $prevYear));
    
    for ($i = $dayOfWeek - 1; $i >= 0; $i--) {
        $days[] = [
            'day' => $daysInPrevMonth - $i,
            'month' => $prevMonth,
            'year' => $prevYear,
            'isCurrentMonth' => false
        ];
    }
    
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $days[] = [
            'day' => $day,
            'month' => $month,
            'year' => $year,
            'isCurrentMonth' => true
        ];
    }
    
    $remainingDays = 42 - count($days);
    $nextMonth = $month + 1;
    $nextYear = $year;
    if ($nextMonth > 12) {
        $nextMonth = 1;
        $nextYear++;
    }
    
    for ($day = 1; $day <= $remainingDays; $day++) {
        $days[] = [
            'day' => $day,
            'month' => $nextMonth,
            'year' => $nextYear,
            'isCurrentMonth' => false
        ];
    }
    
    return $days;
}

$calendarDays = getCalendarDays($year, $month);

// Group events by date
$eventsByDate = [];
foreach ($events as $event) {
    $date = date('Y-m-d', strtotime($event['starts_at']));
    if (!isset($eventsByDate[$date])) {
        $eventsByDate[$date] = [];
    }
    $eventsByDate[$date][] = $event;
}

// Calculate totals
$totalEvents = count($events);
$pendingEvents = count(array_filter($events, fn($e) => $e['status'] === 'pending'));
$doneEvents = count(array_filter($events, fn($e) => $e['status'] === 'done'));

$pageTitle = 'Calendar';
$activePage = 'calendar';
$cacheVersion = '4.0.0';
$pageCSS = ['/calendar/css/calendar.css?v=' . $cacheVersion];
$pageJS = ['/calendar/js/calendar.js?v=' . $cacheVersion];

require_once __DIR__ . '/../shared/components/header.php';
?>

<div class="bg-animation">
    <div class="bg-gradient"></div>
    <canvas id="particles"></canvas>
</div>

<main class="main-content">
    <div class="container">
        
        <section class="hero-section">
            <div class="greeting-card">
                <div class="greeting-time"><?php echo date('l, F j, Y'); ?></div>
                <h1 class="greeting-text">
                    <span class="greeting-icon">📅</span>
                    <span class="greeting-name"><?php echo $monthName; ?></span>
                </h1>
                <p class="greeting-subtitle">Your family's schedule at a glance</p>

                <div class="quick-actions">
                    <button onclick="showCreateEventModal()" class="quick-action-btn">
                        <span class="qa-icon">➕</span>
                        <span class="qa-text">Add Event</span>
                    </button>
                    <button onclick="goToToday()" class="quick-action-btn">
                        <span class="qa-icon">📍</span>
                        <span class="qa-text">Today</span>
                    </button>
                    <button onclick="syncGoogleCalendar()" class="quick-action-btn">
                        <span class="qa-icon">🔄</span>
                        <span class="qa-text">Sync</span>
                    </button>
                </div>
            </div>
        </section>

        <div class="view-switcher glass-card">
            <button class="view-btn active" onclick="switchView('month')" data-tilt>
                <span class="view-icon">📅</span>
                <span class="view-text">Month</span>
            </button>
            <button class="view-btn" onclick="switchView('week')" data-tilt>
                <span class="view-icon">📆</span>
                <span class="view-text">Week</span>
            </button>
            <button class="view-btn" onclick="switchView('day')" data-tilt>
                <span class="view-icon">📋</span>
                <span class="view-text">Day</span>
            </button>
        </div>

        <div class="month-navigator glass-card">
            <button onclick="navigateCalendar(-1)" class="nav-btn" data-tilt>
                <span class="nav-icon">←</span>
            </button>
            
            <div class="month-display">
                <h2 id="currentMonthDisplay"><?php echo $monthName; ?></h2>
            </div>
            
            <button onclick="navigateCalendar(1)" class="nav-btn" data-tilt>
                <span class="nav-icon">→</span>
            </button>
        </div>

        <div class="calendar-layout">
            
            <div class="calendar-main">
                
                <div id="month-view" class="calendar-view active">
                    <div class="calendar-grid glass-card">
                        <div class="calendar-header">
                            <div class="day-header">Sun</div>
                            <div class="day-header">Mon</div>
                            <div class="day-header">Tue</div>
                            <div class="day-header">Wed</div>
                            <div class="day-header">Thu</div>
                            <div class="day-header">Fri</div>
                            <div class="day-header">Sat</div>
                        </div>

                        <div class="calendar-body">
                            <?php foreach ($calendarDays as $index => $dayData): ?>
                                <?php
                                $dateStr = sprintf('%04d-%02d-%02d', $dayData['year'], $dayData['month'], $dayData['day']);
                                $isToday = $dateStr === date('Y-m-d');
                                $dayEvents = $eventsByDate[$dateStr] ?? [];
                                ?>
                                <div class="calendar-day <?php echo !$dayData['isCurrentMonth'] ? 'other-month' : ''; ?> <?php echo $isToday ? 'today' : ''; ?>"
                                     data-date="<?php echo $dateStr; ?>"
                                     onclick="selectDay('<?php echo $dateStr; ?>')"
                                     data-tilt>
                                    
                                    <div class="day-number"><?php echo $dayData['day']; ?></div>
                                    
                                    <?php if (!empty($dayEvents)): ?>
                                        <div class="day-events">
                                            <?php foreach (array_slice($dayEvents, 0, 3) as $event): ?>
                                                <div class="day-event"
                                                     style="background: <?php echo htmlspecialchars($event['color']); ?>;"
                                                     data-event-id="<?php echo $event['id']; ?>"
                                                     onclick="event.stopPropagation(); showEventDetails(<?php echo $event['id']; ?>)">
                                                    <?php echo htmlspecialchars($event['title']); ?>
                                                </div>
                                            <?php endforeach; ?>
                                            
                                            <?php if (count($dayEvents) > 3): ?>
                                                <div class="day-event-more">
                                                    +<?php echo count($dayEvents) - 3; ?> more
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div id="week-view" class="calendar-view">
                    <div class="week-view glass-card">
                        <div class="week-header"></div>
                        <div class="week-grid"></div>
                    </div>
                </div>

                <div id="day-view" class="calendar-view">
                    <div class="day-view glass-card">
                        <div class="day-view-header"></div>
                        <div class="day-view-grid">
                            <div class="day-timeline"></div>
                            <div class="day-events-column"></div>
                        </div>
                    </div>
                </div>

            </div>

            <aside class="calendar-sidebar">
                
                <div class="upcoming-card glass-card" data-tilt>
                    <div class="sidebar-header">
                        <h3><span class="sidebar-icon">🔜</span> Upcoming Events</h3>
                    </div>
                    
                    <?php if (empty($upcomingEvents)): ?>
                        <div class="empty-upcoming">
                            <div class="empty-icon">📭</div>
                            <p>No upcoming events</p>
                        </div>
                    <?php else: ?>
                        <div class="upcoming-list">
                            <?php foreach ($upcomingEvents as $event): ?>
                                <div class="upcoming-item" onclick="showEventDetails(<?php echo $event['id']; ?>)">
                                    <div class="upcoming-date">
                                        <div class="date-month"><?php echo date('M', strtotime($event['starts_at'])); ?></div>
                                        <div class="date-day"><?php echo date('d', strtotime($event['starts_at'])); ?></div>
                                    </div>
                                    <div class="upcoming-content">
                                        <div class="upcoming-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                        <div class="upcoming-time">
                                            <?php 
                                            if ($event['all_day']) {
                                                echo 'All day';
                                            } else {
                                                echo date('g:i A', strtotime($event['starts_at']));
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="upcoming-avatar" style="background: <?php echo htmlspecialchars($event['avatar_color']); ?>">
                                        <?php echo strtoupper(substr($event['full_name'], 0, 1)); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="stats-card glass-card" data-tilt>
                    <div class="sidebar-header">
                        <h3><span class="sidebar-icon">📊</span> This Month</h3>
                    </div>
                    
                    <div class="stat-row">
                        <div class="stat-label">Total Events</div>
                        <div class="stat-value" data-count="<?php echo $totalEvents; ?>">0</div>
                    </div>
                    
                    <div class="stat-row">
                        <div class="stat-label">Upcoming</div>
                        <div class="stat-value" data-count="<?php echo count($upcomingEvents); ?>">0</div>
                    </div>
                    
                    <div class="stat-row">
                        <div class="stat-label">This Week</div>
                        <div class="stat-value" data-count="<?php
                            $thisWeek = array_filter($events, function($e) {
                                $eventDate = strtotime($e['starts_at']);
                                $weekStart = strtotime('monday this week');
                                $weekEnd = strtotime('sunday this week');
                                return $eventDate >= $weekStart && $eventDate <= $weekEnd;
                            });
                            echo count($thisWeek);
                        ?>">0</div>
                    </div>
                </div>

                <div class="legend-card glass-card" data-tilt>
                    <div class="sidebar-header">
                        <h3><span class="sidebar-icon">🎨</span> Event Types</h3>
                    </div>
                    <div class="legend-list">
                        <div class="legend-item">
                            <div class="legend-color" style="background: #e74c3c;"></div>
                            <div class="legend-label">🎂 Birthday</div>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background: #9b59b6;"></div>
                            <div class="legend-label">💍 Anniversary</div>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background: #f39c12;"></div>
                            <div class="legend-label">🎉 Holiday</div>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background: #2ecc71;"></div>
                            <div class="legend-label">👨‍👩‍👧‍👦 Family</div>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background: #e91e63;"></div>
                            <div class="legend-label">❤️ Date</div>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background: #3498db;"></div>
                            <div class="legend-label">📅 Event</div>
                        </div>
                    </div>
                </div>

            </aside>

        </div>

    </div>
</main>

<div id="createEventModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>📅 Create Event</h2>
            <button onclick="closeModal('createEventModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="createEventForm">

                <div class="form-group">
                    <label>Event Type</label>
                    <select id="eventKind" class="form-control" onchange="onEventTypeChange(this, false)">
                        <option value="birthday">🎂 Birthday</option>
                        <option value="anniversary">💍 Anniversary</option>
                        <option value="holiday">🎉 Holiday</option>
                        <option value="family_event">👨‍👩‍👧‍👦 Family Event</option>
                        <option value="date">❤️ Special Date</option>
                        <option value="reminder">🔔 Reminder</option>
                        <option value="event" selected>📅 General Event</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Event Title *</label>
                    <input type="text"
                           id="eventTitle"
                           class="form-control"
                           placeholder="e.g., Family Dinner, Birthday Party"
                           value="<?php echo htmlspecialchars($voicePrefillContent); ?>">
                </div>

                <div class="form-group">
                    <label>Date *</label>
                    <input type="date" id="eventStartDate" class="form-control"
                           value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div id="eventTimeSection">
                    <div class="form-row">
                        <div class="form-group" style="flex: 1;">
                            <label>Start Time</label>
                            <input type="time" id="eventStartTime" class="form-control">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>End Time</label>
                            <input type="time" id="eventEndTime" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>End Date (if different)</label>
                        <input type="date" id="eventEndDate" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="eventAllDay" onchange="toggleAllDay()">
                            All Day Event
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Location (optional)</label>
                    <input type="text" id="eventLocation" class="form-control"
                           placeholder="e.g., Home, Restaurant, Park">
                </div>

                <div class="form-group">
                    <label>Notes (optional)</label>
                    <textarea id="eventNotes" class="form-control" rows="3"
                              placeholder="Add any details..."></textarea>
                </div>

                <div class="form-group" id="eventRepeatSection">
                    <label>Repeat</label>
                    <select id="eventRecurrence" class="form-control">
                        <option value="">No repeat</option>
                        <option value="daily">Daily</option>
                        <option value="weekdays">Weekdays</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Reminder</label>
                    <select id="eventReminder" class="form-control">
                        <option value="0">No reminder</option>
                        <option value="15">15 minutes before</option>
                        <option value="30">30 minutes before</option>
                        <option value="60">1 hour before</option>
                        <option value="1440">1 day before</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Color</label>
                    <div class="color-picker">
                        <input type="radio" name="eventColor" value="#e74c3c" id="ecolor1">
                        <label for="ecolor1" class="color-option" style="background: #e74c3c;"></label>

                        <input type="radio" name="eventColor" value="#9b59b6" id="ecolor2">
                        <label for="ecolor2" class="color-option" style="background: #9b59b6;"></label>

                        <input type="radio" name="eventColor" value="#3498db" id="ecolor3" checked>
                        <label for="ecolor3" class="color-option" style="background: #3498db;"></label>

                        <input type="radio" name="eventColor" value="#2ecc71" id="ecolor4">
                        <label for="ecolor4" class="color-option" style="background: #2ecc71;"></label>

                        <input type="radio" name="eventColor" value="#f39c12" id="ecolor5">
                        <label for="ecolor5" class="color-option" style="background: #f39c12;"></label>

                        <input type="radio" name="eventColor" value="#1abc9c" id="ecolor6">
                        <label for="ecolor6" class="color-option" style="background: #1abc9c;"></label>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" onclick="saveNewEvent()" class="btn btn-primary" style="flex: 1;">Create Event</button>
                    <button type="button" onclick="closeModal('createEventModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="eventDetailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>📋 Event Details</h2>
            <button onclick="closeModal('eventDetailsModal')" class="modal-close">&times;</button>
        </div>
        <div class="modal-body" id="eventDetailsContent">
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
                    <label>Event Type</label>
                    <select id="editEventKind" class="form-control" onchange="onEventTypeChange(this, true)">
                        <option value="birthday">🎂 Birthday</option>
                        <option value="anniversary">💍 Anniversary</option>
                        <option value="holiday">🎉 Holiday</option>
                        <option value="family_event">👨‍👩‍👧‍👦 Family Event</option>
                        <option value="date">❤️ Special Date</option>
                        <option value="reminder">🔔 Reminder</option>
                        <option value="event">📅 General Event</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Event Title *</label>
                    <input type="text" id="editEventTitle" class="form-control">
                </div>

                <div class="form-group">
                    <label>Date *</label>
                    <input type="date" id="editEventStartDate" class="form-control">
                </div>

                <div id="editEventTimeSection">
                    <div class="form-row">
                        <div class="form-group" style="flex: 1;">
                            <label>Start Time</label>
                            <input type="time" id="editEventStartTime" class="form-control">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>End Time</label>
                            <input type="time" id="editEventEndTime" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>End Date (if different)</label>
                        <input type="date" id="editEventEndDate" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="editEventAllDay" onchange="toggleEditAllDay()">
                            All Day Event
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Location (optional)</label>
                    <input type="text" id="editEventLocation" class="form-control"
                           placeholder="e.g., Home, Restaurant, Park">
                </div>

                <div class="form-group">
                    <label>Notes (optional)</label>
                    <textarea id="editEventNotes" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-group" id="editEventRepeatSection">
                    <label>Repeat</label>
                    <select id="editEventRecurrence" class="form-control">
                        <option value="">No repeat</option>
                        <option value="daily">Daily</option>
                        <option value="weekdays">Weekdays</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Reminder</label>
                    <select id="editEventReminder" class="form-control">
                        <option value="0">No reminder</option>
                        <option value="15">15 minutes before</option>
                        <option value="30">30 minutes before</option>
                        <option value="60">1 hour before</option>
                        <option value="1440">1 day before</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Color</label>
                    <div class="color-picker">
                        <input type="radio" name="editEventColor" value="#e74c3c" id="edit_ecolor1">
                        <label for="edit_ecolor1" class="color-option" style="background: #e74c3c;"></label>

                        <input type="radio" name="editEventColor" value="#9b59b6" id="edit_ecolor2">
                        <label for="edit_ecolor2" class="color-option" style="background: #9b59b6;"></label>

                        <input type="radio" name="editEventColor" value="#3498db" id="edit_ecolor3">
                        <label for="edit_ecolor3" class="color-option" style="background: #3498db;"></label>

                        <input type="radio" name="editEventColor" value="#2ecc71" id="edit_ecolor4">
                        <label for="edit_ecolor4" class="color-option" style="background: #2ecc71;"></label>

                        <input type="radio" name="editEventColor" value="#f39c12" id="edit_ecolor5">
                        <label for="edit_ecolor5" class="color-option" style="background: #f39c12;"></label>

                        <input type="radio" name="editEventColor" value="#1abc9c" id="edit_ecolor6">
                        <label for="edit_ecolor6" class="color-option" style="background: #1abc9c;"></label>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" onclick="saveEventChanges()" class="btn btn-primary" style="flex: 1;">Save Changes</button>
                    <button type="button" onclick="closeModal('editEventModal')" class="btn btn-secondary">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($voicePrefillContent): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    showCreateEventModal();
    
    const eventTitle = document.getElementById('eventTitle');
    if (eventTitle) {
        eventTitle.focus();
        eventTitle.select();
        
        if (typeof showToast === 'function') {
            showToast('🎤 Voice command prefilled!', 'success');
        }
    }
});
</script>
<?php endif; ?>

<script>
    window.currentUser = <?php echo json_encode([
        'id' => $user['id'],
        'name' => $user['name']
    ]); ?>;
    window.currentYear = <?php echo $year; ?>;
    window.currentMonth = <?php echo $month; ?>;
    window.events = <?php echo json_encode($events); ?>;
    window.calendarView = 'month';
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>