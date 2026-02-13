<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}

// Auth: require valid token/user. No session gate — uploads always accepted.
$ctx = SiteContext::require($db);
$ctx->requireLocationSharing();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Initialize services (shared for single + batch)
$trackingCache = new TrackingCache($cache);
$settingsRepo = new SettingsRepo($db, $trackingCache);
$settings = $settingsRepo->get($ctx->familyId);
$locationRepo = new LocationRepo($db, $trackingCache);
$eventsRepo = new EventsRepo($db);
$alertsRepo = new AlertsRepo($db, $trackingCache);
$alertsEngine = new AlertsEngine($db, $trackingCache, $alertsRepo);
$geofenceRepo = new GeofenceRepo($db, $trackingCache);
$placesRepo = new PlacesRepo($db, $trackingCache);
$geofenceEngine = new GeofenceEngine($db, $trackingCache, $geofenceRepo, $placesRepo, $eventsRepo, $alertsEngine);

$minAccuracy = (float) ($settings['min_accuracy_m'] ?? 100);
$dedupe = new Dedupe(
    $trackingCache,
    (int) ($settings['dedupe_radius_m'] ?? 10),
    (int) ($settings['dedupe_time_seconds'] ?? 60)
);
$motionGate = new MotionGate(
    (float) ($settings['speed_threshold_mps'] ?? 1.0),
    (float) ($settings['distance_threshold_m'] ?? 50)
);

$serverSettings = [
    'mode' => (int) ($settings['mode'] ?? 2),
    'moving_interval' => (int) ($settings['moving_interval_seconds'] ?? 30),
    'idle_interval' => (int) ($settings['idle_interval_seconds'] ?? 300),
    'min_accuracy_m' => (int) ($settings['min_accuracy_m'] ?? 100),
];

// -----------------------------------------------------------------------
// Detect batch vs single payload
// -----------------------------------------------------------------------
$isBatch = isset($input['locations']) && is_array($input['locations']);

if ($isBatch) {
    // --- BATCH MODE ---
    $locations = $input['locations'];
    $maxBatch = 100;
    if (count($locations) > $maxBatch) {
        $locations = array_slice($locations, 0, $maxBatch);
    }

    $validator = new TrackingValidator();
    $results = [];
    $storedCount = 0;

    foreach ($locations as $i => $locInput) {
        $loc = $validator->validateLocation($locInput);

        if ($loc === null) {
            $results[] = ['index' => $i, 'status' => 'error', 'error' => implode('; ', $validator->getErrors())];
            $validator = new TrackingValidator();
            continue;
        }

        // Accuracy check
        $platform = $loc['platform'] ?? '';
        $isWebPlatform = in_array($platform, ['web', 'android-webview'], true);
        if (!$isWebPlatform && $loc['accuracy_m'] !== null && $loc['accuracy_m'] > $minAccuracy) {
            $results[] = ['index' => $i, 'status' => 'skipped', 'reason' => 'accuracy_too_low'];
            continue;
        }

        // Dedupe check
        if ($dedupe->isDuplicate($ctx->userId, $loc['lat'], $loc['lng'], $loc['recorded_at'])) {
            $results[] = ['index' => $i, 'status' => 'skipped', 'reason' => 'deduplicated'];
            continue;
        }

        // Determine motion state
        $prev = $locationRepo->getCurrent($ctx->userId);
        $motion = $motionGate->evaluate($loc, $prev);

        // Upsert current location
        $locationRepo->upsertCurrent($ctx->userId, $ctx->familyId, $loc, $motion['motion_state']);

        // Store history if motion gate says to
        if ($motion['store_history']) {
            $locationRepo->insertHistory($ctx->familyId, $ctx->userId, $loc, $motion['motion_state']);
        }

        // Process geofences on last item only
        if ($i === array_key_last($locations)) {
            $geofenceEngine->process($ctx->familyId, $ctx->userId, $loc['lat'], $loc['lng'], $ctx->name);
        }

        $storedCount++;
        $results[] = [
            'index' => $i,
            'status' => 'stored',
            'motion_state' => $motion['motion_state'],
            'stored_history' => $motion['store_history'],
        ];
    }

    Response::success([
        'processed' => count($locations),
        'stored' => $storedCount,
        'results' => $results,
        'server_settings' => $serverSettings,
    ]);
} else {
    // --- SINGLE LOCATION MODE ---
    $validator = new TrackingValidator();
    $loc = $validator->validateLocation($input);

    if ($loc === null) {
        Response::error(implode('; ', $validator->getErrors()), 422);
    }

    // Accuracy check - skip for browser/webview uploads
    $platform = $loc['platform'] ?? '';
    $isWebPlatform = in_array($platform, ['web', 'android-webview'], true);
    if (!$isWebPlatform && $loc['accuracy_m'] !== null) {
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
    if ($dedupe->isDuplicate($ctx->userId, $loc['lat'], $loc['lng'], $loc['recorded_at'])) {
        Response::success(['status' => 'deduplicated'], 'Duplicate skipped');
    }

    // Determine motion state
    $prev = $locationRepo->getCurrent($ctx->userId);
    $motion = $motionGate->evaluate($loc, $prev);

    // Upsert current location
    $locationRepo->upsertCurrent($ctx->userId, $ctx->familyId, $loc, $motion['motion_state']);

    // Store history if motion gate says to
    if ($motion['store_history']) {
        $locationRepo->insertHistory($ctx->familyId, $ctx->userId, $loc, $motion['motion_state']);
    }

    // Process geofences
    $geofenceEngine->process($ctx->familyId, $ctx->userId, $loc['lat'], $loc['lng'], $ctx->name);

    Response::success([
        'status' => 'stored',
        'motion_state' => $motion['motion_state'],
        'stored_history' => $motion['store_history'],
        'server_settings' => $serverSettings,
    ]);
}
