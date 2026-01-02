<?php
/**
 * GET PINNED MESSAGES
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
    
    $stmt = $db->prepare("SELECT family_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    $familyId = $user['family_id'];
    
    // Get pinned messages
    $stmt = $db->prepare("
        SELECT m.*, u.full_name, u.avatar_color, pm.pinned_at
        FROM messages m
        JOIN users u ON m.user_id = u.id
        JOIN pinned_messages pm ON m.id = pm.message_id
        WHERE m.family_id = ? AND m.deleted_at IS NULL
        ORDER BY pm.pinned_at DESC
        LIMIT 5
    ");
    $stmt->execute([$familyId]);
    $pinned = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'pinned' => $pinned
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}