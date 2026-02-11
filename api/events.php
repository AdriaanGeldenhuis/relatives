<?php
/**
 * ============================================
 * UNIFIED EVENTS API
 * ============================================
 * Single endpoint for all event operations.
 * Uses EventEngine, ReminderEngine, TemplateEngine, SyncEngine
 * ============================================
 */

session_name('RELATIVES_SESSION');
session_start();
header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Subscription check
require_once __DIR__ . '/../core/SubscriptionManager.php';
$subscriptionManager = new SubscriptionManager($db);

if ($subscriptionManager->isFamilyLocked($user['family_id'])) {
    http_response_code(402);
    echo json_encode([
        'success' => false,
        'error' => 'subscription_locked',
        'message' => 'Your trial has ended. Please subscribe to continue.'
    ]);
    exit;
}

// Load engines
require_once __DIR__ . '/../core/Events/EventEngine.php';
require_once __DIR__ . '/../core/Events/ReminderEngine.php';
require_once __DIR__ . '/../core/Events/TemplateEngine.php';
require_once __DIR__ . '/../core/Events/SyncEngine.php';

$eventEngine = EventEngine::getInstance($db, $user['id'], $user['family_id']);
$reminderEngine = ReminderEngine::getInstance($db);
$templateEngine = TemplateEngine::getInstance($db, $user['id'], $user['family_id']);
$syncEngine = SyncEngine::getInstance($db);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // ==================== EVENT CRUD ====================

        case 'create':
            $data = [
                'title' => trim($_POST['title'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'notes' => trim($_POST['notes'] ?? ''),
                'location' => trim($_POST['location'] ?? ''),
                'starts_at' => $_POST['date'] . ' ' . ($_POST['start_time'] ?? '00:00') . ':00',
                'ends_at' => $_POST['date'] . ' ' . ($_POST['end_time'] ?? '23:59') . ':00',
                'timezone' => $_POST['timezone'] ?? 'Africa/Johannesburg',
                'all_day' => (int)($_POST['all_day'] ?? 0),
                'kind' => $_POST['kind'] ?? 'event',
                'color' => $_POST['color'] ?? '#3498db',
                'reminder_minutes' => (int)($_POST['reminder_minutes'] ?? 0),
                'recurrence_rule' => $_POST['recurrence_rule'] ?? null,
                'assigned_to' => $_POST['assigned_to'] ?? null,
                'focus_mode' => (int)($_POST['focus_mode'] ?? 0)
            ];

            $result = $eventEngine->create($data);

            if ($result['success']) {
                // Queue sync
                $syncEngine->queueSync($result['event_id'], 'create', ['changed_by' => $user['id']]);
            }

            echo json_encode($result);
            break;

        case 'update':
            $eventId = (int)($_POST['event_id'] ?? 0);
            $scope = $_POST['scope'] ?? 'single'; // single, future, all

            $data = [];
            $fields = ['title', 'description', 'notes', 'location', 'kind', 'color', 'assigned_to', 'focus_mode'];

            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    $data[$field] = $_POST[$field];
                }
            }

            if (isset($_POST['date']) && isset($_POST['start_time'])) {
                $data['starts_at'] = $_POST['date'] . ' ' . $_POST['start_time'] . ':00';
            }
            if (isset($_POST['date']) && isset($_POST['end_time'])) {
                $data['ends_at'] = $_POST['date'] . ' ' . $_POST['end_time'] . ':00';
            }
            if (isset($_POST['reminder_minutes'])) {
                $data['reminder_minutes'] = (int)$_POST['reminder_minutes'];
            }
            if (isset($_POST['all_day'])) {
                $data['all_day'] = (int)$_POST['all_day'];
            }

            $result = $eventEngine->update($eventId, $data, $scope);

            if ($result['success']) {
                $syncEngine->queueSync($eventId, 'update', ['changed_by' => $user['id']]);
            }

            echo json_encode($result);
            break;

        case 'delete':
            $eventId = (int)($_POST['event_id'] ?? 0);
            $scope = $_POST['scope'] ?? 'single';

            $result = $eventEngine->delete($eventId, $scope);

            if ($result['success']) {
                $syncEngine->queueSync($eventId, 'delete', ['changed_by' => $user['id']]);
            }

            echo json_encode($result);
            break;

        case 'toggle':
            $eventId = (int)($_POST['event_id'] ?? 0);
            $result = $eventEngine->toggle($eventId);

            if ($result['success']) {
                $action = $result['status'] === 'done' ? 'complete' : 'update';
                $syncEngine->queueSync($eventId, $action, ['changed_by' => $user['id']]);
            }

            echo json_encode($result);
            break;

        case 'undo':
            $eventId = (int)($_POST['event_id'] ?? 0);
            echo json_encode($eventEngine->undo($eventId));
            break;

        // ==================== FOCUS MODE ====================

        case 'start_focus':
            $eventId = (int)($_POST['event_id'] ?? 0);
            echo json_encode($eventEngine->startFocus($eventId));
            break;

        case 'end_focus':
            $eventId = (int)($_POST['event_id'] ?? 0);
            $rating = (int)($_POST['rating'] ?? 0);
            echo json_encode($eventEngine->endFocus($eventId, $rating));
            break;

        // ==================== GET EVENTS ====================

        case 'get':
            $eventId = (int)($_GET['event_id'] ?? 0);
            $event = $eventEngine->getById($eventId);

            echo json_encode([
                'success' => $event !== null,
                'event' => $event
            ]);
            break;

        case 'get_day':
            $date = $_GET['date'] ?? date('Y-m-d');
            $events = $eventEngine->getByDate($date);

            echo json_encode([
                'success' => true,
                'date' => $date,
                'events' => $events
            ]);
            break;

        case 'get_week':
            $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('monday this week'));
            $endDate = $_GET['end_date'] ?? date('Y-m-d', strtotime('sunday this week'));
            $events = $eventEngine->getByDateRange($startDate, $endDate);

            echo json_encode([
                'success' => true,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'events' => $events
            ]);
            break;

        case 'get_month':
            $year = (int)($_GET['year'] ?? date('Y'));
            $month = (int)($_GET['month'] ?? date('m'));
            $startDate = sprintf('%04d-%02d-01', $year, $month);
            $endDate = date('Y-m-t', strtotime($startDate));
            $events = $eventEngine->getByDateRange($startDate, $endDate);

            echo json_encode([
                'success' => true,
                'year' => $year,
                'month' => $month,
                'events' => $events
            ]);
            break;

        case 'get_upcoming':
            $days = (int)($_GET['days'] ?? 7);
            $limit = (int)($_GET['limit'] ?? 10);
            $events = $eventEngine->getUpcoming($days, $limit);

            echo json_encode([
                'success' => true,
                'events' => $events
            ]);
            break;

        // ==================== BULK OPERATIONS ====================

        case 'bulk_mark_done':
            $eventIds = json_decode($_POST['event_ids'] ?? '[]', true);
            $count = $eventEngine->bulkMarkDone($eventIds);

            echo json_encode(['success' => true, 'count' => $count]);
            break;

        case 'bulk_change_type':
            $eventIds = json_decode($_POST['event_ids'] ?? '[]', true);
            $kind = $_POST['kind'] ?? 'todo';
            $count = $eventEngine->bulkChangeType($eventIds, $kind);

            echo json_encode(['success' => true, 'count' => $count]);
            break;

        case 'bulk_assign':
            $eventIds = json_decode($_POST['event_ids'] ?? '[]', true);
            $assignTo = (int)($_POST['assign_to'] ?? 0);
            $count = $eventEngine->bulkAssign($eventIds, $assignTo);

            echo json_encode(['success' => true, 'count' => $count]);
            break;

        case 'bulk_delete':
            $eventIds = json_decode($_POST['event_ids'] ?? '[]', true);
            $count = $eventEngine->bulkDelete($eventIds);

            echo json_encode(['success' => true, 'count' => $count]);
            break;

        case 'clear_done':
            $date = $_POST['date'] ?? date('Y-m-d');
            $count = $eventEngine->clearDone($date);

            echo json_encode(['success' => true, 'count' => $count]);
            break;

        // ==================== REMINDERS ====================

        case 'add_reminder':
            $eventId = (int)($_POST['event_id'] ?? 0);
            $minutes = (int)($_POST['minutes'] ?? 15);
            $type = $_POST['type'] ?? 'push';

            $reminderId = $reminderEngine->create($eventId, $minutes, $type);

            echo json_encode(['success' => true, 'reminder_id' => $reminderId]);
            break;

        case 'update_reminder':
            $reminderId = (int)($_POST['reminder_id'] ?? 0);
            $data = [];

            if (isset($_POST['minutes'])) $data['trigger_offset'] = (int)$_POST['minutes'];
            if (isset($_POST['type'])) $data['trigger_type'] = $_POST['type'];

            $success = $reminderEngine->update($reminderId, $data);

            echo json_encode(['success' => $success]);
            break;

        case 'delete_reminder':
            $reminderId = (int)($_POST['reminder_id'] ?? 0);
            $success = $reminderEngine->delete($reminderId);

            echo json_encode(['success' => $success]);
            break;

        case 'snooze_reminder':
            $reminderId = (int)($_POST['reminder_id'] ?? 0);
            $minutes = (int)($_POST['minutes'] ?? 10);
            $success = $reminderEngine->snooze($reminderId, $minutes);

            echo json_encode(['success' => $success]);
            break;

        case 'get_reminders':
            $eventId = (int)($_GET['event_id'] ?? 0);
            $reminders = $reminderEngine->getForEvent($eventId);

            echo json_encode(['success' => true, 'reminders' => $reminders]);
            break;

        case 'get_upcoming_reminders':
            $hours = (int)($_GET['hours'] ?? 24);
            $reminders = $reminderEngine->getUpcomingForUser($user['id'], $hours);

            echo json_encode(['success' => true, 'reminders' => $reminders]);
            break;

        // ==================== TEMPLATES ====================

        case 'get_templates':
            $templates = $templateEngine->getAll();

            echo json_encode(['success' => true, 'templates' => $templates]);
            break;

        case 'get_template':
            $templateId = (int)($_GET['template_id'] ?? 0);
            $template = $templateEngine->getById($templateId);

            echo json_encode([
                'success' => $template !== null,
                'template' => $template
            ]);
            break;

        case 'create_template':
            $data = [
                'name' => trim($_POST['name'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'icon' => $_POST['icon'] ?? '📋',
                'color' => $_POST['color'] ?? '#667eea',
                'pattern_json' => $_POST['pattern_json'] ?? '[]',
                'is_public' => (int)($_POST['is_public'] ?? 0)
            ];

            $templateId = $templateEngine->create($data);

            echo json_encode(['success' => true, 'template_id' => $templateId]);
            break;

        case 'update_template':
            $templateId = (int)($_POST['template_id'] ?? 0);
            $data = [];

            $fields = ['name', 'description', 'icon', 'color', 'pattern_json', 'is_public'];
            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    $data[$field] = $_POST[$field];
                }
            }

            $success = $templateEngine->update($templateId, $data);

            echo json_encode(['success' => $success]);
            break;

        case 'delete_template':
            $templateId = (int)($_POST['template_id'] ?? 0);
            $success = $templateEngine->delete($templateId);

            echo json_encode(['success' => $success]);
            break;

        case 'preview_template':
            $templateId = (int)($_GET['template_id'] ?? 0);
            $date = $_GET['date'] ?? date('Y-m-d');

            $preview = $templateEngine->preview($templateId, $date);

            echo json_encode(['success' => true, 'preview' => $preview]);
            break;

        case 'apply_template':
            $templateId = (int)($_POST['template_id'] ?? 0);
            $date = $_POST['date'] ?? date('Y-m-d');

            $options = [];
            if (isset($_POST['reminder_minutes'])) $options['reminder_minutes'] = (int)$_POST['reminder_minutes'];
            if (isset($_POST['recurrence_rule'])) $options['recurrence_rule'] = $_POST['recurrence_rule'];
            if (isset($_POST['assigned_to'])) $options['assigned_to'] = (int)$_POST['assigned_to'];

            $result = $templateEngine->apply($templateId, $date, $options);

            echo json_encode($result);
            break;

        case 'apply_template_range':
            $templateId = (int)($_POST['template_id'] ?? 0);
            $startDate = $_POST['start_date'] ?? date('Y-m-d');
            $endDate = $_POST['end_date'] ?? date('Y-m-d', strtotime('+7 days'));

            $options = [];
            if (isset($_POST['weekdays_only'])) $options['weekdays_only'] = (bool)$_POST['weekdays_only'];
            if (isset($_POST['exclude_dates'])) $options['exclude_dates'] = json_decode($_POST['exclude_dates'], true);

            $result = $templateEngine->applyToRange($templateId, $startDate, $endDate, $options);

            echo json_encode($result);
            break;

        case 'create_template_from_day':
            $name = trim($_POST['name'] ?? '');
            $date = $_POST['date'] ?? date('Y-m-d');

            $templateId = $templateEngine->createFromEvents($name, $date);

            echo json_encode(['success' => true, 'template_id' => $templateId]);
            break;

        // ==================== STATS ====================

        case 'get_stats':
            $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
            $endDate = $_GET['end_date'] ?? date('Y-m-d');

            $stats = $eventEngine->getStats($startDate, $endDate);

            echo json_encode(['success' => true, 'stats' => $stats]);
            break;

        case 'get_productivity':
            $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
            $endDate = $_GET['end_date'] ?? date('Y-m-d');

            $data = $eventEngine->getProductivityData($user['id'], $startDate, $endDate);

            echo json_encode(['success' => true, 'productivity' => $data]);
            break;

        // ==================== SYNC ====================

        case 'get_changes':
            $lastSync = $_GET['last_sync'] ?? date('Y-m-d H:i:s', strtotime('-1 day'));
            $changes = $syncEngine->getChangesSince($user['family_id'], $lastSync);

            echo json_encode([
                'success' => true,
                'changes' => $changes,
                'server_time' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'push_changes':
            $changes = json_decode($_POST['changes'] ?? '[]', true);
            $result = $syncEngine->applyOfflineChanges($user['family_id'], $changes);

            echo json_encode($result);
            break;

        case 'get_conflicts':
            $conflicts = $syncEngine->getPendingConflicts($user['family_id']);

            echo json_encode(['success' => true, 'conflicts' => $conflicts]);
            break;

        case 'resolve_conflict':
            $conflictId = (int)($_POST['conflict_id'] ?? 0);
            $choice = $_POST['choice'] ?? 'local'; // local or remote

            $success = $syncEngine->resolveConflictManually($conflictId, $choice);

            echo json_encode(['success' => $success]);
            break;

        default:
            throw new Exception('Invalid action: ' . $action);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    error_log('Events API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
