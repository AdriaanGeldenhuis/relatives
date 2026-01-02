<?php
/**
 * ============================================
 * CRON JOB: DAILY WEATHER NOTIFICATIONS
 * Run every minute, checks if users need 7AM notification
 * ============================================
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Load core
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/NotificationManager.php';
require_once __DIR__ . '/../core/NotificationTriggers.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting weather notification cron...\n";

try {
    $triggers = new NotificationTriggers($db);
    $currentTime = date('H:i:00');
    
    // Get users who need weather notification at this time
    $stmt = $db->prepare("
        SELECT 
            wns.user_id,
            wns.notification_time,
            wns.voice_enabled,
            wns.include_forecast,
            u.full_name
        FROM weather_notification_schedule wns
        JOIN users u ON wns.user_id = u.id
        WHERE wns.enabled = 1
          AND wns.notification_time = ?
          AND (wns.last_sent IS NULL OR DATE(wns.last_sent) < CURDATE())
          AND u.status = 'active'
    ");
    $stmt->execute([$currentTime]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "No users scheduled for $currentTime\n";
        exit(0);
    }
    
    echo "Found " . count($users) . " users needing weather notification\n";
    
    foreach ($users as $user) {
        try {
            echo "Processing user {$user['user_id']}: {$user['full_name']}\n";
            
            // Get user's location (use last known location or default)
            $stmt = $db->prepare("
                SELECT latitude, longitude 
                FROM tracking_locations 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$user['user_id']]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Default to Johannesburg if no location
            $lat = $location['latitude'] ?? -26.2041;
            $lon = $location['longitude'] ?? 28.0473;
            
            // Fetch weather data
            $apiKey = '563504b6b46d0e6bcf9a49e1cb6bc4f3';
            $weatherUrl = "https://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lon}&appid={$apiKey}&units=metric";
            
            $weatherResponse = @file_get_contents($weatherUrl);
            if ($weatherResponse === false) {
                echo "  ⚠️ Failed to fetch weather for user {$user['user_id']}\n";
                continue;
            }
            
            $weatherData = json_decode($weatherResponse, true);
            
            if (!isset($weatherData['main'])) {
                echo "  ⚠️ Invalid weather response for user {$user['user_id']}\n";
                continue;
            }
            
            // Get forecast if enabled
            $forecastData = null;
            if ($user['include_forecast']) {
                $forecastUrl = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&appid={$apiKey}&units=metric&cnt=8";
                $forecastResponse = @file_get_contents($forecastUrl);
                if ($forecastResponse) {
                    $forecastData = json_decode($forecastResponse, true);
                }
            }
            
            // Prepare notification data
            $temp = round($weatherData['main']['temp']);
            $feelsLike = round($weatherData['main']['feels_like']);
            $condition = $weatherData['weather'][0]['main'] ?? 'Clear';
            $description = ucfirst($weatherData['weather'][0]['description'] ?? 'clear sky');
            $location = $weatherData['name'] ?? 'Your location';
            $humidity = $weatherData['main']['humidity'] ?? 0;
            $windSpeed = round(($weatherData['wind']['speed'] ?? 0) * 3.6);
            
            // Build message
            $message = "Good morning! ☀️\n\n";
            $message .= "📍 $location\n";
            $message .= "🌡️ $temp°C (feels like $feelsLike°C)\n";
            $message .= "☁️ $description\n";
            $message .= "💧 Humidity: $humidity%\n";
            $message .= "💨 Wind: $windSpeed km/h";
            
            // Add forecast highlights
            if ($forecastData && isset($forecastData['list'])) {
                $maxTemp = max(array_column(array_column($forecastData['list'], 'main'), 'temp_max'));
                $minTemp = min(array_column(array_column($forecastData['list'], 'main'), 'temp_min'));
                $message .= "\n\n📊 Today: " . round($maxTemp) . "°C / " . round($minTemp) . "°C";
                
                // Check for rain
                $rainChance = 0;
                foreach ($forecastData['list'] as $item) {
                    if (isset($item['pop'])) {
                        $rainChance = max($rainChance, $item['pop'] * 100);
                    }
                }
                if ($rainChance > 30) {
                    $message .= "\n☔ Rain chance: " . round($rainChance) . "%";
                }
            }
            
            // Add advice
            if ($temp > 30) {
                $message .= "\n\n💡 Hot day ahead! Stay hydrated.";
            } elseif ($temp < 15) {
                $message .= "\n\n💡 Chilly morning! Bundle up.";
            }
            
            // Create notification
            $notificationData = [
                'user_id' => $user['user_id'],
                'type' => NotificationManager::TYPE_WEATHER,
                'title' => "Good Morning - Today's Weather",
                'message' => $message,
                'action_url' => '/weather/',
                'priority' => NotificationManager::PRIORITY_LOW,
                'icon' => $weatherData['weather'][0]['icon'] ?? '🌤️',
                'vibrate' => 0,
                'data' => [
                    'temperature' => $temp,
                    'condition' => $condition,
                    'location' => $location,
                    'weather_full' => $weatherData
                ]
            ];
            
            $notifManager = NotificationManager::getInstance($db);
            $notifId = $notifManager->create($notificationData);
            
            if ($notifId) {
                // Update last sent timestamp
                $stmt = $db->prepare("
                    UPDATE weather_notification_schedule 
                    SET last_sent = NOW() 
                    WHERE user_id = ?
                ");
                $stmt->execute([$user['user_id']]);
                
                echo "  ✅ Sent weather notification (ID: $notifId)\n";
            } else {
                echo "  ⚠️ Failed to create notification\n";
            }
            
        } catch (Exception $e) {
            echo "  ❌ Error for user {$user['user_id']}: " . $e->getMessage() . "\n";
            continue;
        }
    }
    
    echo "Cron job completed successfully\n";
    
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    error_log("Weather cron fatal error: " . $e->getMessage());
    exit(1);
}