<?php
/**
 * Holiday Traveling API - Push to Google Calendar
 * GET /api/calendar_google_push.php?trip_id=123
 * POST /api/calendar_google_push.php (JSON body with trip_id)
 *
 * Pushes trip events to user's Google Calendar
 */
declare(strict_types=1);

require_once __DIR__ . '/../routes.php';

// Require authentication
HT_Auth::requireLogin();

$userId = HT_Auth::userId();

// Handle both GET (from redirect) and POST (from button)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // GET request - from OAuth callback redirect
    $tripId = (int) ($_GET['trip_id'] ?? 0);
    $justConnected = isset($_GET['just_connected']);

    if (!$tripId) {
        header('Location: /holiday_traveling/?error=missing_trip_id');
        exit;
    }

    // Check access
    if (!HT_Auth::canAccessTrip($tripId)) {
        header('Location: /holiday_traveling/?error=access_denied');
        exit;
    }

    // Check if connected
    if (!HT_GoogleCalendar::isConnected($userId)) {
        header('Location: /holiday_traveling/api/calendar_google_oauth_start.php?trip_id=' . $tripId);
        exit;
    }

    try {
        // Fetch trip and plan
        $trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);
        if (!$trip) {
            header('Location: /holiday_traveling/?error=trip_not_found');
            exit;
        }

        $planRecord = HT_DB::fetchOne(
            "SELECT plan_json FROM ht_trip_plan_versions WHERE trip_id = ? AND is_active = 1 LIMIT 1",
            [$tripId]
        );

        if (!$planRecord) {
            header('Location: /holiday_traveling/trip_view.php?id=' . $tripId . '&error=no_plan');
            exit;
        }

        $plan = json_decode($planRecord['plan_json'], true);

        // Insert events
        $events = HT_GoogleCalendar::insertTripEvents($userId, $trip, $plan);

        // Log success
        error_log(sprintf(
            'Google Calendar push: User=%d, Trip=%d, Events=%d',
            $userId, $tripId, count($events)
        ));

        // Redirect back to trip with success
        header('Location: /holiday_traveling/trip_view.php?id=' . $tripId . '&google_sync=success&events=' . count($events));
        exit;

    } catch (Exception $e) {
        error_log('Google Calendar push error: ' . $e->getMessage());
        header('Location: /holiday_traveling/trip_view.php?id=' . $tripId . '&google_sync=error');
        exit;
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST request - AJAX call from UI
    HT_CSRF::verifyOrDie();

    try {
        $input = ht_json_input();
        $tripId = (int) ($input['trip_id'] ?? 0);

        if (!$tripId) {
            HT_Response::error('Trip ID is required', 400);
        }

        // Check access
        if (!HT_Auth::canAccessTrip($tripId)) {
            HT_Response::error('Access denied', 403);
        }

        // Check if connected
        if (!HT_GoogleCalendar::isConnected($userId)) {
            HT_Response::error('Google Calendar not connected', 401);
        }

        // Fetch trip and plan
        $trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);
        if (!$trip) {
            HT_Response::error('Trip not found', 404);
        }

        $planRecord = HT_DB::fetchOne(
            "SELECT plan_json FROM ht_trip_plan_versions WHERE trip_id = ? AND is_active = 1 LIMIT 1",
            [$tripId]
        );

        if (!$planRecord) {
            HT_Response::error('No plan available. Generate a plan first.', 400);
        }

        $plan = json_decode($planRecord['plan_json'], true);

        // Insert events
        $events = HT_GoogleCalendar::insertTripEvents($userId, $trip, $plan);

        // Log success
        error_log(sprintf(
            'Google Calendar push (POST): User=%d, Trip=%d, Events=%d',
            $userId, $tripId, count($events)
        ));

        HT_Response::ok([
            'trip_id' => $tripId,
            'events_created' => count($events),
            'message' => count($events) . ' events added to Google Calendar'
        ]);

    } catch (Exception $e) {
        error_log('Google Calendar push POST error: ' . $e->getMessage());
        HT_Response::error($e->getMessage(), 500);
    }

} else {
    HT_Response::error('Method not allowed', 405);
}
