<?php
declare(strict_types=1);

/**
 * ============================================
 * GET CURRENT LOCATIONS v2.0
 * ============================================
 *
 * Flow:
 * 1. Get family users
 * 2. For each user: try cache first
 * 3. If cache miss: fetch from DB
 * 4. Return unified list
 *
 * Rules:
 * - Cache is a performance hint, not authority
 * - Missing cache is NOT an error
 * - DB is always the fallback
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
require_once __DIR__ . '/../../core/Cache.php';

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

    // Get family members (always from DB - lightweight query)
    // Fix #10: Include offline_threshold_seconds and stale_threshold_seconds for proper status calculation
    $stmt = $db->prepare("
        SELECT
            u.id AS user_id,
            u.full_name AS name,
            u.avatar_color,
            u.has_avatar,
            u.location_sharing,
            ts.is_tracking_enabled,
            ts.update_interval_seconds,
            ts.offline_threshold_seconds,
            ts.stale_threshold_seconds
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

    // Initialize cache
    $cache = Cache::init($db);
    $cacheType = $cache->getType();

    // ========== BUILD RESPONSE ==========
    $response = [
        'success' => true,
        'cache_type' => $cacheType,
        'members' => []
    ];

    foreach ($members as $member) {
        $memberId = (int)$member['user_id'];
        $updateInterval = (int)($member['update_interval_seconds'] ?? 30);  // Default 30s

        // Fix #10: Use server settings for thresholds instead of hardcoded values
        // Default values from TrackingSettings.php: offline=720s (12 min), stale=3600s (1 hr)
        $offlineThreshold = (int)($member['offline_threshold_seconds'] ?? 720);
        $staleThreshold = (int)($member['stale_threshold_seconds'] ?? 3600);

        // Online threshold: either from settings or calculate from update interval
        // At minimum, use 2x update interval (but not less than offline threshold)
        $onlineThreshold = min($offlineThreshold, max($updateInterval * 2, 120));

        $loc = null;
        $secondsAgo = null;
        $fromCache = false;

        // ========== TRY CACHE FIRST ==========
        $cached = $cache->getUserLocation($familyId, $memberId);

        if ($cached !== null && isset($cached['lat']) && isset($cached['lng'])) {
            $fromCache = true;
            $createdAt = $cached['ts'] ?? null;

            if ($createdAt) {
                $secondsAgo = time() - strtotime($createdAt);
            }

            $loc = [
                'latitude' => $cached['lat'],
                'longitude' => $cached['lng'],
                'accuracy_m' => $cached['accuracy'] ?? null,
                'speed_kmh' => $cached['speed'] ?? null,
                'heading_deg' => $cached['heading'] ?? null,   // Now reads from cache
                'altitude_m' => $cached['altitude'] ?? null,   // Added altitude
                'battery_level' => $cached['battery'] ?? null,
                'is_moving' => $cached['moving'] ?? false,
                'source' => 'cache',
                'created_at' => $createdAt,
                'device_name' => null,
                'platform' => null
            ];
        }

        // ========== FALLBACK TO DB IF CACHE MISS ==========
        if ($loc === null) {
            $stmt = $db->prepare("
                SELECT
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
                    d.platform,
                    d.location_status,
                    d.permission_status,
                    d.network_status,
                    d.app_state,
                    d.last_seen AS device_last_seen
                FROM tracking_locations l
                LEFT JOIN tracking_devices d ON l.device_id = d.id
                WHERE l.user_id = ? AND l.family_id = ?
                ORDER BY l.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$memberId, $familyId]);
            $dbLoc = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($dbLoc) {
                $loc = $dbLoc;
                $secondsAgo = $dbLoc['seconds_ago'] !== null ? (int)$dbLoc['seconds_ago'] : null;
            }
        }

        // Determine status using configurable thresholds
        // online: within onlineThreshold (2x update interval, min 2 min)
        // stale: between offline threshold and stale threshold
        // offline: beyond stale threshold
        if ($secondsAgo === null || $loc === null || $loc['latitude'] === null) {
            $status = 'no_location';
        } elseif ($secondsAgo < $onlineThreshold) {
            $status = 'online';
        } elseif ($secondsAgo < $offlineThreshold) {
            $status = 'stale';  // Recent but not real-time
        } elseif ($secondsAgo < $staleThreshold) {
            $status = 'stale';  // Last seen within stale threshold
        } else {
            $status = 'offline';  // Beyond stale threshold - truly offline
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
            'from_cache' => $fromCache,
            'diagnostics' => [
                'device_name' => $loc['device_name'] ?? null,
                'platform' => $loc['platform'] ?? null,
                'source' => $loc['source'] ?? null,
                'online_threshold' => $onlineThreshold,
                'stale_threshold' => $staleThreshold,
                // Device state - helps diagnose why location isn't updating
                'location_status' => $loc['location_status'] ?? null,
                'permission_status' => $loc['permission_status'] ?? null,
                'network_status' => $loc['network_status'] ?? null,
                'app_state' => $loc['app_state'] ?? null,
                'device_last_seen' => $loc['device_last_seen'] ?? null
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
