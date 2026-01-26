<?php
/**
 * POST /tracking/api/location.php
 *
 * Submit a single location update.
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

// Validate
$validation = TrackingValidator::validateLocation($input);
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

// Check accuracy
if (isset($location['accuracy_m']) && $location['accuracy_m'] > $settings['min_accuracy_m']) {
    jsonError('poor_accuracy', "Accuracy {$location['accuracy_m']}m exceeds threshold {$settings['min_accuracy_m']}m", 422);
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

// Session gate check (Mode 1)
$sessionCheck = $sessionGate->check($familyId);
if (!$sessionCheck['allowed']) {
    jsonError('session_off', $sessionCheck['message'], 409);
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
