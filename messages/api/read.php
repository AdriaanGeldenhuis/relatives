<?php
/**
 * MARK MESSAGE AS READ API
 * POST: {message_id}
 */

session_name('RELATIVES_SESSION');
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
    
    // Get user's family
    $stmt = $db->prepare("SELECT family_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('User not found');
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
    
    // Check if already marked as read
    $stmt = $db->prepare("
        SELECT id FROM message_reads 
        WHERE message_id = ? AND user_id = ?
    ");
    $stmt->execute([$messageId, $userId]);
    
    if (!$stmt->fetchColumn()) {
        // Mark as read
        $stmt = $db->prepare("
            INSERT INTO message_reads (message_id, user_id, read_at)
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$messageId, $userId]);
    }
    
    echo json_encode([
        'success' => true
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}