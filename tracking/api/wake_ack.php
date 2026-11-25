<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}
$ctx = SiteContext::require($db);
session_write_close();
header('Cache-Control: private, no-store');

$body = json_decode(file_get_contents('php://input'), true);
if (!TrackingWake::isId($body['request_id'] ?? null)
    || !TrackingWake::isId($body['device_id'] ?? null)
    || !in_array($body['state'] ?? null, TrackingWake::DEVICE_STATES, true)) {
    Response::error('invalid_wake_receipt', 422);
}
if (!TrackingPolicy::confirmedWake($db)) {
    Response::error('wake_unavailable', 503);
}
$settings = new SettingsRepo($db, new TrackingCache($cache));
$db->beginTransaction();
try {
    $family = $settings->lockForTracking($ctx->familyId);
    $access = $ctx->lockForLocationWrite($db);
    if ($access === 'not_authenticated') {
        $db->rollBack();
        Response::error('not_authenticated', 401);
    }
    $state = $access === 'allowed' && (int) $family['mode'] !== 0 ? $body['state'] : 'paused';
    $result = (new TrackingWake($db))->acknowledge($ctx, $body['request_id'], $body['device_id'], $state);
    $db->commit();
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
if ($result === null) {
    Response::error('wake_expired_or_unavailable', 409);
}
Response::success($result);
