<?php
/**
 * NOTIFICATION PREFERENCES API
 * Complete CRUD for notification settings
 */

require_once __DIR__ . '/../../core/session_boot.php';
header('Content-Type: application/json');
header('Cache-Control: private, no-store, max-age=0');
header('CDN-Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/CSRF.php';

$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    Response::error('Unauthorized', 401);
}
if (($_GET['action'] ?? $_POST['action'] ?? '') !== 'get'
    && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !CSRF::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    Response::error('Reload notification settings and try again.', 403);
}
session_write_close();

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get':
            // Get all preferences
            $stmt = $db->prepare("
                SELECT * FROM notification_preferences 
                WHERE user_id = ?
                ORDER BY category
            ");
            $stmt->execute([$userId]);
            $prefs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get weather schedule
            $stmt = $db->prepare("
                SELECT * FROM weather_notification_schedule 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $weatherSchedule = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Create default preferences if none exist
            if (empty($prefs)) {
                $categories = ['message', 'shopping', 'calendar', 'schedule', 'tracking', 'weather', 'note', 'system'];
                foreach ($categories as $cat) {
                    $stmt = $db->prepare("
                        INSERT IGNORE INTO notification_preferences 
                        (user_id, category, enabled, push_enabled, sound_enabled, vibrate_enabled)
                        VALUES (?, ?, 1, 1, 1, 1)
                    ");
                    $stmt->execute([$userId, $cat]);
                }
                
                // Re-fetch
                $stmt = $db->prepare("SELECT * FROM notification_preferences WHERE user_id = ?");
                $stmt->execute([$userId]);
                $prefs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            echo json_encode([
                'success' => true,
                'preferences' => $prefs,
                'weather_schedule' => $weatherSchedule
            ]);
            break;
            
        case 'update':
            $category = $_POST['category'] ?? '';
            $settings = json_decode((string) ($_POST['settings'] ?? ''), true);
            if (!in_array($category, ['message', 'shopping', 'calendar', 'schedule', 'tracking', 'weather', 'note', 'system'], true)
                || !is_array($settings)) {
                Response::error('Invalid notification preferences.');
            }
            $values = [];
            foreach (['enabled', 'push_enabled', 'sound_enabled', 'vibrate_enabled', 'quiet_hours_enabled'] as $flag) {
                if (array_key_exists($flag, $settings)) {
                    $values[$flag] = (int) !empty($settings[$flag]);
                }
            }
            foreach (['quiet_hours_start', 'quiet_hours_end'] as $clock) {
                if (isset($settings[$clock])) {
                    if (!is_string($settings[$clock]) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $settings[$clock])) {
                        Response::error('Enter a valid quiet-hours time.');
                    }
                    $values[$clock] = $settings[$clock] . ':00';
                }
            }
            if (!$values) {
                Response::error('Choose a preference to update.');
            }
            $fields = array_keys($values);
            $columns = implode(', ', $fields);
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $updates = implode(', ', array_map(static fn(string $key): string => "$key = VALUES($key)", $fields));
            $db->prepare("INSERT INTO notification_preferences (user_id, category, $columns)
                VALUES (?, ?, $placeholders) ON DUPLICATE KEY UPDATE $updates")
                ->execute([$userId, $category, ...array_values($values)]);
            Response::success();
            break;

        case 'update_weather_schedule':
            $enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : 1;
            $time = $_POST['time'] ?? '07:00:00';
            $voiceEnabled = isset($_POST['voice_enabled']) ? (int)$_POST['voice_enabled'] : 1;
            $includeForecast = isset($_POST['include_forecast']) ? (int)$_POST['include_forecast'] : 1;

            // Validate time format (accept both HH:MM and HH:MM:SS)
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
                throw new Exception('Invalid time format');
            }
            // Ensure time has seconds
            if (strlen($time) === 5) {
                $time .= ':00';
            }

            // Check if exists and get formatted time for comparison
            $stmt = $db->prepare("SELECT id, TIME_FORMAT(notification_time, '%H:%i:%s') as formatted_time FROM weather_notification_schedule WHERE user_id = ?");
            $stmt->execute([$userId]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                // Check if time changed - always reset last_sent on time change
                $timeChanged = ($exists['formatted_time'] !== $time);

                // Basic update without last_sent
                $stmt = $db->prepare("
                    UPDATE weather_notification_schedule
                    SET enabled = ?,
                        notification_time = ?,
                        voice_enabled = ?,
                        include_forecast = ?,
                        updated_at = NOW()
                    WHERE user_id = ?
                ");
                $stmt->execute([$enabled, $time, $voiceEnabled, $includeForecast, $userId]);

                // Reset last_sent separately if time changed (handles missing column gracefully)
                if ($timeChanged) {
                    try {
                        $stmt = $db->prepare("UPDATE weather_notification_schedule SET last_sent = NULL WHERE user_id = ?");
                        $stmt->execute([$userId]);
                    } catch (PDOException $e) {
                        // Column might not exist - log but don't fail
                        error_log("Could not reset last_sent: " . $e->getMessage());
                    }
                }
            } else {
                $stmt = $db->prepare("
                    INSERT INTO weather_notification_schedule
                    (user_id, enabled, notification_time, voice_enabled, include_forecast)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $enabled, $time, $voiceEnabled, $includeForecast]);
            }

            echo json_encode(['success' => true]);
            break;

        case 'test_weather':
            require_once __DIR__ . '/../../weather/digest.php';
            $notice = weatherDigest($db, ['user_id' => $userId, 'family_id' => (int) $user['family_id']], true);
            $id = NotificationManager::getInstance($db)->create($notice);
            if (!$id) {
                Response::error('Weather notifications are disabled or temporarily unavailable.', 503);
            }
            Response::success();
            break;

        case 'test':
            // Send test notification with push via NotificationManager
            require_once __DIR__ . '/../../core/NotificationManager.php';

            $notifManager = NotificationManager::getInstance($db);

            $notifId = $notifManager->create([
                'user_id' => $userId,
                'type' => NotificationManager::TYPE_SYSTEM,
                'title' => 'Test Notification',
                'message' => 'This is a test notification sent at ' . date('g:i A') . '. If you received this on your device, push notifications are working!',
                'action_url' => '/notifications/',
                'priority' => NotificationManager::PRIORITY_NORMAL,
                'icon' => '🔔',
                'vibrate' => 1,
                'data' => [
                    'test' => true,
                    'timestamp' => time()
                ]
            ]);

            echo json_encode([
                'success' => $notifId ? true : false,
                'notification_id' => $notifId,
                'message' => $notifId
                    ? 'Test notification sent successfully (with push)'
                    : 'Failed to send test notification'
            ]);
            break;

        case 'register_fcm_token':
            // Register FCM token from native app
            $token = trim($_POST['token'] ?? '');
            $deviceType = $_POST['device_type'] ?? 'unknown';

            if (empty($token)) {
                throw new Exception('FCM token required');
            }

            $validTypes = ['android', 'ios', 'web'];
            if (!in_array($deviceType, $validTypes, true)) {
                $deviceType = 'unknown';
            }

            // Remove any row already holding this token that is not the exact
            // (user, device_type) row we are about to upsert (device switched
            // accounts, or same token stored under another device_type).
            $stmt = $db->prepare("DELETE FROM fcm_tokens WHERE token = ? AND (user_id != ? OR device_type != ?)");
            $stmt->execute([$token, $userId, $deviceType]);

            // Upsert: the live schema's UNIQUE KEY user_device(user_id,
            // device_type) made a plain INSERT collide with the row holding
            // the device's PREVIOUS token, permanently 500ing re-registration
            // after every FCM token rotation.
            $stmt = $db->prepare("
                INSERT INTO fcm_tokens (user_id, token, device_type, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    token = VALUES(token),
                    device_type = VALUES(device_type),
                    updated_at = NOW()
            ");
            $stmt->execute([$userId, $token, $deviceType]);

            echo json_encode([
                'success' => true,
                'message' => 'FCM token registered'
            ]);
            break;

        case 'unregister_fcm_token':
            // Remove FCM token (on logout)
            $token = trim($_POST['token'] ?? '');

            if (!empty($token)) {
                $stmt = $db->prepare("DELETE FROM fcm_tokens WHERE user_id = ? AND token = ?");
                $stmt->execute([$userId, $token]);
            }

            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    error_log('Preferences API error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}