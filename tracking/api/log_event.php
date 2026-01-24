<?php
declare(strict_types=1);

/**
 * ============================================
 * LOG TRACKING EVENT
 * ============================================
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

session_start();

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/tracking/TrackingAuth.php';
require_once __DIR__ . '/../../core/tracking/TrackingMiddleware.php';

try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_json']);
        exit;
    }

    // Unified auth: Bearer token, session, or body token
    $authResult = tracking_requireUserId($db, $input);
    $userId = $authResult['user_id'];
    
    // Get user info
    $stmt = $db->prepare("SELECT family_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'user_not_found']);
        exit;
    }
    
    $familyId = (int)$user['family_id'];
    
    // ========== SUBSCRIPTION LOCK CHECK ==========
    tracking_requireActiveSubscription($db, $familyId);
    
    // Validate event type
    $validEventTypes = ['enter_zone', 'exit_zone', 'sos', 'tracking_paused', 'tracking_resumed', 'location_stale', 'speed_alert', 'battery_low'];
    $eventType = $input['event_type'] ?? '';
    
    if (!in_array($eventType, $validEventTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_event_type']);
        exit;
    }
    
    $deviceId = isset($input['device_id']) ? (int)$input['device_id'] : null;
    $zoneId = isset($input['zone_id']) ? (int)$input['zone_id'] : null;
    $latitude = isset($input['latitude']) ? (float)$input['latitude'] : null;
    $longitude = isset($input['longitude']) ? (float)$input['longitude'] : null;
    $payloadJson = isset($input['payload']) ? json_encode($input['payload']) : null;
    
    // Insert event
    $stmt = $db->prepare("
        INSERT INTO tracking_events
        (user_id, family_id, device_id, event_type, zone_id, latitude, longitude, payload_json, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $userId,
        $familyId,
        $deviceId,
        $eventType,
        $zoneId,
        $latitude,
        $longitude,
        $payloadJson
    ]);
    
    $eventId = (int)$db->lastInsertId();

    // Trigger notifications based on event type
    if ($eventType === 'sos' && $latitude !== null && $longitude !== null) {
        require_once __DIR__ . '/../../core/NotificationManager.php';
        require_once __DIR__ . '/../../core/NotificationTriggers.php';

        // Get user's name
        $stmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        $userName = $userData['full_name'] ?? 'A family member';

        $triggers = new NotificationTriggers($db);
        $triggers->onSOSAlert($userId, $familyId, $userName, $latitude, $longitude);
    }

    echo json_encode([
        'success' => true,
        'event_id' => $eventId
    ]);
    
} catch (Exception $e) {
    error_log('Log event error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'internal_error']);
}