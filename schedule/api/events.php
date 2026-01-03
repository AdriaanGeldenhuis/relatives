<?php
/**
 * Schedule Events API - Enhanced Version
 * Handles all event operations with productivity tracking
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// ========== SUBSCRIPTION LOCK CHECK ==========
require_once __DIR__ . '/../../core/SubscriptionManager.php';

$subscriptionManager = new SubscriptionManager($db);

if ($subscriptionManager->isFamilyLocked($user['family_id'])) {
    http_response_code(402);
    echo json_encode([
        'success' => false,
        'error' => 'subscription_locked',
        'message' => 'Your trial has ended. Please subscribe to continue using this feature.'
    ]);
    exit;
}
// ========== END SUBSCRIPTION LOCK ==========

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        
        case 'add':
            $title = trim($_POST['title'] ?? '');
            $date = $_POST['date'] ?? date('Y-m-d');
            $startTime = $_POST['start_time'] ?? '';
            $endTime = $_POST['end_time'] ?? '';
            $kind = $_POST['kind'] ?? 'todo';
            $notes = trim($_POST['notes'] ?? '');
            $assignedTo = $_POST['assigned_to'] ?? null;
            $reminderMinutes = (int)($_POST['reminder_minutes'] ?? 0);
            $repeatRule = $_POST['repeat_rule'] ?? null;
            $color = $_POST['color'] ?? '#667eea';
            $focusMode = (int)($_POST['focus_mode'] ?? 0);
            
            if (!$title || !$startTime || !$endTime) {
                throw new Exception('Missing required fields');
            }
            
            // Validate kind
            if (!in_array($kind, ['study', 'work', 'todo', 'break', 'focus'])) {
                throw new Exception('Invalid event type');
            }
            
            $startsAt = $date . ' ' . $startTime . ':00';
            $endsAt = $date . ' ' . $endTime . ':00';
            
            // Check for conflicts
            $stmt = $db->prepare("
                SELECT id, title, starts_at, ends_at 
                FROM schedule_events 
                WHERE family_id = ? 
                AND user_id = ?
                AND status != 'cancelled'
                AND (
                    (starts_at < ? AND ends_at > ?) OR
                    (starts_at >= ? AND starts_at < ?)
                )
                LIMIT 1
            ");
            $stmt->execute([
                $user['family_id'],
                $assignedTo ?? $user['id'],
                $endsAt,
                $startsAt,
                $startsAt,
                $endsAt
            ]);
            
            if ($conflict = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Time conflict detected',
                    'conflict' => $conflict
                ]);
                exit;
            }
            
            $stmt = $db->prepare("
                INSERT INTO schedule_events 
                (family_id, user_id, added_by, assigned_to, title, kind, notes, 
                 starts_at, ends_at, color, status, reminder_minutes, repeat_rule, 
                 focus_mode, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user['family_id'],
                $assignedTo ?? $user['id'],
                $user['id'],
                $assignedTo,
                $title,
                $kind,
                $notes ?: null,
                $startsAt,
                $endsAt,
                $color,
                $reminderMinutes > 0 ? $reminderMinutes : null,
                $repeatRule,
                $focusMode
            ]);
            
            $eventId = $db->lastInsertId();
            
            // Handle recurring events
            if ($repeatRule && in_array($repeatRule, ['daily', 'weekly', 'weekdays', 'monthly'])) {
                $occurrences = 10; // Create next 10 occurrences
                $baseDate = new DateTime($startsAt);
                $duration = (new DateTime($endsAt))->getTimestamp() - $baseDate->getTimestamp();
                
                for ($i = 1; $i <= $occurrences; $i++) {
                    $nextDate = clone $baseDate;
                    
                    switch ($repeatRule) {
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
                    }
                    
                    $newStartsAt = $nextDate->format('Y-m-d H:i:s');
                    $newEndsAt = (clone $nextDate)->modify("+{$duration} seconds")->format('Y-m-d H:i:s');
                    
                    $stmt = $db->prepare("
                        INSERT INTO schedule_events 
                        (family_id, user_id, added_by, assigned_to, title, kind, notes,
                         starts_at, ends_at, color, status, reminder_minutes, repeat_rule,
                         parent_event_id, focus_mode, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $user['family_id'],
                        $assignedTo ?? $user['id'],
                        $user['id'],
                        $assignedTo,
                        $title,
                        $kind,
                        $notes ?: null,
                        $newStartsAt,
                        $newEndsAt,
                        $color,
                        $reminderMinutes > 0 ? $reminderMinutes : null,
                        $repeatRule,
                        $eventId,
                        $focusMode
                    ]);
                }
            }
            
            // Get full event data
            $stmt = $db->prepare("
                SELECT e.*, 
                       u.full_name as added_by_name, u.avatar_color,
                       a.full_name as assigned_to_name
                FROM schedule_events e
                LEFT JOIN users u ON e.added_by = u.id
                LEFT JOIN users a ON e.assigned_to = a.id
                WHERE e.id = ?
            ");
            $stmt->execute([$eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'event' => $event
            ]);
            break;
            
        case 'toggle':
            $eventId = $_POST['event_id'] ?? 0;
            
            $stmt = $db->prepare("
                SELECT id, status, kind, starts_at, ends_at, user_id
                FROM schedule_events 
                WHERE id = ? AND family_id = ?
            ");
            $stmt->execute([$eventId, $user['family_id']]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$event) {
                throw new Exception('Event not found');
            }
            
            $newStatus = $event['status'] === 'done' ? 'pending' : 'done';
            $actualEnd = $newStatus === 'done' ? date('Y-m-d H:i:s') : null;
            
            $stmt = $db->prepare("
                UPDATE schedule_events 
                SET status = ?, actual_end = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $actualEnd, $eventId]);
            
            // Update productivity stats if marking as done
            if ($newStatus === 'done') {
                $eventDate = date('Y-m-d', strtotime($event['starts_at']));
                $duration = (strtotime($event['ends_at']) - strtotime($event['starts_at'])) / 60;
                
                $column = $event['kind'] === 'study' ? 'study_minutes' : 
                         ($event['kind'] === 'work' ? 'work_minutes' : 'focus_minutes');
                
                $stmt = $db->prepare("
                    INSERT INTO schedule_productivity 
                    (user_id, family_id, date, {$column}, completed_tasks, total_tasks)
                    VALUES (?, ?, ?, ?, 1, 1)
                    ON DUPLICATE KEY UPDATE 
                    {$column} = {$column} + VALUES({$column}),
                    completed_tasks = completed_tasks + 1,
                    total_tasks = total_tasks + 1
                ");
                $stmt->execute([
                    $event['user_id'],
                    $user['family_id'],
                    $eventDate,
                    $duration
                ]);
            }
            
            echo json_encode([
                'success' => true,
                'status' => $newStatus
            ]);
            break;
            
        case 'start_focus':
            $eventId = $_POST['event_id'] ?? 0;
            
            $stmt = $db->prepare("
                UPDATE schedule_events 
                SET status = 'in_progress', 
                    actual_start = NOW(),
                    updated_at = NOW()
                WHERE id = ? AND family_id = ? AND status = 'pending'
            ");
            $stmt->execute([$eventId, $user['family_id']]);
            
            echo json_encode(['success' => true, 'status' => 'in_progress']);
            break;
            
        case 'end_focus':
            $eventId = $_POST['event_id'] ?? 0;
            $rating = (int)($_POST['rating'] ?? 0);
            
            $stmt = $db->prepare("
                UPDATE schedule_events 
                SET status = 'done', 
                    actual_end = NOW(),
                    productivity_rating = ?,
                    pomodoro_count = pomodoro_count + 1,
                    updated_at = NOW()
                WHERE id = ? AND family_id = ?
            ");
            $stmt->execute([$rating, $eventId, $user['family_id']]);
            
            echo json_encode(['success' => true, 'status' => 'done']);
            break;
            
        case 'get_suggestions':
            $date = $_GET['date'] ?? date('Y-m-d');
            
            // Get user's typical patterns
            $stmt = $db->prepare("
                SELECT 
                    kind,
                    HOUR(starts_at) as typical_hour,
                    COUNT(*) as frequency,
                    AVG(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) as avg_duration
                FROM schedule_events
                WHERE user_id = ?
                AND status = 'done'
                AND starts_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY kind, HOUR(starts_at)
                ORDER BY frequency DESC
                LIMIT 5
            ");
            $stmt->execute([$user['id']]);
            $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'suggestions' => $patterns
            ]);
            break;
            
        case 'get_productivity':
            $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
            $endDate = $_GET['end_date'] ?? date('Y-m-d');

            $stmt = $db->prepare("
                SELECT * FROM schedule_productivity
                WHERE user_id = ?
                AND date BETWEEN ? AND ?
                ORDER BY date DESC
            ");
            $stmt->execute([$user['id'], $startDate, $endDate]);
            $productivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'productivity' => $productivity
            ]);
            break;

        case 'get_week':
            $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('monday this week'));
            $endDate = $_GET['end_date'] ?? date('Y-m-d', strtotime('sunday this week'));

            $stmt = $db->prepare("
                SELECT
                    e.*,
                    u.full_name as added_by_name, u.avatar_color,
                    a.full_name as assigned_to_name, a.avatar_color as assigned_color
                FROM schedule_events e
                LEFT JOIN users u ON e.added_by = u.id
                LEFT JOIN users a ON e.assigned_to = a.id
                WHERE e.family_id = ?
                AND DATE(e.starts_at) BETWEEN ? AND ?
                AND e.status != 'cancelled'
                ORDER BY e.starts_at ASC
            ");
            $stmt->execute([$user['family_id'], $startDate, $endDate]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'events' => $events
            ]);
            break;
            
        case 'delete':
            $eventId = $_POST['event_id'] ?? 0;
            
            $stmt = $db->prepare("
                UPDATE schedule_events 
                SET status = 'cancelled', updated_at = NOW()
                WHERE id = ? AND family_id = ?
            ");
            $stmt->execute([$eventId, $user['family_id']]);
            
            echo json_encode(['success' => true]);
            break;
            
        case 'update':
            $eventId = $_POST['event_id'] ?? 0;
            $title = trim($_POST['title'] ?? '');
            $date = $_POST['date'] ?? date('Y-m-d');
            $startTime = $_POST['start_time'] ?? '';
            $endTime = $_POST['end_time'] ?? '';
            $kind = $_POST['kind'] ?? 'todo';
            $notes = trim($_POST['notes'] ?? '');
            $assignedTo = $_POST['assigned_to'] ?? null;
            $reminderMinutes = (int)($_POST['reminder_minutes'] ?? 0);
            $repeatRule = $_POST['repeat_rule'] ?? null;
            $focusMode = (int)($_POST['focus_mode'] ?? 0);

            if (!$title || !$startTime || !$endTime) {
                throw new Exception('Missing required fields');
            }

            // Get the original event to check for date changes
            $stmt = $db->prepare("SELECT starts_at, ends_at FROM schedule_events WHERE id = ? AND family_id = ?");
            $stmt->execute([$eventId, $user['family_id']]);
            $originalEvent = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$originalEvent) {
                throw new Exception('Event not found');
            }

            $originalDate = date('Y-m-d', strtotime($originalEvent['starts_at']));
            $startsAt = $date . ' ' . $startTime . ':00';
            $endsAt = $date . ' ' . $endTime . ':00';

            // Update the main event
            $stmt = $db->prepare("
                UPDATE schedule_events
                SET title = ?, kind = ?, starts_at = ?, ends_at = ?,
                    notes = ?, assigned_to = ?, reminder_minutes = ?,
                    repeat_rule = ?, focus_mode = ?, updated_at = NOW()
                WHERE id = ? AND family_id = ?
            ");
            $stmt->execute([
                $title,
                $kind,
                $startsAt,
                $endsAt,
                $notes ?: null,
                $assignedTo ?: null,
                $reminderMinutes > 0 ? $reminderMinutes : null,
                $repeatRule ?: null,
                $focusMode,
                $eventId,
                $user['family_id']
            ]);

            // If date changed, update all child recurring events
            if ($originalDate !== $date) {
                $daysDiff = (strtotime($date) - strtotime($originalDate)) / 86400;

                $stmt = $db->prepare("
                    UPDATE schedule_events
                    SET starts_at = DATE_ADD(starts_at, INTERVAL ? DAY),
                        ends_at = DATE_ADD(ends_at, INTERVAL ? DAY),
                        updated_at = NOW()
                    WHERE parent_event_id = ? AND family_id = ?
                ");
                $stmt->execute([$daysDiff, $daysDiff, $eventId, $user['family_id']]);
            }

            // Also update title/kind/reminder/focus on child events
            $stmt = $db->prepare("
                UPDATE schedule_events
                SET title = ?, kind = ?, reminder_minutes = ?, focus_mode = ?, updated_at = NOW()
                WHERE parent_event_id = ? AND family_id = ?
            ");
            $stmt->execute([
                $title,
                $kind,
                $reminderMinutes > 0 ? $reminderMinutes : null,
                $focusMode,
                $eventId,
                $user['family_id']
            ]);

            echo json_encode(['success' => true]);
            break;
            
        case 'clear_done':
            $date = $_POST['date'] ?? date('Y-m-d');
            
            $stmt = $db->prepare("
                UPDATE schedule_events 
                SET status = 'cancelled', updated_at = NOW()
                WHERE family_id = ? 
                AND DATE(starts_at) = ? 
                AND status = 'done'
            ");
            $stmt->execute([$user['family_id'], $date]);
            
            echo json_encode(['success' => true, 'count' => $stmt->rowCount()]);
            break;
            
        case 'bulk_mark_done':
            $eventIds = json_decode($_POST['event_ids'] ?? '[]', true);
            
            if (empty($eventIds)) {
                throw new Exception('No events selected');
            }
            
            $placeholders = rtrim(str_repeat('?,', count($eventIds)), ',');
            
            $stmt = $db->prepare("
                UPDATE schedule_events 
                SET status = 'done', actual_end = NOW(), updated_at = NOW()
                WHERE id IN ($placeholders) 
                AND family_id = ?
            ");
            $stmt->execute([...$eventIds, $user['family_id']]);
            
            echo json_encode([
                'success' => true,
                'count' => $stmt->rowCount()
            ]);
            break;
            
        case 'bulk_change_type':
            $eventIds = json_decode($_POST['event_ids'] ?? '[]', true);
            $kind = $_POST['kind'] ?? 'todo';
            
            if (empty($eventIds)) {
                throw new Exception('No events selected');
            }
            
            $placeholders = rtrim(str_repeat('?,', count($eventIds)), ',');
            
            $stmt = $db->prepare("
                UPDATE schedule_events 
                SET kind = ?, updated_at = NOW()
                WHERE id IN ($placeholders) 
                AND family_id = ?
            ");
            $stmt->execute([$kind, ...$eventIds, $user['family_id']]);
            
            echo json_encode([
                'success' => true,
                'count' => $stmt->rowCount()
            ]);
            break;
            
        case 'bulk_assign':
            $eventIds = json_decode($_POST['event_ids'] ?? '[]', true);
            $assignTo = $_POST['assign_to'] ?? null;
            
            if (empty($eventIds)) {
                throw new Exception('No events selected');
            }
            
            $placeholders = rtrim(str_repeat('?,', count($eventIds)), ',');
            
            $stmt = $db->prepare("
                UPDATE schedule_events 
                SET assigned_to = ?, updated_at = NOW()
                WHERE id IN ($placeholders) 
                AND family_id = ?
            ");
            $stmt->execute([$assignTo, ...$eventIds, $user['family_id']]);
            
            echo json_encode([
                'success' => true,
                'count' => $stmt->rowCount()
            ]);
            break;
            
        case 'bulk_delete':
            $eventIds = json_decode($_POST['event_ids'] ?? '[]', true);
            
            if (empty($eventIds)) {
                throw new Exception('No events selected');
            }
            
            $placeholders = rtrim(str_repeat('?,', count($eventIds)), ',');
            
            $stmt = $db->prepare("
                UPDATE schedule_events 
                SET status = 'cancelled', updated_at = NOW()
                WHERE id IN ($placeholders) 
                AND family_id = ?
            ");
            $stmt->execute([...$eventIds, $user['family_id']]);
            
            echo json_encode([
                'success' => true,
                'count' => $stmt->rowCount()
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}