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

$body = json_decode(file_get_contents('php://input'), true);
$clientId = $body['client_request_id'] ?? TrackingWake::newId();
$mode = $body['mode'] ?? 'manual';
if (!TrackingWake::isId($clientId) || !in_array($mode, ['manual', 'automatic'], true)) {
    Response::error('invalid_wake_request', 422);
}
if (!TrackingPolicy::confirmedWake($db)) {
    Response::success(['complete' => true]);
}
$policy = TrackingPolicy::native((new SettingsRepo($db, new TrackingCache($cache)))->get($ctx->familyId), true);
if (!$policy['allowed']) {
    Response::success(['complete' => true]);
}

$wake = new TrackingWake($db);
$devices = $wake->devices($ctx, $mode === 'automatic', ($body['refreshing_local'] ?? null) === true, $policy,
    $mode === 'automatic' && ($body['legacy_only'] ?? null) === true);
$existing = $wake->findRequest($ctx, $clientId);
if (!$devices && $existing === null) {
    Response::success(['complete' => true]);
}

$request = $wake->admit($ctx, $clientId, $devices);
if (isset($request['retry_after'])) {
    header('Retry-After: ' . $request['retry_after']);
    Response::error('rate_limited', 429);
}

ignore_user_abort(true);
$id = $request['request_id'];
$dispatchLock = 'tracking:wake:dispatch:' . $id;
$lock = $db->prepare('SELECT GET_LOCK(?, 1)');
$lock->execute([$dispatchLock]);
if ((int) $lock->fetchColumn() === 1) {
    try {
        $start = Time::nowMs();
        $jobs = [];
        $tokens = [];
        foreach ($wake->pendingDispatch($ctx, $id) as $device) {
            $expires = (int) $device['expires_ms'];
            $remaining = $expires - Time::nowMs();
            if ($remaining <= 0) {
                continue;
            }
            $tokenId = (int) $device['token_id'];
            $tokens[$tokenId] = $device['token'];
            $jobs[$tokenId] = [
                'token' => $device['token'],
                'data' => [
                    'type' => 'wake_tracking', 'request_id' => $id,
                    'recipient_user_id' => (string) $device['user_id'],
                    'installation_id' => $device['installation_id'], 'expires_at_ms' => $expires,
                ],
                'android' => ['ttl' => (int) ceil($remaining / 1000) . 's',
                    'collapse_key' => 'wake_tracking', 'priority' => 'normal'],
            ];
        }
        if ($jobs) {
            $accepted = 0;
            $fcm = new FirebaseMessaging();
            foreach ($fcm->sendBatch($jobs, 2700) as $tokenId => $result) {
                $state = match ($result['state']) {
                    'accepted' => 'sent',
                    'invalid_token' => 'unreachable',
                    default => 'send_failed',
                };
                if ($state === 'unreachable') {
                    $db->prepare('DELETE FROM fcm_tokens WHERE id = ? AND BINARY token = BINARY ?')
                        ->execute([$tokenId, $tokens[$tokenId]]);
                }
                $wake->markSent($id, $tokenId, $state);
                $accepted += (int) ($state === 'sent');
            }
            TrackingPolicy::metric('wake_dispatch', [
                'targets' => count($jobs), 'accepted' => $accepted, 'duration_ms' => Time::nowMs() - $start,
            ]);
        }
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$dispatchLock]);
    }
}

Response::success($wake->status($ctx, $id, true, true));
