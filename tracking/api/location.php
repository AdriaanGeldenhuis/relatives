<?php
/**
 * POST /tracking/api/location.php
 *
 * Submit a single location update.
 *
 * Supports both new format and old Android app format:
 * - latitude/longitude -> lat/lng
 * - device_uuid -> device_id
 * - speed_kmh -> speed_mps (converted)
 * - heading_deg -> bearing_deg
 * - client_timestamp -> recorded_at
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('method_not_allowed', 'POST required', 405);
}

// Auth required
$user = requireAuth();
$userId = $user['id'];
$familyId = $user['family_id'];

// Check if user has location sharing enabled
if (!$siteContext->hasLocationSharing()) {
    jsonError('location_sharing_disabled', 'Location sharing is disabled for your account', 403);
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonError('invalid_json', 'Invalid JSON body', 400);
}

// ============================================
// TRANSLATE OLD FIELD NAMES TO NEW FORMAT
// (for Android app compatibility)
// ============================================
$translated = [];

// Required fields - translate latitude/longitude to lat/lng
if (isset($input['latitude'])) {
    $translated['lat'] = $input['latitude'];
} elseif (isset($input['lat'])) {
    $translated['lat'] = $input['lat'];
}

if (isset($input['longitude'])) {
    $translated['lng'] = $input['longitude'];
} elseif (isset($input['lng'])) {
    $translated['lng'] = $input['lng'];
}

// Accuracy
if (isset($input['accuracy_m'])) {
    $translated['accuracy_m'] = $input['accuracy_m'];
} elseif (isset($input['accuracy'])) {
    $translated['accuracy_m'] = $input['accuracy'];
}

// Speed - convert km/h to m/s if needed
// Note: speed > 50 m/s = 180 km/h, very fast for a car
// If generic 'speed' field is > 50, assume it's km/h not m/s
if (isset($input['speed_mps'])) {
    $speed = (float)$input['speed_mps'];
} elseif (isset($input['speed_kmh'])) {
    $speed = (float)$input['speed_kmh'] / 3.6;
} elseif (isset($input['speed'])) {
    $speed = (float)$input['speed'];
    // Heuristic: if speed > 50, it's likely km/h not m/s
    if ($speed > 50) {
        $speed = $speed / 3.6;
    }
} else {
    $speed = null;
}

// Sanity check: cap speed at 100 m/s (360 km/h) - faster than any normal vehicle
if ($speed !== null && $speed > 100) {
    $speed = null; // Treat as invalid/unknown
}

$translated['speed_mps'] = $speed;

// Bearing/heading
if (isset($input['bearing_deg'])) {
    $translated['bearing_deg'] = $input['bearing_deg'];
} elseif (isset($input['heading_deg'])) {
    $translated['bearing_deg'] = $input['heading_deg'];
} elseif (isset($input['heading'])) {
    $translated['bearing_deg'] = $input['heading'];
}

// Altitude
if (isset($input['altitude_m'])) {
    $translated['altitude_m'] = $input['altitude_m'];
} elseif (isset($input['altitude'])) {
    $translated['altitude_m'] = $input['altitude'];
}

// Device ID
if (isset($input['device_id'])) {
    $translated['device_id'] = $input['device_id'];
} elseif (isset($input['device_uuid'])) {
    $translated['device_id'] = $input['device_uuid'];
}

// Platform
if (isset($input['platform'])) {
    $translated['platform'] = $input['platform'];
}

// App version
if (isset($input['app_version'])) {
    $translated['app_version'] = $input['app_version'];
}

// Timestamp - convert unix timestamp to ISO if needed
if (isset($input['recorded_at'])) {
    $translated['recorded_at'] = $input['recorded_at'];
} elseif (isset($input['client_timestamp'])) {
    $ts = $input['client_timestamp'];
    if ($ts > 1000000000000) {
        $ts = $ts / 1000;
    }
    $translated['recorded_at'] = date('Y-m-d H:i:s', (int)$ts);
} elseif (isset($input['timestamp'])) {
    $translated['recorded_at'] = $input['timestamp'];
}

// Validate (using translated input)
$validation = TrackingValidator::validateLocation($translated);
if (!$validation['valid']) {
    jsonError('validation_failed', implode(', ', $validation['errors']), 400);
}

$location = $validation['cleaned'];

// Initialize services
$settingsRepo = new SettingsRepo($db, $trackingCache);
$sessionsRepo = new SessionsRepo($db, $trackingCache);
$locationRepo = new LocationRepo($db, $trackingCache);
$eventsRepo = new EventsRepo($db);
$alertsRepo = new AlertsRepo($db, $trackingCache);
$geofenceRepo = new GeofenceRepo($db, $trackingCache);

$sessionGate = new SessionGate($sessionsRepo, $settingsRepo);
$motionGate = new MotionGate($settingsRepo, $locationRepo);
$rateLimiter = new RateLimiter($trackingCache, $settingsRepo);
$dedupe = new Dedupe($trackingCache, $settingsRepo);
$alertsEngine = new AlertsEngine($alertsRepo, $eventsRepo);
$geofenceEngine = new GeofenceEngine($geofenceRepo, $eventsRepo, $alertsEngine);

// Get settings
$settings = $settingsRepo->get($familyId);

// Check accuracy - More lenient for mobile apps (allow up to 500m)
$maxAccuracy = max($settings['min_accuracy_m'], 500);
if (isset($location['accuracy_m']) && $location['accuracy_m'] > $maxAccuracy) {
    jsonError('poor_accuracy', "Accuracy {$location['accuracy_m']}m exceeds threshold {$maxAccuracy}m", 422);
}

// Rate limit check
$rateCheck = $rateLimiter->check($userId, $familyId);
if (!$rateCheck['allowed']) {
    http_response_code(429);
    jsonResponse([
        'success' => false,
        'error' => 'rate_limited',
        'message' => $rateCheck['message'],
        'retry_after' => $rateCheck['retry_after']
    ]);
}

// Session gate check (Mode 1 only)
// Mode 2 (motion-based) always allows uploads - the native app handles
// when to track based on movement detection.
// Mode 1 (live session) only accepts uploads during active sessions.
$sessionCheck = $sessionGate->check($familyId);
if (!$sessionCheck['allowed']) {
    // Return 409 so the native app knows to back off, but don't hard-reject
    // The native app's TrackingLocationService handles 409 gracefully
    jsonError('session_off', $sessionCheck['message'] ?? 'No active tracking session', 409);
}

// Dedupe check
$dedupeCheck = $dedupe->check($userId, $familyId, $location);
if ($dedupeCheck['is_duplicate']) {
    jsonSuccess([
        'accepted' => false,
        'reason' => 'duplicate',
        'message' => 'Location too similar to previous'
    ]);
}

// Motion analysis
$motionResult = $motionGate->evaluate($familyId, $userId, $location);
$location['motion_state'] = $motionResult['motion_state'];

// Update current location (always)
$locationRepo->upsertCurrent($userId, $familyId, $location);

// Insert history (if allowed)
$historyId = null;
if ($motionResult['store_history']) {
    $historyId = $locationRepo->insertHistory($userId, $familyId, $location);
}

// Process geofences
$geofenceEvents = $geofenceEngine->process($familyId, $userId, $location['lat'], $location['lng']);

jsonSuccess([
    'accepted' => true,
    'motion_state' => $motionResult['motion_state'],
    'stored_history' => $motionResult['store_history'],
    'history_id' => $historyId,
    'geofence_events' => $geofenceEvents
]);
