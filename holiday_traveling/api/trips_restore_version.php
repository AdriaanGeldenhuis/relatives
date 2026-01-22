<?php
/**
 * Holiday Traveling API - Restore Plan Version
 * POST /api/trips_restore_version.php
 *
 * Restores a previous plan version as the active version
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
    $versionNumber = (int) ($input['version_number'] ?? 0);

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    if (!$versionNumber) {
        HT_Response::error('Version number is required', 400);
    }

    // Check permissions
    if (!HT_Auth::canEditTrip($tripId)) {
        HT_Response::error('Permission denied', 403);
    }

    // Fetch trip
    $trip = HT_DB::fetchOne("SELECT * FROM ht_trips WHERE id = ?", [$tripId]);
    if (!$trip) {
        HT_Response::error('Trip not found', 404);
    }

    // Fetch the version to restore
    $versionToRestore = HT_DB::fetchOne(
        "SELECT * FROM ht_trip_plan_versions WHERE trip_id = ? AND version_number = ?",
        [$tripId, $versionNumber]
    );

    if (!$versionToRestore) {
        HT_Response::error('Version not found', 404);
    }

    // Check if already active
    if ($versionToRestore['is_active']) {
        HT_Response::error('This version is already active', 400);
    }

    // Get current max version number
    $maxVersion = HT_DB::fetchColumn(
        "SELECT MAX(version_number) FROM ht_trip_plan_versions WHERE trip_id = ?",
        [$tripId]
    );

    // Create a new version based on the restored one
    $newVersion = ($maxVersion ?? 0) + 1;

    // Deactivate all previous versions
    HT_DB::execute(
        "UPDATE ht_trip_plan_versions SET is_active = 0 WHERE trip_id = ?",
        [$tripId]
    );

    // Insert restored version as new active version
    $planData = json_decode($versionToRestore['plan_json'], true);

    HT_DB::insert('ht_trip_plan_versions', [
        'trip_id' => $tripId,
        'version_number' => $newVersion,
        'plan_json' => $versionToRestore['plan_json'],
        'summary_text' => "Restored from v{$versionNumber}: " . ($versionToRestore['summary_text'] ?? 'No summary'),
        'created_by' => 'user',
        'refinement_instruction' => "Restored from version {$versionNumber}",
        'is_active' => 1
    ]);

    // Update trip current version
    HT_DB::update('ht_trips', [
        'current_plan_version' => $newVersion
    ], 'id = ?', [$tripId]);

    // Log restoration
    error_log(sprintf(
        'Plan version restored: Trip=%d, FromVersion=%d, ToVersion=%d, User=%d',
        $tripId, $versionNumber, $newVersion, HT_Auth::userId()
    ));

    HT_Response::ok([
        'trip_id' => $tripId,
        'restored_from' => $versionNumber,
        'new_version' => $newVersion,
        'plan' => $planData,
        'summary' => "Restored from v{$versionNumber}"
    ]);

} catch (Exception $e) {
    error_log('Restore version error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
