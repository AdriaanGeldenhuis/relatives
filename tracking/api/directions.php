<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/bootstrap_tracking.php';

$ctx = SiteContext::require($db);

$fromLat = (float)($_GET['from_lat'] ?? 0);
$fromLng = (float)($_GET['from_lng'] ?? 0);
$toLat = (float)($_GET['to_lat'] ?? 0);
$toLng = (float)($_GET['to_lng'] ?? 0);
$profile = $_GET['profile'] ?? 'driving';

if (!$fromLat || !$fromLng || !$toLat || !$toLng) {
    Response::error('from_lat, from_lng, to_lat, to_lng required', 400);
}

$directions = new MapboxDirections();
$route = $directions->getRoute($fromLat, $fromLng, $toLat, $toLng, $profile);

if (!$route) {
    Response::error('directions_unavailable', 503);
}

Response::success($route);
