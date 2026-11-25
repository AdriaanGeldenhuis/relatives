<?php
declare(strict_types=1);

/**
 * ============================================
 * LOGOUT - CLEAN SESSION DESTROY
 * ============================================
 */

// Start session with correct name to match bootstrap config
require_once __DIR__ . '/core/session_boot.php';

// Load bootstrap to get DB connection
require_once __DIR__ . '/core/bootstrap.php';

// Destroy session in database if exists
try {
    if (isset($_SESSION['user_id']) && isset($_SESSION['session_token'])) {
        $userId = (int) $_SESSION['user_id'];
        $sessionHash = hash('sha256', $_SESSION['session_token']);
        $auth = new Auth($db);
        $auth->logout();
        $installed = $db->query("SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_push_subscriptions'")->fetchColumn();
        if ($installed) {
            $db->prepare('DELETE FROM web_push_subscriptions WHERE user_id = ? AND session_hash = ?')
                ->execute([$userId, $sessionHash]);
            $db->prepare('DELETE FROM fcm_tokens WHERE user_id = ? AND wake_session_hash = ?')
                ->execute([$userId, $sessionHash]);
        }
    }
} catch (Exception $e) {
    error_log('Logout error: ' . $e->getMessage());
    // Continue with logout even if DB fails
}

// Clear all session data
$_SESSION = [];

// Delete session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );
}

// Destroy session
session_destroy();

// Redirect to login
header('Location: /login.php?logged_out=1', true, 302);
exit;