<?php
/**
 * Holiday Traveling API - Calendar Sync
 * POST /api/calendar_sync.php
 *
 * Syncs an existing trip's plan to the internal calendar
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
    $input = ht_json_input();
    $tripId = (int) ($input['trip_id'] ?? 0);

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    // Check permissions
    if (!HT_Auth::canViewTrip($tripId)) {
        HT_Response::error('You do not have permission to access this trip', 403);
    }

    // Fetch trip data
    $trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);
    if (!$trip) {
        HT_Response::error('Trip not found', 404);
    }

    // Fetch the active plan
    $planRecord = HT_DB::fetchOne(
        "SELECT plan_json FROM ht_trip_plan_versions WHERE trip_id = ? AND is_active = 1",
        [$tripId]
    );

    if (!$planRecord) {
        HT_Response::error('No active plan found for this trip. Generate a plan first.', 400);
    }

    $plan = json_decode($planRecord['plan_json'], true);
    if (!$plan || empty($plan['itinerary'])) {
        HT_Response::error('Plan has no itinerary to sync', 400);
    }

    $userId = HT_Auth::userId();
    $familyId = (int) $trip['family_id'];

    // Log plan structure for debugging
    $itineraryDays = count($plan['itinerary']);
    $totalActivities = 0;
    foreach ($plan['itinerary'] as $day) {
        $totalActivities += count($day['morning'] ?? []);
        $totalActivities += count($day['afternoon'] ?? []);
        $totalActivities += count($day['evening'] ?? []);
    }
    error_log(sprintf(
        'Calendar sync starting: Trip=%d, Days=%d, Activities=%d, StartDate=%s',
        $tripId, $itineraryDays, $totalActivities, $trip['start_date']
    ));

    // Sync to internal calendar
    $events = HT_InternalCalendar::insertTripEvents($userId, $familyId, $trip, $plan);

    error_log(sprintf(
        'Calendar sync complete: Trip=%d, Events created=%d, User=%d',
        $tripId, count($events), $userId
    ));

    HT_Response::ok([
        'success' => true,
        'events_created' => count($events),
        'message' => count($events) . ' activities synced to calendar',
        'debug' => [
            'trip_id' => $tripId,
            'start_date' => $trip['start_date'],
            'itinerary_days' => $itineraryDays,
            'total_activities' => $totalActivities,
            'events' => $events
        ]
    ]);

} catch (Exception $e) {
    error_log('Calendar sync error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
