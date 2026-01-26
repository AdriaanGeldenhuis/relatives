<?php
/**
 * POST /tracking/api/batch.php
 *
 * Submit multiple location updates at once.
 * Useful for uploading buffered locations.
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
    jsonError('location_sharing_disabled', 'Location sharing is disabled', 403);
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['locations'])) {
    jsonError('invalid_json', 'Invalid JSON body or missing locations array', 400);
}

// Validate batch
$validation = TrackingValidator::validateBatch($input['locations']);
if (!$validation['valid']) {
    jsonError('validation_failed', implode('; ', $validation['errors']), 400);
}

$locations = $validation['cleaned'];

// Sort by recorded_at (oldest first)
usort($locations, function($a, $b) {
    return strtotime($a['recorded_at']) <=> strtotime($b['recorded_at']);
});

// Initialize services
$settingsRepo = new SettingsRepo($db, $trackingCache);
$sessionsRepo = new SessionsRepo($db, $trackingCache);
$locationRepo = new LocationRepo($db, $trackingCache);
$eventsRepo = new EventsRepo($db);
$alertsRepo = new AlertsRepo($db, $trackingCache);
$geofenceRepo = new GeofenceRepo($db, $trackingCache);

$sessionGate = new SessionGate($sessionsRepo, $settingsRepo);
$motionGate = new MotionGate($settingsRepo, $locationRepo);
$dedupe = new Dedupe($trackingCache, $settingsRepo);
$alertsEngine = new AlertsEngine($alertsRepo, $eventsRepo);
$geofenceEngine = new GeofenceEngine($geofenceRepo, $eventsRepo, $alertsEngine);

// Get settings
$settings = $settingsRepo->get($familyId);

// Session gate check (Mode 1)
$sessionCheck = $sessionGate->check($familyId);
if (!$sessionCheck['allowed']) {
    jsonError('session_off', $sessionCheck['message'], 409);
}

// Process each location
$results = [];
$accepted = 0;
$rejected = 0;
$lastLocation = null;

foreach ($locations as $location) {
    $result = ['recorded_at' => $location['recorded_at']];

    // Accuracy check
    if (isset($location['accuracy_m']) && $location['accuracy_m'] > $settings['min_accuracy_m']) {
        $result['status'] = 'rejected';
        $result['reason'] = 'poor_accuracy';
        $rejected++;
        $results[] = $result;
        continue;
    }

    // Dedupe check
    $dedupeCheck = $dedupe->check($userId, $familyId, $location);
    if ($dedupeCheck['is_duplicate']) {
        $result['status'] = 'rejected';
        $result['reason'] = 'duplicate';
        $rejected++;
        $results[] = $result;
        continue;
    }

    // Motion analysis
    $motionResult = $motionGate->evaluate($familyId, $userId, $location);
    $location['motion_state'] = $motionResult['motion_state'];

    // Store
    $locationRepo->upsertCurrent($userId, $familyId, $location);

    if ($motionResult['store_history']) {
        $historyId = $locationRepo->insertHistory($userId, $familyId, $location);
        $result['history_id'] = $historyId;
    }

    // Process geofences (only for latest to reduce DB load)
    $lastLocation = $location;

    $result['status'] = 'accepted';
    $result['motion_state'] = $motionResult['motion_state'];
    $result['stored_history'] = $motionResult['store_history'];
    $accepted++;
    $results[] = $result;
}

// Process geofences for final location only
$geofenceEvents = [];
if ($lastLocation) {
    $geofenceEvents = $geofenceEngine->process($familyId, $userId, $lastLocation['lat'], $lastLocation['lng']);
}

jsonSuccess([
    'total' => count($locations),
    'accepted' => $accepted,
    'rejected' => $rejected,
    'results' => $results,
    'geofence_events' => $geofenceEvents
]);
