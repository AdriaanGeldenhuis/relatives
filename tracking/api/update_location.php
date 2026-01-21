<?php
declare(strict_types=1);

/**
 * ============================================
 * UPDATE LOCATION - FAST INGEST v2.0
 * ============================================
 *
 * Optimized for speed:
 * - Validates + auths
 * - Writes to DB + Redis cache
 * - Queues geofence processing (async via cron)
 * - NO geofence loops in request path
 *
 * Auth priority:
 * 1. Existing PHP session
 * 2. Authorization: Bearer <token>
 * 3. RELATIVES_SESSION cookie
 * (body token removed for security - use Bearer header)
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cookie');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

session_start();

$authMethod = 'none';
$bootstrapLoaded = false;

/**
 * Get Authorization header (works across different server configs)
 */
function getAuthorizationHeader(): ?string {
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                return $value;
            }
        }
    }
    return null;
}

/**
 * Validate session token and set session vars
 */
function validateSessionToken(PDO $db, string $token): bool {
    global $authMethod;

    $stmt = $db->prepare("
        SELECT s.user_id, u.family_id
        FROM sessions s
        JOIN users u ON s.user_id = u.id
        WHERE s.session_token = ?
          AND s.expires_at > NOW()
          AND u.status = 'active'
        LIMIT 1
    ");

    $stmt->execute([hash('sha256', $token)]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session) {
        $_SESSION['user_id'] = (int)$session['user_id'];
        $_SESSION['family_id'] = (int)$session['family_id'];
        return true;
    }

    return false;
}

// 1. Check existing PHP session
if (isset($_SESSION['user_id'])) {
    $authMethod = 'session';
}

// 2. Try Bearer token authentication
if (!isset($_SESSION['user_id'])) {
    $authHeader = getAuthorizationHeader();

    if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        $bearerToken = trim($matches[1]);

        try {
            require_once __DIR__ . '/../../core/bootstrap.php';
            $bootstrapLoaded = true;

            if (validateSessionToken($db, $bearerToken)) {
                $authMethod = 'bearer';
            }
        } catch (Exception $e) {
            error_log("TRACKING_UPDATE: bearer auth error - " . $e->getMessage());
        }
    }
}

// 3. Try RELATIVES_SESSION cookie
if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_COOKIE'])) {
        preg_match('/RELATIVES_SESSION=([^;]+)/', $_SERVER['HTTP_COOKIE'], $matches);

        if (isset($matches[1])) {
            try {
                if (!$bootstrapLoaded) {
                    require_once __DIR__ . '/../../core/bootstrap.php';
                    $bootstrapLoaded = true;
                }

                if (validateSessionToken($db, $matches[1])) {
                    $authMethod = 'cookie';
                }
            } catch (Exception $e) {
                error_log("TRACKING_UPDATE: cookie auth error - " . $e->getMessage());
            }
        }
    }
}

// Read input for later use (removed body token auth for security - use Bearer header instead)
$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true);

// Debug headers
header("X-Tracking-Auth: {$authMethod}");

// Final auth check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'unauthorized',
        'hint' => 'Use Authorization: Bearer <token> header'
    ]);
    exit;
}

if (!$bootstrapLoaded) {
    require_once __DIR__ . '/../../core/bootstrap.php';
}

// Load Cache (Memcached with MySQL fallback)
require_once __DIR__ . '/../../core/Cache.php';

