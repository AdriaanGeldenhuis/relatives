<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);
$ctx->requireLocationSharing();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Validate location payload
$validator = new TrackingValidator();
$loc = $validator->validateLocation($input);

if ($loc === null) {
    Response::error(implode('; ', $validator->getErrors()), 422);
}

// Initialize services
$trackingCache = new TrackingCache($cache);
$settingsRepo = new SettingsRepo($db, $trackingCache);
$settings = $settingsRepo->get($ctx->familyId);

// Accuracy check - skip for browser/webview uploads (IP-based geolocation is inaccurate)
$platform = $loc['platform'] ?? '';
$isWebPlatform = in_array($platform, ['web', 'android-webview'], true);
if (!$isWebPlatform && $loc['accuracy_m'] !== null) {
    $minAccuracy = (float) ($settings['min_accuracy_m'] ?? 100);
    if ($loc['accuracy_m'] > $minAccuracy) {
        Response::error('accuracy_too_low: ' . $loc['accuracy_m'] . 'm > ' . $minAccuracy . 'm', 422);
    }
}

// Rate limit check
$rateLimiter = new RateLimiter($trackingCache, (int) ($settings['rate_limit_seconds'] ?? 5));
$rateResult = $rateLimiter->check($ctx->userId);

if (!$rateResult['allowed']) {
    Response::json([
        'success' => false,
        'error' => 'rate_limited',
        'retry_after' => $rateResult['retry_after'],
    ], 429);
}

// Dedupe check
$dedupe = new Dedupe(
    $trackingCache,
    (int) ($settings['dedupe_radius_m'] ?? 10),
    (int) ($settings['dedupe_time_seconds'] ?? 60)
);

if ($dedupe->isDuplicate($ctx->userId, $loc['lat'], $loc['lng'], $loc['recorded_at'])) {
    Response::success(['status' => 'deduplicated'], 'Duplicate skipped');
}

// Determine motion state
$locationRepo = new LocationRepo($db, $trackingCache);
$prev = $locationRepo->getCurrent($ctx->userId);

$motionGate = new MotionGate(
    (float) ($settings['speed_threshold_mps'] ?? 1.0),
    (float) ($settings['distance_threshold_m'] ?? 50)
);
$motion = $motionGate->evaluate($loc, $prev);

// Upsert current location
$locationRepo->upsertCurrent($ctx->userId, $ctx->familyId, $loc, $motion['motion_state']);

// Store history if motion gate says to
if ($motion['store_history']) {
    $locationRepo->insertHistory($ctx->familyId, $ctx->userId, $loc, $motion['motion_state']);
}

// Process geofences
$eventsRepo = new EventsRepo($db);
$alertsRepo = new AlertsRepo($db, $trackingCache);
$alertsEngine = new AlertsEngine($db, $trackingCache, $alertsRepo);
$geofenceRepo = new GeofenceRepo($db, $trackingCache);
$placesRepo = new PlacesRepo($db, $trackingCache);
$geofenceEngine = new GeofenceEngine($db, $trackingCache, $geofenceRepo, $placesRepo, $eventsRepo, $alertsEngine);

$geofenceEngine->process($ctx->familyId, $ctx->userId, $loc['lat'], $loc['lng'], $ctx->name);

// Return success with server settings for client
Response::success([
    'status' => 'stored',
    'motion_state' => $motion['motion_state'],
    'stored_history' => $motion['store_history'],
    'server_settings' => [
        'mode' => (int) ($settings['mode'] ?? 1),
        'moving_interval' => (int) ($settings['moving_interval_seconds'] ?? 30),
        'idle_interval' => (int) ($settings['idle_interval_seconds'] ?? 300),
        'min_accuracy_m' => (int) ($settings['min_accuracy_m'] ?? 100),
    ],
]);
