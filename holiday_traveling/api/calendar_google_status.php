<?php
/**
 * Holiday Traveling API - Google Calendar Status
 * GET /api/calendar_google_status.php
 *
 * Returns whether user has connected Google Calendar
 */
declare(strict_types=1);

require_once __DIR__ . '/../routes.php';

// Require authentication
HT_Auth::requireLogin();

// Only accept GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    HT_Response::error('Method not allowed', 405);
}

try {
    $userId = HT_Auth::userId();
    $isConnected = HT_GoogleCalendar::isConnected($userId);

    HT_Response::ok([
        'connected' => $isConnected
    ]);

} catch (Exception $e) {
    error_log('Google Calendar status error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
