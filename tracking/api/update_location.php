<?php
declare(strict_types=1);

/**
 * ============================================
 * UPDATE LOCATION - FAST INGEST v3.0
 * ============================================
 *
 * Rebuilt with:
 * - Unified auth via TrackingAuth.php (Bearer + session + body fallback)
 * - Server-side fix quality gating (don't promote bad fixes to current)
 * - tracking_current table as source-of-truth for "best known position"
 * - Cache only updated on good fix promotion
 *
 * Flow:
 * 1. Auth (TrackingAuth.php)
 * 2. Validate input
 * 3. Idempotency check
 * 4. Rate limit check
 * 5. Insert to tracking_locations (always - history)
 * 6. Quality gate: if good fix → promote to tracking_current + cache
 * 7. Queue geofence processing
 * 8. Return server settings
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

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/tracking/TrackingAuth.php';
require_once __DIR__ . '/../../core/tracking/TrackingSettings.php';
require_once __DIR__ . '/../../core/tracking/FixQualityGate.php';
require_once __DIR__ . '/../../core/Cache.php';

try {
    // ========== READ INPUT ==========
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_json']);
        exit;
    }

    // ========== UNIFIED AUTH (TrackingAuth.php) ==========
    $auth = tracking_requireUserId($db, $input);
    $userId = $auth['user_id'];
    $authMethod = $auth['auth_method'];

    header("X-Tracking-Auth: {$authMethod}");

    // ========== VALIDATE REQUIRED FIELDS ==========
    $requiredFields = ['device_uuid', 'latitude', 'longitude'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "missing_field_{$field}"]);
            exit;
        }
    }

    // ========== EXTRACT AND VALIDATE INPUT ==========
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

    // Device state info
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

    // SERVER-SIDE SPEED CLAMP: if not moving, speed is 0 (prevents phantom speed from GPS noise)
    if (!$isMoving) {
        $speedKmh = 0.0;
    }

    // ========== GET USER INFO ==========
    $stmt = $db->prepare("SELECT family_id, full_name FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'user_not_found']);
        exit;
    }

    $familyId = (int)$user['family_id'];

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

    // ========== DEVICE UPSERT ==========
    $stmt = $db->prepare("SELECT id FROM tracking_devices WHERE device_uuid = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$deviceUuid, $userId]);
    $device = $stmt->fetch();

    if ($device) {
        $deviceId = (int)$device['id'];
        $stmt = $db->prepare("
            UPDATE tracking_devices
            SET last_seen = NOW(), updated_at = NOW(),
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

    // ========== IDEMPOTENCY CHECK ==========
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
    $rateLimited = false;
    if (!$isDuplicate) {
        $stmt = $db->prepare("
            SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS seconds_ago
            FROM tracking_locations
            WHERE user_id = ? AND device_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, $deviceId]);
        $lastLocation = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($lastLocation && $lastLocation['seconds_ago'] !== null && $lastLocation['seconds_ago'] < 5) {
            $rateLimited = true;
        }
    }

    // ========== INSERT LOCATION (always - this is history) ==========
    $promoted = false;
    if (!$rateLimited && !$isDuplicate) {
        $stmt = $db->prepare("
            INSERT INTO tracking_locations
            (device_id, user_id, family_id, latitude, longitude, accuracy_m, speed_kmh,
             heading_deg, altitude_m, is_moving, battery_level, source,
             client_event_id, client_timestamp, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $deviceId, $userId, $familyId,
            $latitude, $longitude, $accuracyM, $speedKmh,
            $headingDeg, $altitudeM, $isMoving, $batteryLevel,
            $source, $clientEventId, $clientTimestamp
        ]);
        $locationId = (int)$db->lastInsertId();

        // ========== QUALITY GATE: Should this fix become "current"? ==========
        $qualityGate = new FixQualityGate($db);
        $fixData = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy_m' => $accuracyM,
            'speed_kmh' => $speedKmh,
            'heading_deg' => $headingDeg,
            'altitude_m' => $altitudeM,
            'is_moving' => $isMoving,
            'battery_level' => $batteryLevel,
        ];

        $gateResult = $qualityGate->shouldPromote($fixData, $userId);

        if ($gateResult === 'promote') {
            // FULL PROMOTE: Update position + timestamp in tracking_current
            $qualityGate->promote($fixData, $userId, $deviceId, $familyId);
            $promoted = true;

            // CACHE: Only cache good fixes
            try {
                $cache = Cache::init($db);
                $cacheData = [
                    'lat' => $latitude,
                    'lng' => $longitude,
                    'speed' => $speedKmh,
                    'accuracy' => $accuracyM,
                    'heading' => $headingDeg,
                    'altitude' => $altitudeM,
                    'battery' => $batteryLevel,
                    'moving' => (bool)$isMoving,
                    'ts' => date('Y-m-d H:i:s')
                ];
                $cache->setUserLocation($familyId, $userId, $cacheData);
            } catch (Exception $e) {
                error_log('Cache update error: ' . $e->getMessage());
            }
        } elseif ($gateResult === 'touch') {
            // TOUCH: Refresh timestamp only (keeps status alive without moving marker)
            $qualityGate->touch($fixData, $userId, $deviceId);
            $promoted = false;

            // Update cache timestamp too (so status refreshes from cache)
            try {
                $cache = Cache::init($db);
                $existingCache = $cache->getUserLocation($familyId, $userId);
                if ($existingCache) {
                    $existingCache['battery'] = $batteryLevel ?? ($existingCache['battery'] ?? null);
                    $existingCache['moving'] = (bool)$isMoving;
                    $existingCache['ts'] = date('Y-m-d H:i:s');
                    $cache->setUserLocation($familyId, $userId, $existingCache);
                }
            } catch (Exception $e) {
                error_log('Cache touch error: ' . $e->getMessage());
            }
        }
        // else 'reject': don't touch tracking_current at all (garbage fix)

        // ========== QUEUE FOR ASYNC GEOFENCE PROCESSING ==========
        try {
            $stmt = $db->prepare("
                INSERT INTO tracking_geofence_queue
                (user_id, family_id, device_id, location_id, latitude, longitude, battery_level, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $familyId, $deviceId, $locationId, $latitude, $longitude, $batteryLevel]);
        } catch (Exception $e) {
            error_log('Geofence queue error: ' . $e->getMessage());
        }
    }

    // ========== GET SERVER SETTINGS ==========
    $serverSettings = tracking_loadSettings($db, $userId);

    // ========== CLEANUP OLD LOCATIONS (5% chance) ==========
    if (!$rateLimited && !$isDuplicate && mt_rand(1, 100) <= 5) {
        try {
            $stmt = $db->prepare("
                DELETE FROM tracking_locations
                WHERE user_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute([$userId]);
        } catch (Exception $e) {
            // Non-fatal
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
        'duplicate' => $isDuplicate,
        'promoted' => $promoted
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
