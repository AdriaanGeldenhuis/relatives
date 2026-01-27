<?php
/**
 * POST /tracking/api/simple_location.php
 *
 * Simplified location update - direct to database.
 * Works for both web browser and native shell apps.
 *
 * Supports multiple auth methods:
 * - Session-based (web browser)
 * - Bearer token (native apps)
 * - API key header (native apps)
 *
 * Accepts multiple field name formats for compatibility:
 * - lat/latitude, lng/longitude
 * - speed_mps/speed_kmh/speed
 * - bearing_deg/heading_deg/heading
 * - accuracy_m/accuracy
 * - altitude_m/altitude
 * - device_id/device_uuid
 * - recorded_at/client_timestamp/timestamp
 */

// Start session for web auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../core/bootstrap.php';

header('Content-Type: application/json');

// Allow CORS for native apps
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

// ============================================
// AUTHENTICATION - Multiple methods supported
// ============================================
$userId = null;
$familyId = null;

// Method 1: Session-based (web browser)
if (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
}

// Method 2: Bearer token (native apps)
if (!$userId && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        $token = $matches[1];
        // Look up user by remember token
        $stmt = $db->prepare("SELECT id FROM users WHERE remember_token = ? AND status = 'active'");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $userId = (int)$row['id'];
        }
    }
}

// Method 3: API Key header (native apps)
if (!$userId && isset($_SERVER['HTTP_X_API_KEY'])) {
    $apiKey = $_SERVER['HTTP_X_API_KEY'];
    $stmt = $db->prepare("SELECT id FROM users WHERE api_key = ? AND status = 'active'");
    $stmt->execute([$apiKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $userId = (int)$row['id'];
    }
}

// Method 4: User ID + token in body (legacy native apps)
$input = json_decode(file_get_contents('php://input'), true);
if (!$userId && $input && isset($input['user_id']) && isset($input['auth_token'])) {
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND remember_token = ? AND status = 'active'");
    $stmt->execute([(int)$input['user_id'], $input['auth_token']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $userId = (int)$row['id'];
    }
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'not_authenticated', 'message' => 'Authentication required']);
    exit;
}

// Get user's family_id and check location sharing
$stmt = $db->prepare("SELECT family_id, location_sharing FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'user_not_found']);
    exit;
}

if (!$user['location_sharing']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'location_sharing_disabled', 'message' => 'Location sharing is disabled for your account']);
    exit;
}

$familyId = (int)$user['family_id'];

// ============================================
// PARSE INPUT - Accept multiple field formats
// ============================================
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_json', 'message' => 'Invalid or empty JSON body']);
    exit;
}

// Coordinates (required)
$lat = null;
$lng = null;

if (isset($input['lat'])) $lat = (float)$input['lat'];
elseif (isset($input['latitude'])) $lat = (float)$input['latitude'];

if (isset($input['lng'])) $lng = (float)$input['lng'];
elseif (isset($input['longitude'])) $lng = (float)$input['longitude'];
elseif (isset($input['lon'])) $lng = (float)$input['lon'];

if ($lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_coordinates', 'message' => 'lat/latitude and lng/longitude are required']);
    exit;
}

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_coordinates', 'message' => 'Coordinates out of valid range']);
    exit;
}

// Accuracy (optional)
$accuracy = null;
if (isset($input['accuracy_m'])) $accuracy = (float)$input['accuracy_m'];
elseif (isset($input['accuracy'])) $accuracy = (float)$input['accuracy'];
elseif (isset($input['horizontal_accuracy'])) $accuracy = (float)$input['horizontal_accuracy'];

// Speed (optional) - convert km/h to m/s if needed
$speed = null;
if (isset($input['speed_mps'])) {
    $speed = (float)$input['speed_mps'];
} elseif (isset($input['speed_kmh'])) {
    $speed = (float)$input['speed_kmh'] / 3.6;
} elseif (isset($input['speed'])) {
    // Assume m/s unless > 100 (then probably km/h)
    $speed = (float)$input['speed'];
    if ($speed > 100) $speed = $speed / 3.6;
}

// Bearing/Heading (optional)
$bearing = null;
if (isset($input['bearing_deg'])) $bearing = (float)$input['bearing_deg'];
elseif (isset($input['heading_deg'])) $bearing = (float)$input['heading_deg'];
elseif (isset($input['heading'])) $bearing = (float)$input['heading'];
elseif (isset($input['bearing'])) $bearing = (float)$input['bearing'];
elseif (isset($input['course'])) $bearing = (float)$input['course'];

// Altitude (optional)
$altitude = null;
if (isset($input['altitude_m'])) $altitude = (float)$input['altitude_m'];
elseif (isset($input['altitude'])) $altitude = (float)$input['altitude'];
elseif (isset($input['elevation'])) $altitude = (float)$input['elevation'];

// Device info (optional)
$deviceId = null;
if (isset($input['device_id'])) $deviceId = substr($input['device_id'], 0, 64);
elseif (isset($input['device_uuid'])) $deviceId = substr($input['device_uuid'], 0, 64);
elseif (isset($input['deviceId'])) $deviceId = substr($input['deviceId'], 0, 64);

$platform = 'unknown';
if (isset($input['platform'])) $platform = substr(strtolower($input['platform']), 0, 20);
elseif (isset($input['os'])) $platform = substr(strtolower($input['os']), 0, 20);

$appVersion = null;
if (isset($input['app_version'])) $appVersion = substr($input['app_version'], 0, 20);
elseif (isset($input['appVersion'])) $appVersion = substr($input['appVersion'], 0, 20);
elseif (isset($input['version'])) $appVersion = substr($input['version'], 0, 20);

// Motion state
$motionState = 'unknown';
if (isset($input['motion_state'])) {
    $motionState = in_array($input['motion_state'], ['moving', 'idle', 'unknown']) ? $input['motion_state'] : 'unknown';
} elseif ($speed !== null) {
    $motionState = $speed > 1.0 ? 'moving' : 'idle';
}

// Timestamp (optional)
$recordedAt = date('Y-m-d H:i:s');
if (isset($input['recorded_at'])) {
    $recordedAt = $input['recorded_at'];
} elseif (isset($input['client_timestamp'])) {
    $ts = $input['client_timestamp'];
    if (is_numeric($ts)) {
        if ($ts > 1000000000000) $ts = $ts / 1000; // milliseconds to seconds
        $recordedAt = date('Y-m-d H:i:s', (int)$ts);
    } else {
        $recordedAt = $ts;
    }
} elseif (isset($input['timestamp'])) {
    $ts = $input['timestamp'];
    if (is_numeric($ts)) {
        if ($ts > 1000000000000) $ts = $ts / 1000;
        $recordedAt = date('Y-m-d H:i:s', (int)$ts);
    } else {
        $recordedAt = $ts;
    }
}

// ============================================
// STORE IN DATABASE
// ============================================
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

    echo json_encode([
        'success' => true,
        'data' => [
            'accepted' => true,
            'motion_state' => $motionState,
            'stored_history' => true,
            'history_id' => $historyId,
            'lat' => round($lat, 7),
            'lng' => round($lng, 7)
        ]
    ]);

} catch (PDOException $e) {
    error_log('Location update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'database_error',
        'message' => 'Failed to save location'
    ]);
}
