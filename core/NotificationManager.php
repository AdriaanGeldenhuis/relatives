<?php
/**
 * ============================================
 * NOTIFICATION MANAGER - ENHANCED WITH FCM
 * ============================================
 */

require_once __DIR__ . '/PushOutbox.php';

class NotificationManager {
    private $db;
    private static $instance = null;
    private FirebaseMessaging $firebase;
    private PushOutbox $outbox;
    
    const TYPE_MESSAGE = 'message';
    const TYPE_SHOPPING = 'shopping';
    const TYPE_CALENDAR = 'calendar';
    const TYPE_SCHEDULE = 'schedule';
    const TYPE_TRACKING = 'tracking';
    const TYPE_WEATHER = 'weather';
    const TYPE_NOTE = 'note';
    const TYPE_SYSTEM = 'system';
    
    const PRIORITY_LOW = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';
    
    private function __construct($db) {
        $this->db = $db;

        $this->firebase = new FirebaseMessaging();
        $this->outbox = new PushOutbox($db);
    }

    public static function getInstance($db) {
        if (self::$instance === null) {
            self::$instance = new self($db);
        }
        return self::$instance;
    }
    
    /**
     * Create a notification
     */
    public function create(array $data) {
        $queued = PushOutbox::available($this->db);
        $insideTransaction = $this->db->inTransaction();
        $ownTransaction = $queued && !$insideTransaction;
        try {
            foreach (['user_id', 'type', 'title', 'message'] as $field) {
                if (!isset($data[$field]) || $data[$field] === '') {
                    throw new InvalidArgumentException("Missing required field: $field");
                }
            }
            $category = $data['category'] ?? $data['type'];
            $target = $data['device_target'] ?? null;
            if ($target === null && !$this->isNotificationEnabled((int) $data['user_id'], $category)) {
                return false;
            }
            $data['action_url'] = $this->actionPath($data);
            if ($ownTransaction) {
                $this->db->beginTransaction();
            }

            // Insert notification
            $stmt = $this->db->prepare("
                INSERT INTO notifications (
                    user_id, from_user_id, type, priority, category,
                    title, message, icon, sound, vibrate,
                    action_url, data_json, requires_interaction, expires_at,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                (int)$data['user_id'],
                $data['from_user_id'] ?? null,
                $data['type'],
                $data['priority'] ?? self::PRIORITY_NORMAL,
                $data['category'] ?? $data['type'],
                $data['title'],
                $data['message'],
                $data['icon'] ?? $this->getDefaultIcon($data['type']),
                $data['sound'] ?? null,
                isset($data['vibrate']) ? (int)$data['vibrate'] : 1,
                $data['action_url'] ?? null,
                isset($data['data']) ? json_encode($data['data']) : null,
                isset($data['requires_interaction']) ? (int)$data['requires_interaction'] : 0,
                $data['expires_at'] ?? null
            ]);
            
            $notificationId = (int)$this->db->lastInsertId();
            
            if (($data['send_push'] ?? true) !== false) {
                if ($queued) {
                    $this->outbox->enqueue($notificationId, (int) $data['user_id'], $target, $data['push_expires_unix'] ?? null);
                    $this->outbox->sendAfterResponse($notificationId);
                } elseif ($data['type'] !== self::TYPE_TRACKING) {
                    $this->legacyPushAfterCommit($notificationId, $data, $category, $insideTransaction);
                }
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
            return $notificationId;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($insideTransaction) {
                throw $e;
            }
            error_log('Notification could not be saved: ' . $e->getMessage());
            return false;
        }
    }

    private function actionPath(array $data): string {
        $entity = $data['data'] ?? [];
        $id = match ($data['type']) {
            self::TYPE_MESSAGE => (int) ($entity['message_id'] ?? 0),
            self::TYPE_SHOPPING => (int) ($entity['list_id'] ?? 0),
            self::TYPE_CALENDAR => (int) ($entity['event_id'] ?? 0),
            self::TYPE_SCHEDULE => (int) ($entity['task_id'] ?? $entity['event_id'] ?? 0),
            self::TYPE_NOTE => (int) ($entity['note_id'] ?? 0),
            default => 0,
        };
        if ($id > 0) {
            return match ($data['type']) {
                self::TYPE_MESSAGE => '/messages/?message=' . $id,
                self::TYPE_SHOPPING => '/shopping/?list=' . $id,
                self::TYPE_CALENDAR => '/calendar/?event=' . $id,
                self::TYPE_SCHEDULE => '/schedule/?event=' . $id,
                self::TYPE_NOTE => '/notes/?note=' . $id,
            };
        }
        return PushOutbox::safePath($data['action_url'] ?? null);
    }

    private function legacyPushAfterCommit(int $notificationId, array $data, string $category, bool $defer): void {
        $priority = $data['priority'] ?? self::PRIORITY_NORMAL;
        if ((!in_array($priority, [self::PRIORITY_HIGH, self::PRIORITY_URGENT], true)
            && $this->isQuietHours((int) $data['user_id'], $category))
            || !$this->isPushEnabled((int) $data['user_id'], $category)) {
            return;
        }
        if (!$defer) {
            $this->sendPushNotification($notificationId, $data);
            return;
        }
        register_shutdown_function(function () use ($notificationId, $data): void {
            if ($this->db->inTransaction()) {
                return;
            }
            $stmt = $this->db->prepare('SELECT 1 FROM notifications WHERE id = ? AND user_id = ?');
            $stmt->execute([$notificationId, $data['user_id']]);
            if ($stmt->fetchColumn()) {
                $this->sendPushNotification($notificationId, $data);
            }
        });
    }

    /**
     * Send push notification via FCM
     */
    private function sendPushNotification(int $notificationId, array $data) {
        if (!$this->firebase) {
            $this->logDelivery($notificationId, $data['user_id'], 'push', 'failed', 'FCM not configured');
            return;
        }

        try {
            // Get FCM tokens
            $stmt = $this->db->prepare("
                SELECT id, token, device_type
                FROM fcm_tokens
                WHERE user_id = ?
                ORDER BY updated_at DESC
            ");
            $stmt->execute([$data['user_id']]);
            $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($tokens)) {
                $this->logDelivery($notificationId, $data['user_id'], 'push', 'failed', 'No tokens');
                return;
            }

            $sender = null;
            if (isset($data['from_user_id'])) {
                $stmt = $this->db->prepare('SELECT full_name FROM users WHERE id = ?');
                $stmt->execute([$data['from_user_id']]);
                $sender = $stmt->fetchColumn() ?: null;
            }
            $notification = PushOutbox::alert($data['type'], $sender);
            $fcmData = [
                'notification_id' => (string) $notificationId,
                'type' => $data['type'],
                'action_url' => $data['action_url'],
                'recipient_user_id' => (string) $data['user_id'],
            ];

            // Send to all tokens
            foreach ($tokens as $tokenData) {
                $result = $this->firebase->send($tokenData['token'], $notification, $fcmData);

                // Handle invalid tokens - remove them from database
                if ($result === 'invalid_token') {
                    $this->removeInvalidToken($tokenData['id'], $tokenData['token']);
                    $this->logDelivery(
                        $notificationId,
                        $data['user_id'],
                        'push',
                        'failed',
                        'Invalid token removed'
                    );
                } else {
                    $this->logDelivery(
                        $notificationId,
                        $data['user_id'],
                        'push',
                        $result === true ? 'sent' : 'failed',
                        $result === true ? null : 'FCM send failed'
                    );
                }
            }

        } catch (Exception $e) {
            error_log('sendPushNotification error: ' . $e->getMessage());
            $this->logDelivery($notificationId, $data['user_id'], 'push', 'failed', $e->getMessage());
        }
    }

    /**
     * Remove invalid FCM token from database
     */
    private function removeInvalidToken(int $tokenId, string $token) {
        try {
            $stmt = $this->db->prepare("DELETE FROM fcm_tokens WHERE id = ? AND BINARY token = BINARY ?");
            $stmt->execute([$tokenId, $token]);
            error_log("FCM: Removed invalid token ID $tokenId");
        } catch (Exception $e) {
            error_log("FCM: Failed to remove invalid token: " . $e->getMessage());
        }
    }
    
    /**
     * Check if notification type is enabled
     */
    private function isNotificationEnabled(int $userId, string $type): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT enabled 
                FROM notification_preferences 
                WHERE user_id = ? AND category = ?
            ");
            $stmt->execute([$userId, $type]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? (bool)$result['enabled'] : true;
        } catch (Exception $e) {
            return true;
        }
    }
    
