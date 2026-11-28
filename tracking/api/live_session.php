<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}
$ctx = SiteContext::require($db);
header('Cache-Control: private, no-store');
if (!FamilyLive::available($db)) {
    Response::error('live_session_unavailable', 503);
}

$live = new FamilyLive($db);
if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
    Response::error('json_required', 415);
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? null;
if ($action === 'open_required') {
    session_write_close();
    $ctx->requireLocationSharing();
    $device = $input['device_id'] ?? null;
    $session = $input['session_id'] ?? null;
    if (!TrackingWake::isId($device) || !TrackingWake::isId($session)) {
        Response::error('invalid_live_session', 422);
    }
    $live->requireOpen($ctx, $session, $device);
    Response::success();
}
if (!CSRF::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    Response::error('invalid_csrf', 403);
}
session_write_close();
$viewer = $input['viewer_id'] ?? null;
if (!in_array($action, ['view', 'leave'], true) || !TrackingWake::isId($viewer)) {
    Response::error('invalid_live_viewer', 422);
}
if ($action === 'view') {
    (new SettingsRepo($db, new TrackingCache($cache)))->get($ctx->familyId);
}
ignore_user_abort(true);
$result = $live->view($ctx, $viewer, $action === 'view');
$lease = $result['session'];
if ($lease !== null && $result['targets']) {
    $jobs = [];
    foreach ($result['targets'] as $target) {
        $jobs[] = [
            'token' => $target['token'],
            'data' => [
                'type' => 'family_live_start',
                'session_id' => $lease['id'],
                'recipient_user_id' => (string) $target['user_id'],
                'installation_id' => $target['installation_id'],
                'expires_at_ms' => (string) ($lease['server_time_ms'] + $lease['remaining_ms']),
            ],
            'android' => ['ttl' => (int) ceil($lease['remaining_ms'] / 1000) . 's',
                'collapse_key' => 'family_live_start', 'priority' => 'high'],
        ];
    }
    try {
        $dispatch = (new FirebaseMessaging())->sendBatch($jobs, 2700);
        TrackingPolicy::metric('family_live_start', ['targets' => count($jobs),
            'accepted' => count(array_filter($dispatch, static fn(array $result): bool => $result['state'] === 'accepted'))]);
    } catch (Throwable $error) {
        error_log('Tracking: family live start dispatch failed');
    }
}
Response::success(['session' => $lease]);
