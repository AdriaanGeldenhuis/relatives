<?php
declare(strict_types=1);

/**
 * ============================================
 * GET CURRENT LOCATIONS v3.0
 * ============================================
 *
 * Rebuilt with proper data source priority:
 * 1. Cache (speed layer - populated only by quality-gated fixes)
 * 2. tracking_current table (source of truth for best-known position)
 * 3. tracking_locations latest (last resort fallback)
 *
 * "Current" now means "best known" not "latest garbage".
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
require_once __DIR__ . '/../../core/tracking/TrackingSettings.php';

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

    // Get family members with settings
    $stmt = $db->prepare("
        SELECT
            u.id AS user_id,
            u.full_name AS name,
            u.avatar_color,
            u.has_avatar,
            u.location_sharing,
            ts.is_tracking_enabled,
            ts.update_interval_seconds,
            ts.idle_heartbeat_seconds,
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
        $updateInterval = (int)($member['update_interval_seconds'] ?? TRACKING_DEFAULT_UPDATE_INTERVAL);
        $idleHeartbeat = (int)($member['idle_heartbeat_seconds'] ?? TRACKING_DEFAULT_IDLE_HEARTBEAT);
        $offlineThreshold = (int)($member['offline_threshold_seconds'] ?? TRACKING_DEFAULT_OFFLINE_THRESHOLD);
        $staleThreshold = (int)($member['stale_threshold_seconds'] ?? TRACKING_DEFAULT_STALE_THRESHOLD);

        // Online (Tracking): within heartbeat + 60s buffer
        // This means: if we got a fix within last 6 minutes, device is actively tracking
        $onlineThreshold = $idleHeartbeat + 60;

        $loc = null;
        $secondsAgo = null;
        $dataSource = 'none';

        // ========== PRIORITY 1: TRY CACHE FIRST (speed layer) ==========
        $cached = $cache->getUserLocation($familyId, $memberId);

        if ($cached !== null && isset($cached['lat']) && isset($cached['lng'])) {
            $dataSource = 'cache';
            $createdAt = $cached['ts'] ?? null;

            if ($createdAt) {
                $secondsAgo = time() - strtotime($createdAt);
            }

            $loc = [
                'latitude' => $cached['lat'],
                'longitude' => $cached['lng'],
                'accuracy_m' => $cached['accuracy'] ?? null,
                'speed_kmh' => $cached['speed'] ?? null,
                'heading_deg' => $cached['heading'] ?? null,
                'altitude_m' => $cached['altitude'] ?? null,
                'battery_level' => $cached['battery'] ?? null,
                'is_moving' => $cached['moving'] ?? false,
                'source' => 'cache',
                'created_at' => $createdAt,
                'device_name' => null,
                'platform' => null
            ];
        }

        // ========== PRIORITY 2: TRACKING_CURRENT (source of truth) ==========
        if ($loc === null) {
            $stmt = $db->prepare("
                SELECT
                    tc.latitude,
                    tc.longitude,
                    tc.accuracy_m,
                    tc.speed_kmh,
                    tc.heading_deg,
                    tc.altitude_m,
                    tc.battery_level,
                    tc.is_moving,
                    tc.fix_source AS source,
                    tc.fix_quality_score,
                    tc.updated_at AS created_at,
                    TIMESTAMPDIFF(SECOND, tc.updated_at, NOW()) AS seconds_ago,
                    d.device_name,
                    d.platform,
                    d.location_status,
                    d.permission_status,
                    d.network_status,
                    d.app_state,
                    d.last_seen AS device_last_seen
                FROM tracking_current tc
                LEFT JOIN tracking_devices d ON tc.device_id = d.id
                WHERE tc.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$memberId]);
            $currentLoc = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($currentLoc) {
                $loc = $currentLoc;
                $secondsAgo = $currentLoc['seconds_ago'] !== null ? (int)$currentLoc['seconds_ago'] : null;
                $dataSource = 'tracking_current';
            }
        }

        // ========== PRIORITY 3: LATEST TRACKING_LOCATIONS (last resort) ==========
        if ($loc === null) {
            $stmt = $db->prepare("
                SELECT
                    l.latitude,
                    l.longitude,
                    l.accuracy_m,
                    l.speed_kmh,
                    l.heading_deg,
                    l.altitude_m,
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
                $dataSource = 'tracking_locations';
            }
        }

        // ========== DETERMINE STATUS ==========
        // Use the freshest signal: either tracking_current.updated_at OR device.last_seen
        // Device last_seen is always updated on every upload, even rejected fixes.
        $deviceLastSeenAgo = null;
        if (isset($loc['device_last_seen']) && $loc['device_last_seen']) {
            $deviceLastSeenAgo = time() - strtotime($loc['device_last_seen']);
        }
        // Use whichever is more recent: location timestamp or device heartbeat
        $effectiveAge = $secondsAgo;
        if ($deviceLastSeenAgo !== null && ($effectiveAge === null || $deviceLastSeenAgo < $effectiveAge)) {
            $effectiveAge = $deviceLastSeenAgo;
        }

        if ($effectiveAge === null || $loc === null || ($loc['latitude'] ?? null) === null) {
            $status = 'no_location';
        } elseif ($effectiveAge < $onlineThreshold) {
            $status = 'online';   // Actively tracking
        } elseif ($effectiveAge < $offlineThreshold) {
            $status = 'idle';     // Between heartbeats, service still alive
        } else {
            $status = 'offline';  // Service dead or phone off
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
            'seconds_ago' => $effectiveAge,
            'update_interval' => $updateInterval,
            'location' => null,
            'data_source' => $dataSource,
            'diagnostics' => [
                'device_name' => $loc['device_name'] ?? null,
                'platform' => $loc['platform'] ?? null,
                'source' => $loc['source'] ?? null,
                'fix_quality_score' => $loc['fix_quality_score'] ?? null,
                'online_threshold' => $onlineThreshold,
                'offline_threshold' => $offlineThreshold,
                'stale_threshold' => $staleThreshold,
                'location_status' => $loc['location_status'] ?? null,
                'permission_status' => $loc['permission_status'] ?? null,
                'network_status' => $loc['network_status'] ?? null,
                'app_state' => $loc['app_state'] ?? null,
                'device_last_seen' => $loc['device_last_seen'] ?? null
            ]
        ];

        if ($loc && ($loc['latitude'] ?? null) !== null && ($loc['longitude'] ?? null) !== null) {
            $locIsMoving = (bool)($loc['is_moving'] ?? false);
            // Server-side speed clamp: if not moving, speed is always 0
            $locSpeed = ($locIsMoving && isset($loc['speed_kmh'])) ? (float)$loc['speed_kmh'] : 0.0;

            $memberData['location'] = [
                'lat' => (float)$loc['latitude'],
                'lng' => (float)$loc['longitude'],
                'accuracy_m' => isset($loc['accuracy_m']) ? (int)$loc['accuracy_m'] : null,
                'speed_kmh' => $locSpeed,
                'heading_deg' => isset($loc['heading_deg']) ? (float)$loc['heading_deg'] : null,
                'altitude_m' => isset($loc['altitude_m']) ? (float)$loc['altitude_m'] : null,
                'battery_level' => isset($loc['battery_level']) ? (int)$loc['battery_level'] : null,
                'is_moving' => $locIsMoving
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
