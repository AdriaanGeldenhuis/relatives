<?php
declare(strict_types=1);

/**
 * ============================================
 * ROOT INDEX - SMART REDIRECT (FIXED)
 * ============================================
 */

// Start session BEFORE anything else
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in (simple check, no DB needed)
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    // Logged in - redirect to home
    header('Location: /home/', true, 302);
    exit;
}

// Not logged in - redirect to login
header('Location: /login.php', true, 302);
exit;