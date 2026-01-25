<?php
/**
 * ============================================
 * EVENT ENGINE - UNIFIED EVENT HANDLING
 * ============================================
 * Single source of truth for all event operations.
 * Handles calendar events, schedule events, reminders,
 * recurrence, and history tracking.
 * ============================================
 */

class EventEngine {
    private $db;
    private $userId;
    private $familyId;
    private static $instance = null;

    // Event types
    const TYPE_EVENT = 'event';
    const TYPE_STUDY = 'study';
    const TYPE_WORK = 'work';
    const TYPE_TODO = 'todo';
    const TYPE_BREAK = 'break';
    const TYPE_FOCUS = 'focus';

    // Event statuses
    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_DONE = 'done';
    const STATUS_CANCELLED = 'cancelled';

    // Recurrence rules
    const RECUR_DAILY = 'daily';
    const RECUR_WEEKDAYS = 'weekdays';
    const RECUR_WEEKLY = 'weekly';
    const RECUR_BIWEEKLY = 'biweekly';
    const RECUR_MONTHLY = 'monthly';

    private function __construct($db, $userId = null, $familyId = null) {
        $this->db = $db;
        $this->userId = $userId;
        $this->familyId = $familyId;
    }

    public static function getInstance($db, $userId = null, $familyId = null) {
        if (self::$instance === null) {
            self::$instance = new self($db, $userId, $familyId);
        }
        return self::$instance;
    }

    public function setUser($userId, $familyId) {
        $this->userId = $userId;
        $this->familyId = $familyId;
        return $this;
    }

    // ============================================
    // CREATE EVENT
    // ============================================

