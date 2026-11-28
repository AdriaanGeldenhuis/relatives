<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}
$ctx = SiteContext::require($db);
$ctx->requireLocationSharing();
session_write_close();

$body = json_decode(file_get_contents('php://input'), true);
$points = $body['locations'] ?? $body;
$live = $body['live_pulse'] ?? null;
if ($live !== null && (!is_array($live) || !TrackingWake::isId($live['session_id'] ?? null)
        || !TrackingWake::isId($live['device_id'] ?? null))) {
    Response::error('invalid_live_pulse', 422);
}
if ($live !== null && strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
    Response::error('json_required', 415);
}
if (!is_array($points) || ($points === [] && $live === null)) {
    Response::error('locations array is required', 422);
}
$trackingCache = new TrackingCache($cache);
LocationIngest::respond((new LocationIngest($db, $trackingCache))->process(
    $ctx, array_values(array_slice($points, 0, 100)), $live
), false);
