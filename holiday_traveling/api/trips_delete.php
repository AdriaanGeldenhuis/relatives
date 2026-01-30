<?php
/**
 * Holiday Traveling API - Delete Trip
 * DELETE /api/trips_delete.php?id={id}
 */
declare(strict_types=1);

require_once __DIR__ . '/../routes.php';

// Require authentication
HT_Auth::requireLogin();

// Verify CSRF
HT_CSRF::verifyOrDie();

// Accept DELETE or POST with _method=DELETE
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && ($_POST['_method'] ?? '') === 'DELETE') {
    $method = 'DELETE';
}

if ($method !== 'DELETE') {
    HT_Response::error('Method not allowed', 405);
}

try {
    $tripId = (int) ($_GET['id'] ?? 0);

    if (!$tripId) {
        HT_Response::error('Trip ID is required', 400);
    }

    // Check if user can edit this trip
    if (!HT_Auth::canEditTrip($tripId)) {
        HT_Response::error('You do not have permission to delete this trip', 403);
    }

    // Get trip info before deleting (for logging and calendar cleanup)
    $trip = HT_DB::fetchOne(
        "SELECT id, family_id, title, destination FROM ht_trips WHERE id = ?",
        [$tripId]
    );

    if (!$trip) {
        HT_Response::error('Trip not found', 404);
    }

    // Delete all calendar events for this trip (main holiday + activities + sleepovers)
    $deletedEvents = 0;
    try {
        $deletedEvents = HT_InternalCalendar::deleteTripEvents((int) $trip['family_id'], $tripId);
        error_log(sprintf(
            'Calendar events deleted: Trip=%d, Events=%d, User=%d',
            $tripId, $deletedEvents, HT_Auth::userId()
        ));
    } catch (Exception $e) {
        error_log('Calendar cleanup failed during trip deletion: ' . $e->getMessage());
        // Continue with trip deletion even if calendar cleanup fails
    }

    // Delete trip (cascades to related tables)
    HT_DB::delete('ht_trips', 'id = ?', [$tripId]);

    // Log the deletion
    error_log(sprintf(
        'Trip deleted: ID=%d, Title=%s, Destination=%s, by User=%d',
        $tripId,
        $trip['title'],
        $trip['destination'],
        HT_Auth::userId()
    ));

    HT_Response::ok([
        'deleted_id' => $tripId,
        'message' => 'Trip deleted successfully'
    ]);

} catch (Exception $e) {
    error_log('Trip deletion error: ' . $e->getMessage());
    HT_Response::error('Failed to delete trip', 500);
}
