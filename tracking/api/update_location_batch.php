<?php
/**
 * POST /tracking/api/update_location_batch.php
 *
 * Android app batch location upload endpoint.
 *
 * Accepts Android's format and translates to internal format.
 * Authenticates via Bearer token (session_token).
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('method_not_allowed', 'POST required', 405);
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonError('invalid_json', 'Invalid JSON body', 400);
}

// ============================================
// AUTHENTICATE VIA BEARER TOKEN
// Android app sends session_token in header and body
// ============================================
$sessionToken = null;

// Try Authorization header first
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
    $sessionToken = $matches[1];
}

// Fall back to body
if (!$sessionToken && isset($input['session_token'])) {
    $sessionToken = $input['session_token'];
}

if (!$sessionToken) {
    jsonError('auth_required', 'Missing session_token', 401);
}

// Authenticate via session_token
$hashedToken = hash('sha256', $sessionToken);
$stmt = $db->prepare("
    SELECT u.id, u.family_id, u.full_name as name, u.status
    FROM users u
    JOIN sessions s ON s.user_id = u.id
    WHERE s.session_token = ?
      AND s.expires_at > NOW()
      AND u.status = 'active'
    LIMIT 1
");
$stmt->execute([$hashedToken]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    jsonError('invalid_token', 'Invalid or expired session token', 401);
}

$userId = (int)$user['id'];
$familyId = (int)$user['family_id'];

// ============================================
// VALIDATE LOCATIONS ARRAY
// ============================================
if (!isset($input['locations']) || !is_array($input['locations'])) {
    jsonError('invalid_format', 'Missing locations array', 400);
}

if (empty($input['locations'])) {
    jsonSuccess([
        'total' => 0,
        'accepted' => 0,
        'rejected' => 0,
        'results' => []
    ]);
}

// Device info from request
$deviceId = $input['device_uuid'] ?? $input['device_id'] ?? null;
$platform = $input['platform'] ?? 'android';
$appVersion = $input['app_version'] ?? null;

// ============================================
// TRANSLATE ANDROID FORMAT TO INTERNAL FORMAT
// ============================================
$translatedLocations = [];

foreach ($input['locations'] as $loc) {
    $translated = [];

    // Required: lat/lng
    if (isset($loc['latitude'])) {
        $translated['lat'] = (float)$loc['latitude'];
    } elseif (isset($loc['lat'])) {
        $translated['lat'] = (float)$loc['lat'];
    }

    if (isset($loc['longitude'])) {
        $translated['lng'] = (float)$loc['longitude'];
    } elseif (isset($loc['lng'])) {
        $translated['lng'] = (float)$loc['lng'];
    }

    // Skip if no coordinates
    if (!isset($translated['lat']) || !isset($translated['lng'])) {
        continue;
    }

    // Accuracy
    if (isset($loc['accuracy_m'])) {
        $translated['accuracy_m'] = (float)$loc['accuracy_m'];
    } elseif (isset($loc['accuracy'])) {
        $translated['accuracy_m'] = (float)$loc['accuracy'];
    }

    // Speed - convert km/h to m/s
    // If generic 'speed' field > 50, assume it's km/h not m/s (heuristic)
    $speed = null;
    if (isset($loc['speed_mps'])) {
        $speed = (float)$loc['speed_mps'];
    } elseif (isset($loc['speed_kmh'])) {
        $speed = (float)$loc['speed_kmh'] / 3.6;
    } elseif (isset($loc['speed'])) {
        $speed = (float)$loc['speed'];
        if ($speed > 50) {
            $speed = $speed / 3.6; // Assume km/h, convert to m/s
        }
    }
    // Sanity check: cap at 100 m/s (360 km/h)
    if ($speed !== null && $speed > 100) {
        $speed = null;
    }
    $translated['speed_mps'] = $speed;

    // Bearing/heading
    if (isset($loc['bearing_deg'])) {
        $translated['bearing_deg'] = (float)$loc['bearing_deg'];
    } elseif (isset($loc['heading_deg'])) {
        $translated['bearing_deg'] = (float)$loc['heading_deg'];
    } elseif (isset($loc['heading'])) {
        $translated['bearing_deg'] = (float)$loc['heading'];
    }

    // Altitude
    if (isset($loc['altitude_m']) && $loc['altitude_m'] !== null) {
        $translated['altitude_m'] = (float)$loc['altitude_m'];
    } elseif (isset($loc['altitude']) && $loc['altitude'] !== null) {
        $translated['altitude_m'] = (float)$loc['altitude'];
    }

    // Timestamp
    if (isset($loc['recorded_at'])) {
        $translated['recorded_at'] = $loc['recorded_at'];
    } elseif (isset($loc['client_timestamp'])) {
        $ts = $loc['client_timestamp'];
        // Convert ms to seconds if needed
        if ($ts > 1000000000000) {
            $ts = $ts / 1000;
        }
        $translated['recorded_at'] = date('Y-m-d H:i:s', (int)$ts);
    } else {
        $translated['recorded_at'] = date('Y-m-d H:i:s');
    }

    // Device info
    $translated['device_id'] = $deviceId;
    $translated['platform'] = $platform;
    $translated['app_version'] = $appVersion;

    // Client event ID for response tracking
    $translated['client_event_id'] = $loc['client_event_id'] ?? null;

    $translatedLocations[] = $translated;
}

if (empty($translatedLocations)) {
    jsonSuccess([
        'total' => 0,
        'accepted' => 0,
        'rejected' => 0,
        'results' => []
    ]);
}

// Sort by recorded_at (oldest first)
usort($translatedLocations, function($a, $b) {
    return strtotime($a['recorded_at']) <=> strtotime($b['recorded_at']);
});

// ============================================
// INITIALIZE SERVICES
// ============================================
$settingsRepo = new SettingsRepo($db, $trackingCache);
$sessionsRepo = new SessionsRepo($db, $trackingCache);
$locationRepo = new LocationRepo($db, $trackingCache);
$eventsRepo = new EventsRepo($db);
$alertsRepo = new AlertsRepo($db, $trackingCache);
$geofenceRepo = new GeofenceRepo($db, $trackingCache);

$motionGate = new MotionGate($settingsRepo, $locationRepo);
$dedupe = new Dedupe($trackingCache, $settingsRepo);
$alertsEngine = new AlertsEngine($alertsRepo, $eventsRepo);
$geofenceEngine = new GeofenceEngine($geofenceRepo, $eventsRepo, $alertsEngine);

$settings = $settingsRepo->get($familyId);

// More lenient accuracy for Android (500m)
$maxAccuracy = max($settings['min_accuracy_m'], 500);

// ============================================
// PROCESS LOCATIONS
// ============================================
$results = [];
$accepted = 0;
$rejected = 0;
$lastLocation = null;

foreach ($translatedLocations as $location) {
    $result = [
        'client_event_id' => $location['client_event_id'],
        'recorded_at' => $location['recorded_at']
    ];

    // Accuracy check (lenient)
    if (isset($location['accuracy_m']) && $location['accuracy_m'] > $maxAccuracy) {
        $result['success'] = false;
        $result['reason'] = 'poor_accuracy';
        $rejected++;
        $results[] = $result;
        continue;
    }

    // Dedupe check
    $dedupeCheck = $dedupe->check($userId, $familyId, $location);
    if ($dedupeCheck['is_duplicate']) {
        $result['success'] = true;
        $result['already_exists'] = true;
        $result['reason'] = 'duplicate';
        $results[] = $result;
        continue;
    }

    // Motion analysis
    $motionResult = $motionGate->evaluate($familyId, $userId, $location);
    $location['motion_state'] = $motionResult['motion_state'];

    // Store current location
    $locationRepo->upsertCurrent($userId, $familyId, $location);

    // Store history if needed
    if ($motionResult['store_history']) {
        $historyId = $locationRepo->insertHistory($userId, $familyId, $location);
        $result['history_id'] = $historyId;
    }

    $lastLocation = $location;

    $result['success'] = true;
    $result['motion_state'] = $motionResult['motion_state'];
    $accepted++;
    $results[] = $result;
}

// Process geofences for final location only
$geofenceEvents = [];
if ($lastLocation) {
    $geofenceEvents = $geofenceEngine->process($familyId, $userId, $lastLocation['lat'], $lastLocation['lng']);
}

jsonSuccess([
    'total' => count($translatedLocations),
    'accepted' => $accepted,
    'rejected' => $rejected,
    'results' => $results,
    'geofence_events' => $geofenceEvents
]);
