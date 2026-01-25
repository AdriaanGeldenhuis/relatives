<?php
/**
 * Holiday Traveling API - Get Specific Plan Version
 * GET /api/trips_get_version.php?trip_id=123&version=2
 *
 * Returns the full plan data for a specific version
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
    $versionNumber = (int) ($_GET['version'] ?? 0);

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    if (!$versionNumber) {
        HT_Response::error('Version number is required', 400);
    }

    // Check access
    if (!HT_Auth::canAccessTrip($tripId)) {
        HT_Response::error('Access denied', 403);
    }

    // Fetch the version
    $version = HT_DB::fetchOne(
        "SELECT * FROM ht_trip_plan_versions WHERE trip_id = ? AND version_number = ?",
        [$tripId, $versionNumber]
    );

    if (!$version) {
        HT_Response::error('Version not found', 404);
    }

    // Parse plan JSON
    $plan = json_decode($version['plan_json'], true);

    HT_Response::ok([
        'trip_id' => $tripId,
        'version' => (int) $version['version_number'],
        'summary' => $version['summary_text'],
        'created_by' => $version['created_by'],
        'instruction' => $version['refinement_instruction'],
        'is_active' => (bool) $version['is_active'],
        'created_at' => $version['created_at'],
        'created_at_formatted' => ht_format_datetime($version['created_at']),
        'plan' => $plan
    ]);

} catch (Exception $e) {
    error_log('Get version error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
