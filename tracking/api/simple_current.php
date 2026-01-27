<?php
/**
 * GET /tracking/api/simple_current.php
 *
 * Simplified current locations - direct from database.
 * Works for both web browser and native shell apps.
 *
 * Supports multiple auth methods:
 * - Session-based (web browser)
 * - Bearer token (native apps)
 * - API key header (native apps)
 */

// Start session for web auth
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../core/bootstrap.php';

header('Content-Type: application/json');

// Allow CORS for native apps
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================
// AUTHENTICATION - Multiple methods supported
// ============================================
$userId = null;

// Method 1: Session-based (web browser)
if (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
}

// Method 2: Bearer token (native apps)
if (!$userId && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        $token = $matches[1];
        $stmt = $db->prepare("SELECT id FROM users WHERE remember_token = ? AND status = 'active'");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $userId = (int)$row['id'];
        }
    }
}

// Method 3: API Key header (native apps)
if (!$userId && isset($_SERVER['HTTP_X_API_KEY'])) {
    $apiKey = $_SERVER['HTTP_X_API_KEY'];
    $stmt = $db->prepare("SELECT id FROM users WHERE api_key = ? AND status = 'active'");
    $stmt->execute([$apiKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $userId = (int)$row['id'];
    }
}

// Method 4: Query param token (legacy)
if (!$userId && isset($_GET['token'])) {
    $token = $_GET['token'];
    $stmt = $db->prepare("SELECT id FROM users WHERE remember_token = ? AND status = 'active'");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $userId = (int)$row['id'];
    }
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'not_authenticated', 'message' => 'Authentication required']);
    exit;
}

// Get user's family_id
$stmt = $db->prepare("SELECT family_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'user_not_found']);
    exit;
}

$familyId = (int)$user['family_id'];

// ============================================
// FETCH FROM DATABASE
// ============================================
try {
    // Get all family members with their current locations
    $stmt = $db->prepare("
        SELECT
            tc.user_id,
            tc.lat,
            tc.lng,
            tc.accuracy_m,
            tc.speed_mps,
            tc.bearing_deg,
            tc.altitude_m,
            tc.motion_state,
            tc.recorded_at,
            tc.updated_at,
            tc.device_id,
            tc.platform,
            u.full_name as name,
            u.avatar_color,
            u.has_avatar,
            u.profile_picture
        FROM tracking_current tc
        JOIN users u ON tc.user_id = u.id
        WHERE tc.family_id = ?
          AND u.status = 'active'
          AND u.location_sharing = 1
        ORDER BY tc.updated_at DESC
    ");
    $stmt->execute([$familyId]);

    $members = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Calculate time since update
        $updatedAt = strtotime($row['updated_at']);
        $now = time();
        $secondsSince = $now - $updatedAt;

        // Determine status
        $status = 'active';
        if ($secondsSince > 3600) {
            $status = 'offline';
        } elseif ($secondsSince > 300) {
            $status = 'stale';
        }

        // Format time ago
        $timeAgo = 'Just now';
        if ($secondsSince >= 60 && $secondsSince < 3600) {
            $mins = floor($secondsSince / 60);
            $timeAgo = $mins . ' min ago';
        } elseif ($secondsSince >= 3600 && $secondsSince < 86400) {
            $hrs = floor($secondsSince / 3600);
            $timeAgo = $hrs . ' hr ago';
        } elseif ($secondsSince >= 86400) {
            $days = floor($secondsSince / 86400);
            $timeAgo = $days . ' days ago';
        }

        // Build avatar URL if has profile picture
        $avatarUrl = null;
        if ($row['has_avatar'] || $row['profile_picture']) {
            $avatarUrl = '/uploads/avatars/' . $row['user_id'] . '.jpg';
        }

        $members[] = [
            'user_id' => (int)$row['user_id'],
            'name' => $row['name'],
            'avatar_color' => $row['avatar_color'],
            'has_avatar' => (bool)$row['has_avatar'],
            'avatar_url' => $avatarUrl,
            'lat' => (float)$row['lat'],
            'lng' => (float)$row['lng'],
            'latitude' => (float)$row['lat'],  // For native app compatibility
            'longitude' => (float)$row['lng'], // For native app compatibility
            'accuracy_m' => $row['accuracy_m'] ? (float)$row['accuracy_m'] : null,
            'accuracy' => $row['accuracy_m'] ? (float)$row['accuracy_m'] : null, // Alias
            'speed_mps' => $row['speed_mps'] ? (float)$row['speed_mps'] : null,
            'speed' => $row['speed_mps'] ? (float)$row['speed_mps'] : null, // Alias
            'speed_kmh' => $row['speed_mps'] ? round((float)$row['speed_mps'] * 3.6, 1) : null, // km/h for display
            'bearing_deg' => $row['bearing_deg'] ? (float)$row['bearing_deg'] : null,
            'heading' => $row['bearing_deg'] ? (float)$row['bearing_deg'] : null, // Alias
            'altitude_m' => $row['altitude_m'] ? (float)$row['altitude_m'] : null,
            'altitude' => $row['altitude_m'] ? (float)$row['altitude_m'] : null, // Alias
            'motion_state' => $row['motion_state'],
            'is_moving' => $row['motion_state'] === 'moving', // Boolean for native apps
            'recorded_at' => $row['recorded_at'],
            'updated_at' => $row['updated_at'],
            'timestamp' => strtotime($row['updated_at']), // Unix timestamp for native apps
            'status' => $status,
            'last_seen_ago' => $timeAgo,
            'seconds_since_update' => $secondsSince,
            'device_id' => $row['device_id'],
            'platform' => $row['platform']
        ];
    }

    // Get family info
    $stmt = $db->prepare("SELECT name FROM families WHERE id = ?");
    $stmt->execute([$familyId]);
    $family = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'members' => $members,
            'family' => [
                'id' => $familyId,
                'name' => $family ? $family['name'] : 'Family'
            ],
            'session' => [
                'active' => true,
                'expires_in_seconds' => 300
            ],
            'settings' => [
                'mode' => 2,
                'units' => 'metric'
            ],
            'count' => count($members),
            'timestamp' => date('Y-m-d H:i:s'),
            'server_time' => time()
        ]
    ]);

} catch (PDOException $e) {
    error_log('Current locations error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'database_error',
        'message' => 'Failed to fetch locations'
    ]);
}
