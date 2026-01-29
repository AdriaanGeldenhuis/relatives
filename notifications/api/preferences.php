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
            // Send immediate weather notification for testing
            require_once __DIR__ . '/../../core/NotificationManager.php';

            // Get user's weather schedule settings
            $stmt = $db->prepare("SELECT * FROM weather_notification_schedule WHERE user_id = ?");
            $stmt->execute([$userId]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get user's location - first try tracking_current (most recent)
            $stmt = $db->prepare("
                SELECT lat, lng
                FROM tracking_current
                WHERE user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC);

            // Fallback to tracking_locations if no current location
            if (!$location) {
                $stmt = $db->prepare("
                    SELECT lat, lng
                    FROM tracking_locations
                    WHERE user_id = ?
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $stmt->execute([$userId]);
                $location = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // Default to Johannesburg if no location
            $lat = $location['lat'] ?? -26.2041;
            $lon = $location['lng'] ?? 28.0473;

            // Fetch weather data using cURL for better reliability
            $apiKey = '563504b6b46d0e6bcf9a49e1cb6bc4f3';
            $weatherUrl = "https://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lon}&appid={$apiKey}&units=metric";

            $weatherResponse = false;
            if (function_exists('curl_init')) {
                $ch = curl_init($weatherUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $weatherResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($weatherResponse === false || $httpCode !== 200) {
                    error_log("Weather API error: HTTP $httpCode, cURL error: $curlError");
                    $weatherResponse = false;
                }
            }

            // Fallback to file_get_contents
            if ($weatherResponse === false) {
                $context = stream_context_create(['http' => ['timeout' => 10]]);
                $weatherResponse = @file_get_contents($weatherUrl, false, $context);
            }

            if ($weatherResponse === false) {
                throw new Exception('Failed to fetch weather data - check server connectivity');
            }

            $weatherData = json_decode($weatherResponse, true);
            if (!isset($weatherData['main'])) {
                throw new Exception('Invalid weather response');
            }

            // Prepare notification - GOOGLE-STYLE FORMAT
            $temp = isset($weatherData['main']['temp']) ? round($weatherData['main']['temp']) : null;
            $feelsLike = isset($weatherData['main']['feels_like']) ? round($weatherData['main']['feels_like']) : null;
            $condition = $weatherData['weather'][0]['main'] ?? 'Clear';
            $description = ucfirst($weatherData['weather'][0]['description'] ?? 'clear sky');
            $locationName = $weatherData['name'] ?? 'Your location';
            $windSpeed = round(($weatherData['wind']['speed'] ?? 0) * 3.6);

            if ($temp === null) {
                throw new Exception('No temperature data available');
            }

            // Get weather icon based on condition
            $weatherIcons = [
                'Clear' => '☀️',
                'Clouds' => '☁️',
                'Rain' => '🌧️',
                'Drizzle' => '🌦️',
                'Thunderstorm' => '⛈️',
                'Snow' => '❄️',
                'Mist' => '🌫️',
                'Fog' => '🌫️',
                'Haze' => '🌫️'
            ];
            $weatherIcon = $weatherIcons[$condition] ?? '🌤️';

            // Get forecast for high/low temps (use cURL for reliability)
            $forecastUrl = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&appid={$apiKey}&units=metric&cnt=8";
            $forecastResponse = false;
            if (function_exists('curl_init')) {
                $ch = curl_init($forecastUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $forecastResponse = curl_exec($ch);
                curl_close($ch);
            }
            if (!$forecastResponse) {
                $forecastResponse = @file_get_contents($forecastUrl);
            }
            $forecastData = $forecastResponse ? json_decode($forecastResponse, true) : null;

            $maxTemp = $temp;
            $minTemp = $temp;
            $rainChance = 0;

            if ($forecastData && isset($forecastData['list']) && !empty($forecastData['list'])) {
                $temps = [];
                foreach ($forecastData['list'] as $item) {
                    if (isset($item['main']['temp_max'])) $temps[] = $item['main']['temp_max'];
                    if (isset($item['main']['temp_min'])) $temps[] = $item['main']['temp_min'];
                    if (isset($item['pop'])) {
                        $rainChance = max($rainChance, round($item['pop'] * 100));
                    }
                }
                if (!empty($temps)) {
                    $maxTemp = round(max($temps));
                    $minTemp = round(min($temps));
                }
            }

            // ============================================
            // BEAUTIFUL WEATHER NOTIFICATION
            // ============================================

            // Determine greeting based on time
            $hour = (int)date('H');
            if ($hour >= 5 && $hour < 12) {
                $greeting = "Good morning";
            } elseif ($hour >= 12 && $hour < 17) {
                $greeting = "Good afternoon";
            } elseif ($hour >= 17 && $hour < 21) {
                $greeting = "Good evening";
            } else {
                $greeting = "Weather update";
            }

            // Title: Greeting + location
            $title = "{$weatherIcon} {$greeting} · {$locationName}";

            // Body: Multi-line beautiful format
            $lines = [];

            // Line 1: Current temp big and bold
            $lines[] = "🌡️ Currently {$temp}°";

            // Line 2: Condition + Feels like
            $line2 = $description;
            if ($feelsLike !== null && $feelsLike != $temp) {
                $line2 .= " · Feels {$feelsLike}°";
            }
            $lines[] = $line2;

            // Line 2: High/Low temps
            if ($maxTemp != $minTemp) {
                $lines[] = "📈 High {$maxTemp}° · Low {$minTemp}°";
            }

            // Line 3: Rain, Humidity, Wind
            $humidity = $weatherData['main']['humidity'] ?? 0;
            $statsLine = [];
            if ($rainChance > 0) {
                $statsLine[] = "☔ {$rainChance}%";
            }
            $statsLine[] = "💧 {$humidity}%";
            $statsLine[] = "💨 {$windSpeed} km/h";
            $lines[] = implode(" · ", $statsLine);

            // Line 4: Sunrise/Sunset if available
            if (isset($weatherData['sys']['sunrise']) && isset($weatherData['sys']['sunset'])) {
                $sunrise = date('H:i', $weatherData['sys']['sunrise'] + ($weatherData['timezone'] ?? 7200));
                $sunset = date('H:i', $weatherData['sys']['sunset'] + ($weatherData['timezone'] ?? 7200));
                $lines[] = "🌅 {$sunrise} · 🌇 {$sunset}";
            }

            // Line 5: Smart tip based on conditions
            $tip = null;
            if ($rainChance >= 60) {
                $tip = "☂️ Take an umbrella today!";
            } elseif ($rainChance >= 30) {
                $tip = "🌂 Might want an umbrella";
            } elseif ($temp >= 30) {
                $tip = "🥵 Stay hydrated, it's hot!";
            } elseif ($temp <= 10) {
                $tip = "🧥 Bundle up, it's cold!";
            } elseif ($temp >= 20 && $temp <= 26 && $rainChance < 20) {
                $tip = "😎 Perfect weather outside!";
            }
            if ($tip) {
                $lines[] = $tip;
            }

            $message = implode("\n", $lines);

            $notifManager = NotificationManager::getInstance($db);
            $notifId = $notifManager->create([
                'user_id' => $userId,
                'type' => NotificationManager::TYPE_WEATHER,
                'title' => $title,
                'message' => $message,
                'action_url' => '/weather/',
                'priority' => NotificationManager::PRIORITY_NORMAL,
                'icon' => $weatherIcon,
                'vibrate' => 1,
                'data' => [
                    'test' => true,
                    'temperature' => $temp,
                    'condition' => $condition
                ]
            ]);

            echo json_encode([
                'success' => $notifId ? true : false,
                'notification_id' => $notifId,
                'message' => $notifId ? 'Weather notification sent!' : 'Failed to send notification'
            ]);
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

            // Remove any existing token for other users (device switched users)
            $stmt = $db->prepare("DELETE FROM fcm_tokens WHERE token = ? AND user_id != ?");
            $stmt->execute([$token, $userId]);

            // Check if this token already exists for this user
            $stmt = $db->prepare("SELECT id FROM fcm_tokens WHERE user_id = ? AND token = ?");
            $stmt->execute([$userId, $token]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Update
                $stmt = $db->prepare("UPDATE fcm_tokens SET device_type = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$deviceType, $existing['id']]);
            } else {
                // Insert
                $stmt = $db->prepare("
                    INSERT INTO fcm_tokens (user_id, token, device_type, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$userId, $token, $deviceType]);
            }

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