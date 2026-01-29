<?php
/**
 * ============================================
 * MESSAGES API - FETCH MESSAGES
 * Retrieves messages for the family
 * Optimized for both web and native app
 * ============================================
 */

session_start();

// Prevent output before headers
ob_start();

// Set headers
header('Content-Type: application/json');
header('Cache-Control: private, no-store, max-age=0');
header('CDN-Cache-Control: no-store');

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Authentication check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Not authenticated'
    ]);
    exit;
}

// Prevent multiple simultaneous requests from same session
if (isset($_SESSION['fetching_messages']) && $_SESSION['fetching_messages'] === true) {
    // Check if request is stale (more than 10 seconds old)
    if (isset($_SESSION['fetch_started']) && (time() - $_SESSION['fetch_started']) < 10) {
        http_response_code(429); // Too Many Requests
        echo json_encode([
            'success' => false,
            'message' => 'Request already in progress'
        ]);
        exit;
    }
}

// Mark as fetching
$_SESSION['fetching_messages'] = true;
$_SESSION['fetch_started'] = time();

try {
    require_once __DIR__ . '/../../core/bootstrap.php';
    
    $userId = $_SESSION['user_id'];
    
    // Get user's family_id
    $stmt = $db->prepare("SELECT family_id FROM users WHERE id = ? AND status = 'active'");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('User not found or inactive');
    }
    
    $familyId = $user['family_id'];
    
    // Check if we're fetching new messages only (since parameter)
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
    
    if ($since > 0) {
        // Fetch only new messages since last ID
        $stmt = $db->prepare("
            SELECT
                m.id,
                m.user_id,
                m.content,
                m.message_type,
                m.media_path,
                m.reply_to_message_id,
                m.created_at,
                m.edited_at,
                u.full_name,
                u.avatar_color,
                u.has_avatar,
                (SELECT content FROM messages WHERE id = m.reply_to_message_id LIMIT 1) as reply_to_content
            FROM messages m
            JOIN users u ON m.user_id = u.id
            WHERE m.family_id = ?
            AND m.id > ?
            AND m.deleted_at IS NULL
            ORDER BY m.created_at ASC
            LIMIT 50
        ");
        $stmt->execute([$familyId, $since]);
    } else {
        // Fetch initial load - last 100 messages
        $stmt = $db->prepare("
            SELECT
                m.id,
                m.user_id,
                m.content,
                m.message_type,
                m.media_path,
                m.reply_to_message_id,
                m.created_at,
                m.edited_at,
                u.full_name,
                u.avatar_color,
                u.has_avatar,
                (SELECT content FROM messages WHERE id = m.reply_to_message_id LIMIT 1) as reply_to_content
            FROM messages m
            JOIN users u ON m.user_id = u.id
            WHERE m.family_id = ?
            AND m.deleted_at IS NULL
            ORDER BY m.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$familyId]);
    }
    
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Reverse order for initial load (oldest to newest)
    if ($since === 0) {
        $messages = array_reverse($messages);
    }
    
    // Fetch reactions for each message
    if (!empty($messages)) {
        $messageIds = array_column($messages, 'id');
        $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
        
        $reactionStmt = $db->prepare("
            SELECT 
                message_id,
                user_id,
                emoji
            FROM message_reactions
            WHERE message_id IN ($placeholders)
            ORDER BY created_at ASC
        ");
        $reactionStmt->execute($messageIds);
        $reactions = $reactionStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group reactions by message_id
        $reactionsByMessage = [];
        foreach ($reactions as $reaction) {
            $reactionsByMessage[$reaction['message_id']][] = [
                'user_id' => $reaction['user_id'],
                'emoji' => $reaction['emoji']
            ];
        }
        
        // Add reactions to messages
        foreach ($messages as &$message) {
            $message['reactions'] = $reactionsByMessage[$message['id']] ?? [];
        }
    }
    
    // Update last_seen for user
    $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $updateStmt->execute([$userId]);
    
    // Clear fetching flag
    $_SESSION['fetching_messages'] = false;
    unset($_SESSION['fetch_started']);
    
    // Clear any output buffer
    ob_end_clean();
    
    // Send response
    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'count' => count($messages),
        'since' => $since,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    // Clear fetching flag on error
    $_SESSION['fetching_messages'] = false;
    unset($_SESSION['fetch_started']);
    
    // Log error
    error_log("Messages fetch error: " . $e->getMessage());
    
    // Clear output buffer
    ob_end_clean();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch messages',
        'error' => $e->getMessage()
    ]);
}

exit;