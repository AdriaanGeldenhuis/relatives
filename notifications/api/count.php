<?php
/**
 * API: Get Unread Notification Count & Latest Item
 * OPTIMIZED FOR NATIVE APP - WITH AUTH PATCH
 */

header('Content-Type: application/json');
header('Cache-Control: private, no-store, max-age=0');
header('CDN-Cache-Control: no-store');

// Start session if not already started. session_boot restores $_SESSION from
// the RELATIVES_SESSION cookie for the native app too — the old "native app
// auth patch" here compared hash(PHP session id) against the hash of a
// different random token in the sessions table, so it could never match and
// was pure dead code.
require_once __DIR__ . '/../../core/session_boot.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'count' => 0
    ]);
    exit;
}

// Bootstrap if not already loaded
if (!isset($db)) {
    require_once __DIR__ . '/../../core/bootstrap.php';
}

try {
    $userId = (int)$_SESSION['user_id'];
    
    // ==========================================
    // GET UNREAD COUNT
    // ==========================================
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM notifications 
        WHERE user_id = ? 
          AND is_read = 0
    ");
    $stmt->execute([$userId]);
    $count = (int)$stmt->fetchColumn();
    
    $response = [
        'success' => true,
        'count' => $count,
        'timestamp' => time()
    ];
    
    // ==========================================
    // GET LATEST NOTIFICATION DETAILS
    // ==========================================
    if ($count > 0) {
        $stmt = $db->prepare("
            SELECT 
                n.title,
                n.message,
                n.icon,
                n.type,
                n.created_at,
                u.full_name as sender_name
            FROM notifications n
            LEFT JOIN users u ON n.from_user_id = u.id
            WHERE n.user_id = ? 
              AND n.is_read = 0
            ORDER BY n.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $latest = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($latest) {
            // Add latest notification details
            $response['latest_title'] = $latest['title'] ?? 'New Notification';
            $response['latest_message'] = $latest['message'] ?? 'You have a new update.';
            $response['latest_icon'] = $latest['icon'] ?? '🔔';
            $response['latest_type'] = $latest['type'] ?? 'system';
            
            // Include sender name if available
            if (!empty($latest['sender_name'])) {
                $response['latest_sender'] = $latest['sender_name'];
            }
            
            // Calculate time ago
            $createdAt = strtotime($latest['created_at']);
            $secondsAgo = time() - $createdAt;
            
            if ($secondsAgo < 60) {
                $response['latest_time_ago'] = 'Just now';
            } elseif ($secondsAgo < 3600) {
                $response['latest_time_ago'] = floor($secondsAgo / 60) . 'm ago';
            } elseif ($secondsAgo < 86400) {
                $response['latest_time_ago'] = floor($secondsAgo / 3600) . 'h ago';
            } else {
                $response['latest_time_ago'] = floor($secondsAgo / 86400) . 'd ago';
            }
        }
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log('Notification count API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'count' => 0
    ]);
}