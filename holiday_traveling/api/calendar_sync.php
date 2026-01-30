<?php
/**
 * Holiday Traveling API - Calendar Sync
 * POST /api/calendar_sync.php
 *
 * Syncs an existing trip's plan to the internal calendar
 */

// Catch all errors and convert to JSON response
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__ . '/../routes.php';

// Set JSON header early
header('Content-Type: application/json');

// Require authentication
HT_Auth::requireLogin();

// Verify CSRF
HT_CSRF::verifyOrDie();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = ht_json_input();
    $tripId = (int) ($input['trip_id'] ?? 0);

    if (!$tripId) {
        echo json_encode(['success' => false, 'error' => 'Trip ID is required']);
        exit;
    }

    // Check permissions
    if (!HT_Auth::canViewTrip($tripId)) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to access this trip']);
        exit;
    }

    // Fetch trip data
    $trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);
    if (!$trip) {
        echo json_encode(['success' => false, 'error' => 'Trip not found']);
        exit;
    }

    // Ensure trip ID is integer
    $trip['id'] = (int) $trip['id'];

    // Fetch the active plan
    $planRecord = HT_DB::fetchOne(
        "SELECT plan_json FROM ht_trip_plan_versions WHERE trip_id = ? AND is_active = 1",
        [$tripId]
    );

    if (!$planRecord) {
        echo json_encode(['success' => false, 'error' => 'No active plan found for this trip. Generate a plan first.']);
        exit;
    }

    $plan = json_decode($planRecord['plan_json'], true);
    if (!$plan || empty($plan['itinerary'])) {
        echo json_encode(['success' => false, 'error' => 'Plan has no itinerary to sync']);
        exit;
    }

    $userId = HT_Auth::userId();
    $familyId = (int) $trip['family_id'];

    // Count activities
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

    // Get unique dates from events
    $dates = [];
    foreach ($events as $event) {
        if (!empty($event['date'])) {
            $dates[$event['date']] = true;
        }
    }
    $dateList = array_keys($dates);
    sort($dateList);

    $message = count($events) . ' activities synced to calendar';
    if (!empty($dateList)) {
        $message .= ' (' . $dateList[0] . ' to ' . end($dateList) . ')';
    }

    echo json_encode([
        'success' => true,
        'events_created' => count($events),
        'message' => $message,
        'dates' => $dateList,
        'debug' => [
            'trip_id' => $tripId,
            'start_date' => $trip['start_date'],
            'itinerary_days' => $itineraryDays,
            'total_activities' => $totalActivities
        ]
    ]);

} catch (Throwable $e) {
    error_log('Calendar sync error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}
