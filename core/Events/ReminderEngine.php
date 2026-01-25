<?php
/**
 * ============================================
 * REMINDER ENGINE - ROBUST REMINDER SYSTEM
 * ============================================
 * Handles all reminder operations:
 * - Multiple reminders per event
 * - Snooze support
 * - Offline retry
 * - Sync with event changes
 * ============================================
 */

class ReminderEngine {
    private $db;
    private static $instance = null;

    // Trigger types
    const TYPE_PUSH = 'push';
    const TYPE_EMAIL = 'email';
    const TYPE_SMS = 'sms';
    const TYPE_SOUND = 'sound';
    const TYPE_SILENT = 'silent';

    // Snooze intervals (minutes)
    const SNOOZE_5_MIN = 5;
    const SNOOZE_10_MIN = 10;
    const SNOOZE_15_MIN = 15;
    const SNOOZE_30_MIN = 30;
    const SNOOZE_1_HOUR = 60;

    // Max retries for failed sends
    const MAX_RETRIES = 3;

    private function __construct($db) {
        $this->db = $db;
    }

    public static function getInstance($db) {
        if (self::$instance === null) {
            self::$instance = new self($db);
        }
        return self::$instance;
    }

    // ============================================
    // CREATE REMINDER
    // ============================================

    public function create(int $eventId, int $minutesBefore, string $type = self::TYPE_PUSH): int {
        // Get event to calculate trigger time
        $stmt = $this->db->prepare("SELECT starts_at FROM events WHERE id = ?");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            throw new InvalidArgumentException("Event not found: $eventId");
        }

