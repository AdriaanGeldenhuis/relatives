<?php
declare(strict_types=1);

/**
 * BATCH LOCATION UPLOAD - For efficient offline queue flush
 *
 * Accepts array of locations with client_event_id for idempotency.
 * Processes each location and returns per-item results.
 *
 * Auth: Bearer token (preferred for native apps)
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
require_once __DIR__ . '/../../core/NotificationManager.php';
require_once __DIR__ . '/../../core/NotificationTriggers.php';

try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_json']);
        exit;
    }

    // Authenticate
    $auth = tracking_requireUserId($db, $input);
    $userId = $auth['user_id'];
    $authMethod = $auth['auth_method'];

    error_log("TRACKING_BATCH: auth success user={$userId} method={$authMethod}");

    // Validate required fields
    if (!isset($input['device_uuid'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'missing_field_device_uuid']);
        exit;
    }

    if (!isset($input['locations']) || !is_array($input['locations'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'missing_field_locations']);
        exit;
    }

    $deviceUuid = trim($input['device_uuid']);
    $locations = $input['locations'];
    $platform = $input['platform'] ?? 'android';
    $deviceName = $input['device_name'] ?? null;
    $source = $input['source'] ?? 'native';

    // Limit batch size to prevent abuse
    $maxBatchSize = 50;
    if (count($locations) > $maxBatchSize) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "batch_too_large (max {$maxBatchSize})"]);
        exit;
    }

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

    // Subscription check
    require_once __DIR__ . '/../../core/SubscriptionManager.php';
    $subscriptionManager = new SubscriptionManager($db);

    if ($subscriptionManager->isFamilyLocked($familyId)) {
        http_response_code(402);
        echo json_encode([
            'success' => false,
            'error' => 'subscription_locked',
            'message' => 'Your trial has ended. Please subscribe to continue using this feature.'
        ]);
        exit;
    }

    // Get or create device
    $stmt = $db->prepare("SELECT id FROM tracking_devices WHERE device_uuid = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$deviceUuid, $userId]);
    $device = $stmt->fetch();

    if ($device) {
        $deviceId = (int)$device['id'];
        $stmt = $db->prepare("UPDATE tracking_devices SET last_seen = NOW(), updated_at = NOW() WHERE id = ?");
        $stmt->execute([$deviceId]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO tracking_devices (user_id, device_uuid, platform, device_name, last_seen, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())
        ");
        $stmt->execute([$userId, $deviceUuid, $platform, $deviceName]);
        $deviceId = (int)$db->lastInsertId();
    }

    // Check if idempotency columns exist
    $hasIdempotencyColumns = false;
    try {
        $checkStmt = $db->query("SELECT client_event_id FROM tracking_locations LIMIT 1");
        $hasIdempotencyColumns = true;
    } catch (Exception $e) {
        // Columns don't exist yet - run migration 015
    }

    // Process each location
    $results = [];
    $insertedCount = 0;
    $duplicateCount = 0;
    $triggers = new NotificationTriggers($db);

    foreach ($locations as $loc) {
        $clientEventId = isset($loc['client_event_id']) ? trim($loc['client_event_id']) : null;
        $result = [
            'client_event_id' => $clientEventId,
            'success' => false,
            'already_exists' => false,
            'error' => null
        ];

        // Validate coordinates
        if (!isset($loc['latitude']) || !isset($loc['longitude'])) {
            $result['error'] = 'missing_coordinates';
            $results[] = $result;
            continue;
        }

        $latitude = (float)$loc['latitude'];
        $longitude = (float)$loc['longitude'];

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            $result['error'] = 'invalid_coordinates';
            $results[] = $result;
            continue;
        }

        // Check idempotency
        if ($hasIdempotencyColumns && $clientEventId) {
            $stmt = $db->prepare("SELECT id FROM tracking_locations WHERE client_event_id = ? AND user_id = ? LIMIT 1");
            $stmt->execute([$clientEventId, $userId]);
            $existing = $stmt->fetch();

            if ($existing) {
                $result['success'] = true;
                $result['already_exists'] = true;
                $result['location_id'] = (int)$existing['id'];
                $duplicateCount++;
                $results[] = $result;
                continue;
            }
        }

        // Extract optional fields
        $accuracyM = isset($loc['accuracy_m']) ? (int)$loc['accuracy_m'] : null;
        $speedKmh = isset($loc['speed_kmh']) ? (float)$loc['speed_kmh'] : null;
        $headingDeg = isset($loc['heading_deg']) ? (float)$loc['heading_deg'] : null;
        $altitudeM = isset($loc['altitude_m']) ? (float)$loc['altitude_m'] : null;
        $isMoving = isset($loc['is_moving']) ? (int)(bool)$loc['is_moving'] : 0;
        $batteryLevel = isset($loc['battery_level']) ? (int)$loc['battery_level'] : null;
        $clientTimestamp = isset($loc['client_timestamp']) ? (int)$loc['client_timestamp'] : null;

        if ($batteryLevel !== null && ($batteryLevel < 0 || $batteryLevel > 100)) {
            $batteryLevel = null;
        }

        // Insert location
        try {
            if ($hasIdempotencyColumns) {
                $stmt = $db->prepare("
                    INSERT INTO tracking_locations
                    (device_id, user_id, family_id, latitude, longitude, accuracy_m, speed_kmh,
                     heading_deg, altitude_m, is_moving, battery_level, source, client_event_id, client_timestamp, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $deviceId, $userId, $familyId,
                    $latitude, $longitude, $accuracyM, $speedKmh,
                    $headingDeg, $altitudeM, $isMoving, $batteryLevel,
                    $source, $clientEventId,
                    $clientTimestamp ? date('Y-m-d H:i:s', $clientTimestamp / 1000) : null
                ]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO tracking_locations
                    (device_id, user_id, family_id, latitude, longitude, accuracy_m, speed_kmh,
                     heading_deg, altitude_m, is_moving, battery_level, source, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $deviceId, $userId, $familyId,
                    $latitude, $longitude, $accuracyM, $speedKmh,
                    $headingDeg, $altitudeM, $isMoving, $batteryLevel,
                    $source
                ]);
            }

            $result['success'] = true;
            $result['location_id'] = (int)$db->lastInsertId();
            $insertedCount++;

            // Check for low battery notification (only on last item to avoid spam)
            if ($batteryLevel !== null && $batteryLevel <= 15) {
                // We'll trigger notification only once at the end
            }

        } catch (Exception $e) {
            error_log("TRACKING_BATCH: insert failed for {$clientEventId}: " . $e->getMessage());
            $result['error'] = 'insert_failed';
        }

        $results[] = $result;
    }

    // Update last_fix_at on device
    $stmt = $db->prepare("UPDATE tracking_devices SET last_fix_at = NOW() WHERE id = ?");
    $stmt->execute([$deviceId]);

    error_log("TRACKING_BATCH: processed user={$userId} device={$deviceId} total=" . count($locations) . " inserted={$insertedCount} duplicates={$duplicateCount}");

    // Load server settings for response (uses shared TrackingSettings.php)
    $serverSettings = tracking_loadSettings($db, $userId);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'processed' => count($locations),
        'inserted' => $insertedCount,
        'duplicates' => $duplicateCount,
        'results' => $results,
        'device_id' => $deviceId,
        'server_settings' => $serverSettings
    ]);

} catch (Exception $e) {
    error_log('Batch upload error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'internal_error']);
}
