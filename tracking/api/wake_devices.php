<?php
/**
 * POST /tracking/api/wake_devices.php
 *
 * Sends FCM push notifications to ALL family members' devices
 * to wake their tracking into LIVE mode.
 *
 * Called when a family member presses the "Wake Devices" button.
 *
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "devices_notified": 3,
 *     "failed": 0
 *   }
 * }
 */

require_once __DIR__ . '/../core/bootstrap_tracking.php';

header('Content-Type: application/json');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('method_not_allowed', 'POST required', 405);
}

// Auth required
$user = requireAuth();
$userId = $user['id'];
$familyId = $user['family_id'];

// Optional: check subscription
requireActiveSubscription($familyId);

// Also start/extend the session (same as keepalive)
$sessionsRepo = new SessionsRepo($db, $trackingCache);
$settingsRepo = new SettingsRepo($db, $trackingCache);
$eventsRepo = new EventsRepo($db);
$sessionGate = new SessionGate($sessionsRepo, $settingsRepo);

$wasActive = $sessionsRepo->isActive($familyId);
$session = $sessionGate->keepalive($familyId, $userId);

if (!$wasActive) {
    $eventsRepo->logSessionOn($familyId, $userId);
}

// Get all FCM tokens for family members (except the requesting user)
$stmt = $db->prepare("
    SELECT ft.token, ft.device_type, ft.user_id
    FROM fcm_tokens ft
    JOIN users u ON ft.user_id = u.id
    WHERE u.family_id = ?
      AND u.status = 'active'
      AND u.location_sharing = 1
      AND ft.user_id != ?
");
$stmt->execute([$familyId, $userId]);
$tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($tokens)) {
    jsonSuccess([
        'devices_notified' => 0,
        'failed' => 0,
        'message' => 'No other family devices to wake'
    ]);
}

// Send FCM push to all family devices
require_once __DIR__ . '/../../core/FirebaseMessaging.php';
$fcm = new FirebaseMessaging();

$notified = 0;
$failed = 0;
$invalidTokens = [];

foreach ($tokens as $tokenInfo) {
    $result = $fcm->send(
        $tokenInfo['token'],
        [
            'title' => 'Location Update Requested',
            'body' => $user['name'] . ' is requesting your location'
        ],
        [
            'type' => 'wake_tracking',
            'action' => 'wake_tracking',
            'requested_by' => (string)$userId,
            'requested_by_name' => $user['name'],
            'family_id' => (string)$familyId
        ]
    );

    if ($result === true) {
        $notified++;
    } elseif ($result === 'invalid_token') {
        $invalidTokens[] = $tokenInfo['token'];
        $failed++;
    } else {
        $failed++;
    }
}

// Clean up invalid tokens
if (!empty($invalidTokens)) {
    $placeholders = implode(',', array_fill(0, count($invalidTokens), '?'));
    $stmt = $db->prepare("DELETE FROM fcm_tokens WHERE token IN ($placeholders)");
    $stmt->execute($invalidTokens);
    error_log("Cleaned up " . count($invalidTokens) . " invalid FCM tokens");
}

// Log the wake event
$eventsRepo->log($familyId, $userId, 'wake_devices', [
    'notified' => $notified,
    'failed' => $failed
]);

jsonSuccess([
    'devices_notified' => $notified,
    'failed' => $failed,
    'session' => [
        'active' => true,
        'expires_at' => $session['expires_at']
    ]
]);
