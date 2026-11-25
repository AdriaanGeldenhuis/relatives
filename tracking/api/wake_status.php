<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('method_not_allowed', 405);
}
$ctx = SiteContext::require($db);
session_write_close();
header('Cache-Control: private, no-store');

$id = $_GET['request_id'] ?? null;
if (!TrackingWake::isId($id)) {
    Response::error('invalid_wake_request', 422);
}
if (!TrackingPolicy::hasTable($db, 'tracking_wake_targets')) {
    Response::error('wake_unavailable', 503);
}
$settings = (new SettingsRepo($db, new TrackingCache($cache)))->get($ctx->familyId);
$status = (new TrackingWake($db))->status($ctx, $id, (int) $settings['mode'] !== 0);
if ($status === null) {
    Response::error('wake_request_not_found', 404);
}
Response::success($status);
