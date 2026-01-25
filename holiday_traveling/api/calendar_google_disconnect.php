<?php
/**
 * Holiday Traveling API - Disconnect Google Calendar
 * POST /api/calendar_google_disconnect.php
 *
 * Removes Google Calendar connection for user
 */
declare(strict_types=1);

require_once __DIR__ . '/../routes.php';

// Require authentication
HT_Auth::requireLogin();

// Verify CSRF
HT_CSRF::verifyOrDie();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    HT_Response::error('Method not allowed', 405);
}

try {
    $userId = HT_Auth::userId();

    // Check if connected
    if (!HT_GoogleCalendar::isConnected($userId)) {
        HT_Response::error('Google Calendar not connected', 400);
    }

    // Disconnect
    HT_GoogleCalendar::disconnect($userId);

    error_log("Google Calendar disconnected for user {$userId}");

    HT_Response::ok([
        'message' => 'Google Calendar disconnected successfully'
    ]);

} catch (Exception $e) {
    error_log('Google Calendar disconnect error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
