<?php
/**
 * MESSAGE REACTION API
 * POST: {message_id, emoji}
 * Toggles reaction (add if not exists, remove if exists)
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $userId = $_SESSION['user_id'];
    $input = json_decode(file_get_contents('php://input'), true);
    
    $messageId = (int)($input['message_id'] ?? 0);
    $emoji = trim($input['emoji'] ?? '');
    
    if (!$messageId || !$emoji) {
        throw new Exception('Missing message_id or emoji');
    }
    
    // Validate emoji (basic check)
    if (mb_strlen($emoji) > 10) {
        throw new Exception('Invalid emoji');
    }
    
    // Get user's family
    $stmt = $db->prepare("SELECT family_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    $familyId = $user['family_id'];
    
    // ========== SUBSCRIPTION LOCK CHECK ==========
    require_once __DIR__ . '/../../core/SubscriptionManager.php';
    
    $subscriptionManager = new SubscriptionManager($db);
    
    if ($subscriptionManager->isFamilyLocked($familyId)) {
        http_response_code(402);
        echo json_encode([
            'success' => false,
            'error' => 'subscription_locked',
            'message' => 'Your trial has ended. Please subscribe to continue using this feature.'
        ]);
        exit;
    }
    // ========== END SUBSCRIPTION LOCK ==========
    
    // Verify message belongs to family
    $stmt = $db->prepare("
        SELECT id FROM messages 
        WHERE id = ? AND family_id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$messageId, $familyId]);
    
    if (!$stmt->fetchColumn()) {
        throw new Exception('Message not found');
    }
    
    // Check if reaction already exists
    $stmt = $db->prepare("
        SELECT id FROM message_reactions 
        WHERE message_id = ? AND user_id = ? AND emoji = ?
    ");
    $stmt->execute([$messageId, $userId, $emoji]);
    $existingReaction = $stmt->fetchColumn();
    
    if ($existingReaction) {
        // Remove reaction
        $stmt = $db->prepare("
            DELETE FROM message_reactions 
            WHERE id = ?
        ");
        $stmt->execute([$existingReaction]);
        $action = 'removed';
    } else {
        // Add reaction
        $stmt = $db->prepare("
            INSERT INTO message_reactions (message_id, user_id, emoji, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$messageId, $userId, $emoji]);
        $action = 'added';

        // Send notification to message owner
        $stmt = $db->prepare("SELECT user_id FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $messageOwnerId = (int)$stmt->fetchColumn();

        if ($messageOwnerId && $messageOwnerId !== $userId) {
            require_once __DIR__ . '/../../core/NotificationManager.php';
            require_once __DIR__ . '/../../core/NotificationTriggers.php';
            $triggers = new NotificationTriggers($db);
            $triggers->onMessageReaction($messageId, $userId, $messageOwnerId, $emoji);
        }
    }
    
    // Fetch updated reactions for this message
    $stmt = $db->prepare("
        SELECT user_id, emoji
        FROM message_reactions
        WHERE message_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$messageId]);
    $reactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'action' => $action,
        'reactions' => $reactions
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}