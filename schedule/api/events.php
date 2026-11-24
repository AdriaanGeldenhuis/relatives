<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/session_boot.php';
require_once __DIR__ . '/../planner.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

function scheduleResponse(array $data, int $code = 200): void
{
    http_response_code($code);
    echo scheduleJson($data);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    scheduleResponse(['success' => false, 'error' => 'Sign in again to use Schedule.'], 401);
}

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/CSRF.php';
require_once __DIR__ . '/write.php';

$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    scheduleResponse(['success' => false, 'error' => 'Sign in again to use Schedule.'], 401);
}
$userId = (int)$user['id'];
$familyId = (int)$user['family_id'];

if ((new SubscriptionManager($db))->isFamilyLocked($familyId)) {
    scheduleResponse(['success' => false, 'error' => 'subscription_locked', 'message' => 'Subscribe to continue editing your schedule.'], 402);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$read = in_array($action, ['get_productivity', 'get_week', 'get_suggestions', 'get_birthdays'], true);
if (!$read && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($_POST['csrf_token'] ?? null)
    || !CSRF::validate($_POST['csrf_token']))) {
    scheduleResponse(['success' => false, 'error' => 'Reload Schedule before saving changes.'], 403);
}
session_write_close();

try {
    switch ($action) {
        case 'add':
        case 'update':
            $db->beginTransaction();
            $eventId = $action === 'update' ? scheduleEventId() : null;
            $original = $eventId ? scheduleLockEvent($db, $familyId, $eventId) : null;
            $version = schedulePost('version');
            if ($original && $version !== '' && !hash_equals(scheduleEventVersion($original), $version)) {
                $db->rollBack();
                scheduleResponse(['success' => false, 'error' => 'This event changed since you opened it. Copy your edits before reopening the event.'], 409);
            }
            $event = scheduleFields($db, $userId, $familyId, $original);
            $eventId = scheduleWriteEvent($db, $familyId, $userId, $event, $original);
            $stmt = $db->prepare('SELECT * FROM events WHERE id = ? AND family_id = ?');
            $stmt->execute([$eventId, $familyId]);
            $saved = $stmt->fetch();
            $db->commit();
            if (!$original) {
                try {
                    require_once __DIR__ . '/../../core/NotificationManager.php';
                    require_once __DIR__ . '/../../core/NotificationTriggers.php';
                    (new NotificationTriggers($db))->onScheduleTaskCreated(
                        $eventId, $userId, $familyId, $event['title'],
                        date('j M Y \a\t H:i', strtotime($event['starts_at']))
                    );
                } catch (Throwable $error) {
                    error_log('Schedule notification failed: ' . $error->getMessage());
                }
            }
            scheduleResponse(['success' => true, 'event' => $saved]);

        case 'toggle':
            $db->beginTransaction();
            $id = scheduleEventId();
            $event = scheduleLockEvent($db, $familyId, $id);
            if ($event['status'] === 'in_progress' && $event['focus_mode']) {
                throw new DomainException('Finish the active focus session from its timer.');
            }
            $requested = schedulePost('status');
            if ($requested !== '' && !in_array($requested, ['done', 'pending'], true)) {
                throw new DomainException('Choose a valid status.');
            }
            $status = $requested ?: ($event['status'] === 'done' ? 'pending' : 'done');
            if ($event['status'] !== $status) {
                $stmt = $db->prepare('UPDATE events SET status = ?, actual_end = ? WHERE id = ? AND family_id = ?');
                $stmt->execute([$status, $status === 'done' ? date('Y-m-d H:i:s') : null, $id, $familyId]);
            }
            $db->commit();
            scheduleResponse(['success' => true, 'status' => $status]);

        case 'start_focus':
            $db->beginTransaction();
            scheduleLockFocusUser($db, $userId, $familyId);
            $id = scheduleEventId();
            $event = scheduleLockEvent($db, $familyId, $id);
            if ((int)$event['user_id'] !== $userId || !$event['focus_mode'] || $event['status'] !== 'pending'
                || !$event['ends_at'] || $event['ends_at'] <= $event['starts_at'] || $event['all_day']) {
                throw new DomainException('This event is unavailable for focus. Assign it to yourself and set a time range.');
            }
            $stmt = $db->prepare("UPDATE events SET status = 'in_progress', actual_start = NOW() WHERE id = ? AND family_id = ?");
            $stmt->execute([$id, $familyId]);
            $event['status'] = 'in_progress';
            $event['actual_start'] = date('Y-m-d H:i:s');
            $db->commit();
            scheduleResponse(['success' => true, 'event' => $event]);

        case 'end_focus':
            $id = scheduleEventId();
            $rating = scheduleNumber('rating', 0, 5);
            $stmt = $db->prepare("
                UPDATE events SET status = 'done', actual_end = NOW(), productivity_rating = ?,
                    pomodoro_count = COALESCE(pomodoro_count, 0) + 1
                WHERE id = ? AND family_id = ? AND user_id = ? AND status = 'in_progress' AND focus_mode = 1
            ");
            $stmt->execute([$rating ?: null, $id, $familyId, $userId]);
            if (!$stmt->rowCount()) {
                throw new DomainException('This focus session has already ended or is unavailable.');
            }
            scheduleResponse(['success' => true, 'status' => 'done']);

        case 'get_productivity':
            [$from, $until] = scheduleRange('-30 days', 'today', 366);
            $stmt = $db->prepare("
                SELECT DATE(starts_at) AS date,
                    SUM(CASE WHEN status = 'done' AND kind = 'study' AND all_day = 0 THEN GREATEST(0, COALESCE(TIMESTAMPDIFF(MINUTE, starts_at, ends_at), 0)) ELSE 0 END) AS study_minutes,
                    SUM(CASE WHEN status = 'done' AND kind IN ('work','church','event') AND all_day = 0 THEN GREATEST(0, COALESCE(TIMESTAMPDIFF(MINUTE, starts_at, ends_at), 0)) ELSE 0 END) AS work_minutes,
                    SUM(CASE WHEN status = 'done' AND (kind = 'focus' OR focus_mode = 1) AND all_day = 0
                        THEN GREATEST(0, COALESCE(TIMESTAMPDIFF(MINUTE, actual_start, actual_end),
                            TIMESTAMPDIFF(MINUTE, starts_at, ends_at), 0)) ELSE 0 END) AS focus_minutes,
                    SUM(status = 'done') AS completed_tasks,
                    COUNT(*) AS total_tasks
                FROM events WHERE family_id = ? AND user_id = ?
                    AND status != 'cancelled' AND starts_at >= ? AND starts_at < ?
                GROUP BY DATE(starts_at) ORDER BY date DESC
            ");
            $stmt->execute([$familyId, $userId, $from->format('Y-m-d'), $until->format('Y-m-d')]);
            scheduleResponse(['success' => true, 'productivity' => $stmt->fetchAll()]);

        case 'get_week':
            [$from, $until] = scheduleRange('monday this week', 'sunday this week', 31);
            scheduleResponse(['success' => true, 'events' => scheduleEvents($db, $familyId, $from, $until)]);

        case 'get_suggestions':
            $stmt = $db->prepare("
                SELECT kind, HOUR(starts_at) AS typical_hour, COUNT(*) AS frequency,
                    AVG(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) AS avg_duration
                FROM events WHERE user_id = ? AND family_id = ? AND status = 'done'
                    AND starts_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY kind, HOUR(starts_at) ORDER BY frequency DESC LIMIT 5
            ");
            $stmt->execute([$userId, $familyId]);
            scheduleResponse(['success' => true, 'suggestions' => $stmt->fetchAll()]);

        case 'clear_done':
            $date = scheduleDate(schedulePost('date', date('Y-m-d')));
            if (!$date) {
                throw new DomainException('Pick a valid date.');
            }
            $stmt = $db->prepare("
                UPDATE events SET status = 'cancelled'
                WHERE family_id = ? AND status = 'done'
                    AND starts_at < ? AND (starts_at >= ? OR ends_at > ?)
            ");
            $start = $date->format('Y-m-d H:i:s');
            $stmt->execute([$familyId, $date->modify('+1 day')->format('Y-m-d H:i:s'), $start, $start]);
            scheduleResponse(['success' => true, 'count' => $stmt->rowCount()]);

        case 'delete':
            $stmt = $db->prepare("
                UPDATE events SET status = 'cancelled'
                WHERE family_id = ? AND id = ? AND status != 'cancelled' AND NOT (status = 'in_progress' AND focus_mode = 1)
            ");
            $stmt->execute([$familyId, scheduleEventId()]);
            if (!$stmt->rowCount()) {
                throw new DomainException('This event is unavailable or has an active focus session.');
            }
            scheduleResponse(['success' => true]);

        case 'bulk_mark_done':
        case 'bulk_change_type':
        case 'bulk_assign':
        case 'bulk_delete':
            $ids = scheduleEventIds();
            $in = implode(',', array_fill(0, count($ids), '?'));
            $values = [];
            switch ($action) {
                case 'bulk_mark_done':
                    $set = "status = 'done', actual_end = NOW()";
                    $condition = "AND status != 'done'";
                    break;
                case 'bulk_change_type':
                    $set = 'kind = ?';
                    $values = [scheduleKind(schedulePost('kind'))];
                    $condition = '';
                    break;
                case 'bulk_assign':
                    $set = 'assigned_to = ?, user_id = COALESCE(?, created_by, user_id)';
                    $assignee = scheduleAssignee($db, $familyId, schedulePost('assign_to'));
                    $values = [$assignee, $assignee];
                    $condition = '';
                    break;
                case 'bulk_delete':
                    $set = "status = 'cancelled'";
                    $condition = '';
                    break;
            }
            $stmt = $db->prepare("
                UPDATE events SET $set WHERE family_id = ? AND id IN ($in)
                    AND status != 'cancelled' AND NOT (status = 'in_progress' AND focus_mode = 1) $condition
            ");
            $stmt->execute([...$values, $familyId, ...$ids]);
            scheduleResponse(['success' => true, 'count' => $stmt->rowCount()]);

        case 'add_birthday':
            $name = trim(schedulePost('name'));
            $birthday = scheduleDate(schedulePost('date'));
            $notes = str_replace(["\r\n", "\r"], "\n", schedulePost('notes'));
            $color = schedulePost('color', '#e74c3c');
            if (!$birthday || $name === '' || !preg_match('/^.{1,180}$/usD', $name)
                || strlen($notes) > 65535 || !preg_match('/^#[0-9a-f]{6}$/iD', $color)) {
                throw new DomainException('Enter a valid name, birth date and color.');
            }
            $today = new DateTimeImmutable('today');
            $years = (int)$today->format('Y') - (int)$birthday->format('Y');
            if ($years < 0 || $birthday > $today) {
                throw new DomainException('Birth date must be on or before today.');
            }
            $day = scheduleOccurrence($birthday, 'yearly', $years);
            if ($day < $today) {
                $day = scheduleOccurrence($birthday, 'yearly', $years + 1);
            }
            $startsAt = $day->format('Y-m-d H:i:s');
            $db->beginTransaction();
            $eventId = scheduleWriteEvent($db, $familyId, $userId, [
                'user_id' => $userId, 'assigned_to' => null, 'title' => $name . "'s Birthday",
                'kind' => 'birthday', 'notes' => $notes ?: null, 'starts_at' => $startsAt,
                'ends_at' => $day->modify('+1 day')->format('Y-m-d H:i:s'), 'all_day' => 1,
                'color' => $color, 'priority' => 'medium', 'reminder_minutes' => null,
                'recurrence_rule' => 'yearly', 'focus_mode' => 0, 'actual_start' => null
            ], null);
            $stmt = $db->prepare('SELECT * FROM events WHERE id = ? AND family_id = ?');
            $stmt->execute([$eventId, $familyId]);
            $event = $stmt->fetch();
            $db->commit();
            try {
                require_once __DIR__ . '/../../core/NotificationTriggers.php';
                (new NotificationTriggers($db))->onBirthdayCreated($eventId, $userId, $familyId, $name, $startsAt);
            } catch (Throwable $error) {
                error_log('Birthday notification failed: ' . $error->getMessage());
            }
            scheduleResponse(['success' => true, 'event' => $event, 'message' => 'Birthday added with yearly recurrence']);

        case 'get_birthdays':
            $year = $_GET['year'] ?? date('Y');
            $first = is_string($year) ? scheduleDate($year . '-01-01') : null;
            if (!$first) {
                throw new DomainException('Choose a valid year.');
            }
            $events = scheduleEvents($db, $familyId, $first, $first->modify('+1 year'));
            scheduleResponse(['success' => true, 'birthdays' => array_values(array_filter($events, static fn($event) => $event['kind'] === 'birthday'))]);

        default:
            throw new DomainException('Unknown schedule action.');
    }
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($error instanceof DomainException) {
        scheduleResponse(['success' => false, 'error' => $error->getMessage()], 422);
    }
    error_log('Schedule request failed: ' . $error->getMessage());
    scheduleResponse(['success' => false, 'error' => 'Could not save or load the schedule. Try again.'], 500);
}
