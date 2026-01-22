<?php
/**
 * Holiday Traveling API - Google Calendar OAuth Start
 * GET /api/calendar_google_oauth_start.php?trip_id=123
 *
 * Initiates Google OAuth flow for calendar integration
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
    $tripId = (int) ($_GET['trip_id'] ?? 0);

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    // Check access
    if (!HT_Auth::canAccessTrip($tripId)) {
        HT_Response::error('Access denied', 403);
    }

    // Check if already connected
    $userId = HT_Auth::userId();
    if (HT_GoogleCalendar::isConnected($userId)) {
        // Already connected, redirect to push page
        header('Location: /holiday_traveling/api/calendar_google_push.php?trip_id=' . $tripId);
        exit;
    }

    // Build redirect URI
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $redirectUri = "{$protocol}://{$host}/holiday_traveling/api/calendar_google_oauth_callback.php";

    // Build state to pass trip_id through OAuth flow
    $state = base64_encode(json_encode([
        'trip_id' => $tripId,
        'user_id' => $userId,
        'csrf' => HT_CSRF::token()
    ]));

    // Get authorization URL and redirect
    $authUrl = HT_GoogleCalendar::getAuthUrl($redirectUri, $state);
    header('Location: ' . $authUrl);
    exit;

} catch (Exception $e) {
    error_log('Google OAuth start error: ' . $e->getMessage());

    // Show user-friendly error
    if (strpos($e->getMessage(), 'not configured') !== false) {
        // Redirect back with message
        header('Location: /holiday_traveling/trip_view.php?id=' . ($tripId ?? 0) . '&error=google_not_configured');
        exit;
    }

    HT_Response::error($e->getMessage(), 500);
}
