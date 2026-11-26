<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('method_not_allowed', 405);
}
$ctx = SiteContext::require($db);
session_write_close();
header('Cache-Control: private, no-store');

$installation = $_GET['device_id'] ?? null;
if (!TrackingWake::isId($installation)) {
    Response::error('invalid_installation', 422);
}
if (!$ctx->locationSharing || !TrackingPolicy::confirmedWake($db)) {
    Response::success(null);
}
$settings = (new SettingsRepo($db, new TrackingCache($cache)))->get($ctx->familyId);
Response::success((int) $settings['mode'] !== 0 ? (new TrackingWake($db))->pending($ctx, $installation) : null);
