<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);

$fromLat = $_GET['from_lat'] ?? null;
$fromLng = $_GET['from_lng'] ?? null;
$toLat   = $_GET['to_lat'] ?? null;
$toLng   = $_GET['to_lng'] ?? null;

if ($fromLat === null || $fromLng === null || $toLat === null || $toLng === null) {
    Response::error('from_lat, from_lng, to_lat, to_lng are required', 422);
}

$fromLat = (float) $fromLat;
$fromLng = (float) $fromLng;
$toLat   = (float) $toLat;
$toLng   = (float) $toLng;

if ($fromLat < -90 || $fromLat > 90 || $toLat < -90 || $toLat > 90) {
    Response::error('latitude must be between -90 and 90', 422);
}

if ($fromLng < -180 || $fromLng > 180 || $toLng < -180 || $toLng > 180) {
    Response::error('longitude must be between -180 and 180', 422);
}

$profile = $_GET['profile'] ?? 'driving';

$trackingCache = new TrackingCache($cache);
$directions = new MapboxDirections($trackingCache);

$route = $directions->getRoute($fromLat, $fromLng, $toLat, $toLng, $profile);

if ($route === null) {
    Response::error('directions_unavailable', 503);
}

Response::success($route);