try {
    $input = $inputData ?? json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE && !isset($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_json']);
        exit;
    }

    // Validate required fields
    $requiredFields = ['device_uuid', 'latitude', 'longitude'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "missing_field_{$field}"]);
            exit;
        }
    }

    // Extract and validate input
    $deviceUuid = trim($input['device_uuid']);
    $latitude = (float)$input['latitude'];
    $longitude = (float)$input['longitude'];
    $accuracyM = isset($input['accuracy_m']) ? (int)$input['accuracy_m'] : null;
    $speedKmh = isset($input['speed_kmh']) ? (float)$input['speed_kmh'] : null;
    $headingDeg = isset($input['heading_deg']) ? (float)$input['heading_deg'] : null;
    $altitudeM = isset($input['altitude_m']) ? (float)$input['altitude_m'] : null;
    $isMoving = isset($input['is_moving']) ? (int)(bool)$input['is_moving'] : 0;
    $batteryLevel = isset($input['battery_level']) ? (int)$input['battery_level'] : null;
    $source = $input['source'] ?? 'native';
    $clientEventId = $input['client_event_id'] ?? null;
    $clientTimestamp = isset($input['client_timestamp']) ? date('Y-m-d H:i:s', (int)($input['client_timestamp'] / 1000)) : null;

    // Device state info (for tracking_devices table)
    $networkStatus = $input['network_status'] ?? null;
    $locationStatus = $input['location_status'] ?? null;
    $permissionStatus = $input['permission_status'] ?? null;
    $appState = $input['app_state'] ?? null;

    // Validate coordinates
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_coordinates']);
        exit;
    }

    // Validate battery level
    if ($batteryLevel !== null && ($batteryLevel < 0 || $batteryLevel > 100)) {
        $batteryLevel = null;
    }

    $userId = (int)$_SESSION['user_id'];

    // Get user info
    $stmt = $db->prepare("SELECT family_id, full_name FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'user_not_found']);
        exit;
    }

    $familyId = (int)$user['family_id'];
    $userName = $user['full_name'];

    // ========== SUBSCRIPTION LOCK CHECK ==========
    require_once __DIR__ . '/../../core/SubscriptionManager.php';

    $subscriptionManager = new SubscriptionManager($db);

    if ($subscriptionManager->isFamilyLocked($familyId)) {
        http_response_code(402);
        echo json_encode([
            'success' => false,
            'error' => 'subscription_locked',
            'message' => 'Your trial has ended. Please subscribe to continue.'
        ]);
        exit;
    }

    // ========== DEVICE UPSERT WITH STATE ==========
    $stmt = $db->prepare("
        SELECT id FROM tracking_devices
        WHERE device_uuid = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$deviceUuid, $userId]);
    $device = $stmt->fetch();

    if ($device) {
        $deviceId = (int)$device['id'];

        // Update device with state info
        $stmt = $db->prepare("
            UPDATE tracking_devices
            SET last_seen = NOW(),
                updated_at = NOW(),
                network_status = COALESCE(?, network_status),
                location_status = COALESCE(?, location_status),
                permission_status = COALESCE(?, permission_status),
                app_state = COALESCE(?, app_state),
                last_fix_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$networkStatus, $locationStatus, $permissionStatus, $appState, $deviceId]);

    } else {
        $platform = $input['platform'] ?? 'android';
        $deviceName = $input['device_name'] ?? null;

        $stmt = $db->prepare("
            INSERT INTO tracking_devices
            (user_id, device_uuid, platform, device_name, last_seen,
             network_status, location_status, permission_status, app_state, last_fix_at,
             created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, NOW(), NOW(), NOW())
        ");
        $stmt->execute([
            $userId, $deviceUuid, $platform, $deviceName,
            $networkStatus, $locationStatus, $permissionStatus, $appState
        ]);
        $deviceId = (int)$db->lastInsertId();
    }

    // ========== IDEMPOTENCY CHECK (must be FIRST!) ==========
    // Check idempotency before rate limiting to ensure queued locations
    // that get retried are properly recognized as duplicates
    $isDuplicate = false;
    $locationId = null;

    if ($clientEventId) {
        $stmt = $db->prepare("
            SELECT id FROM tracking_locations
            WHERE user_id = ? AND client_event_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $clientEventId]);
        $existing = $stmt->fetch();
        if ($existing) {
            $isDuplicate = true;
            $locationId = (int)$existing['id'];
        }
    }

    // ========== RATE LIMITING (5 second minimum) ==========
    // Only check rate limiting if this is not already a known duplicate
    $rateLimited = false;
    if (!$isDuplicate) {
        $stmt = $db->prepare("
            SELECT id, created_at,
                   TIMESTAMPDIFF(SECOND, created_at, NOW()) AS seconds_ago
            FROM tracking_locations
            WHERE user_id = ? AND device_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, $deviceId]);
        $lastLocation = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($lastLocation && $lastLocation['seconds_ago'] !== null && $lastLocation['seconds_ago'] < 5) {
            $rateLimited = true;
            // Note: we don't set locationId here anymore - let the response indicate rate_limited
        }
    }

    // ========== INSERT LOCATION ==========
    if (!$rateLimited && !$isDuplicate) {
        $stmt = $db->prepare("
            INSERT INTO tracking_locations
            (device_id, user_id, family_id, latitude, longitude, accuracy_m, speed_kmh,
             heading_deg, altitude_m, is_moving, battery_level, source,
             client_event_id, client_timestamp, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $deviceId,
            $userId,
            $familyId,
            $latitude,
            $longitude,
            $accuracyM,
            $speedKmh,
            $headingDeg,
            $altitudeM,
            $isMoving,
            $batteryLevel,
            $source,
            $clientEventId,
            $clientTimestamp
        ]);

        $locationId = (int)$db->lastInsertId();

        // ========== CACHE UPDATE (Memcached or MySQL) ==========
        try {
            $cache = Cache::init($db);
            $cacheData = [
                'lat' => $latitude,
                'lng' => $longitude,
                'speed' => $speedKmh,
                'accuracy' => $accuracyM,
                'heading' => $headingDeg,   // Added: was missing from cache
                'altitude' => $altitudeM,   // Added: useful for completeness
                'battery' => $batteryLevel,
                'moving' => (bool)$isMoving,
                'ts' => date('Y-m-d H:i:s')
            ];
            $cache->setUserLocation($familyId, $userId, $cacheData);
        } catch (Exception $e) {
            // Cache errors are non-fatal
            error_log('Cache update error: ' . $e->getMessage());
        }

        // ========== QUEUE FOR ASYNC GEOFENCE PROCESSING ==========
        try {
            $stmt = $db->prepare("
                INSERT INTO tracking_geofence_queue
                (user_id, family_id, device_id, location_id, latitude, longitude, battery_level, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $familyId, $deviceId, $locationId, $latitude, $longitude, $batteryLevel]);
        } catch (Exception $e) {
            // Queue errors are non-fatal - geofences just won't be checked
            error_log('Geofence queue error: ' . $e->getMessage());
        }
    }

    // ========== GET SERVER SETTINGS TO RETURN ==========
    $serverSettings = null;
    try {
        // Get tracking settings for this user
        $stmt = $db->prepare("
            SELECT update_interval_seconds, idle_heartbeat_seconds,
                   offline_threshold_seconds, stale_threshold_seconds
            FROM tracking_settings
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($settings) {
            $serverSettings = [
                'update_interval_seconds' => (int)$settings['update_interval_seconds'],
                'idle_heartbeat_seconds' => (int)($settings['idle_heartbeat_seconds'] ?? 600),
                'offline_threshold_seconds' => (int)($settings['offline_threshold_seconds'] ?? 720),
                'stale_threshold_seconds' => (int)($settings['stale_threshold_seconds'] ?? 3600)
            ];
        }
    } catch (Exception $e) {
        // Settings fetch error is non-fatal
    }

    // ========== CLEANUP OLD LOCATIONS (async-safe, runs rarely) ==========
    if (!$rateLimited && !$isDuplicate && mt_rand(1, 100) <= 5) { // 5% chance
        try {
            $stmt = $db->prepare("
                DELETE FROM tracking_locations
                WHERE user_id = ?
                  AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute([$userId]);
        } catch (Exception $e) {
            // Cleanup errors are non-fatal
        }
    }

    // ========== SUCCESS RESPONSE ==========
    $response = [
        'success' => true,
        'location_id' => $locationId,
        'device_id' => $deviceId,
        'timestamp' => date('Y-m-d H:i:s'),
        'auth_method' => $authMethod,
        'rate_limited' => $rateLimited,
        'duplicate' => $isDuplicate
    ];

    if ($serverSettings) {
        $response['server_settings'] = $serverSettings;
    }

    http_response_code(200);
    echo json_encode($response);

} catch (Exception $e) {
    error_log('Location update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'internal_error']);
}
