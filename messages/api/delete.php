<?php
/**
 * DELETE MESSAGE API
 * POST: {message_id}
 * Soft deletes message (sets deleted_at)
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
    
    // Get message
    $stmt = $db->prepare("
        SELECT user_id FROM messages 
        WHERE id = ? AND family_id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$messageId, $familyId]);
    $message = $stmt->fetch();
    
    if (!$message) {
        throw new Exception('Message not found');
    }
    
    // Check permissions (own message OR admin/owner)
    if ($message['user_id'] != $userId && !in_array($user['role'], ['admin', 'owner'])) {
        throw new Exception('Permission denied');
    }
    
    // Soft delete
    $stmt = $db->prepare("
        UPDATE messages 
        SET deleted_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$messageId]);
    
    // Log to audit
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_log (family_id, user_id, action, entity_type, entity_id, created_at)
            VALUES (?, ?, 'delete', 'message', ?, NOW())
        ");
        $stmt->execute([$familyId, $userId, $messageId]);
    } catch (Exception $e) {
        // Audit log failed, continue
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Message deleted'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}