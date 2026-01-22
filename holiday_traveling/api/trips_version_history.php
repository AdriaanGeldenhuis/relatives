<?php
/**
 * Holiday Traveling API - Get Plan Version History
 * GET /api/trips_version_history.php?trip_id=123
 *
 * Returns all plan versions for a trip
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

    // Fetch trip
    $trip = HT_DB::fetchOne("SELECT id, destination, current_plan_version FROM ht_trips WHERE id = ?", [$tripId]);
    if (!$trip) {
        HT_Response::error('Trip not found', 404);
    }

    // Fetch all versions
    $versions = HT_DB::fetchAll(
        "SELECT
            id,
            version_number,
            summary_text,
            created_by,
            refinement_instruction,
            is_active,
            created_at
        FROM ht_trip_plan_versions
        WHERE trip_id = ?
        ORDER BY version_number DESC",
        [$tripId]
    );

    // Format versions for response
    $formattedVersions = array_map(function($v) {
        return [
            'id' => (int) $v['id'],
            'version' => (int) $v['version_number'],
            'summary' => $v['summary_text'],
            'created_by' => $v['created_by'],
            'instruction' => $v['refinement_instruction'],
            'is_active' => (bool) $v['is_active'],
            'created_at' => $v['created_at'],
            'created_at_formatted' => ht_format_datetime($v['created_at'])
        ];
    }, $versions);

    HT_Response::ok([
        'trip_id' => $tripId,
        'destination' => $trip['destination'],
        'current_version' => (int) $trip['current_plan_version'],
        'total_versions' => count($versions),
        'versions' => $formattedVersions
    ]);

} catch (Exception $e) {
    error_log('Version history error: ' . $e->getMessage());
    HT_Response::error($e->getMessage(), 500);
}
