<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap_tracking.php';
require_once __DIR__ . '/../../core/NotificationManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}

$ctx = SiteContext::require($db);

// One wake per user per 60s: each wake fans a high-priority FCM out to every
// family device and triggers a GPS burst on each — unthrottled, one user
// could hold the whole family's phones in high-accuracy mode.
$wakeKey = 'tracking:wake_rl:' . $ctx->userId;
if ($cache->available()) {
    if ($cache->get($wakeKey)) {
        Response::error('rate_limited', 429);
    }
    $cache->set($wakeKey, time(), 60);
}

try {
    $nm = NotificationManager::getInstance($db);
    $nm->createForFamily($ctx->familyId, [
        'type' => 'tracking',
        'priority' => 'high',
        'title' => 'Family Tracking',
        'message' => $ctx->name . ' started a tracking session',
        'action_url' => '/tracking/app/',
        'data' => [
            'type' => 'wake_tracking',
            'initiated_by' => $ctx->userId,
        ],
    ], $ctx->userId);

    Response::success(null, 'Wake notification sent');
} catch (Exception $e) {
    error_log('wake_devices error: ' . $e->getMessage());
    Response::error('notification_failed', 500);
}
