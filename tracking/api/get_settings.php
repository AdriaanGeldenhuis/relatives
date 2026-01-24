<?php
declare(strict_types=1);

/**
 * ============================================
 * GET TRACKING SETTINGS
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
require_once __DIR__ . '/../../core/tracking/TrackingSettings.php';

try {
    $userId = (int)$_SESSION['user_id'];
    
    $stmt = $db->prepare("
        SELECT
            update_interval_seconds,
            idle_heartbeat_seconds,
            offline_threshold_seconds,
            stale_threshold_seconds,
            history_retention_days,
            show_speed,
            show_battery,
            show_accuracy,
            is_tracking_enabled,
            high_accuracy_mode,
            background_tracking
        FROM tracking_settings
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        $settings = [
            'update_interval_seconds' => TRACKING_DEFAULT_UPDATE_INTERVAL,
            'idle_heartbeat_seconds' => TRACKING_DEFAULT_IDLE_HEARTBEAT,
            'offline_threshold_seconds' => TRACKING_DEFAULT_OFFLINE_THRESHOLD,
            'stale_threshold_seconds' => TRACKING_DEFAULT_STALE_THRESHOLD,
            'history_retention_days' => 30,
            'show_speed' => 1,
            'show_battery' => 1,
            'show_accuracy' => 1,
            'is_tracking_enabled' => 1,
            'high_accuracy_mode' => 1,
            'background_tracking' => 1
        ];
    } else {
        // Convert to proper types
        $settings['update_interval_seconds'] = (int)$settings['update_interval_seconds'];
        $settings['idle_heartbeat_seconds'] = (int)($settings['idle_heartbeat_seconds'] ?? 300);
        $settings['offline_threshold_seconds'] = (int)($settings['offline_threshold_seconds'] ?? 660);
        $settings['stale_threshold_seconds'] = (int)($settings['stale_threshold_seconds'] ?? 3600);
        $settings['history_retention_days'] = (int)$settings['history_retention_days'];
        $settings['show_speed'] = (bool)$settings['show_speed'];
        $settings['show_battery'] = (bool)$settings['show_battery'];
        $settings['show_accuracy'] = (bool)$settings['show_accuracy'];
        $settings['is_tracking_enabled'] = (bool)$settings['is_tracking_enabled'];
        $settings['high_accuracy_mode'] = (bool)$settings['high_accuracy_mode'];
        $settings['background_tracking'] = (bool)$settings['background_tracking'];
    }
    
    echo json_encode([
        'success' => true,
        'settings' => $settings
    ]);
    
} catch (Exception $e) {
    error_log('Get settings error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'internal_error']);
}