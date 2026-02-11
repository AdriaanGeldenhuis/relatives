<?php
/**
 * MESSAGE STATUS API
 * GET: Get delivery status for messages
 * POST: Update message status (delivered/read)
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
    
    // Get user's family
    $stmt = $db->prepare("SELECT family_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    $familyId = $user['family_id'];
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // UPDATE STATUS
        $input = json_decode(file_get_contents('php://input'), true);
        $messageIds = $input['message_ids'] ?? [];
        $status = $input['status'] ?? 'delivered';
        
        if (empty($messageIds) || !is_array($messageIds)) {
            throw new Exception('Missing or invalid message_ids');
        }
        
        if (!in_array($status, ['delivered', 'read'])) {
            throw new Exception('Invalid status. Use: delivered, read');
        }
        
        // Verify messages belong to family
        $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
        $stmt = $db->prepare("
            SELECT id FROM messages 
            WHERE id IN ($placeholders) 
            AND family_id = ? 
            AND deleted_at IS NULL
        ");
        $stmt->execute(array_merge($messageIds, [$familyId]));
        $validIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($validIds)) {
            throw new Exception('No valid messages found');
        }
        
        // Update or insert status
        foreach ($validIds as $msgId) {
            $stmt = $db->prepare("
                INSERT INTO message_delivery_status (message_id, user_id, status, delivered_at, read_at)
                VALUES (?, ?, ?, 
                    CASE WHEN ? = 'delivered' THEN NOW() ELSE NULL END,
                    CASE WHEN ? = 'read' THEN NOW() ELSE NULL END
                )
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    delivered_at = CASE WHEN VALUES(delivered_at) IS NOT NULL THEN VALUES(delivered_at) ELSE delivered_at END,
                    read_at = CASE WHEN VALUES(read_at) IS NOT NULL THEN VALUES(read_at) ELSE read_at END
            ");
            $stmt->execute([$msgId, $userId, $status, $status, $status]);
        }
        
        echo json_encode([
            'success' => true,
            'updated_count' => count($validIds)
        ]);
        
    } else {
        // GET STATUS
        $messageIds = $_GET['message_ids'] ?? '';
        
        if (empty($messageIds)) {
            throw new Exception('Missing message_ids parameter');
        }
        
        $messageIds = array_filter(array_map('intval', explode(',', $messageIds)));
        
        if (empty($messageIds)) {
            throw new Exception('Invalid message_ids');
        }
        
        $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
        
        // Get status for all family members
        $stmt = $db->prepare("
            SELECT 
                mds.message_id,
                mds.user_id,
                mds.status,
                mds.delivered_at,
                mds.read_at,
                u.full_name
            FROM message_delivery_status mds
            JOIN users u ON mds.user_id = u.id
            WHERE mds.message_id IN ($placeholders)
            AND u.family_id = ?
            ORDER BY mds.message_id, mds.read_at DESC
        ");
        $stmt->execute(array_merge($messageIds, [$familyId]));
        $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by message
        $grouped = [];
        foreach ($statuses as $status) {
            $msgId = $status['message_id'];
            if (!isset($grouped[$msgId])) {
                $grouped[$msgId] = [];
            }
            $grouped[$msgId][] = $status;
        }
        
        echo json_encode([
            'success' => true,
            'statuses' => $grouped
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}