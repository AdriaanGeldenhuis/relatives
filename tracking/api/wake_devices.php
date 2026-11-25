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
$existing = $useLedger ? $wake->findRequest($ctx, $clientId) : null;
$devices = $wake->devices($ctx);
$eligible = array_filter($devices, static fn(array $device): bool => TrackingWake::initialState($device) === 'queued');
if (!$eligible && !$existing) {
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
    if ($existing) {
        Response::success($wake->status($ctx, $existing));
    }
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

// A repeated POST can resume a dispatch interrupted before its result was saved.
ignore_user_abort(true);
$dispatchStart = Time::nowMs();
$requestId = $request['request_id'] ?? null;
$dispatchLock = $requestId === null ? null : 'tracking:wake:dispatch:' . $requestId;
if ($dispatchLock !== null) {
    $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([$dispatchLock]);
    if ((int) $lock->fetchColumn() !== 1) {
        Response::success($wake->status($ctx, $requestId));
    }
}

$sent = 0;
$failed = 0;
try {
    if ($requestId !== null) {
        $eligible = $wake->pendingDispatch($ctx, $requestId);
    }
    $jobs = [];
    $targets = [];
    foreach ($eligible as $device) {
        $expires = (int) ($device['expires_ms'] ?? $request['expires_at_ms']);
        if ($expires <= Time::nowMs()) {
            continue;
        }
        $key = (int) $device['token_id'];
        $receipt = $confirmed && $device['installation_id'] !== null;
        $data = ['type' => 'wake_tracking', 'recipient_user_id' => (string) $device['user_id']];
        if ($receipt) {
            $data += [
                'request_id' => $requestId,
                'requester_name' => $device['requester_name'],
                'installation_id' => $device['installation_id'],
                'expires_at_ms' => $expires,
            ];
        }
        $targets[$key] = ['token' => $device['token'], 'expires' => $expires, 'receipt' => $receipt];
        $jobs[$key] = [
            'token' => $device['token'],
            'data' => $data,
            'android' => ['ttl' => (int) ceil(($expires - Time::nowMs()) / 1000) . 's',
                'collapse_key' => 'wake_tracking', 'priority' => 'high'],
        ];
    }

    $results = $fcm->sendBatch($jobs, 2750);
    $retry = [];
    foreach ($results as $key => $result) {
        if ($result['state'] === 'temporary' && $result['retry_after'] <= 1
            && $targets[$key]['receipt'] && $targets[$key]['expires'] > Time::nowMs() + 2000) {
            $retry[$key] = $jobs[$key];
        }
    }
    if ($retry) {
        $earliestExpiry = min(array_map(static fn(int $key): int => $targets[$key]['expires'], array_keys($retry)));
        if ($earliestExpiry > Time::nowMs() + 1200) {
            usleep(1000000);
            foreach ($retry as $key => &$job) {
                $job['android']['ttl'] = (int) ceil(max(0, $targets[$key]['expires'] - Time::nowMs()) / 1000) . 's';
            }
            unset($job);
            $results = $fcm->sendBatch($retry, min(2300, max(1, $earliestExpiry - Time::nowMs()))) + $results;
        }
    }

    foreach ($results as $key => $result) {
        $state = match ($result['state']) {
            'accepted' => $targets[$key]['receipt'] ? 'sent' : 'legacy_sent',
            'invalid_token' => 'unreachable',
            'configuration' => 'unavailable',
            default => 'send_failed',
        };
        if ($state === 'unreachable') {
            $db->prepare('DELETE FROM fcm_tokens WHERE id = ? AND BINARY token = BINARY ?')
                ->execute([$key, $targets[$key]['token']]);
        }
        if ($result['state'] === 'accepted') {
            $sent++;
        } else {
            $failed++;
        }
        if ($requestId !== null) {
            $wake->markSent($requestId, $key, $state);
        }
    }
} finally {
    if ($dispatchLock !== null) {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$dispatchLock]);
    }
}

TrackingPolicy::metric('wake_dispatch', [
    'targets' => count($eligible), 'accepted' => $sent, 'failed' => $failed,
    'confirmed_protocol' => $confirmed, 'duration_ms' => Time::nowMs() - $dispatchStart,
]);
$status = $requestId === null ? [] : $wake->status($ctx, $requestId);

Response::success($status + ['devices_targeted' => count($eligible), 'sent' => $sent, 'failed' => $failed]);
