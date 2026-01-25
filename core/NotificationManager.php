<?php
/**
 * ============================================
 * NOTIFICATION MANAGER - ENHANCED WITH FCM
 * ============================================
 */

class NotificationManager {
    private $db;
    private static $instance = null;
    private $firebase = null;
    
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
        
        // Initialize Firebase if available
        if (class_exists('FirebaseMessaging')) {
            $this->firebase = new FirebaseMessaging();
        }
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
        try {
            // Validate
            $required = ['user_id', 'type', 'title', 'message'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Check if enabled
            if (!$this->isNotificationEnabled($data['user_id'], $data['type'])) {
                return false;
            }
            
            // Check quiet hours (unless urgent)
            if ($this->isQuietHours($data['user_id'])) {
                $priority = $data['priority'] ?? self::PRIORITY_NORMAL;
                if ($priority !== self::PRIORITY_URGENT) {
                    // Skip non-urgent during quiet hours
                    return false;
                }
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
            
            // Send push notification
            if ($this->isPushEnabled($data['user_id'], $data['type'])) {
                $this->sendPushNotification($notificationId, $data);
            }
            
            return $notificationId;
            
        } catch (Exception $e) {
            error_log('NotificationManager::create error: ' . $e->getMessage());
            return false;
        }
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

            // Prepare FCM message
            $notification = [
                'title' => $data['title'],
                'body' => $data['message'],
                'icon' => $data['icon'] ?? $this->getDefaultIcon($data['type']),
                'click_action' => $data['action_url'] ?? '/',
                'sound' => $data['sound'] ?? 'default'
            ];

            $fcmData = array_merge($data['data'] ?? [], [
                'notification_id' => (string)$notificationId,
                'type' => $data['type'],
                'action_url' => $data['action_url'] ?? '/'
            ]);

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
            $stmt = $this->db->prepare("DELETE FROM fcm_tokens WHERE id = ?");
            $stmt->execute([$tokenId]);
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
     * Check if user is in quiet hours
     */
    private function isQuietHours(int $userId): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT quiet_hours_start, quiet_hours_end 
                FROM notification_preferences 
                WHERE user_id = ? AND quiet_hours_enabled = 1
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $prefs = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$prefs) {
                return false;
            }
            
            $now = new DateTime();
            $start = DateTime::createFromFormat('H:i:s', $prefs['quiet_hours_start']);
            $end = DateTime::createFromFormat('H:i:s', $prefs['quiet_hours_end']);
            
            if ($start > $end) {
                return $now >= $start || $now <= $end;
            } else {
                return $now >= $start && $now <= $end;
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
     * Get unread count
     */
    public function getUnreadCount(int $userId, ?string $type = null): int {
        try {
            $sql = "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0";
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