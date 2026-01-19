<?php
declare(strict_types=1);

/**
 * ============================================
 * GET CURRENT LOCATIONS - With Redis Cache
 * ============================================
 *
 * Optimized for speed:
 * 1. Try Redis cache first (if available)
 * 2. Fallback to MySQL if cache miss
 * 3. Return consistent response format
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
require_once __DIR__ . '/../../core/RedisClient.php';

try {
    $userId = (int)$_SESSION['user_id'];

    // Get current user's family
    $stmt = $db->prepare("SELECT family_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'user_not_found']);
        exit;
    }

    $familyId = (int)$user['family_id'];

    // Get family members (always from DB - this is lightweight)
    $stmt = $db->prepare("
        SELECT
            u.id AS user_id,
            u.full_name AS name,
            u.avatar_color,
            u.has_avatar,
            u.location_sharing,
            ts.is_tracking_enabled,
            ts.update_interval_seconds
        FROM users u
        LEFT JOIN tracking_settings ts ON u.id = ts.user_id
        WHERE u.family_id = ?
          AND u.status = 'active'
          AND (u.location_sharing = 1 OR u.id = ?)
        ORDER BY u.full_name ASC
    ");
    $stmt->execute([$familyId, $userId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $memberIds = array_column($members, 'user_id');

    // ========== TRY REDIS CACHE FIRST ==========
    $redis = RedisClient::getInstance();
    $cacheHit = false;
    $cachedLocations = null;

    if ($redis->isAvailable() && !empty($memberIds)) {
        $cachedLocations = $redis->getFamilyLocations($familyId, $memberIds);
        if ($cachedLocations !== null) {
            $cacheHit = true;
        }
    }

    // ========== FALLBACK TO MYSQL IF CACHE MISS ==========
    $locationData = [];

    if (!$cacheHit) {
        // Get latest location for each family member
        $stmt = $db->prepare("
            SELECT
                l.user_id,
                l.device_id,
                l.latitude,
                l.longitude,
                l.accuracy_m,
                l.speed_kmh,
                l.heading_deg,
                l.battery_level,
                l.is_moving,
                l.source,
                l.created_at,
                TIMESTAMPDIFF(SECOND, l.created_at, NOW()) AS seconds_ago,
                d.device_name,
                d.platform
            FROM tracking_locations l
            INNER JOIN (
                SELECT user_id, MAX(created_at) AS max_created
                FROM tracking_locations
                WHERE family_id = ?
                GROUP BY user_id
            ) latest ON l.user_id = latest.user_id AND l.created_at = latest.max_created
            LEFT JOIN tracking_devices d ON l.device_id = d.id
            WHERE l.family_id = ?
        ");
        $stmt->execute([$familyId, $familyId]);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($locations as $loc) {
            $locationData[(int)$loc['user_id']] = $loc;
        }
    }

    // ========== BUILD RESPONSE ==========
    $response = [
        'success' => true,
        'cache_hit' => $cacheHit,
        'members' => []
    ];

    foreach ($members as $member) {
        $memberId = (int)$member['user_id'];
        $updateInterval = (int)($member['update_interval_seconds'] ?? 60);

        // Smart thresholds based on user's update interval
        $onlineThreshold = max($updateInterval * 2, 300); // At least 5 min
        $staleThreshold = 3600; // 60 minutes

        // Get location data from cache or DB
        $loc = null;
        $secondsAgo = null;

        if ($cacheHit && isset($cachedLocations[$memberId])) {
            // From Redis cache
            $cached = $cachedLocations[$memberId];
            $createdAt = $cached['created_at'] ?? null;
            if ($createdAt) {
                $secondsAgo = time() - strtotime($createdAt);
            }
            $loc = [
                'latitude' => $cached['lat'] ?? null,
                'longitude' => $cached['lng'] ?? null,
                'accuracy_m' => $cached['accuracy_m'] ?? null,
                'speed_kmh' => $cached['speed_kmh'] ?? null,
                'heading_deg' => $cached['heading_deg'] ?? null,
                'battery_level' => $cached['battery_level'] ?? null,
                'is_moving' => $cached['is_moving'] ?? 0,
                'source' => $cached['source'] ?? 'native',
                'created_at' => $createdAt,
                'device_name' => null, // Not in cache
                'platform' => null
            ];
        } elseif (isset($locationData[$memberId])) {
            // From MySQL
            $loc = $locationData[$memberId];
            $secondsAgo = $loc['seconds_ago'] !== null ? (int)$loc['seconds_ago'] : null;
        }

        // Determine status
        if ($secondsAgo === null || $loc === null || $loc['latitude'] === null) {
            $status = 'no_location';
        } elseif ($secondsAgo < $onlineThreshold) {
            $status = 'online';
        } elseif ($secondsAgo < $staleThreshold) {
            $status = 'stale';
        } else {
            $status = 'offline';
        }

        $memberData = [
            'user_id' => $memberId,
            'name' => $member['name'],
            'avatar_color' => $member['avatar_color'],
            'has_avatar' => (bool)$member['has_avatar'],
            'avatar_url' => $member['has_avatar']
                ? "/saves/{$memberId}/avatar/avatar.webp?" . time()
                : null,
            'status' => $status,
            'last_seen' => $loc['created_at'] ?? null,
            'seconds_ago' => $secondsAgo,
            'update_interval' => $updateInterval,
            'location' => null,
            'diagnostics' => [
                'device_name' => $loc['device_name'] ?? null,
                'platform' => $loc['platform'] ?? null,
                'source' => $loc['source'] ?? null,
                'online_threshold' => $onlineThreshold,
                'stale_threshold' => $staleThreshold,
                'cache_hit' => $cacheHit
            ]
        ];

        if ($loc && $loc['latitude'] !== null && $loc['longitude'] !== null) {
            $memberData['location'] = [
                'lat' => (float)$loc['latitude'],
                'lng' => (float)$loc['longitude'],
                'accuracy_m' => $loc['accuracy_m'] ? (int)$loc['accuracy_m'] : null,
                'speed_kmh' => $loc['speed_kmh'] ? (float)$loc['speed_kmh'] : null,
                'heading_deg' => $loc['heading_deg'] ? (float)$loc['heading_deg'] : null,
                'battery_level' => $loc['battery_level'] ? (int)$loc['battery_level'] : null,
                'is_moving' => (bool)$loc['is_moving']
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
