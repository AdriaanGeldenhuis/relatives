<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/NotificationManager.php';
require_once __DIR__ . '/../../core/CSRF.php';

header('Cache-Control: private, no-store');
$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    Response::error('Sign in to manage notifications.', 401);
}
$userId = (int) $user['id'];
$session = hash('sha256', (string) $_SESSION['session_token']);
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    Response::success([
        'public_key' => PushOutbox::available($db) ? WebPushTransport::publicKey() : null,
        'available' => PushOutbox::available($db),
    ]);
}
if ($method !== 'POST') {
    Response::error('Method not allowed.', 405);
}
if (!CSRF::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    Response::error('Reload notification settings and try again.', 403);
}
session_write_close();
if (!PushOutbox::available($db)) {
    Response::error('Device notification setup is temporarily unavailable.', 503);
}

$data = json_decode(file_get_contents('php://input', false, null, 0, 8192), true);
if (!is_array($data)) {
    Response::error('Invalid notification settings.');
}
$action = $data['action'] ?? '';
$channel = $data['channel'] ?? 'web';
if (!in_array($channel, ['web', 'fcm'], true)) {
    Response::error('Unknown device type.');
}
$endpoint = $data['endpoint'] ?? $data['subscription']['endpoint'] ?? '';
if ($channel === 'web' && (!is_string($endpoint) || !WebPushTransport::validEndpoint($endpoint))) {
    Response::error('This browser notification service is unsupported.');
}
$hash = $channel === 'web' ? hash('sha256', $endpoint) : '';
try {
    if ($action === 'subscribe' && $channel === 'web') {
        if (WebPushTransport::publicKey() === null) {
            Response::error('Browser push is temporarily unavailable.', 503);
        }
        $public = $data['subscription']['keys']['p256dh'] ?? null;
        $auth = $data['subscription']['keys']['auth'] ?? null;
        if (!is_string($public) || !preg_match('/^[A-Za-z0-9_-]{87}$/D', $public)
            || !is_string($auth) || !preg_match('/^[A-Za-z0-9_-]{22}$/D', $auth)) {
            Response::error('The browser returned an invalid subscription.');
        }
        // A subscription that moves to another session receives a new device ID.
        // Pending alerts from its old binding then cannot follow it.
        $db->beginTransaction();
        $db->prepare('DELETE FROM web_push_subscriptions WHERE endpoint_hash = ?
            AND (user_id <> ? OR session_hash <> ?)')->execute([$hash, $userId, $session]);
        $db->prepare('INSERT INTO web_push_subscriptions
            (endpoint_hash, endpoint, public_key, auth_secret, user_id, session_hash)
            VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE
            public_key = VALUES(public_key), auth_secret = VALUES(auth_secret), updated_at = NOW()')
            ->execute([$hash, $endpoint, $public, $auth, $userId, $session]);
        $db->commit();
        Response::success();
    }
    if ($action === 'unsubscribe' && $channel === 'web') {
        $db->prepare('DELETE FROM web_push_subscriptions WHERE endpoint_hash = ? AND user_id = ? AND session_hash = ?')
            ->execute([$hash, $userId, $session]);
        Response::success();
    }
    $stmt = $channel === 'web'
        ? $db->prepare('SELECT id FROM web_push_subscriptions WHERE user_id = ? AND session_hash = ? AND endpoint_hash = ?')
        : $db->prepare("SELECT id FROM fcm_tokens WHERE user_id = ? AND wake_session_hash = ?
            AND device_type = 'android' ORDER BY updated_at DESC, id DESC LIMIT 1");
    $stmt->execute($channel === 'web' ? [$userId, $session, $hash] : [$userId, $session]);
    $id = $stmt->fetchColumn();

    if ($action === 'status') {
        $last = $db->prepare('SELECT state, reason FROM push_deliveries WHERE user_id = ? AND channel = ? AND device_id = ?
            ORDER BY id DESC LIMIT 1');
        $last->execute([$userId, $channel, $id ?: 0]);
        Response::success(['registered' => (bool) $id, 'last' => $last->fetch() ?: null]);
    }
    if ($action !== 'test' || !$id) {
        Response::error('Enable notifications on this device first.', 409);
    }

    $db->beginTransaction();
    $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
    $recent = $db->prepare("SELECT 1 FROM push_deliveries d JOIN notifications n ON n.id = d.notification_id
        WHERE d.user_id = ? AND d.channel = ? AND d.device_id = ? AND d.created_at >= NOW() - INTERVAL 15 SECOND
        AND JSON_EXTRACT(n.data_json, '$.device_test') = 1 LIMIT 1");
    $recent->execute([$userId, $channel, $id]);
    if ($recent->fetchColumn()) {
        $db->rollBack();
        Response::error('Wait a moment before sending another test.', 429);
    }
    NotificationManager::getInstance($db)->create([
        'user_id' => $userId, 'type' => 'system',
        'title' => 'Notification test', 'message' => 'This is a test for this device.',
        'action_url' => '/profile/notifications.php',
        'expires_at' => date('Y-m-d H:i:s', time() + 120),
        'device_target' => ['id' => (int) $id, 'channel' => $channel],
        'data' => ['device_test' => 1],
    ]);
    $db->commit();
    Response::success(null, 'Test queued for this device.');
} catch (Throwable) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Device notification settings could not be saved');
    Response::error('Device notification settings are temporarily unavailable.', 503);
}
