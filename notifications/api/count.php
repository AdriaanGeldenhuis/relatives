<?php
/**
 * API: Get Unread Notification Count & Latest Item
 * OPTIMIZED FOR NATIVE APP - WITH AUTH PATCH
 */

header('Content-Type: application/json');
header('Cache-Control: private, no-store, max-age=0');
header('CDN-Cache-Control: no-store');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('RELATIVES_SESSION');
    session_start();
}

// ==========================================
// NATIVE APP AUTH PATCH - CRITICAL FIX
// ==========================================
if (!isset($_SESSION['user_id'])) {
    // Check for RELATIVES_SESSION cookie (native app)
    if (isset($_SERVER['HTTP_COOKIE'])) {
        preg_match('/RELATIVES_SESSION=([^;]+)/', $_SERVER['HTTP_COOKIE'], $matches);
        
        if (isset($matches[1])) {
            $cookieSessionId = $matches[1];
            
            try {
                // Bootstrap only if needed
                if (!isset($db)) {
                    require_once __DIR__ . '/../../core/bootstrap.php';
                }
                
                // Validate session token from cookie
                $stmt = $db->prepare("
                    SELECT s.user_id 
                    FROM sessions s 
                    JOIN users u ON s.user_id = u.id 
                    WHERE s.session_token = ? 
                      AND s.expires_at > NOW() 
                      AND u.status = 'active' 
                    LIMIT 1
                ");
                
                $tokenHash = hash('sha256', $cookieSessionId);
                $stmt->execute([$tokenHash]);
                $session = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($session) {
                    $_SESSION['user_id'] = (int)$session['user_id'];
                }
            } catch (Exception $e) {
                error_log('Native app auth error: ' . $e->getMessage());
            }
        }
    }
}

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