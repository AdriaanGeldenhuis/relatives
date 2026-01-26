<?php
/**
 * POST /tracking/api/places_add.php
 *
 * Add a new saved place.
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
$validation = TrackingValidator::validatePlace($input);
if (!$validation['valid']) {
    jsonError('validation_failed', implode(', ', $validation['errors']), 400);
}

$data = $validation['cleaned'];

// Initialize services
$placesRepo = new PlacesRepo($db, $trackingCache);

// Create
$id = $placesRepo->create($familyId, $user['id'], $data);

// Get the created place
$place = $placesRepo->get($id, $familyId);

jsonSuccess([
    'place' => $place,
    'message' => 'Place added successfully'
]);
