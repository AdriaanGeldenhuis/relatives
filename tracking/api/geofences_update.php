<?php
/**
 * PUT /tracking/api/geofences_update.php
 *
 * Update an existing geofence.
 *
 * Parameters:
 * - id: geofence ID
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Only PUT or POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'POST'])) {
    jsonError('method_not_allowed', 'PUT or POST required', 405);
}

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Get geofence ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonError('invalid_json', 'Invalid JSON body', 400);
}

// ID can also be in body
if (!$id && isset($input['id'])) {
    $id = (int)$input['id'];
}

if (!$id) {
    jsonError('missing_id', 'Geofence ID is required', 400);
}

// Validate
$validation = TrackingValidator::validateGeofence($input, true);
if (!$validation['valid']) {
    jsonError('validation_failed', implode(', ', $validation['errors']), 400);
}

$data = $validation['cleaned'];

if (empty($data)) {
    jsonError('no_changes', 'No valid fields to update', 400);
}

// Initialize services
$geofenceRepo = new GeofenceRepo($db, $trackingCache);

// Check geofence exists
$existing = $geofenceRepo->get($id, $familyId);
if (!$existing) {
    jsonError('not_found', 'Geofence not found', 404);
}

// Update
$updated = $geofenceRepo->update($id, $familyId, $data);

if (!$updated) {
    jsonError('update_failed', 'No changes made', 400);
}

// Get updated geofence
$geofence = $geofenceRepo->get($id, $familyId);

jsonSuccess([
    'geofence' => $geofence,
    'message' => 'Geofence updated successfully'
]);
