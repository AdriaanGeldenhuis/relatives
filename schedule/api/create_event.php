<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    // Validate input
    $kind = $_POST['kind'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $date = $_POST['date'] ?? '';
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $reminderMinutes = (int)($_POST['reminder_minutes'] ?? 0);
    $repeatRule = $_POST['repeat_rule'] ?? '';
    $color = $_POST['color'] ?? '#3498db';
    
    if (!in_array($kind, ['study', 'work', 'todo'])) {
        throw new Exception('Invalid event type');
    }
    
    if (empty($title)) {
        throw new Exception('Title is required');
    }
    
    if (empty($date) || empty($startTime) || empty($endTime)) {
        throw new Exception('Date and times are required');
    }
    
    // Combine date and time
    $startsAt = $date . ' ' . $startTime . ':00';
    $endsAt = $date . ' ' . $endTime . ':00';
    
    // Validate times
    if (strtotime($endsAt) <= strtotime($startsAt)) {
        throw new Exception('End time must be after start time');
    }
    
    // Get user's family_id
    $stmt = $db->prepare("SELECT family_id, full_name, avatar_color FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('User not found');
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
    
    // Insert event
    $stmt = $db->prepare("
        INSERT INTO events (
            user_id, family_id, kind, title, notes,
            starts_at, ends_at, color, reminder_minutes,
            repeat_rule, status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
    ");
    
    $stmt->execute([
        $_SESSION['user_id'],
        $user['family_id'],
        $kind,
        $title,
        $notes,
        $startsAt,
        $endsAt,
        $color,
        $reminderMinutes > 0 ? $reminderMinutes : null,
        $repeatRule ?: null
    ]);
    
    $eventId = $db->lastInsertId();
    
    // Fetch the created event
    $stmt = $db->prepare("
        SELECT e.*, u.full_name, u.avatar_color
        FROM events e
        LEFT JOIN users u ON e.user_id = u.id
        WHERE e.id = ?
    ");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Convert to proper types
    $event['id'] = (int)$event['id'];
    $event['user_id'] = (int)$event['user_id'];
    $event['family_id'] = (int)$event['family_id'];
    $event['reminder_minutes'] = $event['reminder_minutes'] ? (int)$event['reminder_minutes'] : null;
    
    // Log activity
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_log (user_id, family_id, action, entity_type, entity_id, created_at)
            VALUES (?, ?, ?, 'event', ?, NOW())
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $user['family_id'],
            "created a {$kind} session: {$title}",
            $eventId
        ]);
    } catch (Exception $e) {
        // Ignore audit log errors
    }
    
    echo json_encode([
        'success' => true,
        'event' => $event,
        'message' => 'Event created successfully'
    ]);
    
} catch (Exception $e) {
    error_log('Create event error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}