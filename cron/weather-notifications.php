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
    $currentHour = (int)date('H');
    $currentMinute = (int)date('i');
    $currentTimeHHMM = sprintf('%02d:%02d', $currentHour, $currentMinute);

    echo "Current time: $currentTimeHHMM\n";

    // Debug: Show all scheduled weather notifications
    // Handle case where last_sent column might not exist
    try {
        $debugStmt = $db->prepare("
            SELECT wns.user_id, u.full_name,
                   TIME_FORMAT(wns.notification_time, '%H:%i') as scheduled_time,
                   wns.enabled, wns.last_sent
            FROM weather_notification_schedule wns
            JOIN users u ON wns.user_id = u.id
            WHERE wns.enabled = 1
            ORDER BY wns.notification_time
        ");
        $debugStmt->execute();
        $allSchedules = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback without last_sent
        $debugStmt = $db->prepare("
            SELECT wns.user_id, u.full_name,
                   TIME_FORMAT(wns.notification_time, '%H:%i') as scheduled_time,
                   wns.enabled
            FROM weather_notification_schedule wns
            JOIN users u ON wns.user_id = u.id
            WHERE wns.enabled = 1
            ORDER BY wns.notification_time
        ");
        $debugStmt->execute();
        $allSchedules = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!empty($allSchedules)) {
        echo "Active weather schedules:\n";
        foreach ($allSchedules as $sched) {
            $lastSent = isset($sched['last_sent']) && $sched['last_sent'] ? date('Y-m-d', strtotime($sched['last_sent'])) : 'never';
            echo "  - {$sched['full_name']}: {$sched['scheduled_time']} (last sent: $lastSent)\n";
        }
    } else {
        echo "No active weather schedules found in database\n";
    }

    // Simple and reliable time matching using TIME_FORMAT
    // This converts the TIME field to HH:MM format for exact comparison
    // Handle case where last_sent column might not exist
    try {
        $stmt = $db->prepare("
            SELECT
                wns.user_id,
                wns.notification_time,
                TIME_FORMAT(wns.notification_time, '%H:%i') as time_formatted,
                wns.voice_enabled,
                wns.include_forecast,
                u.full_name
            FROM weather_notification_schedule wns
            JOIN users u ON wns.user_id = u.id
            WHERE wns.enabled = 1
              AND TIME_FORMAT(wns.notification_time, '%H:%i') = ?
              AND (wns.last_sent IS NULL OR DATE(wns.last_sent) < CURDATE())
              AND u.status = 'active'
        ");
        $stmt->execute([$currentTimeHHMM]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback without last_sent check
        echo "Note: last_sent column may not exist, proceeding without dedup check\n";
        $stmt = $db->prepare("
            SELECT
                wns.user_id,
                wns.notification_time,
                TIME_FORMAT(wns.notification_time, '%H:%i') as time_formatted,
                wns.voice_enabled,
                wns.include_forecast,
                u.full_name
            FROM weather_notification_schedule wns
            JOIN users u ON wns.user_id = u.id
            WHERE wns.enabled = 1
              AND TIME_FORMAT(wns.notification_time, '%H:%i') = ?
              AND u.status = 'active'
        ");
        $stmt->execute([$currentTimeHHMM]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($users)) {
        echo "No users scheduled for $currentTimeHHMM\n";
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
            
            // Get forecast if enabled (use cURL for reliability)
            $forecastData = null;
            if ($user['include_forecast']) {
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
                if ($forecastResponse) {
                    $forecastData = json_decode($forecastResponse, true);
                }
            }
            
            // Prepare notification data
            $temp = isset($weatherData['main']['temp']) ? round($weatherData['main']['temp']) : null;
            $feelsLike = isset($weatherData['main']['feels_like']) ? round($weatherData['main']['feels_like']) : null;
            $condition = $weatherData['weather'][0]['main'] ?? 'Clear';
            $description = ucfirst($weatherData['weather'][0]['description'] ?? 'clear sky');
            $locationName = $weatherData['name'] ?? 'Your location';
            $humidity = $weatherData['main']['humidity'] ?? 0;
            $windSpeed = round(($weatherData['wind']['speed'] ?? 0) * 3.6);

            // Skip if no temperature data
            if ($temp === null) {
                echo "  ⚠️ No temperature data for user {$user['user_id']}\n";
                continue;
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
                'Haze' => '🌫️',
                'Smoke' => '🌫️',
                'Dust' => '🌫️'
            ];
            $weatherIcon = $weatherIcons[$condition] ?? '🌤️';

            // Get forecast data for high/low temps
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

            // Line 1: Current temp
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
            
            // Create notification
            $notificationData = [
                'user_id' => $user['user_id'],
                'type' => NotificationManager::TYPE_WEATHER,
                'title' => $title,
                'message' => $message,
                'action_url' => '/weather/',
                'priority' => NotificationManager::PRIORITY_LOW,
                'icon' => $weatherIcon,
                'vibrate' => 0,
                'data' => [
                    'temperature' => $temp,
                    'condition' => $condition,
                    'location' => $locationName,
                    'weather_full' => $weatherData
                ]
            ];
            
            $notifManager = NotificationManager::getInstance($db);
            $notifId = $notifManager->create($notificationData);
            
            if ($notifId) {
                // Update last sent timestamp (handle missing column gracefully)
                try {
                    $stmt = $db->prepare("
                        UPDATE weather_notification_schedule
                        SET last_sent = NOW()
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$user['user_id']]);
                } catch (PDOException $e) {
                    // Column might not exist - log but continue
                    error_log("Could not update last_sent for user {$user['user_id']}: " . $e->getMessage());
                }

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