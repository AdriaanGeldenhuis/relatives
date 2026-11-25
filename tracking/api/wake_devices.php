<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}
$ctx = SiteContext::require($db);
if (!CSRF::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    Response::error('invalid_csrf', 403);
}
session_write_close();
header('Cache-Control: private, no-store');
$policy = TrackingPolicy::native((new SettingsRepo($db, new TrackingCache($cache)))->get($ctx->familyId), true);
if (!$policy['allowed']) {
    Response::error('tracking_disabled', 409);
}
$body = json_decode(file_get_contents('php://input'), true);
$clientId = $body['client_request_id'] ?? TrackingWake::newId();
if (!TrackingWake::isId($clientId)) {
    Response::error('invalid_wake_request', 422);
}

$wake = new TrackingWake($db);
$useLedger = TrackingPolicy::hasTable($db, 'tracking_wake_targets');
if ($useLedger && ($existing = $wake->findRequest($ctx, $clientId)) !== null) {
    Response::success($wake->status($ctx, $existing));
}
$devices = $wake->devices($ctx);
$eligible = array_filter($devices, static fn(array $device): bool => TrackingWake::initialState($device) === 'queued');
if (!$eligible) {
    Response::success([
        'devices_targeted' => 0, 'sent' => 0, 'failed' => 0,
        'targets' => array_map(static fn(array $device): array => [
            'user_id' => (int) $device['user_id'], 'name' => $device['full_name'],
            'state' => TrackingWake::initialState($device),
        ], $devices),
    ]);
}

$confirmed = TrackingPolicy::confirmedWake($db);
$fcm = new FirebaseMessaging();
if (!$fcm->prepareSend()) {
    Response::json([
        'success' => false,
        'error' => 'wake_service_unavailable',
        'reason' => $fcm->lastFailure(),
    ], 503);
}

if ($useLedger) {
    $request = $wake->admit($ctx, $clientId, $devices);
    if (isset($request['retry_after'])) {
        header('Retry-After: ' . $request['retry_after']);
        Response::json(['success' => false, 'error' => 'rate_limited', 'retry_after' => $request['retry_after']], 429);
    }
    if (!$request['dispatch']) {
        Response::success($wake->status($ctx, $request['request_id']));
    }
} else {
    // Supports rolling deployment before applying 003.
    $key = 'tracking:wake_rl:family:' . $ctx->familyId;
    if (!$cache->available()) {
        Response::error('wake_setup_required', 503);
    }
    $lock = $db->prepare('SELECT GET_LOCK(?, 1)');
    $lock->execute([$key]);
    if ((int) $lock->fetchColumn() !== 1) {
        Response::json(['success' => false, 'error' => 'rate_limited', 'retry_after' => 2], 429);
    }
    try {
        $lastWake = $cache->get($key);
        $reserved = $lastWake === null && $cache->set($key, time(), 60);
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);
    }
    if ($lastWake !== null) {
        $retry = max(1, 60 - (time() - (int) $lastWake));
        header('Retry-After: ' . $retry);
        Response::json(['success' => false, 'error' => 'rate_limited', 'retry_after' => $retry], 429);
    }
    if (!$reserved) {
        Response::error('wake_service_unavailable', 503);
    }
    $request = ['expires_at_ms' => Time::nowMs() + TrackingWake::WINDOW_MS];
}

// Finish the bounded dispatch even if the caller navigates away after admission.
ignore_user_abort(true);
$dispatchStart = Time::nowMs();
$dispatchDeadline = min($dispatchStart + 10000, $request['expires_at_ms']);
$sent = 0;
$failed = 0;
foreach ($eligible as $device) {
    $remaining = (int) ($dispatchDeadline - Time::nowMs());
    $supportsReceipt = $confirmed && $device['installation_id'] !== null;
    $data = ['type' => 'wake_tracking', 'recipient_user_id' => (string) $device['user_id']];
    if ($supportsReceipt) {
        $data += [
            'request_id' => $request['request_id'],
            'installation_id' => $device['installation_id'],
            'expires_at_ms' => $request['expires_at_ms'],
        ];
    }
    $result = $remaining > 0 ? $fcm->send($device['token'], [], $data, [
        'ttl' => max(0, (int) floor(($request['expires_at_ms'] - Time::nowMs()) / 1000)) . 's',
        'collapse_key' => 'wake_tracking',
        'priority' => 'high',
    ], min($remaining, 3500)) : false;
    $state = 'send_failed';
    if ($result === true) {
        $sent++;
        $state = $supportsReceipt ? 'sent' : 'legacy_sent';
    } else {
        $failed++;
        if ($result === 'invalid_token') {
            $db->prepare('DELETE FROM fcm_tokens WHERE id = ? AND BINARY token = BINARY ?')->execute([$device['token_id'], $device['token']]);
            $state = 'unreachable';
        } elseif ($remaining > 0 && $fcm->lastFailure() === 'configuration') {
            $state = 'unavailable';
        }
    }
    if ($useLedger) {
        $wake->markSent($request['request_id'], (int) $device['token_id'], $state);
    }
}
TrackingPolicy::metric('wake_dispatch', [
    'targets' => count($eligible), 'accepted' => $sent, 'failed' => $failed,
    'confirmed_protocol' => $confirmed, 'duration_ms' => Time::nowMs() - $dispatchStart,
]);
$status = $useLedger ? $wake->status($ctx, $request['request_id']) : [];

Response::success($status + ['devices_targeted' => count($eligible), 'sent' => $sent, 'failed' => $failed]);
