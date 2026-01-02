<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $eventId = (int)($_POST['event_id'] ?? 0);
    
    if ($eventId <= 0) {
        throw new Exception('Invalid event ID');
    }
    
    // Check if user has access to this event
    $stmt = $db->prepare("
        SELECT e.*, u.family_id
        FROM events e
        LEFT JOIN users u ON e.user_id = u.id
        WHERE e.id = ?
    ");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    
    if (!$event) {
        throw new Exception('Event not found');
    }
    
    // Get current user's family_id
    $stmt = $db->prepare("SELECT family_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userFamily = $stmt->fetch();
    
    if ($event['family_id'] != $userFamily['family_id']) {
        throw new Exception('Access denied');
    }
    
    // ========== SUBSCRIPTION LOCK CHECK ==========
    require_once __DIR__ . '/../../core/SubscriptionManager.php';
    
    $subscriptionManager = new SubscriptionManager($db);
    
    if ($subscriptionManager->isFamilyLocked($event['family_id'])) {
        http_response_code(402);
        echo json_encode([
            'success' => false,
            'error' => 'subscription_locked',
            'message' => 'Your trial has ended. Please subscribe to continue using this feature.'
        ]);
        exit;
    }
    // ========== END SUBSCRIPTION LOCK ==========
    
    // Soft delete (set status to cancelled)
    $stmt = $db->prepare("
        UPDATE events 
        SET status = 'cancelled', updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$eventId]);
    
    // Or hard delete if you prefer
    // $stmt = $db->prepare("DELETE FROM events WHERE id = ?");
    // $stmt->execute([$eventId]);
    
    // Log activity
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_log (user_id, family_id, action, entity_type, entity_id, created_at)
            VALUES (?, ?, ?, 'event', ?, NOW())
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $event['family_id'],
            "deleted: {$event['title']}",
            $eventId
        ]);
    } catch (Exception $e) {
        // Ignore audit log errors
    }
    
    echo json_encode([
        'success' => true,
        'event_id' => $eventId,
        'message' => 'Event deleted successfully'
    ]);
    
} catch (Exception $e) {
    error_log('Delete event error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}