    public function create(array $data): array {
        $this->validateRequired($data, ['title', 'starts_at', 'ends_at']);

        // Check for conflicts (unless it's a break)
        if (($data['kind'] ?? self::TYPE_EVENT) !== self::TYPE_BREAK) {
            $conflict = $this->checkConflicts(
                $data['starts_at'],
                $data['ends_at'],
                $data['assigned_to'] ?? $this->userId
            );
            if ($conflict) {
                return [
                    'success' => false,
                    'error' => 'Time conflict detected',
                    'conflict' => $conflict
                ];
            }
        }

        $stmt = $this->db->prepare("
            INSERT INTO events (
                family_id, user_id, created_by, assigned_to,
                title, description, notes, location,
                starts_at, ends_at, timezone, all_day,
                kind, color, status, reminder_minutes,
                recurrence_rule, focus_mode,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, 'pending', ?,
                ?, ?,
                NOW(), NOW()
            )
        ");

        $stmt->execute([
            $this->familyId,
            $data['assigned_to'] ?? $this->userId,
            $this->userId,
            $data['assigned_to'] ?? null,
            $data['title'],
            $data['description'] ?? null,
            $data['notes'] ?? null,
            $data['location'] ?? null,
            $data['starts_at'],
            $data['ends_at'],
            $data['timezone'] ?? 'Africa/Johannesburg',
            $data['all_day'] ?? 0,
            $data['kind'] ?? self::TYPE_EVENT,
            $data['color'] ?? '#3498db',
            $data['reminder_minutes'] ?? null,
            $data['recurrence_rule'] ?? null,
            $data['focus_mode'] ?? 0
        ]);

        $eventId = (int)$this->db->lastInsertId();

        // Create reminder if specified
        if (!empty($data['reminder_minutes'])) {
            $this->createReminder($eventId, (int)$data['reminder_minutes']);
        }

        // Handle recurrence
        if (!empty($data['recurrence_rule'])) {
            $this->createRecurringInstances($eventId, $data, 10);
        }

        // Log history
        $this->logHistory($eventId, 'create', null, $data);

        // Get the full event
        $event = $this->getById($eventId);

        return [
            'success' => true,
            'event' => $event,
            'event_id' => $eventId
        ];
    }

    // ============================================
    // UPDATE EVENT
    // ============================================

    public function update(int $eventId, array $data, string $scope = 'single'): array {
        // Get original event
        $original = $this->getById($eventId);
        if (!$original) {
            return ['success' => false, 'error' => 'Event not found'];
        }

        // Check permissions
        if ($original['family_id'] != $this->familyId) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        // Build update query dynamically
        $updates = [];
        $params = [];

        $allowedFields = [
            'title', 'description', 'notes', 'location',
            'starts_at', 'ends_at', 'timezone', 'all_day',
            'kind', 'color', 'status', 'reminder_minutes',
            'assigned_to', 'focus_mode'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }

        $updates[] = "updated_at = NOW()";
        $params[] = $eventId;
        $params[] = $this->familyId;

        $stmt = $this->db->prepare("
            UPDATE events
            SET " . implode(', ', $updates) . "
            WHERE id = ? AND family_id = ?
        ");
        $stmt->execute($params);

        // Handle recurrence scope
        if ($scope === 'future' && $original['recurrence_parent_id']) {
            // Update all future instances
            $this->updateFutureInstances($original['recurrence_parent_id'], $original['starts_at'], $data);
        } elseif ($scope === 'all' && ($original['recurrence_parent_id'] || $this->hasChildren($eventId))) {
            // Update all instances
            $parentId = $original['recurrence_parent_id'] ?? $eventId;
            $this->updateAllInstances($parentId, $data);
        }

        // Update reminder if changed
        if (isset($data['reminder_minutes'])) {
            $this->updateReminder($eventId, (int)$data['reminder_minutes']);
        }

        // Log history
        $this->logHistory($eventId, 'update', $original, $data);

        return [
            'success' => true,
            'event' => $this->getById($eventId)
        ];
    }

    // ============================================
    // DELETE EVENT
    // ============================================

    public function delete(int $eventId, string $scope = 'single'): array {
        $original = $this->getById($eventId);
        if (!$original) {
            return ['success' => false, 'error' => 'Event not found'];
        }

        if ($original['family_id'] != $this->familyId) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        // Soft delete
        $stmt = $this->db->prepare("
            UPDATE events
            SET status = 'cancelled', updated_at = NOW()
            WHERE id = ? AND family_id = ?
        ");
        $stmt->execute([$eventId, $this->familyId]);

        // Handle recurrence scope
        if ($scope === 'future' && $original['recurrence_parent_id']) {
            $this->deleteFutureInstances($original['recurrence_parent_id'], $original['starts_at']);
        } elseif ($scope === 'all') {
            $parentId = $original['recurrence_parent_id'] ?? $eventId;
            $this->deleteAllInstances($parentId);
        }

        // Log history
        $this->logHistory($eventId, 'delete', $original, null);

        return ['success' => true];
    }

    // ============================================
    // TOGGLE COMPLETE
    // ============================================

    public function toggle(int $eventId): array {
        $event = $this->getById($eventId);
        if (!$event) {
            return ['success' => false, 'error' => 'Event not found'];
        }

        $newStatus = $event['status'] === self::STATUS_DONE
            ? self::STATUS_PENDING
            : self::STATUS_DONE;

        $actualEnd = $newStatus === self::STATUS_DONE ? date('Y-m-d H:i:s') : null;

        $stmt = $this->db->prepare("
            UPDATE events
            SET status = ?, actual_end = ?, updated_at = NOW()
            WHERE id = ? AND family_id = ?
        ");
        $stmt->execute([$newStatus, $actualEnd, $eventId, $this->familyId]);

        // Update productivity stats if marking done
        if ($newStatus === self::STATUS_DONE) {
            $this->updateProductivityStats($event);
        }

        // Log history
        $action = $newStatus === self::STATUS_DONE ? 'complete' : 'uncomplete';
        $this->logHistory($eventId, $action, ['status' => $event['status']], ['status' => $newStatus]);

        return [
            'success' => true,
            'status' => $newStatus
        ];
    }

    // ============================================
    // START FOCUS SESSION
    // ============================================

    public function startFocus(int $eventId): array {
        $event = $this->getById($eventId);
        if (!$event) {
            return ['success' => false, 'error' => 'Event not found'];
        }

        if ($event['status'] !== self::STATUS_PENDING) {
            return ['success' => false, 'error' => 'Event is not pending'];
        }

        $stmt = $this->db->prepare("
            UPDATE events
            SET status = 'in_progress', actual_start = NOW(), updated_at = NOW()
            WHERE id = ? AND family_id = ?
        ");
        $stmt->execute([$eventId, $this->familyId]);

        $this->logHistory($eventId, 'update', ['status' => $event['status']], ['status' => 'in_progress']);

        return [
            'success' => true,
            'status' => self::STATUS_IN_PROGRESS
        ];
    }

    // ============================================
    // END FOCUS SESSION
    // ============================================

    public function endFocus(int $eventId, int $rating = null): array {
        $event = $this->getById($eventId);
        if (!$event) {
            return ['success' => false, 'error' => 'Event not found'];
        }

        $stmt = $this->db->prepare("
            UPDATE events
            SET status = 'done',
                actual_end = NOW(),
                productivity_rating = ?,
                pomodoro_count = pomodoro_count + 1,
                updated_at = NOW()
            WHERE id = ? AND family_id = ?
        ");
        $stmt->execute([$rating, $eventId, $this->familyId]);

        $this->updateProductivityStats($event);

        $this->logHistory($eventId, 'complete', ['status' => $event['status']], [
            'status' => 'done',
            'productivity_rating' => $rating
        ]);

        return [
            'success' => true,
            'status' => self::STATUS_DONE
        ];
    }

    // ============================================
    // UNDO LAST ACTION
    // ============================================

    public function undo(int $eventId): array {
        // Get most recent undoable action
        $stmt = $this->db->prepare("
            SELECT * FROM event_history
            WHERE event_id = ?
            AND is_undone = 0
            AND action IN ('update', 'delete', 'complete')
            ORDER BY changed_at DESC
            LIMIT 1
        ");
        $stmt->execute([$eventId]);
        $history = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$history) {
            return ['success' => false, 'error' => 'Nothing to undo'];
        }

        $oldValues = json_decode($history['old_values'], true);

        if ($history['action'] === 'delete') {
            // Restore event
            $stmt = $this->db->prepare("
                UPDATE events SET status = 'pending', updated_at = NOW()
                WHERE id = ? AND family_id = ?
            ");
            $stmt->execute([$eventId, $this->familyId]);
        } elseif ($oldValues) {
            // Restore old values
            $updates = [];
            $params = [];
            foreach ($oldValues as $field => $value) {
                $updates[] = "$field = ?";
                $params[] = $value;
            }
            $updates[] = "updated_at = NOW()";
            $params[] = $eventId;
            $params[] = $this->familyId;

            $stmt = $this->db->prepare("
                UPDATE events SET " . implode(', ', $updates) . "
                WHERE id = ? AND family_id = ?
            ");
            $stmt->execute($params);
        }

        // Mark history as undone
        $stmt = $this->db->prepare("
            UPDATE event_history
            SET is_undone = 1, undone_at = NOW(), undone_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$this->userId, $history['id']]);

        return [
            'success' => true,
            'restored' => $oldValues
        ];
    }

    // ============================================
    // GET EVENTS
    // ============================================

    public function getById(int $eventId): ?array {
        $stmt = $this->db->prepare("
            SELECT e.*,
                   u.full_name as user_name, u.avatar_color,
                   c.full_name as created_by_name,
                   a.full_name as assigned_to_name, a.avatar_color as assigned_color
            FROM events e
            LEFT JOIN users u ON e.user_id = u.id
            LEFT JOIN users c ON e.created_by = c.id
            LEFT JOIN users a ON e.assigned_to = a.id
            WHERE e.id = ?
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getByDate(string $date): array {
        $stmt = $this->db->prepare("
            SELECT e.*,
                   u.full_name as user_name, u.avatar_color,
                   a.full_name as assigned_to_name, a.avatar_color as assigned_color
            FROM events e
            LEFT JOIN users u ON e.user_id = u.id
            LEFT JOIN users a ON e.assigned_to = a.id
            WHERE e.family_id = ?
            AND DATE(e.starts_at) = ?
            AND e.status != 'cancelled'
            ORDER BY e.starts_at ASC
        ");
        $stmt->execute([$this->familyId, $date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByDateRange(string $startDate, string $endDate): array {
        $stmt = $this->db->prepare("
            SELECT e.*,
                   u.full_name as user_name, u.avatar_color,
                   a.full_name as assigned_to_name, a.avatar_color as assigned_color
            FROM events e
            LEFT JOIN users u ON e.user_id = u.id
            LEFT JOIN users a ON e.assigned_to = a.id
            WHERE e.family_id = ?
            AND DATE(e.starts_at) >= ?
            AND DATE(e.starts_at) <= ?
            AND e.status != 'cancelled'
            ORDER BY e.starts_at ASC
        ");
        $stmt->execute([$this->familyId, $startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUpcoming(int $days = 7, int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT e.*,
                   u.full_name as user_name, u.avatar_color
            FROM events e
            LEFT JOIN users u ON e.user_id = u.id
            WHERE e.family_id = ?
            AND e.starts_at >= NOW()
            AND e.starts_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
            AND e.status IN ('pending', 'in_progress')
            ORDER BY e.starts_at ASC
            LIMIT ?
        ");
        $stmt->execute([$this->familyId, $days, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================
    // CONFLICT DETECTION
    // ============================================

    public function checkConflicts(string $startsAt, string $endsAt, int $userId = null, int $excludeId = null): ?array {
        $userId = $userId ?? $this->userId;

        $sql = "
            SELECT id, title, starts_at, ends_at, kind, color
            FROM events
            WHERE family_id = ?
            AND user_id = ?
            AND status NOT IN ('cancelled', 'done')
            AND (
                (starts_at < ? AND ends_at > ?) OR
                (starts_at >= ? AND starts_at < ?) OR
                (ends_at > ? AND ends_at <= ?)
            )
        ";
        $params = [
            $this->familyId, $userId,
            $endsAt, $startsAt,
            $startsAt, $endsAt,
            $startsAt, $endsAt
        ];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ============================================
    // RECURRENCE HANDLING
    // ============================================

    private function createRecurringInstances(int $parentId, array $data, int $count): void {
        $baseDate = new DateTime($data['starts_at']);
        $duration = (new DateTime($data['ends_at']))->getTimestamp() - $baseDate->getTimestamp();

        for ($i = 1; $i <= $count; $i++) {
            $nextDate = $this->getNextRecurrenceDate($baseDate, $data['recurrence_rule'], $i);
            if (!$nextDate) continue;

            $newStartsAt = $nextDate->format('Y-m-d H:i:s');
            $newEndsAt = (clone $nextDate)->modify("+{$duration} seconds")->format('Y-m-d H:i:s');

            $stmt = $this->db->prepare("
                INSERT INTO events (
                    family_id, user_id, created_by, assigned_to,
                    title, description, notes, location,
                    starts_at, ends_at, timezone, all_day,
                    kind, color, status, reminder_minutes,
                    recurrence_rule, recurrence_parent_id, focus_mode,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, 'pending', ?,
                    ?, ?, ?,
                    NOW(), NOW()
                )
            ");

            $stmt->execute([
                $this->familyId,
                $data['assigned_to'] ?? $this->userId,
                $this->userId,
                $data['assigned_to'] ?? null,
                $data['title'],
                $data['description'] ?? null,
                $data['notes'] ?? null,
                $data['location'] ?? null,
                $newStartsAt,
                $newEndsAt,
                $data['timezone'] ?? 'Africa/Johannesburg',
                $data['all_day'] ?? 0,
                $data['kind'] ?? self::TYPE_EVENT,
                $data['color'] ?? '#3498db',
                $data['reminder_minutes'] ?? null,
                $data['recurrence_rule'],
                $parentId,
                $data['focus_mode'] ?? 0
            ]);

            $childId = (int)$this->db->lastInsertId();

            // Create reminder for child
            if (!empty($data['reminder_minutes'])) {
                $this->createReminder($childId, (int)$data['reminder_minutes']);
            }
        }
    }

    private function getNextRecurrenceDate(DateTime $baseDate, string $rule, int $instance): ?DateTime {
        $nextDate = clone $baseDate;

        switch ($rule) {
            case self::RECUR_DAILY:
                $nextDate->modify("+{$instance} day");
                break;

            case self::RECUR_WEEKDAYS:
                $daysAdded = 0;
                while ($daysAdded < $instance) {
                    $nextDate->modify('+1 day');
                    if ($nextDate->format('N') < 6) { // Mon-Fri
                        $daysAdded++;
                    }
                }
                break;

            case self::RECUR_WEEKLY:
                $nextDate->modify("+{$instance} week");
                break;

            case self::RECUR_BIWEEKLY:
                $nextDate->modify("+" . ($instance * 2) . " weeks");
                break;

            case self::RECUR_MONTHLY:
                $nextDate->modify("+{$instance} month");
                break;

            default:
                return null;
        }

        return $nextDate;
    }

    private function hasChildren(int $eventId): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ?
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetchColumn() > 0;
    }

    private function updateFutureInstances(int $parentId, string $fromDate, array $data): void {
        $allowedFields = ['title', 'kind', 'reminder_minutes', 'focus_mode', 'color'];
        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) return;

        $updates[] = "updated_at = NOW()";
        $params[] = $parentId;
        $params[] = $fromDate;
        $params[] = $this->familyId;

        $stmt = $this->db->prepare("
            UPDATE events
            SET " . implode(', ', $updates) . "
            WHERE recurrence_parent_id = ? AND starts_at >= ? AND family_id = ?
        ");
        $stmt->execute($params);
    }

    private function updateAllInstances(int $parentId, array $data): void {
        $this->updateFutureInstances($parentId, '1970-01-01', $data);
    }

    private function deleteFutureInstances(int $parentId, string $fromDate): void {
        $stmt = $this->db->prepare("
            UPDATE events
            SET status = 'cancelled', updated_at = NOW()
            WHERE recurrence_parent_id = ? AND starts_at >= ? AND family_id = ?
        ");
        $stmt->execute([$parentId, $fromDate, $this->familyId]);
    }

    private function deleteAllInstances(int $parentId): void {
        $stmt = $this->db->prepare("
            UPDATE events
            SET status = 'cancelled', updated_at = NOW()
            WHERE (recurrence_parent_id = ? OR id = ?) AND family_id = ?
        ");
        $stmt->execute([$parentId, $parentId, $this->familyId]);
    }

    // ============================================
    // REMINDERS
    // ============================================

    private function createReminder(int $eventId, int $minutesBefore): void {
        $event = $this->getById($eventId);
        if (!$event) return;

        $nextTrigger = (new DateTime($event['starts_at']))
            ->modify("-{$minutesBefore} minutes")
            ->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            INSERT INTO event_reminders (event_id, trigger_offset, trigger_type, next_trigger_at)
            VALUES (?, ?, 'push', ?)
            ON DUPLICATE KEY UPDATE
            trigger_offset = VALUES(trigger_offset),
            next_trigger_at = VALUES(next_trigger_at),
            is_sent = 0
        ");
        $stmt->execute([$eventId, $minutesBefore, $nextTrigger]);
    }

    private function updateReminder(int $eventId, int $minutesBefore): void {
        // Delete existing reminders
        $stmt = $this->db->prepare("DELETE FROM event_reminders WHERE event_id = ?");
        $stmt->execute([$eventId]);

        // Create new if minutes > 0
        if ($minutesBefore > 0) {
            $this->createReminder($eventId, $minutesBefore);
        }
    }

    // ============================================
    // PRODUCTIVITY STATS
    // ============================================

    private function updateProductivityStats(array $event): void {
        $eventDate = date('Y-m-d', strtotime($event['starts_at']));
        $duration = (strtotime($event['ends_at']) - strtotime($event['starts_at'])) / 60;

        $column = match($event['kind']) {
            self::TYPE_STUDY => 'study_minutes',
            self::TYPE_WORK => 'work_minutes',
            self::TYPE_FOCUS => 'focus_minutes',
            default => null
        };

        if (!$column) return;

        $stmt = $this->db->prepare("
            INSERT INTO schedule_productivity
            (user_id, family_id, date, {$column}, completed_tasks, total_tasks)
            VALUES (?, ?, ?, ?, 1, 1)
            ON DUPLICATE KEY UPDATE
            {$column} = {$column} + VALUES({$column}),
            completed_tasks = completed_tasks + 1
        ");
        $stmt->execute([
            $event['user_id'],
            $this->familyId,
            $eventDate,
            $duration
        ]);
    }

    // ============================================
    // HISTORY LOGGING
    // ============================================

    private function logHistory(int $eventId, string $action, ?array $oldValues, ?array $newValues): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO event_history (event_id, action, changed_by, old_values, new_values)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $eventId,
                $action,
                $this->userId,
                $oldValues ? json_encode($oldValues) : null,
                $newValues ? json_encode($newValues) : null
            ]);
        } catch (Exception $e) {
            error_log('EventEngine::logHistory error: ' . $e->getMessage());
        }
    }

    // ============================================
    // BULK OPERATIONS
    // ============================================

    public function bulkMarkDone(array $eventIds): int {
        if (empty($eventIds)) return 0;

        $placeholders = rtrim(str_repeat('?,', count($eventIds)), ',');

        $stmt = $this->db->prepare("
            UPDATE events
            SET status = 'done', actual_end = NOW(), updated_at = NOW()
            WHERE id IN ($placeholders) AND family_id = ?
        ");
        $stmt->execute([...$eventIds, $this->familyId]);

        return $stmt->rowCount();
    }

    public function bulkChangeType(array $eventIds, string $kind): int {
        if (empty($eventIds)) return 0;

        $placeholders = rtrim(str_repeat('?,', count($eventIds)), ',');

        $stmt = $this->db->prepare("
            UPDATE events
            SET kind = ?, updated_at = NOW()
            WHERE id IN ($placeholders) AND family_id = ?
        ");
        $stmt->execute([$kind, ...$eventIds, $this->familyId]);

        return $stmt->rowCount();
    }

    public function bulkAssign(array $eventIds, int $assignTo): int {
        if (empty($eventIds)) return 0;

        $placeholders = rtrim(str_repeat('?,', count($eventIds)), ',');

        $stmt = $this->db->prepare("
            UPDATE events
            SET assigned_to = ?, updated_at = NOW()
            WHERE id IN ($placeholders) AND family_id = ?
        ");
        $stmt->execute([$assignTo, ...$eventIds, $this->familyId]);

        return $stmt->rowCount();
    }

    public function bulkDelete(array $eventIds): int {
        if (empty($eventIds)) return 0;

        $placeholders = rtrim(str_repeat('?,', count($eventIds)), ',');

        $stmt = $this->db->prepare("
            UPDATE events
            SET status = 'cancelled', updated_at = NOW()
            WHERE id IN ($placeholders) AND family_id = ?
        ");
        $stmt->execute([...$eventIds, $this->familyId]);

        return $stmt->rowCount();
    }

    public function clearDone(string $date): int {
        $stmt = $this->db->prepare("
            UPDATE events
            SET status = 'cancelled', updated_at = NOW()
            WHERE family_id = ? AND DATE(starts_at) = ? AND status = 'done'
        ");
        $stmt->execute([$this->familyId, $date]);

        return $stmt->rowCount();
    }

    // ============================================
    // VALIDATION
    // ============================================

    private function validateRequired(array $data, array $fields): void {
        foreach ($fields as $field) {
            if (empty($data[$field])) {
                throw new InvalidArgumentException("Missing required field: $field");
            }
        }
    }

    // ============================================
    // STATISTICS
    // ============================================

    public function getStats(string $startDate, string $endDate): array {
        $stmt = $this->db->prepare("
            SELECT
                kind,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as completed,
                SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)) as total_minutes,
                AVG(productivity_rating) as avg_rating
            FROM events
            WHERE family_id = ?
            AND DATE(starts_at) >= ?
            AND DATE(starts_at) <= ?
            AND status != 'cancelled'
            GROUP BY kind
        ");
        $stmt->execute([$this->familyId, $startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProductivityData(int $userId, string $startDate, string $endDate): array {
        $stmt = $this->db->prepare("
            SELECT * FROM schedule_productivity
            WHERE user_id = ?
            AND date BETWEEN ? AND ?
            ORDER BY date DESC
        ");
        $stmt->execute([$userId, $startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
