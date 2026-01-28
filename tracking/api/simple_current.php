<?php
/**
 * GET /tracking/api/simple_current.php
 *
 * Simplified current locations - direct from database.
 * No caching - always fresh data from DB.
 */

session_start();

// Check auth
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'not_authenticated']);
    exit;
}

// Get user info
require_once __DIR__ . '/../../core/bootstrap.php';

$userId = (int)$_SESSION['user_id'];

// Get user's family_id
$stmt = $db->prepare("SELECT family_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'user_not_found']);
    exit;
}

$familyId = (int)$user['family_id'];

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
            u.full_name as name,
            u.avatar_color,
            u.has_avatar
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
            $timeAgo = floor($secondsSince / 60) . ' min ago';
        } elseif ($secondsSince >= 3600 && $secondsSince < 86400) {
            $timeAgo = floor($secondsSince / 3600) . ' hr ago';
        } elseif ($secondsSince >= 86400) {
            $timeAgo = floor($secondsSince / 86400) . ' days ago';
        }

        // Avatar path
        $avatarUrl = '/saves/' . $row['user_id'] . '/avatar/avatar.webp';

        $members[] = [
            'user_id' => (int)$row['user_id'],
            'name' => $row['name'],
            'avatar_color' => $row['avatar_color'],
            'has_avatar' => (bool)$row['has_avatar'],
            'avatar_url' => $avatarUrl,
            'lat' => (float)$row['lat'],
            'lng' => (float)$row['lng'],
            'accuracy_m' => $row['accuracy_m'] ? (float)$row['accuracy_m'] : null,
            'speed_mps' => $row['speed_mps'] ? (float)$row['speed_mps'] : null,
            'bearing_deg' => $row['bearing_deg'] ? (float)$row['bearing_deg'] : null,
            'altitude_m' => $row['altitude_m'] ? (float)$row['altitude_m'] : null,
            'motion_state' => $row['motion_state'],
            'recorded_at' => $row['recorded_at'],
            'updated_at' => $row['updated_at'],
            'status' => $status,
            'last_seen_ago' => $timeAgo
        ];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => [
            'members' => $members,
            'session' => [
                'active' => true,
                'expires_in_seconds' => 300
            ],
            'settings' => [
                'mode' => 2,
                'units' => 'metric'
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (PDOException $e) {
    error_log('Current locations error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'database_error',
        'message' => 'Failed to fetch locations'
    ]);
}
