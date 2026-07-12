<?php
declare(strict_types=1);

/**
 * Tracking entry redirect.
 *
 * Logged-out users must land on the login page here. Redirecting them
 * straight to /tracking/app/ meant the Android app's URL interception
 * captured the redirect and opened the native tracking screen with no
 * session — every API call 401'd and there was no way to reach the login
 * form. /login.php sends them back here after signing in.
 */
require_once __DIR__ . '/../core/session_boot.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=' . rawurlencode('/tracking/app/'));
    exit;
}

header('Location: /tracking/app/');
exit;
