<?php
/**
 * DELETE /tracking/api/geofences_delete.php
 *
 * Delete a geofence.
 *
 * Parameters:
 * - id: geofence ID
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Only DELETE or POST with _method=DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['_method'] ?? '') !== 'DELETE') {
        jsonError('method_not_allowed', 'DELETE required', 405);
    }
}

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Get geofence ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
if (!$id) {
    // Try from body
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? (int)$input['id'] : null;
}

if (!$id) {
    jsonError('missing_id', 'Geofence ID is required', 400);
}

// Initialize services
$geofenceRepo = new GeofenceRepo($db, $trackingCache);

// Check geofence exists
$geofence = $geofenceRepo->get($id, $familyId);
if (!$geofence) {
    jsonError('not_found', 'Geofence not found', 404);
}

// Delete
$deleted = $geofenceRepo->delete($id, $familyId);

if (!$deleted) {
    jsonError('delete_failed', 'Failed to delete geofence', 500);
}

jsonSuccess([
    'deleted' => true,
    'id' => $id,
    'message' => 'Geofence deleted successfully'
]);
