<?php
/**
 * ============================================
 * SYNC ENGINE - EVENT SYNCHRONIZATION
 * ============================================
 * Handles sync between:
 * - Internal calendar and schedule
 * - Event edits propagation
 * - Reminder sync
 * - Conflict resolution
 * ============================================
 */

class SyncEngine {
    private $db;
    private static $instance = null;

    // Sync statuses
    const STATUS_PENDING = 'pending';
    const STATUS_SYNCED = 'synced';
    const STATUS_CONFLICT = 'conflict';
    const STATUS_FAILED = 'failed';

    // Conflict resolution strategies
    const RESOLVE_LATEST_WINS = 'latest_wins';
    const RESOLVE_MANUAL = 'manual';
    const RESOLVE_SOURCE_WINS = 'source_wins';
    const RESOLVE_TARGET_WINS = 'target_wins';

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
    // SYNC QUEUE MANAGEMENT
    // ============================================

    /**
     * Add item to sync queue
     */
    public function queueSync(int $eventId, string $action, array $changes = []): int {
        $stmt = $this->db->prepare("
            INSERT INTO event_sync_queue
            (event_id, action, changes_json, status, created_at)
            VALUES (?, ?, ?, 'pending', NOW())
            ON DUPLICATE KEY UPDATE
            action = VALUES(action),
            changes_json = VALUES(changes_json),
            status = 'pending',
            retry_count = 0,
            updated_at = NOW()
        ");

        $stmt->execute([
            $eventId,
            $action,
            json_encode($changes)
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get pending sync items
     */
    public function getPendingSync(int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT sq.*, e.title, e.family_id, e.updated_at as event_updated_at
            FROM event_sync_queue sq
            JOIN events e ON sq.event_id = e.id
            WHERE sq.status = 'pending'
            AND sq.retry_count < 5
            ORDER BY sq.created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark sync as complete
     */
    public function markSynced(int $queueId): bool {
        $stmt = $this->db->prepare("
            UPDATE event_sync_queue
            SET status = 'synced', synced_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$queueId]);
    }

    /**
     * Mark sync as failed
     */
    public function markFailed(int $queueId, string $error = null): bool {
        $stmt = $this->db->prepare("
            UPDATE event_sync_queue
            SET status = 'failed',
                retry_count = retry_count + 1,
                error_message = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$error, $queueId]);
    }

    // ============================================
    // EVENT VERSIONING
    // ============================================

    /**
     * Get event version (for conflict detection)
     */
    public function getEventVersion(int $eventId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, updated_at, status,
                   MD5(CONCAT(title, starts_at, ends_at, status)) as content_hash
            FROM events WHERE id = ?
        ");
        $stmt->execute([$eventId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Compare versions and detect conflicts
     */
    public function detectConflict(int $eventId, string $lastKnownHash): array {
        $current = $this->getEventVersion($eventId);

        if (!$current) {
            return ['conflict' => false, 'reason' => 'deleted'];
        }

        if ($current['content_hash'] !== $lastKnownHash) {
            return [
                'conflict' => true,
                'reason' => 'modified',
                'current_hash' => $current['content_hash'],
                'current_updated' => $current['updated_at']
            ];
        }

        return ['conflict' => false];
    }

    // ============================================
    // CONFLICT RESOLUTION
    // ============================================

    /**
     * Resolve conflict between two versions
     */
    public function resolveConflict(
        int $eventId,
        array $localChanges,
        array $remoteChanges,
        string $strategy = self::RESOLVE_LATEST_WINS
    ): array {
        switch ($strategy) {
            case self::RESOLVE_LATEST_WINS:
                // Compare timestamps, use most recent
                $localTime = strtotime($localChanges['updated_at'] ?? '1970-01-01');
                $remoteTime = strtotime($remoteChanges['updated_at'] ?? '1970-01-01');

                if ($localTime >= $remoteTime) {
                    return $this->applyChanges($eventId, $localChanges);
                } else {
                    return $this->applyChanges($eventId, $remoteChanges);
                }

            case self::RESOLVE_SOURCE_WINS:
                return $this->applyChanges($eventId, $localChanges);

            case self::RESOLVE_TARGET_WINS:
                return $this->applyChanges($eventId, $remoteChanges);

            case self::RESOLVE_MANUAL:
            default:
                // Store conflict for manual resolution
                return $this->storeConflict($eventId, $localChanges, $remoteChanges);
        }
    }

    /**
     * Apply changes to event
     */
    private function applyChanges(int $eventId, array $changes): array {
        $allowedFields = [
            'title', 'description', 'notes', 'location',
            'starts_at', 'ends_at', 'timezone', 'all_day',
            'kind', 'color', 'status', 'reminder_minutes'
        ];

        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $changes)) {
                $updates[] = "$field = ?";
                $params[] = $changes[$field];
            }
        }

        if (empty($updates)) {
            return ['success' => true, 'message' => 'No changes to apply'];
        }

        $updates[] = "updated_at = NOW()";
        $params[] = $eventId;

        $stmt = $this->db->prepare("
            UPDATE events SET " . implode(', ', $updates) . " WHERE id = ?
        ");
        $stmt->execute($params);

        return ['success' => true, 'applied' => array_keys($changes)];
    }

    /**
     * Store conflict for manual resolution
     */
    private function storeConflict(int $eventId, array $local, array $remote): array {
        $stmt = $this->db->prepare("
            INSERT INTO event_conflicts
            (event_id, local_changes, remote_changes, status, created_at)
            VALUES (?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([
            $eventId,
            json_encode($local),
            json_encode($remote)
        ]);

        return [
            'success' => false,
            'conflict_id' => (int)$this->db->lastInsertId(),
            'requires_manual_resolution' => true
        ];
    }

    /**
     * Get pending conflicts
     */
    public function getPendingConflicts(int $familyId): array {
        $stmt = $this->db->prepare("
            SELECT c.*, e.title, e.starts_at, e.family_id
            FROM event_conflicts c
            JOIN events e ON c.event_id = e.id
            WHERE e.family_id = ?
            AND c.status = 'pending'
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$familyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Resolve conflict manually
     */
    public function resolveConflictManually(int $conflictId, string $choice): bool {
        $stmt = $this->db->prepare("
            SELECT * FROM event_conflicts WHERE id = ?
        ");
        $stmt->execute([$conflictId]);
        $conflict = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$conflict) return false;

        $changes = $choice === 'local'
            ? json_decode($conflict['local_changes'], true)
            : json_decode($conflict['remote_changes'], true);

        $this->applyChanges($conflict['event_id'], $changes);

        $stmt = $this->db->prepare("
            UPDATE event_conflicts
            SET status = 'resolved', resolved_at = NOW(), resolved_with = ?
            WHERE id = ?
        ");
        return $stmt->execute([$choice, $conflictId]);
    }

    // ============================================
    // REMINDER SYNC
    // ============================================

    /**
     * Sync reminders after event change
     */
    public function syncReminders(int $eventId): void {
        require_once __DIR__ . '/ReminderEngine.php';
        $reminderEngine = ReminderEngine::getInstance($this->db);
        $reminderEngine->syncWithEvent($eventId);
    }

    /**
     * Sync all reminders for recurrence
     */
    public function syncRecurrenceReminders(int $parentId): void {
        require_once __DIR__ . '/ReminderEngine.php';
        $reminderEngine = ReminderEngine::getInstance($this->db);

        $stmt = $this->db->prepare("
            SELECT id FROM events
            WHERE recurrence_parent_id = ? OR id = ?
        ");
        $stmt->execute([$parentId, $parentId]);
        $events = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($events as $eventId) {
            $reminderEngine->syncWithEvent($eventId);
        }
    }

    // ============================================
    // FAMILY SYNC
    // ============================================

    /**
     * Broadcast event change to family members
     */
    public function broadcastChange(int $eventId, string $action, int $changedBy): void {
        $stmt = $this->db->prepare("
            SELECT e.family_id, e.title, u.full_name as changed_by_name
            FROM events e
            JOIN users u ON u.id = ?
            WHERE e.id = ?
        ");
        $stmt->execute([$changedBy, $eventId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) return;

        // Get family members except the one who made the change
        $stmt = $this->db->prepare("
            SELECT id FROM users
            WHERE family_id = ? AND id != ? AND status = 'active'
        ");
        $stmt->execute([$data['family_id'], $changedBy]);
        $members = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Create sync notification for each member
        foreach ($members as $memberId) {
            $this->createSyncNotification($memberId, $eventId, $action, $data);
        }
    }

    /**
     * Create sync notification
     */
    private function createSyncNotification(int $userId, int $eventId, string $action, array $data): void {
        $messages = [
            'create' => "added \"{$data['title']}\" to the calendar",
            'update' => "updated \"{$data['title']}\"",
            'delete' => "removed \"{$data['title']}\" from the calendar",
            'complete' => "completed \"{$data['title']}\""
        ];

        $message = $data['changed_by_name'] . ' ' . ($messages[$action] ?? "modified \"{$data['title']}\"");

        // Use notification manager if available
        if (class_exists('NotificationManager')) {
            $notifManager = NotificationManager::getInstance($this->db);
            $notifManager->create([
                'user_id' => $userId,
                'type' => 'calendar',
                'title' => 'Calendar Update',
                'message' => $message,
                'priority' => 'low',
                'action_url' => '/calendar/',
                'data' => ['event_id' => $eventId, 'action' => $action]
            ]);
        }
    }

    // ============================================
    // BATCH SYNC
    // ============================================

    /**
     * Sync all pending items
     */
    public function processSyncQueue(): array {
        $pending = $this->getPendingSync();
        $results = ['synced' => 0, 'failed' => 0, 'conflicts' => 0];

        foreach ($pending as $item) {
            try {
                // Sync reminders
                $this->syncReminders($item['event_id']);

                // If recurrence parent, sync all children
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM events WHERE recurrence_parent_id = ?
                ");
                $stmt->execute([$item['event_id']]);
                if ($stmt->fetchColumn() > 0) {
                    $this->syncRecurrenceReminders($item['event_id']);
                }

                // Broadcast to family
                $changes = json_decode($item['changes_json'], true) ?? [];
                if (!empty($changes['changed_by'])) {
                    $this->broadcastChange($item['event_id'], $item['action'], $changes['changed_by']);
                }

                $this->markSynced($item['id']);
                $results['synced']++;

            } catch (Exception $e) {
                $this->markFailed($item['id'], $e->getMessage());
                $results['failed']++;
            }
        }

        return $results;
    }

    // ============================================
    // OFFLINE SYNC SUPPORT
    // ============================================

    /**
     * Get changes since last sync for offline support
     */
    public function getChangesSince(int $familyId, string $lastSyncTime): array {
        $stmt = $this->db->prepare("
            SELECT
                e.*,
                CASE
                    WHEN e.status = 'cancelled' THEN 'delete'
                    WHEN e.created_at > ? THEN 'create'
                    ELSE 'update'
                END as sync_action
            FROM events e
            WHERE e.family_id = ?
            AND (e.updated_at > ? OR e.created_at > ?)
            ORDER BY e.updated_at ASC
        ");
        $stmt->execute([$lastSyncTime, $familyId, $lastSyncTime, $lastSyncTime]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Apply offline changes
     */
    public function applyOfflineChanges(int $familyId, array $changes): array {
        $results = ['applied' => 0, 'conflicts' => 0, 'errors' => []];

        require_once __DIR__ . '/EventEngine.php';

        foreach ($changes as $change) {
            try {
                $eventEngine = EventEngine::getInstance($this->db);
                $eventEngine->setUser($change['user_id'], $familyId);

                switch ($change['action']) {
                    case 'create':
                        $result = $eventEngine->create($change['data']);
                        if ($result['success']) $results['applied']++;
                        break;

                    case 'update':
                        // Check for conflict
                        if (!empty($change['last_known_hash'])) {
                            $conflict = $this->detectConflict($change['event_id'], $change['last_known_hash']);
                            if ($conflict['conflict']) {
                                $results['conflicts']++;
                                $this->storeConflict($change['event_id'], $change['data'], []);
                                continue 2;
                            }
                        }
                        $result = $eventEngine->update($change['event_id'], $change['data']);
                        if ($result['success']) $results['applied']++;
                        break;

                    case 'delete':
                        $result = $eventEngine->delete($change['event_id']);
                        if ($result['success']) $results['applied']++;
                        break;
                }
            } catch (Exception $e) {
                $results['errors'][] = [
                    'change' => $change,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    // ============================================
    // CLEANUP
    // ============================================

    /**
     * Clean up old sync queue entries
     */
    public function cleanup(int $daysOld = 30): int {
        $stmt = $this->db->prepare("
            DELETE FROM event_sync_queue
            WHERE status IN ('synced', 'failed')
            AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$daysOld]);
        $count = $stmt->rowCount();

        // Also clean resolved conflicts
        $stmt = $this->db->prepare("
            DELETE FROM event_conflicts
            WHERE status = 'resolved'
            AND resolved_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$daysOld]);
        $count += $stmt->rowCount();

        return $count;
    }
}
