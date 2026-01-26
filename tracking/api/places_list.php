<?php
/**
 * GET /tracking/api/places_list.php
 *
 * Get all saved places for the family.
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Initialize services
$placesRepo = new PlacesRepo($db, $trackingCache);

// Get places
$places = $placesRepo->getAll($familyId);

jsonSuccess([
    'places' => $places,
    'count' => count($places)
]);
