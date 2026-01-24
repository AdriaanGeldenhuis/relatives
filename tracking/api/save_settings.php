<?php
declare(strict_types=1);

/**
 * ============================================
 * SAVE TRACKING SETTINGS
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

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/tracking/TrackingMiddleware.php';

try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_json']);
        exit;
    }
    
    $userId = (int)$_SESSION['user_id'];
    
    // Get user's family
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
    
    // Prepare values with validation (default 30s - matches TrackingSettings.php)
    $updateInterval = isset($input['update_interval_seconds']) ? (int)$input['update_interval_seconds'] : 30;
    $historyRetention = isset($input['history_retention_days']) ? (int)$input['history_retention_days'] : 30;
    $showSpeed = isset($input['show_speed']) ? (int)(bool)$input['show_speed'] : 1;
    $showBattery = isset($input['show_battery']) ? (int)(bool)$input['show_battery'] : 1;
    $showAccuracy = isset($input['show_accuracy']) ? (int)(bool)$input['show_accuracy'] : 1;
    $isTrackingEnabled = isset($input['is_tracking_enabled']) ? (int)(bool)$input['is_tracking_enabled'] : 1;
    $highAccuracyMode = isset($input['high_accuracy_mode']) ? (int)(bool)$input['high_accuracy_mode'] : 1;
    $backgroundTracking = isset($input['background_tracking']) ? (int)(bool)$input['background_tracking'] : 1;
    
    // Validate ranges (must match TrackingSettings.php constants)
    if ($updateInterval < 10) $updateInterval = 10;  // TRACKING_UPDATE_INTERVAL_MIN
    if ($updateInterval > 300) $updateInterval = 300; // TRACKING_UPDATE_INTERVAL_MAX
    if ($historyRetention < 1) $historyRetention = 1;
    if ($historyRetention > 365) $historyRetention = 365;
    
    // Check if settings exist
    $stmt = $db->prepare("SELECT id FROM tracking_settings WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Update
        $stmt = $db->prepare("
            UPDATE tracking_settings
            SET update_interval_seconds = ?,
                history_retention_days = ?,
                show_speed = ?,
                show_battery = ?,
                show_accuracy = ?,
                is_tracking_enabled = ?,
                high_accuracy_mode = ?,
                background_tracking = ?,
                updated_at = NOW()
            WHERE user_id = ?
        ");

        $stmt->execute([
            $updateInterval,
            $historyRetention,
            $showSpeed,
            $showBattery,
            $showAccuracy,
            $isTrackingEnabled,
            $highAccuracyMode,
            $backgroundTracking,
            $userId
        ]);

    } else {
        // Insert
        $stmt = $db->prepare("
            INSERT INTO tracking_settings
            (user_id, family_id, update_interval_seconds, history_retention_days,
             show_speed, show_battery, show_accuracy, is_tracking_enabled,
             high_accuracy_mode, background_tracking, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $stmt->execute([
            $userId,
            $familyId,
            $updateInterval,
            $historyRetention,
            $showSpeed,
            $showBattery,
            $showAccuracy,
            $isTrackingEnabled,
            $highAccuracyMode,
            $backgroundTracking
        ]);
    }

    // Also sync to users.location_update_interval for page reload consistency
    $stmt = $db->prepare("UPDATE users SET location_update_interval = ? WHERE id = ?");
    $stmt->execute([$updateInterval, $userId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Settings saved successfully'
    ]);
    
} catch (Exception $e) {
    error_log('Save settings error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'internal_error']);
}