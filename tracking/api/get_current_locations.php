<?php
declare(strict_types=1);

/**
 * ============================================
 * GET CURRENT LOCATIONS - WITH AVATAR SUPPORT
 * ============================================
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json');

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $userId = (int)$_SESSION['user_id'];
    
    $stmt = $db->prepare("SELECT family_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'user_not_found']);
        exit;
    }
    
    $familyId = (int)$user['family_id'];
    
    $stmt = $db->prepare("
        SELECT 
            u.id AS user_id,
            u.full_name AS name,
            u.avatar_color,
            u.has_avatar,
            u.location_sharing,
            ts.is_tracking_enabled,
            l.latitude,
            l.longitude,
            l.accuracy_m,
            l.speed_kmh,
            l.heading_deg,
            l.battery_level,
            l.is_moving,
            l.created_at AS last_seen,
            TIMESTAMPDIFF(SECOND, l.created_at, NOW()) AS seconds_ago
        FROM users u
        LEFT JOIN tracking_settings ts ON u.id = ts.user_id
        LEFT JOIN (
            SELECT 
                user_id,
                latitude,
                longitude,
                accuracy_m,
                speed_kmh,
                heading_deg,
                battery_level,
                is_moving,
                created_at,
                ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY created_at DESC) AS rn
            FROM tracking_locations
            WHERE family_id = ?
        ) l ON u.id = l.user_id AND l.rn = 1
        WHERE u.family_id = ?
          AND u.status = 'active'
          AND (u.location_sharing = 1 OR u.id = ?)
        ORDER BY u.full_name ASC
    ");
    
    $stmt->execute([$familyId, $familyId, $userId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response = [
        'success' => true,
        'members' => []
    ];
    
    foreach ($members as $member) {
        $isOnline = $member['seconds_ago'] !== null && $member['seconds_ago'] < 300;
        
        $memberData = [
            'user_id' => (int)$member['user_id'],
            'name' => $member['name'],
            'avatar_color' => $member['avatar_color'],
            'has_avatar' => (bool)$member['has_avatar'],
            'avatar_url' => $member['has_avatar'] 
                ? "/saves/{$member['user_id']}/avatar/avatar.webp?" . time()
                : null,
            'status' => $isOnline ? 'online' : 'offline',
            'last_seen' => $member['last_seen'],
            'location' => null
        ];
        
        if ($member['latitude'] !== null && $member['longitude'] !== null) {
            $memberData['location'] = [
                'lat' => (float)$member['latitude'],
                'lng' => (float)$member['longitude'],
                'accuracy_m' => $member['accuracy_m'] ? (int)$member['accuracy_m'] : null,
                'speed_kmh' => $member['speed_kmh'] ? (float)$member['speed_kmh'] : null,
                'heading_deg' => $member['heading_deg'] ? (float)$member['heading_deg'] : null,
                'battery_level' => $member['battery_level'] ? (int)$member['battery_level'] : null,
                'is_moving' => (bool)$member['is_moving']
            ];
        }
        
        $response['members'][] = $memberData;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log('Get locations error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'internal_error']);
}