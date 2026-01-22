<?php
/**
 * Holiday Traveling API - Export ICS Calendar
 * GET /api/calendar_export_ics.php?id=123
 *
 * Downloads an ICS file for the trip itinerary
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
    $tripId = (int) ($_GET['id'] ?? 0);

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    // Check access
    if (!HT_Auth::canAccessTrip($tripId)) {
        HT_Response::error('Access denied', 403);
    }

    // Fetch trip
    $trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);
    if (!$trip) {
        HT_Response::error('Trip not found', 404);
    }

    // Get active plan
    $planRecord = HT_DB::fetchOne(
        "SELECT plan_json FROM ht_trip_plan_versions WHERE trip_id = ? AND is_active = 1 LIMIT 1",
        [$tripId]
    );

    if (!$planRecord) {
        HT_Response::error('No plan available for this trip. Generate a plan first.', 400);
    }

    $plan = json_decode($planRecord['plan_json'], true);

    // Generate ICS
    $ics = HT_ICS::fromTripPlan($trip, $plan);

    // Generate safe filename
    $destination = preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($trip['destination']));
    $filename = "trip-{$destination}-{$trip['start_date']}.ics";

    // Output ICS file for download
    $ics->download($filename);

} catch (Exception $e) {
    error_log('Calendar export error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
