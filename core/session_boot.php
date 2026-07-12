<?php
declare(strict_types=1);

/**
 * ============================================
 * SHARED SESSION BOOTSTRAP
 * ============================================
 *
 * Every entry point that starts the session early (before core/bootstrap.php)
 * MUST do it through this file:
 *
 *     require_once __DIR__ . '/<path to>/core/session_boot.php';
 *
 * Why this exists: a bare session_name('RELATIVES_SESSION'); session_start();
 * runs with php.ini defaults — session.gc_maxlifetime is typically 1440s
 * (24 minutes) and use_strict_mode is off. PHP's probabilistic garbage
 * collector uses the CURRENT request's gc_maxlifetime, so any high-frequency
 * endpoint started that way (e.g. the tracking API polled every 15s by the
 * app) randomly deletes EVERY session file idle for more than 24 minutes —
 * logging the whole family out of their "30-day" sessions. bootstrap.php
 * cannot repair this later: its hardening branch is skipped when the session
 * is already active under the correct name.
 */

if (!function_exists('relatives_session_start')) {
    function relatives_session_ini(): void
    {
        ini_set('session.gc_maxlifetime', '2592000');  // 30 days
        ini_set('session.cookie_lifetime', '2592000'); // 30 days
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
    }

    function relatives_session_start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && session_name() !== 'RELATIVES_SESSION') {
            // Session started with the wrong name — migrate its data.
            $existingData = $_SESSION;
            session_write_close();

            relatives_session_ini();
            session_name('RELATIVES_SESSION');
            session_start();

            if (empty($_SESSION) && !empty($existingData)) {
                $_SESSION = $existingData;
            }
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            relatives_session_ini();
            session_name('RELATIVES_SESSION');
            session_start();
        }
    }
}

relatives_session_start();
