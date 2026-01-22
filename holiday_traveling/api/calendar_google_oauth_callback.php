<?php
/**
 * Holiday Traveling API - Google Calendar OAuth Callback
 * GET /api/calendar_google_oauth_callback.php
 *
 * Handles OAuth callback from Google
 */
declare(strict_types=1);

require_once __DIR__ . '/../routes.php';

// Require authentication (session should still be active)
HT_Auth::requireLogin();

try {
    // Check for error from Google
    if (isset($_GET['error'])) {
        error_log('Google OAuth error: ' . $_GET['error']);
        header('Location: /holiday_traveling/?error=google_auth_denied');
        exit;
    }

    // Get authorization code
    $code = $_GET['code'] ?? '';
    $stateParam = $_GET['state'] ?? '';

    if (empty($code) || empty($stateParam)) {
        header('Location: /holiday_traveling/?error=invalid_callback');
        exit;
    }

    // Decode state
    $state = json_decode(base64_decode($stateParam), true);
    if (!$state || !isset($state['trip_id']) || !isset($state['user_id'])) {
        header('Location: /holiday_traveling/?error=invalid_state');
        exit;
    }

    // Verify user matches
    $currentUserId = HT_Auth::userId();
    if ($state['user_id'] != $currentUserId) {
        error_log("OAuth user mismatch: state={$state['user_id']}, current={$currentUserId}");
        header('Location: /holiday_traveling/?error=user_mismatch');
        exit;
    }

    $tripId = (int) $state['trip_id'];

    // Build redirect URI (must match exactly what was sent)
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $redirectUri = "{$protocol}://{$host}/holiday_traveling/api/calendar_google_oauth_callback.php";

    // Exchange code for tokens
    $tokens = HT_GoogleCalendar::exchangeCode($code, $redirectUri);

    // Save tokens
    HT_GoogleCalendar::saveTokens($currentUserId, $tokens);

    // Log success
    error_log("Google Calendar connected for user {$currentUserId}");

    // Redirect to push events
    header('Location: /holiday_traveling/api/calendar_google_push.php?trip_id=' . $tripId . '&just_connected=1');
    exit;

} catch (Exception $e) {
    error_log('Google OAuth callback error: ' . $e->getMessage());
    header('Location: /holiday_traveling/?error=google_auth_failed');
    exit;
}
