<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);
session_write_close();
header('Cache-Control: private, no-store');

$trackingCache = new TrackingCache($cache);
$sessionsRepo = new SessionsRepo($db);
$settingsRepo = new SettingsRepo($db, $trackingCache);
$settings = $settingsRepo->get($ctx->familyId);

$sessionGate = new SessionGate(
    $trackingCache,
    $sessionsRepo,
    (int) ($settings['session_ttl_seconds'] ?? 300)
);

$status = $sessionGate->getStatus($ctx->familyId, $settings);

$status['tracking_policy'] = TrackingPolicy::native($settings, $ctx->locationSharing);
$status['live_session'] = $status['tracking_policy']['allowed'] && FamilyLive::available($db)
    ? (new FamilyLive($db))->active($ctx->familyId) : null;
Response::success($status);
