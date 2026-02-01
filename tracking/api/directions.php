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

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../core/bootstrap_tracking.php';

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

        if (!$userLoc) {
            jsonError('user_not_found', 'User location not available. User may not have shared their location yet.', 404);
        }

        // Cast both to int for safe comparison
        if ((int)$userLoc['family_id'] !== (int)$familyId) {
            jsonError('user_not_found', 'User not in your family', 403);
        }

        $toLat = (float)$userLoc['lat'];
        $toLng = (float)$userLoc['lng'];

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

        $toLat = (float)$place['lat'];
        $toLng = (float)$place['lng'];
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

    // Initialize directions service
    $directions = new MapboxDirections($trackingCache);

    // Check if API is available
    if (!$directions->isAvailable()) {
        jsonError('service_unavailable', 'Directions service not configured. Please check MAPBOX_TOKEN in .env', 503);
    }

    // Get route
    $route = $directions->getRoute($fromLat, $fromLng, $toLat, $toLng, $profile);

    if (!$route) {
        $errorMsg = $directions->getLastError() ?: 'Could not calculate route';
        jsonError('route_failed', $errorMsg, 500);
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

} catch (Exception $e) {
    error_log("Directions API Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
} catch (Error $e) {
    error_log("Directions API Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
