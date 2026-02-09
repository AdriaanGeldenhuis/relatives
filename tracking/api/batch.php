<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);
$ctx->requireLocationSharing();

$body = json_decode(file_get_contents('php://input'), true);
$locations = $body['locations'] ?? $body ?? [];

if (!is_array($locations) || empty($locations)) {
    Response::error('locations array is required', 422);
}

// Cap batch size to prevent abuse
$maxBatch = 100;
if (count($locations) > $maxBatch) {
    $locations = array_slice($locations, 0, $maxBatch);
}

// Initialize services
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

$validator = new TrackingValidator();
$results = [];
$storedCount = 0;

foreach ($locations as $i => $input) {
    $loc = $validator->validateLocation($input);

    if ($loc === null) {
        $results[] = ['index' => $i, 'status' => 'error', 'error' => implode('; ', $validator->getErrors())];
        // Reset validator errors for next iteration
        $validator = new TrackingValidator();
        continue;
    }

    // Accuracy check
    if ($loc['accuracy_m'] !== null && $loc['accuracy_m'] > $minAccuracy) {
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

    // Process geofences on last item only to avoid excessive event processing
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
    'server_settings' => [
        'mode' => (int) ($settings['mode'] ?? 1),
        'moving_interval' => (int) ($settings['moving_interval_seconds'] ?? 30),
        'idle_interval' => (int) ($settings['idle_interval_seconds'] ?? 300),
        'min_accuracy_m' => (int) ($settings['min_accuracy_m'] ?? 100),
    ],
]);
