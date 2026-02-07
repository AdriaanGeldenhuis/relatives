<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    Response::error('invalid_json', 400);
}

// Validate coordinates
$errors = TrackingValidator::coordinates($input);
if (!empty($errors)) {
    Response::error(implode(', ', $errors), 400);
}

// Sanitize
$loc = TrackingValidator::locationUpdate($input);

// Reject poor accuracy
if ($loc['accuracy'] > 100) {
    Response::error('accuracy_too_low', 400);
}

$trackingCache = new TrackingCache($cache);

// Rate limit (max 4 per minute)
$rateLimiter = new RateLimiter($trackingCache);
if (!$rateLimiter->allow('update', $ctx->userId, 4)) {
    Response::error('rate_limited', 429);
}

// Deduplicate
$dedupe = new Dedupe($trackingCache);
$isDupe = $dedupe->isDuplicate($ctx->userId, $loc['lat'], $loc['lng'], 10.0);

// Always upsert current location (even dupes update timestamp)
$locationRepo = new LocationRepo($db, $trackingCache);
$locationRepo->upsertCurrent($ctx->userId, $ctx->familyId, $loc);

if (!$isDupe) {
    // Store history
    $locationRepo->storeHistory($ctx->userId, $ctx->familyId, $loc);

    // Process geofences
    $geofenceEngine = new GeofenceEngine($db);
    $gfEvents = $geofenceEngine->process($ctx->userId, $ctx->familyId, $loc['lat'], $loc['lng']);

    // Fire geofence notifications
    if (!empty($gfEvents) && class_exists('NotificationTriggers')) {
        $triggers = new NotificationTriggers($db);
        $eventsRepo = new EventsRepo($db);
        foreach ($gfEvents as $gfEvent) {
            $zoneName = $gfEvent['geofence']['name'] ?? 'Unknown zone';
            if ($gfEvent['type'] === 'enter') {
                $triggers->onGeofenceEnter($ctx->userId, $ctx->familyId, $zoneName, $ctx->userName);
                $eventsRepo->create($ctx->familyId, $ctx->userId, 'geofence_enter', "Entered $zoneName", null, $loc['lat'], $loc['lng'], ['geofence_id' => $gfEvent['geofence']['id']]);
            } else {
                $triggers->onGeofenceExit($ctx->userId, $ctx->familyId, $zoneName, $ctx->userName);
                $eventsRepo->create($ctx->familyId, $ctx->userId, 'geofence_exit', "Left $zoneName", null, $loc['lat'], $loc['lng'], ['geofence_id' => $gfEvent['geofence']['id']]);
            }
        }
    }

    // Process alerts
    $alertsEngine = new AlertsEngine($db);
    $alertsEngine->process($ctx->userId, $ctx->familyId, $loc);
}

Response::success(['stored' => !$isDupe, 'ts' => date('Y-m-d H:i:s')]);
