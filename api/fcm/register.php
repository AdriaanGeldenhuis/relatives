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

session_name('RELATIVES_SESSION');
session_start();
header('Content-Type: application/json');

// Handle CORS for native apps
$allowedOrigins = [
    'capacitor://localhost',
    'ionic://localhost',
    'http://localhost',
    'https://localhost'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins) || strpos($origin, 'localhost') !== false) {
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

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$token = trim($input['token'] ?? '');

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'FCM token is required']);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        // Register or update token
        $deviceType = $input['device_type'] ?? 'unknown';
        $deviceInfo = isset($input['device_info']) ? json_encode($input['device_info']) : null;

        // Validate device type
        $validTypes = ['android', 'ios', 'web'];
        if (!in_array($deviceType, $validTypes)) {
            $deviceType = 'unknown';
        }

        // Check if token exists for this user
        $stmt = $db->prepare("
            SELECT id FROM fcm_tokens
            WHERE user_id = ? AND token = ?
        ");
        $stmt->execute([$user['id'], $token]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update existing token
            $stmt = $db->prepare("
                UPDATE fcm_tokens
                SET device_type = ?,
                    device_info = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$deviceType, $deviceInfo, $existing['id']]);

            echo json_encode([
                'success' => true,
                'message' => 'Token updated',
                'token_id' => $existing['id']
            ]);
        } else {
            // Check if token exists for another user (device switched users)
            $stmt = $db->prepare("DELETE FROM fcm_tokens WHERE token = ?");
            $stmt->execute([$token]);

            // Insert new token
            $stmt = $db->prepare("
                INSERT INTO fcm_tokens
                (user_id, token, device_type, device_info, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$user['id'], $token, $deviceType, $deviceInfo]);
            $tokenId = $db->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Token registered',
                'token_id' => $tokenId
            ]);
        }

        // Log for debugging
        error_log("FCM token registered for user {$user['id']}: $deviceType");

    } elseif ($method === 'DELETE') {
        // Unregister token (logout)
        $stmt = $db->prepare("
            DELETE FROM fcm_tokens
            WHERE user_id = ? AND token = ?
        ");
        $stmt->execute([$user['id'], $token]);

        echo json_encode([
            'success' => true,
            'message' => 'Token unregistered'
        ]);

        error_log("FCM token unregistered for user {$user['id']}");

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
