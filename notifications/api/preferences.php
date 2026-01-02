<?php
/**
 * NOTIFICATION PREFERENCES API
 * Complete CRUD for notification settings
 */

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

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
            $settingsJson = $_POST['settings'] ?? '{}';
            $settings = json_decode($settingsJson, true);
            
            if (empty($category)) {
                throw new Exception('Category required');
            }
            
            // Check if exists
            $stmt = $db->prepare("
                SELECT id FROM notification_preferences 
                WHERE user_id = ? AND category = ?
            ");
            $stmt->execute([$userId, $category]);
            $exists = $stmt->fetch();
            
            if ($exists) {
                // Update
                $fields = [];
                $values = [];
                
                foreach ($settings as $key => $value) {
                    if (in_array($key, ['enabled', 'push_enabled', 'sound_enabled', 'vibrate_enabled'])) {
                        $fields[] = "$key = ?";
                        $values[] = (int)$value;
                    }
                }
                
                if (!empty($fields)) {
                    $values[] = $userId;
                    $values[] = $category;
                    
                    $sql = "UPDATE notification_preferences SET " . 
                           implode(', ', $fields) . 
                           " WHERE user_id = ? AND category = ?";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->execute($values);
                }
            } else {
                // Insert with defaults
                $enabled = isset($settings['enabled']) ? (int)$settings['enabled'] : 1;
                $pushEnabled = isset($settings['push_enabled']) ? (int)$settings['push_enabled'] : 1;
                $soundEnabled = isset($settings['sound_enabled']) ? (int)$settings['sound_enabled'] : 1;
                $vibrateEnabled = isset($settings['vibrate_enabled']) ? (int)$settings['vibrate_enabled'] : 1;
                
                $stmt = $db->prepare("
                    INSERT INTO notification_preferences 
                    (user_id, category, enabled, push_enabled, sound_enabled, vibrate_enabled)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $category, $enabled, $pushEnabled, $soundEnabled, $vibrateEnabled]);
            }
            
            echo json_encode(['success' => true]);
            break;
            
        case 'update_weather_schedule':
            $enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : 1;
            $time = $_POST['time'] ?? '07:00:00';
            $voiceEnabled = isset($_POST['voice_enabled']) ? (int)$_POST['voice_enabled'] : 1;
            $includeForecast = isset($_POST['include_forecast']) ? (int)$_POST['include_forecast'] : 1;
            
            // Validate time format
            if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
                throw new Exception('Invalid time format');
            }
            
            // Check if exists
            $stmt = $db->prepare("SELECT id FROM weather_notification_schedule WHERE user_id = ?");
            $stmt->execute([$userId]);
            $exists = $stmt->fetch();
            
            if ($exists) {
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
            
        case 'test':
            // Send test notification
            $stmt = $db->prepare("
                INSERT INTO notifications 
                (user_id, type, title, message, action_url, icon, created_at)
                VALUES (?, 'system', ?, ?, '/notifications/', '🔔', NOW())
            ");
            
            $title = 'Test Notification';
            $message = 'This is a test notification sent at ' . date('g:i A');
            
            $stmt->execute([$userId, $title, $message]);
            $notifId = $db->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'notification_id' => $notifId,
                'message' => 'Test notification sent successfully'
            ]);
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