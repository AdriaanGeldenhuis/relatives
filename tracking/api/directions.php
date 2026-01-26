<?php
/**
 * GET /tracking/api/directions.php
 *
 * Get directions between two points.
 *
 * Parameters:
 * - from_lat, from_lng: starting point
 * - to_lat, to_lng OR to_user_id OR to_place_id: destination
 * - profile (optional): driving|walking|cycling (default: driving)
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Auth required
$user = requireAuth();
$familyId = $user['family_id'];

// Parse parameters
$fromLat = isset($_GET['from_lat']) ? (float)$_GET['from_lat'] : null;
$fromLng = isset($_GET['from_lng']) ? (float)$_GET['from_lng'] : null;

$toLat = isset($_GET['to_lat']) ? (float)$_GET['to_lat'] : null;
$toLng = isset($_GET['to_lng']) ? (float)$_GET['to_lng'] : null;

$toUserId = isset($_GET['to_user_id']) ? (int)$_GET['to_user_id'] : null;
$toPlaceId = isset($_GET['to_place_id']) ? (int)$_GET['to_place_id'] : null;

$profile = $_GET['profile'] ?? 'driving';

// Validate from
if ($fromLat === null || $fromLng === null) {
    jsonError('missing_from', 'from_lat and from_lng are required', 400);
}

if ($fromLat < -90 || $fromLat > 90 || $fromLng < -180 || $fromLng > 180) {
    jsonError('invalid_from', 'Invalid from coordinates', 400);
}

// Determine destination
$destination = null;
$destinationName = null;

if ($toUserId) {
    // Get user's current location
    $locationRepo = new LocationRepo($db, $trackingCache);
    $userLoc = $locationRepo->getCurrent($toUserId);

    if (!$userLoc || $userLoc['family_id'] !== $familyId) {
        jsonError('user_not_found', 'User location not available', 404);
    }

    $toLat = $userLoc['lat'];
    $toLng = $userLoc['lng'];

    // Get user name
    $stmt = $db->prepare("SELECT full_name FROM users WHERE id = ? AND family_id = ?");
    $stmt->execute([$toUserId, $familyId]);
    $destinationName = $stmt->fetchColumn() ?: 'Family member';
} elseif ($toPlaceId) {
    // Get place location
    $placesRepo = new PlacesRepo($db, $trackingCache);
    $place = $placesRepo->get($toPlaceId, $familyId);

    if (!$place) {
        jsonError('place_not_found', 'Place not found', 404);
    }

    $toLat = $place['lat'];
    $toLng = $place['lng'];
    $destinationName = $place['label'];
}

// Validate to
if ($toLat === null || $toLng === null) {
    jsonError('missing_to', 'Destination required (to_lat/to_lng, to_user_id, or to_place_id)', 400);
}

if ($toLat < -90 || $toLat > 90 || $toLng < -180 || $toLng > 180) {
    jsonError('invalid_to', 'Invalid destination coordinates', 400);
}

// Validate profile
if (!in_array($profile, ['driving', 'walking', 'cycling'])) {
    $profile = 'driving';
}

// Initialize services
$directions = new MapboxDirections($trackingCache);

// Check if API is available
if (!$directions->isAvailable()) {
    jsonError('service_unavailable', 'Directions service not configured', 503);
}

// Get route
$route = $directions->getRoute($fromLat, $fromLng, $toLat, $toLng, $profile);

if (!$route) {
    jsonError('route_failed', 'Could not calculate route', 500);
}

// Add destination name if we have it
if ($destinationName) {
    $route['destination_name'] = $destinationName;
}

if ($toUserId) {
    $route['to_user_id'] = $toUserId;
}

if ($toPlaceId) {
    $route['to_place_id'] = $toPlaceId;
}

jsonSuccess($route);