    /**
     * Check if push is enabled
     */
    private function isPushEnabled(int $userId, string $type): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT push_enabled 
                FROM notification_preferences 
                WHERE user_id = ? AND category = ?
            ");
            $stmt->execute([$userId, $type]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? (bool)$result['push_enabled'] : true;
        } catch (Exception $e) {
            return true;
        }
    }
    
    /**
     * Check if user is in quiet hours for this notification category.
     * Preferences rows are per (user, category); matching an arbitrary row
     * would apply one category's quiet hours to every other category.
     */
    private function isQuietHours(int $userId, string $category): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT quiet_hours_start, quiet_hours_end
                FROM notification_preferences
                WHERE user_id = ? AND category = ? AND quiet_hours_enabled = 1
                LIMIT 1
            ");
            $stmt->execute([$userId, $category]);
            $prefs = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$prefs) {
                return false;
            }
            
            $now = new DateTime();
            $start = DateTime::createFromFormat('H:i:s', $prefs['quiet_hours_start']);
            $end = DateTime::createFromFormat('H:i:s', $prefs['quiet_hours_end']);
            
            // End time is exclusive - if quiet hours end at 07:00, 07:00 is NOT in quiet hours
            if ($start > $end) {
                // Overnight quiet hours (e.g., 22:00 - 07:00)
                return $now >= $start || $now < $end;
            } else {
                // Same-day quiet hours (e.g., 13:00 - 15:00)
                return $now >= $start && $now < $end;
            }
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get default icon
     */
    private function getDefaultIcon(string $type): string {
        $icons = [
            self::TYPE_MESSAGE => '💬',
            self::TYPE_SHOPPING => '🛒',
            self::TYPE_CALENDAR => '📅',
            self::TYPE_SCHEDULE => '⏰',
            self::TYPE_TRACKING => '📍',
            self::TYPE_WEATHER => '🌤️',
            self::TYPE_NOTE => '📝',
            self::TYPE_SYSTEM => '⚙️'
        ];
        
        return $icons[$type] ?? '🔔';
    }
    
    /**
     * Log delivery
     */
    private function logDelivery(int $notifId, int $userId, string $method, string $status, ?string $error = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notification_delivery_log 
                (notification_id, user_id, delivery_method, status, error_message, sent_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$notifId, $userId, $method, $status, $error]);
        } catch (Exception $e) {
            error_log('logDelivery error: ' . $e->getMessage());
        }
    }
    
    /**
     * Create for multiple users
     */
    public function createBulk(array $userIds, array $data) {
        $ids = [];
        foreach ($userIds as $userId) {
            $data['user_id'] = $userId;
            $id = $this->create($data);
            if ($id) $ids[] = $id;
        }
        return $ids;
    }
    
    /**
     * Create for family
     */
    public function createForFamily(int $familyId, array $data, ?int $excludeUserId = null) {
        try {
            $sql = "SELECT id FROM users WHERE family_id = ? AND status = 'active'";
            $params = [$familyId];
            
            if ($excludeUserId) {
                $sql .= " AND id != ?";
                $params[] = $excludeUserId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            return $this->createBulk($userIds, $data);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                throw $e;
            }
            error_log('createForFamily error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark as read
     */
    public function markAsRead(int $notifId, int $userId): bool {
        try {
            $stmt = $this->db->prepare("
                UPDATE notifications 
                SET is_read = 1, read_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            return $stmt->execute([$notifId, $userId]);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Mark all as read
     */
    public function markAllAsRead(int $userId, ?string $type = null): bool {
        try {
            $sql = "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0";
            $params = [$userId];
            
            if ($type) {
                $sql .= " AND type = ?";
                $params[] = $type;
            }
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Delete notification
     */
    public function delete(int $notifId, int $userId): bool {
        try {
            $stmt = $this->db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
            return $stmt->execute([$notifId, $userId]);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Delete read notifications older than $days days.
     * Called by cron/notification-cleanup.php (which previously fataled:
     * this method did not exist).
     */
    public function cleanup(int $days = 30): int {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM notifications
                WHERE is_read = 1
                  AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log('NotificationManager::cleanup error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get unread count
     */
    public function getUnreadCount(int $userId, ?string $type = null): int {
        try {
            $sql = "SELECT COUNT(*) FROM notifications n WHERE user_id = ? AND is_read = 0 AND " . LocationNotice::visible($this->db);
            $params = [$userId];
            
            if ($type) {
                $sql .= " AND type = ?";
                $params[] = $type;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}
