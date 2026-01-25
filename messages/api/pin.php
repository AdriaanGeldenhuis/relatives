<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Pin feature not yet enabled']);
exit;

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $userId = $_SESSION['user_id'];
    $input = json_decode(file_get_contents('php://input'), true);
    
    $messageId = (int)($input['message_id'] ?? 0);
    
    if (!$messageId) {
        throw new Exception('Missing message_id');
    }
    
    // Get user's family and role
    $stmt = $db->prepare("SELECT family_id, role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
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
    
    // Check permissions (admin or owner only)
    if (!in_array($user['role'], ['admin', 'owner'])) {
        throw new Exception('Only admins can pin messages');
    }
    
    $familyId = $user['family_id'];
    
    // Verify message exists
    $stmt = $db->prepare("
        SELECT id FROM messages 
        WHERE id = ? AND family_id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$messageId, $familyId]);
    
    if (!$stmt->fetchColumn()) {
        throw new Exception('Message not found');
    }
    
    // Check if already pinned
    $stmt = $db->prepare("
        SELECT id FROM pinned_messages 
        WHERE message_id = ? AND family_id = ?
    ");
    $stmt->execute([$messageId, $familyId]);
    $existingPin = $stmt->fetchColumn();
    
    if ($existingPin) {
        // Unpin
        $stmt = $db->prepare("
            DELETE FROM pinned_messages 
            WHERE id = ?
        ");
        $stmt->execute([$existingPin]);
        
        // Update message pinned flag
        $stmt = $db->prepare("UPDATE messages SET pinned = 0 WHERE id = ?");
        $stmt->execute([$messageId]);
        
        $action = 'unpinned';
    } else {
        // Check pin limit (max 5)
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM pinned_messages WHERE family_id = ?
        ");
        $stmt->execute([$familyId]);
        $pinCount = (int)$stmt->fetchColumn();
        
        if ($pinCount >= 5) {
            throw new Exception('Maximum 5 pinned messages allowed');
        }
        
        // Pin message
        $stmt = $db->prepare("
            INSERT INTO pinned_messages (family_id, message_id, pinned_by, pinned_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$familyId, $messageId, $userId]);
        
        // Update message pinned flag
        $stmt = $db->prepare("UPDATE messages SET pinned = 1 WHERE id = ?");
        $stmt->execute([$messageId]);
        
        $action = 'pinned';
    }
    
    echo json_encode([
        'success' => true,
        'action' => $action
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}