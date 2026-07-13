<?php
/**
 * ============================================
 * FCM TOKEN REGISTRATION API
 * For native apps (Android/iOS) to register push notification tokens
 * ============================================
 *
 * POST /api/fcm/register.php
 * Body: { "token": "FCM_TOKEN", "device_type": "android|ios|web", "device_info": {...} }
 *
 * DELETE /api/fcm/register.php
 * Body: { "token": "FCM_TOKEN" }
 * ============================================
 */

require_once __DIR__ . '/../../core/session_boot.php';
header('Content-Type: application/json');

// Handle CORS for native apps. Host-based match: the old
// strpos($origin, 'localhost') check also matched hostile origins like
// https://evil-localhost.example, while a plain exact-match list would drop
// dev servers on non-standard ports (localhost:8100 etc.).
function fcm_register_origin_allowed(string $origin): bool {
    if ($origin === '') {
        return false;
    }
    $parts = parse_url($origin);
    $scheme = $parts['scheme'] ?? '';
    $host = $parts['host'] ?? '';
    return in_array($scheme, ['http', 'https', 'capacitor', 'ionic'], true)
        && in_array($host, ['localhost', '127.0.0.1'], true);
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (fcm_register_origin_allowed($origin)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$token = trim($input['token'] ?? '');

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'FCM token is required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    if ($method === 'DELETE') {
        // Unregister token (kept auth-scoped: an unauthenticated
        // delete-by-token would let anyone who learns a token value sever a
        // device's push delivery). Account switches don't need this — the
        // POST upsert below reassigns the token to the new user on login.
        $stmt = $db->prepare("DELETE FROM fcm_tokens WHERE user_id = ? AND token = ?");
        $stmt->execute([$user['id'], $token]);

        echo json_encode([
            'success' => true,
            'message' => 'Token unregistered'
        ]);

        error_log("FCM token unregistered for user {$user['id']}");

    } elseif ($method === 'POST') {
        // Register or update token
        $deviceType = $input['device_type'] ?? 'unknown';
        $deviceInfo = isset($input['device_info']) ? json_encode($input['device_info']) : null;

        // Validate device type
        $validTypes = ['android', 'ios', 'web'];
        if (!in_array($deviceType, $validTypes, true)) {
            $deviceType = 'unknown';
        }

        // Remove any row already holding this token that is not the exact
        // (user, device_type) row we are about to upsert: a different user's
        // row means the device switched accounts, and a same-user row under
        // another device_type would make the upsert below trip the
        // (user_id, token) unique key.
        $stmt = $db->prepare("DELETE FROM fcm_tokens WHERE token = ? AND (user_id != ? OR device_type != ?)");
        $stmt->execute([$token, $user['id'], $deviceType]);

        // Upsert. The live schema has UNIQUE KEY user_device(user_id,
        // device_type): a plain INSERT collided with the row holding the
        // PREVIOUS token for this device and 500'd forever, so a device could
        // never re-register after its FCM token rotated — the single biggest
        // reason pushes stopped reaching the app. ON DUPLICATE KEY UPDATE
        // turns that collision (and the user_id+token one) into an update of
        // the existing row. id = LAST_INSERT_ID(id) makes lastInsertId()
        // return the row id on the update path too.
        $stmt = $db->prepare("
            INSERT INTO fcm_tokens
            (user_id, token, device_type, device_info, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                token = VALUES(token),
                device_type = VALUES(device_type),
                device_info = VALUES(device_info),
                updated_at = NOW()
        ");
        $stmt->execute([$user['id'], $token, $deviceType, $deviceInfo]);
        $tokenId = (int)$db->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Token registered',
            'token_id' => $tokenId
        ]);

        // Log for debugging
        error_log("FCM token registered for user {$user['id']}: $deviceType");

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log('FCM register error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Registration failed'
    ]);
}
