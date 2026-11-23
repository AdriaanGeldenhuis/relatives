<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';
require_once __DIR__ . '/../../core/NotificationManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);

// Respect the family-level Off switch: no tracking means no GPS bursts.
$trackingCache = new TrackingCache($cache);
$settingsRepo = new SettingsRepo($db, $trackingCache);
$familySettings = $settingsRepo->get($ctx->familyId);
if ((int) ($familySettings['mode'] ?? 1) === 0) {
    Response::error('tracking_disabled', 409);
}

// Reserve a single refresh per family, including concurrent requests.
if (!$cache->available()) {
    Response::error('refresh_temporarily_unavailable', 503);
}
$wakeKey = 'tracking:wake_rl:family:' . $ctx->familyId;
$lock = $db->prepare('SELECT GET_LOCK(?, 0)');
$lock->execute([$wakeKey]);
if ((int) $lock->fetchColumn() !== 1) {
    Response::error('rate_limited', 429);
}
try {
    $lastWake = $cache->get($wakeKey);
    $reserved = $lastWake === null && $cache->set($wakeKey, time(), 60);
} finally {
    $release = $db->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$wakeKey]);
}
if ($lastWake !== null) {
    Response::json([
        'success' => false,
        'error' => 'rate_limited',
        'retry_after' => max(1, 60 - (time() - (int) $lastWake)),
    ], 429);
}
if (!$reserved) {
    Response::error('refresh_temporarily_unavailable', 503);
}

if (!class_exists('FirebaseMessaging')) {
    error_log('wake_devices: FirebaseMessaging not available');
    Response::error('notification_failed', 500);
}

// The wake is a FUNCTIONAL signal, not a courtesy notification: send it as a
// direct data-only FCM to every family Android device, bypassing per-user
// notification preferences entirely. Routing it through NotificationManager
// meant anyone who switched off "tracking" notifications (a cosmetic
// preference) silently became unwakeable — the single most common reason the
// Wake button "did nothing".
try {
    $stmt = $db->prepare("
        SELECT ft.id AS token_id, ft.token, u.id AS user_id
        FROM users u
        JOIN fcm_tokens ft
            ON ft.user_id = u.id
           AND ft.device_type = 'android'
        WHERE u.family_id = ?
          AND u.status = 'active'
          AND u.location_sharing = 1
          AND u.id != ?
    ");
    $stmt->execute([$ctx->familyId, $ctx->userId]);
    $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('wake_devices target query failed: ' . $e->getMessage());
    Response::error('notification_failed', 500);
}

$fcm = new FirebaseMessaging();
if (!$fcm->isConfigured()) {
    error_log('wake_devices: FCM not configured');
    Response::error('notification_failed', 500);
}

$sent = 0;
$failed = 0;

foreach ($targets as $t) {
    // No keepalive flag: the receiving app treats this as a user-initiated
    // wake and requests one fresh fix.
    // 60s TTL + collapse key: a wake older than the rate-limit window is
    // useless, and an offline phone must never replay a night's worth of
    // queued wake requests when it comes back online.
    $result = $fcm->send($t['token'], [], [
        'type' => 'wake_tracking',
        'initiated_by' => (string) $ctx->userId,
        'recipient_user_id' => (string) $t['user_id'],
    ], [
        'ttl' => '60s',
        'collapse_key' => 'wake_tracking',
    ]);

    if ($result === 'invalid_token') {
        try {
            $db->prepare('DELETE FROM fcm_tokens WHERE id = ?')->execute([$t['token_id']]);
        } catch (Exception $e) {
            error_log('wake_devices: failed to prune token: ' . $e->getMessage());
        }
        $failed++;
    } elseif ($result === true) {
        $sent++;
    } else {
        $failed++;
    }

    // Same FCM pacing as FirebaseMessaging::sendMultiple.
    if (count($targets) > 1) {
        usleep(50000);
    }
}

// Keep the informational in-app notification rows ("X started a tracking
// session" in the notifications page) but WITHOUT their own push — the
// functional wake above already reached every device, and a second
// preference-gated push would only double-trigger the GPS burst.
try {
    $nm = NotificationManager::getInstance($db);
    $nm->createForFamily($ctx->familyId, [
        'type' => 'tracking',
        'priority' => 'high',
        'title' => 'Family Tracking',
        'message' => $ctx->name . ' started a tracking session',
        'action_url' => '/tracking/app/',
        'send_push' => false,
        'data' => [
            'type' => 'wake_tracking',
            'initiated_by' => $ctx->userId,
        ],
    ], $ctx->userId);
} catch (Exception $e) {
    // Informational only — a failure here must not fail the wake.
    error_log('wake_devices notification rows failed: ' . $e->getMessage());
}

Response::success([
    'devices_targeted' => count($targets),
    'sent' => $sent,
    'failed' => $failed,
], $sent > 0 ? 'Wake sent' : 'No wakeable devices');