        $nextTrigger = (new DateTime($event['starts_at']))
            ->modify("-{$minutesBefore} minutes")
            ->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            INSERT INTO event_reminders
            (event_id, trigger_offset, trigger_type, next_trigger_at, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$eventId, $minutesBefore, $type, $nextTrigger]);

        return (int)$this->db->lastInsertId();
    }

    // ============================================
    // CREATE MULTIPLE REMINDERS
    // ============================================

    public function createMultiple(int $eventId, array $reminders): array {
        $ids = [];
        foreach ($reminders as $reminder) {
            $minutes = $reminder['minutes'] ?? $reminder;
            $type = $reminder['type'] ?? self::TYPE_PUSH;
            $ids[] = $this->create($eventId, $minutes, $type);
        }
        return $ids;
    }

    // ============================================
    // UPDATE REMINDER
    // ============================================

    public function update(int $reminderId, array $data): bool {
        $updates = [];
        $params = [];

        if (isset($data['trigger_offset'])) {
            $updates[] = "trigger_offset = ?";
            $params[] = $data['trigger_offset'];
        }

        if (isset($data['trigger_type'])) {
            $updates[] = "trigger_type = ?";
            $params[] = $data['trigger_type'];
        }

        if (empty($updates)) return false;

        // Recalculate next_trigger_at if offset changed
        if (isset($data['trigger_offset'])) {
            $stmt = $this->db->prepare("
                SELECT e.starts_at FROM event_reminders r
                JOIN events e ON r.event_id = e.id
                WHERE r.id = ?
            ");
            $stmt->execute([$reminderId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                $nextTrigger = (new DateTime($result['starts_at']))
                    ->modify("-{$data['trigger_offset']} minutes")
                    ->format('Y-m-d H:i:s');
                $updates[] = "next_trigger_at = ?";
                $params[] = $nextTrigger;
            }
        }

        $updates[] = "updated_at = NOW()";
        $updates[] = "is_sent = 0"; // Reset sent status
        $params[] = $reminderId;

        $stmt = $this->db->prepare("
            UPDATE event_reminders
            SET " . implode(', ', $updates) . "
            WHERE id = ?
        ");

        return $stmt->execute($params);
    }

    // ============================================
    // DELETE REMINDER
    // ============================================

    public function delete(int $reminderId): bool {
        $stmt = $this->db->prepare("DELETE FROM event_reminders WHERE id = ?");
        return $stmt->execute([$reminderId]);
    }

    public function deleteForEvent(int $eventId): bool {
        $stmt = $this->db->prepare("DELETE FROM event_reminders WHERE event_id = ?");
        return $stmt->execute([$eventId]);
    }

    // ============================================
    // SNOOZE REMINDER
    // ============================================

    public function snooze(int $reminderId, int $minutes = self::SNOOZE_10_MIN): bool {
        $snoozeUntil = (new DateTime())->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            UPDATE event_reminders
            SET snooze_count = snooze_count + 1,
                snooze_until = ?,
                next_trigger_at = ?,
                is_sent = 0,
                updated_at = NOW()
            WHERE id = ?
        ");

        return $stmt->execute([$snoozeUntil, $snoozeUntil, $reminderId]);
    }

    // ============================================
    // GET DUE REMINDERS
    // ============================================

    public function getDueReminders(int $minutesAhead = 5): array {
        $stmt = $this->db->prepare("
            SELECT
                r.*,
                e.id as event_id,
                e.title,
                e.kind,
                e.starts_at,
                e.ends_at,
                e.user_id,
                e.family_id,
                e.location,
                u.full_name as user_name
            FROM event_reminders r
            JOIN events e ON r.event_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE r.is_sent = 0
            AND r.retry_count < r.max_retries
            AND (
                r.next_trigger_at <= NOW()
                OR r.next_trigger_at <= DATE_ADD(NOW(), INTERVAL ? MINUTE)
            )
            AND (r.snooze_until IS NULL OR r.snooze_until <= NOW())
            AND e.status = 'pending'
            AND e.starts_at > NOW()
            ORDER BY r.next_trigger_at ASC
        ");
        $stmt->execute([$minutesAhead]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================
    // MARK AS SENT
    // ============================================

    public function markAsSent(int $reminderId): bool {
        $stmt = $this->db->prepare("
            UPDATE event_reminders
            SET is_sent = 1,
                sent_at = NOW(),
                last_triggered_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$reminderId]);
    }

    // ============================================
    // MARK AS FAILED (FOR RETRY)
    // ============================================

    public function markAsFailed(int $reminderId, string $error = null): bool {
        // Calculate exponential backoff: 2^retry_count minutes
        $stmt = $this->db->prepare("SELECT retry_count FROM event_reminders WHERE id = ?");
        $stmt->execute([$reminderId]);
        $reminder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reminder) return false;

        $retryCount = $reminder['retry_count'] + 1;
        $backoffMinutes = min(pow(2, $retryCount), 30); // Max 30 min backoff
        $nextRetry = (new DateTime())->modify("+{$backoffMinutes} minutes")->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            UPDATE event_reminders
            SET retry_count = ?,
                next_trigger_at = ?,
                last_triggered_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");

        return $stmt->execute([$retryCount, $nextRetry, $reminderId]);
    }

    // ============================================
    // SYNC REMINDERS WITH EVENT CHANGES
    // ============================================

    public function syncWithEvent(int $eventId): void {
        // Get event
        $stmt = $this->db->prepare("SELECT starts_at, status FROM events WHERE id = ?");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) return;

        // If event is cancelled or done, mark reminders as sent
        if (in_array($event['status'], ['cancelled', 'done'])) {
            $stmt = $this->db->prepare("
                UPDATE event_reminders
                SET is_sent = 1, updated_at = NOW()
                WHERE event_id = ?
            ");
            $stmt->execute([$eventId]);
            return;
        }

        // Recalculate next_trigger_at for all reminders
        $stmt = $this->db->prepare("
            UPDATE event_reminders
            SET next_trigger_at = DATE_SUB(?, INTERVAL trigger_offset MINUTE),
                is_sent = CASE
                    WHEN DATE_SUB(?, INTERVAL trigger_offset MINUTE) < NOW() THEN 1
                    ELSE 0
                END,
                updated_at = NOW()
            WHERE event_id = ?
            AND is_sent = 0
        ");
        $stmt->execute([$event['starts_at'], $event['starts_at'], $eventId]);
    }

    // ============================================
    // GET REMINDERS FOR EVENT
    // ============================================

    public function getForEvent(int $eventId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM event_reminders
            WHERE event_id = ?
            ORDER BY trigger_offset ASC
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================
    // GET UPCOMING REMINDERS FOR USER
    // ============================================

    public function getUpcomingForUser(int $userId, int $hours = 24): array {
        $stmt = $this->db->prepare("
            SELECT
                r.*,
                e.title,
                e.kind,
                e.starts_at,
                e.color
            FROM event_reminders r
            JOIN events e ON r.event_id = e.id
            WHERE e.user_id = ?
            AND r.is_sent = 0
            AND e.status = 'pending'
            AND e.starts_at <= DATE_ADD(NOW(), INTERVAL ? HOUR)
            AND e.starts_at > NOW()
            ORDER BY r.next_trigger_at ASC
        ");
        $stmt->execute([$userId, $hours]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================
    // CLEANUP OLD REMINDERS
    // ============================================

    public function cleanup(int $daysOld = 30): int {
        $stmt = $this->db->prepare("
            DELETE r FROM event_reminders r
            JOIN events e ON r.event_id = e.id
            WHERE e.starts_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$daysOld]);
        return $stmt->rowCount();
    }

    // ============================================
    // GET SNOOZE OPTIONS
    // ============================================

    public static function getSnoozeOptions(): array {
        return [
            ['value' => self::SNOOZE_5_MIN, 'label' => '5 minutes'],
            ['value' => self::SNOOZE_10_MIN, 'label' => '10 minutes'],
            ['value' => self::SNOOZE_15_MIN, 'label' => '15 minutes'],
            ['value' => self::SNOOZE_30_MIN, 'label' => '30 minutes'],
            ['value' => self::SNOOZE_1_HOUR, 'label' => '1 hour'],
        ];
    }

    // ============================================
    // PRESET REMINDER OPTIONS
    // ============================================

    public static function getPresetOptions(): array {
        return [
            ['value' => 0, 'label' => 'At event time'],
            ['value' => 5, 'label' => '5 minutes before'],
            ['value' => 10, 'label' => '10 minutes before'],
            ['value' => 15, 'label' => '15 minutes before'],
            ['value' => 30, 'label' => '30 minutes before'],
            ['value' => 60, 'label' => '1 hour before'],
            ['value' => 120, 'label' => '2 hours before'],
            ['value' => 1440, 'label' => '1 day before'],
            ['value' => 2880, 'label' => '2 days before'],
        ];
    }
}
