<?php
/**
 * ============================================
 * NOTIFICATION TRIGGERS - AUTO FIRE SYSTEM
 * All notification creation points in one place
 * ============================================
 */

class NotificationTriggers {
    private $db;
    private $notifManager;
    
    public function __construct($db) {
        $this->db = $db;
        $this->notifManager = NotificationManager::getInstance($db);
    }
    
    // ==================== MESSAGES ====================
    
    public function onNewMessage(int $messageId, int $senderId, int $familyId, string $preview) {
        // Get sender info
        $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$senderId]);
        $sender = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get family members except sender
        $stmt = $this->db->prepare("
            SELECT id FROM users 
            WHERE family_id = ? AND id != ? AND status = 'active'
        ");
        $stmt->execute([$familyId, $senderId]);
        $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Create notification for each recipient
        foreach ($recipients as $recipientId) {
            $this->notifManager->create([
                'user_id' => $recipientId,
                'from_user_id' => $senderId,
                'type' => NotificationManager::TYPE_MESSAGE,
                'title' => $sender['full_name'] ?? 'New Message',
                'message' => mb_substr($preview, 0, 100),
                'action_url' => '/messages/',
                'priority' => NotificationManager::PRIORITY_NORMAL,
                'icon' => '💬',
                'vibrate' => 1,
                'data' => [
                    'message_id' => $messageId,
                    'sender_id' => $senderId,
                    'sender_name' => $sender['full_name'] ?? 'User'
                ]
            ]);
        }
    }
    
    public function onMessageReaction(int $messageId, int $reactorId, int $messageOwnerId, string $emoji) {
        if ($reactorId === $messageOwnerId) return; // Don't notify self
        
        $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$reactorId]);
        $reactor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->notifManager->create([
            'user_id' => $messageOwnerId,
            'from_user_id' => $reactorId,
            'type' => NotificationManager::TYPE_MESSAGE,
            'title' => 'Message Reaction',
            'message' => ($reactor['full_name'] ?? 'Someone') . " reacted $emoji to your message",
            'action_url' => '/messages/',
            'priority' => NotificationManager::PRIORITY_LOW,
            'icon' => $emoji,
            'vibrate' => 0,
            'data' => [
                'message_id' => $messageId,
                'reactor_id' => $reactorId,
                'emoji' => $emoji
            ]
        ]);
    }
    
    // ==================== SHOPPING ====================
    
    public function onShoppingItemAdded(int $itemId, int $addedBy, int $familyId, string $itemName, int $listId) {
        $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$addedBy]);
        $adder = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $this->db->prepare("SELECT name FROM shopping_lists WHERE id = ?");
        $stmt->execute([$listId]);
        $list = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->notifManager->createForFamily($familyId, [
            'from_user_id' => $addedBy,
            'type' => NotificationManager::TYPE_SHOPPING,
            'title' => 'Shopping List Updated',
            'message' => ($adder['full_name'] ?? 'Someone') . " added \"$itemName\" to " . ($list['name'] ?? 'shopping list'),
            'action_url' => '/shopping/?list=' . $listId,
            'priority' => NotificationManager::PRIORITY_LOW,
            'icon' => '🛒',
            'vibrate' => 0,
            'data' => [
                'item_id' => $itemId,
                'list_id' => $listId,
                'item_name' => $itemName
            ]
        ], $addedBy);
    }
    
    public function onShoppingItemAssigned(int $itemId, int $assignedTo, int $assignedBy, string $itemName) {
        if ($assignedTo === $assignedBy) return;
        
        $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$assignedBy]);
        $assigner = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->notifManager->create([
            'user_id' => $assignedTo,
            'from_user_id' => $assignedBy,
            'type' => NotificationManager::TYPE_SHOPPING,
            'title' => 'Shopping Task Assigned',
            'message' => ($assigner['full_name'] ?? 'Someone') . " assigned you to buy \"$itemName\"",
            'action_url' => '/shopping/',
            'priority' => NotificationManager::PRIORITY_NORMAL,
            'icon' => '🛒',
            'vibrate' => 1,
            'data' => [
                'item_id' => $itemId,
                'item_name' => $itemName
            ]
        ]);
    }
    
    public function onShoppingListShared(int $listId, int $sharedBy, string $shareUrl) {
        $stmt = $this->db->prepare("
            SELECT sl.name, sl.family_id, u.full_name 
            FROM shopping_lists sl
            JOIN users u ON sl.family_id = u.family_id
            WHERE sl.id = ?
        ");
        $stmt->execute([$listId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$sharedBy]);
        $sharer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->notifManager->createForFamily($data['family_id'], [
            'from_user_id' => $sharedBy,
            'type' => NotificationManager::TYPE_SHOPPING,
            'title' => 'Shopping List Shared',
            'message' => ($sharer['full_name'] ?? 'Someone') . " shared the \"" . $data['name'] . "\" list",
            'action_url' => $shareUrl,
            'priority' => NotificationManager::PRIORITY_LOW,
            'icon' => '📤',
            'data' => [
                'list_id' => $listId,
                'share_url' => $shareUrl
            ]
        ], $sharedBy);
    }
    
    // ==================== CALENDAR ====================
    
    public function onEventCreated(int $eventId, int $createdBy, int $familyId, string $title, string $startsAt) {
        $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$createdBy]);
        $creator = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $startDate = new DateTime($startsAt);
        $formatted = $startDate->format('M j, Y \a\t g:i A');
        
        $this->notifManager->createForFamily($familyId, [
            'from_user_id' => $createdBy,
            'type' => NotificationManager::TYPE_CALENDAR,
            'title' => 'New Calendar Event',
            'message' => ($creator['full_name'] ?? 'Someone') . " created \"$title\" for $formatted",
            'action_url' => '/calendar/',
            'priority' => NotificationManager::PRIORITY_NORMAL,
            'icon' => '📅',
            'vibrate' => 1,
            'data' => [
                'event_id' => $eventId,
                'starts_at' => $startsAt
            ]
        ], $createdBy);
    }
    
    public function onEventReminder(int $eventId, int $userId, string $title, int $minutesBefore) {
        $this->notifManager->create([
            'user_id' => $userId,
            'type' => NotificationManager::TYPE_CALENDAR,
            'title' => 'Event Reminder',
            'message' => "\"$title\" starts in $minutesBefore minutes",
            'action_url' => '/calendar/',
            'priority' => NotificationManager::PRIORITY_HIGH,
            'icon' => '⏰',
            'vibrate' => 1,
            'sound' => 'reminder',
            'requires_interaction' => 1,
            'data' => [
                'event_id' => $eventId,
                'minutes_before' => $minutesBefore
            ]
        ]);
    }
    
    public function onEventUpdated(int $eventId, int $updatedBy, int $familyId, string $title, string $changes) {
        $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$updatedBy]);
        $updater = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->notifManager->createForFamily($familyId, [
            'from_user_id' => $updatedBy,
            'type' => NotificationManager::TYPE_CALENDAR,
            'title' => 'Event Updated',
            'message' => ($updater['full_name'] ?? 'Someone') . " updated \"$title\": $changes",
            'action_url' => '/calendar/',
            'priority' => NotificationManager::PRIORITY_NORMAL,
            'icon' => '📝',
            'data' => [
                'event_id' => $eventId,
                'changes' => $changes
            ]
        ], $updatedBy);
    }
    
    // ==================== SCHEDULE ====================
    
    public function onScheduleTaskCreated(int $taskId, int $createdBy, int $familyId, string $title, string $scheduledFor) {
        $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$createdBy]);
        $creator = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->notifManager->createForFamily($familyId, [
            'from_user_id' => $createdBy,
            'type' => NotificationManager::TYPE_SCHEDULE,
            'title' => 'New Task Scheduled',
            'message' => ($creator['full_name'] ?? 'Someone') . " scheduled \"$title\" for $scheduledFor",
            'action_url' => '/schedule/',
            'priority' => NotificationManager::PRIORITY_LOW,
            'icon' => '⏰',
            'data' => [
                'task_id' => $taskId,
                'scheduled_for' => $scheduledFor
            ]
        ], $createdBy);
    }
    
    public function onScheduleTaskDue(int $taskId, int $userId, string $title) {
        $this->notifManager->create([
            'user_id' => $userId,
            'type' => NotificationManager::TYPE_SCHEDULE,
            'title' => 'Task Due Now',
            'message' => "\"$title\" is scheduled to start now",
            'action_url' => '/schedule/',
            'priority' => NotificationManager::PRIORITY_HIGH,
            'icon' => '🔔',
            'vibrate' => 1,
            'sound' => 'alarm',
            'requires_interaction' => 1,
            'data' => [
                'task_id' => $taskId
            ]
        ]);
    }
    
    // ==================== TRACKING ====================
    
    public function onGeofenceEnter(int $userId, int $familyId, string $zoneName, string $memberName) {
        $this->notifManager->createForFamily($familyId, [
            'from_user_id' => $userId,
            'type' => NotificationManager::TYPE_TRACKING,
            'title' => 'Location Alert',
            'message' => "$memberName arrived at $zoneName",
            'action_url' => '/tracking/',
            'priority' => NotificationManager::PRIORITY_NORMAL,
            'icon' => '📍',
            'vibrate' => 1,
            'data' => [
                'user_id' => $userId,
                'zone_name' => $zoneName,
                'event_type' => 'enter'
            ]
        ], $userId);
    }
    
    public function onGeofenceExit(int $userId, int $familyId, string $zoneName, string $memberName) {
        $this->notifManager->createForFamily($familyId, [
            'from_user_id' => $userId,
            'type' => NotificationManager::TYPE_TRACKING,
            'title' => 'Location Alert',
            'message' => "$memberName left $zoneName",
            'action_url' => '/tracking/',
            'priority' => NotificationManager::PRIORITY_NORMAL,
            'icon' => '🚶',
            'vibrate' => 1,
            'data' => [
                'user_id' => $userId,
                'zone_name' => $zoneName,
                'event_type' => 'exit'
            ]
        ], $userId);
    }
    
    public function onLowBattery(int $userId, int $familyId, string $memberName, int $batteryLevel) {
        $this->notifManager->createForFamily($familyId, [
            'from_user_id' => $userId,
            'type' => NotificationManager::TYPE_TRACKING,
            'title' => 'Low Battery Alert',
            'message' => "$memberName's device battery is at $batteryLevel%",
            'action_url' => '/tracking/',
            'priority' => NotificationManager::PRIORITY_NORMAL,
            'icon' => '🔋',
            'data' => [
                'user_id' => $userId,
                'battery_level' => $batteryLevel
            ]
        ], $userId);
    }
    
    public function onSOSAlert(int $userId, int $familyId, string $memberName, float $lat, float $lng) {
        $this->notifManager->createForFamily($familyId, [
            'from_user_id' => $userId,
            'type' => NotificationManager::TYPE_TRACKING,
            'title' => '🚨 SOS EMERGENCY',
            'message' => "$memberName triggered an SOS alert!",
            'action_url' => '/tracking/',
            'priority' => NotificationManager::PRIORITY_URGENT,
            'icon' => '🚨',
            'vibrate' => 1,
            'sound' => 'emergency',
            'requires_interaction' => 1,
            'data' => [
                'user_id' => $userId,
                'latitude' => $lat,
                'longitude' => $lng,
                'emergency' => true
            ]
        ], $userId);
    }
    
    // ==================== WEATHER ====================
    
    public function onWeatherDailyUpdate(int $userId, array $weatherData) {
        $temp = $weatherData['temperature'] ?? 'N/A';
        $condition = $weatherData['condition'] ?? 'Unknown';
        $location = $weatherData['location'] ?? 'Your area';
        
        $message = "Good morning! Today in $location: $temp°C, $condition";
        
        if (isset($weatherData['rain_chance']) && $weatherData['rain_chance'] > 60) {
            $message .= ". High chance of rain - don't forget an umbrella!";
        }
        
        $this->notifManager->create([
            'user_id' => $userId,
            'type' => NotificationManager::TYPE_WEATHER,
            'title' => '🌤️ Daily Weather Update',
            'message' => $message,
            'action_url' => '/weather/',
            'priority' => NotificationManager::PRIORITY_LOW,
            'icon' => $weatherData['icon'] ?? '🌤️',
            'vibrate' => 0,
            'data' => $weatherData
        ]);
    }
    
    public function onWeatherAlert(int $userId, string $alertType, string $alertMessage) {
        $icons = [
            'storm' => '⛈️',
            'rain' => '🌧️',
            'snow' => '❄️',
            'heat' => '🔥',
            'cold' => '🥶',
            'wind' => '💨'
        ];
        
        $this->notifManager->create([
            'user_id' => $userId,
            'type' => NotificationManager::TYPE_WEATHER,
            'title' => 'Weather Alert',
            'message' => $alertMessage,
            'action_url' => '/weather/',
            'priority' => NotificationManager::PRIORITY_HIGH,
            'icon' => $icons[$alertType] ?? '⚠️',
            'vibrate' => 1,
            'sound' => 'alert',
            'requires_interaction' => 1,
            'data' => [
                'alert_type' => $alertType
            ]
        ]);
    }
    
    // ==================== NOTES ====================
    
    public function onNoteShared(int $noteId, int $sharedBy, int $sharedWith, string $noteTitle) {
        $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$sharedBy]);
        $sharer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->notifManager->create([
            'user_id' => $sharedWith,
            'from_user_id' => $sharedBy,
            'type' => NotificationManager::TYPE_NOTE,
            'title' => 'Note Shared',
            'message' => ($sharer['full_name'] ?? 'Someone') . " shared a note: \"$noteTitle\"",
            'action_url' => '/notes/',
            'priority' => NotificationManager::PRIORITY_LOW,
            'icon' => '📝',
            'data' => [
                'note_id' => $noteId
            ]
        ]);
    }
    
    public function onNoteMentioned(int $noteId, int $mentionedBy, int $mentionedUser, string $notePreview) {
        if ($mentionedBy === $mentionedUser) return;
        
        $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$mentionedBy]);
        $mentioner = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->notifManager->create([
            'user_id' => $mentionedUser,
            'from_user_id' => $mentionedBy,
            'type' => NotificationManager::TYPE_NOTE,
            'title' => 'You were mentioned',
            'message' => ($mentioner['full_name'] ?? 'Someone') . " mentioned you in a note: " . mb_substr($notePreview, 0, 50),
            'action_url' => '/notes/',
            'priority' => NotificationManager::PRIORITY_NORMAL,
            'icon' => '@',
            'vibrate' => 1,
            'data' => [
                'note_id' => $noteId
            ]
        ]);
    }
    
    // ==================== SYSTEM ====================
    
    public function onSystemUpdate(int $userId, string $title, string $message) {
        $this->notifManager->create([
            'user_id' => $userId,
            'type' => NotificationManager::TYPE_SYSTEM,
            'title' => $title,
            'message' => $message,
            'action_url' => '/home/',
            'priority' => NotificationManager::PRIORITY_LOW,
            'icon' => '⚙️',
            'vibrate' => 0
        ]);
    }
    
    public function onFamilyMemberJoined(int $familyId, int $newMemberId, string $memberName) {
        $this->notifManager->createForFamily($familyId, [
            'from_user_id' => $newMemberId,
            'type' => NotificationManager::TYPE_SYSTEM,
            'title' => 'New Family Member',
            'message' => "$memberName joined your family!",
            'action_url' => '/home/',
            'priority' => NotificationManager::PRIORITY_NORMAL,
            'icon' => '👋',
            'vibrate' => 1
        ], $newMemberId);
    }
}