<?php
/**
 * POST /tracking/api/geofences_add.php
 *
 * Create a new geofence.
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('method_not_allowed', 'POST required', 405);
}

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonError('invalid_json', 'Invalid JSON body', 400);
}

// Validate
$validation = TrackingValidator::validateGeofence($input, false);
if (!$validation['valid']) {
    jsonError('validation_failed', implode(', ', $validation['errors']), 400);
}

$data = $validation['cleaned'];

// Initialize services
$geofenceRepo = new GeofenceRepo($db, $trackingCache);

// Create
$id = $geofenceRepo->create($familyId, $user['id'], $data);

// Get the created geofence
$geofence = $geofenceRepo->get($id, $familyId);

jsonSuccess([
    'geofence' => $geofence,
    'message' => 'Geofence created successfully'
]);
