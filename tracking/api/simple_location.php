<?php
/**
 * POST /tracking/api/simple_location.php
 *
 * Simplified location update for browser-based tracking.
 * Stores location AND processes geofences for enter/exit detection.
 */

session_start();

// Check auth
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'not_authenticated']);
    exit;
}

// Use tracking bootstrap (includes geofence services)
require_once __DIR__ . '/../core/bootstrap_tracking.php';

$userId = (int)$_SESSION['user_id'];

// Get user's family_id
$stmt = $db->prepare("SELECT family_id, location_sharing FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'user_not_found']);
    exit;
}

if (!$user['location_sharing']) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'location_sharing_disabled']);
    exit;
}

$familyId = (int)$user['family_id'];

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'invalid_json']);
    exit;
}

// Extract and validate coordinates
$lat = isset($input['lat']) ? (float)$input['lat'] : (isset($input['latitude']) ? (float)$input['latitude'] : null);
$lng = isset($input['lng']) ? (float)$input['lng'] : (isset($input['longitude']) ? (float)$input['longitude'] : null);

if ($lat === null || $lng === null) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'missing_coordinates', 'message' => 'lat and lng are required']);
    exit;
}

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'invalid_coordinates']);
    exit;
}

// Optional fields
$accuracy = isset($input['accuracy_m']) ? (float)$input['accuracy_m'] : (isset($input['accuracy']) ? (float)$input['accuracy'] : null);
$speed = isset($input['speed_mps']) ? (float)$input['speed_mps'] : (isset($input['speed']) ? (float)$input['speed'] : null);
$bearing = isset($input['bearing_deg']) ? (float)$input['bearing_deg'] : (isset($input['heading']) ? (float)$input['heading'] : null);
$altitude = isset($input['altitude_m']) ? (float)$input['altitude_m'] : (isset($input['altitude']) ? (float)$input['altitude'] : null);
$deviceId = isset($input['device_id']) ? substr($input['device_id'], 0, 64) : null;
$platform = isset($input['platform']) ? substr($input['platform'], 0, 20) : 'web';
$appVersion = isset($input['app_version']) ? substr($input['app_version'], 0, 20) : 'web-1.0';

// Determine motion state
$motionState = 'unknown';
if ($speed !== null) {
    $motionState = $speed > 1.0 ? 'moving' : 'idle';
}

// Current timestamp
$recordedAt = date('Y-m-d H:i:s');
if (isset($input['recorded_at'])) {
    $recordedAt = $input['recorded_at'];
} elseif (isset($input['timestamp'])) {
    $ts = $input['timestamp'];
    if (is_numeric($ts)) {
        if ($ts > 1000000000000) $ts = $ts / 1000;
        $recordedAt = date('Y-m-d H:i:s', (int)$ts);
    } else {
        $recordedAt = $ts;
    }
}

try {
    // UPSERT into tracking_current (always update latest)
    $stmt = $db->prepare("
        INSERT INTO tracking_current (
            user_id, family_id, lat, lng, accuracy_m, speed_mps,
            bearing_deg, altitude_m, motion_state, recorded_at, updated_at,
            device_id, platform, app_version
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            lat = VALUES(lat),
            lng = VALUES(lng),
            accuracy_m = VALUES(accuracy_m),
            speed_mps = VALUES(speed_mps),
            bearing_deg = VALUES(bearing_deg),
            altitude_m = VALUES(altitude_m),
            motion_state = VALUES(motion_state),
            recorded_at = VALUES(recorded_at),
            updated_at = NOW(),
            device_id = VALUES(device_id),
            platform = VALUES(platform),
            app_version = VALUES(app_version)
    ");

    $stmt->execute([
        $userId,
        $familyId,
        round($lat, 7),
        round($lng, 7),
        $accuracy,
        $speed,
        $bearing,
        $altitude,
        $motionState,
        $recordedAt,
        $deviceId,
        $platform,
        $appVersion
    ]);

    // Also insert into history for trail
    $stmt = $db->prepare("
        INSERT INTO tracking_locations (
            family_id, user_id, lat, lng, accuracy_m, speed_mps,
            bearing_deg, altitude_m, motion_state, recorded_at, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )
    ");

    $stmt->execute([
        $familyId,
        $userId,
        round($lat, 7),
        round($lng, 7),
        $accuracy,
        $speed,
        $bearing,
        $altitude,
        $motionState,
        $recordedAt
    ]);

    $historyId = (int)$db->lastInsertId();

    // =========================================
    // PROCESS GEOFENCES (enter/exit detection)
    // =========================================
    $geofenceEvents = [];
    try {
        $geofenceRepo = new GeofenceRepo($db, $trackingCache);
        $eventsRepo = new EventsRepo($db);
        $alertsRepo = new AlertsRepo($db, $trackingCache);
        $alertsEngine = new AlertsEngine($alertsRepo, $eventsRepo);
        $geofenceEngine = new GeofenceEngine($geofenceRepo, $eventsRepo, $alertsEngine);

        // Process geofence transitions
        $geofenceEvents = $geofenceEngine->process($familyId, $userId, $lat, $lng);
    } catch (Exception $e) {
        // Log but don't fail the request - location was saved successfully
        error_log('Geofence processing error: ' . $e->getMessage());
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => [
            'accepted' => true,
            'motion_state' => $motionState,
            'stored_history' => true,
            'history_id' => $historyId,
            'geofence_events' => $geofenceEvents
        ]
    ]);

} catch (PDOException $e) {
    error_log('Location update error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'database_error',
        'message' => 'Failed to save location'
    ]);
}
