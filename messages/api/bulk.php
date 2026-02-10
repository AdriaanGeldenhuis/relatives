<?php
/**
 * BULK OPERATIONS API
 * POST: {action: 'delete'|'mark_read'|'export', message_ids: []}
 * Perform bulk operations on multiple messages
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
    
    $action = $input['action'] ?? '';
    $messageIds = $input['message_ids'] ?? [];
    
    if (empty($action)) {
        throw new Exception('Missing action parameter');
    }
    
    if (empty($messageIds) || !is_array($messageIds)) {
        throw new Exception('Missing or invalid message_ids');
    }
    
    // Limit bulk operations
    if (count($messageIds) > 100) {
        throw new Exception('Maximum 100 messages per bulk operation');
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
    
    // Check if admin required for bulk operations
    $stmt = $db->prepare("
        SELECT require_admin_for_bulk 
        FROM message_settings 
        WHERE family_id = ?
    ");
    $stmt->execute([$familyId]);
    $settings = $stmt->fetch();
    
    $requireAdmin = $settings ? $settings['require_admin_for_bulk'] : true;
    
    if ($requireAdmin && !in_array($user['role'], ['admin', 'owner'])) {
        throw new Exception('Only admins can perform bulk operations');
    }
    
    // Perform action
    $result = [];
    
    switch ($action) {
        case 'delete':
            $result = bulkDelete($db, $familyId, $userId, $messageIds, $user['role']);
            break;
        
        case 'mark_read':
            $result = bulkMarkRead($db, $familyId, $userId, $messageIds);
            break;
        
        case 'archive':
            $result = bulkArchive($db, $familyId, $messageIds);
            break;
        
        default:
            throw new Exception('Invalid action: ' . $action);
    }
    
    // Log bulk operation
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_log (family_id, user_id, action, entity_type, meta, created_at)
            VALUES (?, ?, ?, 'messages', ?, NOW())
        ");
        $stmt->execute([
            $familyId,
            $userId,
            'bulk_' . $action,
            json_encode(['message_ids' => $messageIds, 'count' => count($messageIds)])
        ]);
    } catch (Exception $e) {
        // Audit log failed, continue
    }
    
    echo json_encode(array_merge(['success' => true], $result));
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Bulk delete messages
 */
function bulkDelete($db, $familyId, $userId, $messageIds, $userRole) {
    $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
    
    // Get messages to verify ownership
    $stmt = $db->prepare("
        SELECT id, user_id FROM messages 
        WHERE id IN ($placeholders) 
        AND family_id = ? 
        AND deleted_at IS NULL
    ");
    $stmt->execute(array_merge($messageIds, [$familyId]));
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filter messages user can delete
    $deletableIds = [];
    $isAdmin = in_array($userRole, ['admin', 'owner']);
    
    foreach ($messages as $msg) {
        if ($isAdmin || $msg['user_id'] == $userId) {
            $deletableIds[] = $msg['id'];
        }
    }
    
    if (empty($deletableIds)) {
        throw new Exception('No messages found that you can delete');
    }
    
    // Perform soft delete
    $placeholders = str_repeat('?,', count($deletableIds) - 1) . '?';
    $stmt = $db->prepare("
        UPDATE messages 
        SET deleted_at = NOW() 
        WHERE id IN ($placeholders)
    ");
    $stmt->execute($deletableIds);
    
    return [
        'deleted_count' => count($deletableIds),
        'skipped_count' => count($messageIds) - count($deletableIds)
    ];
}

/**
 * Bulk mark as read
 */
function bulkMarkRead($db, $familyId, $userId, $messageIds) {
    $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
    
    // Verify messages belong to family
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
    
    // Check which messages are already read
    $placeholders = str_repeat('?,', count($validIds) - 1) . '?';
    $stmt = $db->prepare("
        SELECT message_id FROM message_reads 
        WHERE message_id IN ($placeholders) AND user_id = ?
    ");
    $stmt->execute(array_merge($validIds, [$userId]));
    $alreadyRead = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $unreadIds = array_diff($validIds, $alreadyRead);
    
    if (!empty($unreadIds)) {
        // Insert read receipts
        $values = [];
        $params = [];
        foreach ($unreadIds as $msgId) {
            $values[] = "(?, ?, NOW())";
            $params[] = $msgId;
            $params[] = $userId;
        }
        
        $stmt = $db->prepare("
            INSERT INTO message_reads (message_id, user_id, read_at)
            VALUES " . implode(',', $values)
        );
        $stmt->execute($params);
    }
    
    return [
        'marked_count' => count($unreadIds),
        'already_read' => count($alreadyRead)
    ];
}

/**
 * Bulk archive messages
 */
function bulkArchive($db, $familyId, $messageIds) {
    require_once __DIR__ . '/classes/MessageLimitManager.php';
    
    $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
    
    // Get messages
    $stmt = $db->prepare("
        SELECT * FROM messages 
        WHERE id IN ($placeholders) 
        AND family_id = ? 
        AND deleted_at IS NULL
    ");
    $stmt->execute(array_merge($messageIds, [$familyId]));
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($messages)) {
        throw new Exception('No valid messages found to archive');
    }
    
    // Archive messages
    $limitManager = new MessageLimitManager($db, $familyId);
    $stmt = $db->prepare("
        INSERT INTO message_archives 
        (original_message_id, family_id, user_id, message_type, content, media_path, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($messages as $msg) {
        $stmt->execute([
            $msg['id'],
            $msg['family_id'],
            $msg['user_id'],
            $msg['message_type'],
            $msg['content'],
            $msg['media_path'],
            $msg['created_at']
        ]);
    }

    // Soft-delete the originals now that they're archived
    $archivedIds = array_column($messages, 'id');
    $placeholders2 = str_repeat('?,', count($archivedIds) - 1) . '?';
    $stmt = $db->prepare("
        UPDATE messages SET deleted_at = NOW()
        WHERE id IN ($placeholders2)
    ");
    $stmt->execute($archivedIds);

    return [
        'archived_count' => count($messages)
    ];
}