<?php
/**
 * EDIT MESSAGE API
 * POST: {message_id, content}
 * Allows editing messages with history tracking
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
    $newContent = trim($input['content'] ?? '');
    
    if (!$messageId || empty($newContent)) {
        throw new Exception('Missing message_id or content');
    }
    
    if (strlen($newContent) > 5000) {
        throw new Exception('Message too long (max 5000 characters)');
    }
    
    // Get user's family and message settings
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
    
    // Get message settings
    $stmt = $db->prepare("
        SELECT allow_editing, edit_time_limit_minutes 
        FROM message_settings 
        WHERE family_id = ?
    ");
    $stmt->execute([$familyId]);
    $settings = $stmt->fetch();
    
    // Check if editing is allowed
    if ($settings && !$settings['allow_editing']) {
        throw new Exception('Message editing is disabled for this family');
    }
    
    $editTimeLimitMinutes = $settings ? $settings['edit_time_limit_minutes'] : 15;
    
    // Get original message
    $stmt = $db->prepare("
        SELECT user_id, content, created_at, edit_count
        FROM messages 
        WHERE id = ? AND family_id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$messageId, $familyId]);
    $message = $stmt->fetch();
    
    if (!$message) {
        throw new Exception('Message not found');
    }
    
    // Check ownership
    if ($message['user_id'] != $userId) {
        throw new Exception('You can only edit your own messages');
    }
    
    // Check if same content
    if ($message['content'] === $newContent) {
        throw new Exception('No changes detected');
    }
    
    // Check edit time limit
    $createdTime = new DateTime($message['created_at']);
    $now = new DateTime();
    $minutesSinceCreation = ($now->getTimestamp() - $createdTime->getTimestamp()) / 60;
    
    if ($minutesSinceCreation > $editTimeLimitMinutes) {
        throw new Exception("Messages can only be edited within {$editTimeLimitMinutes} minutes of sending");
    }
    
    // Save edit history
    $stmt = $db->prepare("
        INSERT INTO message_edit_history (message_id, previous_content, edited_by, edited_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$messageId, $message['content'], $userId]);
    
    // Update message
    $stmt = $db->prepare("
        UPDATE messages 
        SET content = ?, 
            edited_at = NOW(),
            edit_count = edit_count + 1
        WHERE id = ?
    ");
    $stmt->execute([$newContent, $messageId]);
    
    // Update search index
    $stmt = $db->prepare("
        UPDATE message_search_index 
        SET search_content = ?
        WHERE message_id = ?
    ");
    $stmt->execute([$newContent, $messageId]);
    
    // Log to audit
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_log (family_id, user_id, action, entity_type, entity_id, created_at)
            VALUES (?, ?, 'edit', 'message', ?, NOW())
        ");
        $stmt->execute([$familyId, $userId, $messageId]);
    } catch (Exception $e) {
        // Audit log failed, continue
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Message updated successfully',
        'edit_count' => (int)$message['edit_count'] + 1,
        'edited_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}