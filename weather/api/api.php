<?php
declare(strict_types=1);

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit;
}

session_start();

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    require_once __DIR__ . '/../../core/bootstrap.php';
    
    if (!isset($db)) {
        throw new Exception('Database not available');
    }
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Bootstrap failed', 'message' => $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'current':
            getCurrentWeather($db);
            break;
        case 'forecast':
            getWeatherForecast($db);
            break;
        case 'hourly':
            getHourlyForecast($db);
            break;
        case 'detailed':
            getDetailedForecast($db);
            break;
        case 'search':
            searchLocation($db);
            break;
        default:
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            exit;
    }
    
    ob_end_flush();
    
} catch (Exception $e) {
    error_log('Weather API Error: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ============================================
// CURL HELPER FUNCTION
// ============================================

function curlGet($url, $timeout = 10) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false) {
        throw new Exception("cURL error: $error");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP $httpCode: $response");
    }
    
    return $response;
}

// ============================================
// LOCATION SEARCH
// ============================================

function searchLocation($db) {
    $query = $_GET['q'] ?? '';
    
    if (strlen($query) < 2) {
        echo json_encode(['results' => []]);
        return;
    }
    
    $apiKey = '563504b6b46d0e6bcf9a49e1cb6bc4f3';
    $url = "http://api.openweathermap.org/geo/1.0/direct?q=" . urlencode($query) . "&limit=5&appid={$apiKey}";
    
    try {
        $response = curlGet($url);
        $locations = json_decode($response, true);
        
        if (!is_array($locations)) {
            echo json_encode(['results' => []]);
            return;
        }
        
        $results = array_map(function($loc) {
            return [
                'name' => $loc['name'] ?? '',
                'state' => $loc['state'] ?? '',
                'country' => $loc['country'] ?? '',
                'lat' => $loc['lat'] ?? 0,
                'lon' => $loc['lon'] ?? 0,
                'display' => ($loc['name'] ?? '') . 
                    (isset($loc['state']) ? ', ' . $loc['state'] : '') . 
                    ', ' . ($loc['country'] ?? '')
            ];
        }, $locations);
        
        echo json_encode(['results' => $results]);
    } catch (Exception $e) {
        echo json_encode(['results' => [], 'error' => $e->getMessage()]);
    }
}

// ============================================
// CACHE FUNCTIONS
// ============================================

function getCachedWeather($db, $lat, $lon, $type) {
    try {
        $stmt = $db->prepare("
            SELECT weather_data, location_name
            FROM weather_cache 
            WHERE location_lat = ? 
              AND location_lon = ? 
              AND cache_type = ?
              AND expires_at > NOW()
            ORDER BY cached_at DESC
            LIMIT 1
        ");
        
        $stmt->execute([round($lat, 4), round($lon, 4), $type]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $data = json_decode($result['weather_data'], true);
            if ($result['location_name']) {
                $data['location_name'] = $result['location_name'];
            }
            return $data;
        }
    } catch (Exception $e) {
        error_log('Cache read error: ' . $e->getMessage());
    }
    
    return null;
}

function cacheWeather($db, $lat, $lon, $type, $data, $ttlMinutes = 10) {
    try {
        $lat = round($lat, 4);
        $lon = round($lon, 4);
        $locationName = $data['location_name'] ?? null;
        
        $stmt = $db->prepare("DELETE FROM weather_cache WHERE location_lat = ? AND location_lon = ? AND cache_type = ?");
        $stmt->execute([$lat, $lon, $type]);
        
        $stmt = $db->prepare("
            INSERT INTO weather_cache 
            (location_lat, location_lon, cache_type, location_name, weather_data, cached_at, expires_at)
            VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE))
        ");
        
        $stmt->execute([$lat, $lon, $type, $locationName, json_encode($data), $ttlMinutes]);
        return true;
    } catch (Exception $e) {
        error_log('Cache write error: ' . $e->getMessage());
        return false;
    }
}

// ============================================
// CURRENT WEATHER
// ============================================

function getCurrentWeather($db) {
    $lat = (float)($_GET['lat'] ?? -26.2041);
    $lon = (float)($_GET['lon'] ?? 28.0473);
    
    $cached = getCachedWeather($db, $lat, $lon, 'current');
    if ($cached) {
        echo json_encode($cached);
        return;
    }
    
    $apiKey = '563504b6b46d0e6bcf9a49e1cb6bc4f3';
    $url = "https://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lon}&appid={$apiKey}&units=metric";
    
    $response = curlGet($url);
    $apiData = json_decode($response, true);
    
    if (!isset($apiData['main'])) {
        throw new Exception($apiData['message'] ?? 'Invalid API response');
    }
    
    $data = [
        'location' => $apiData['name'] ?? 'Unknown',
        'location_name' => $apiData['name'] ?? 'Unknown',
        'country' => $apiData['sys']['country'] ?? '',
        'temperature' => round($apiData['main']['temp']),
        'feels_like' => round($apiData['main']['feels_like']),
        'temp_min' => round($apiData['main']['temp_min']),
        'temp_max' => round($apiData['main']['temp_max']),
        'description' => ucfirst($apiData['weather'][0]['description'] ?? 'Clear'),
        'icon' => $apiData['weather'][0]['icon'] ?? '01d',
        'condition' => $apiData['weather'][0]['main'] ?? 'Clear',
        'humidity' => $apiData['main']['humidity'] ?? 0,
        'pressure' => $apiData['main']['pressure'] ?? 1013,
        'wind_speed' => round(($apiData['wind']['speed'] ?? 0) * 3.6),
        'wind_direction' => $apiData['wind']['deg'] ?? 0,
        'wind_gust' => isset($apiData['wind']['gust']) ? round($apiData['wind']['gust'] * 3.6) : null,
        'clouds' => $apiData['clouds']['all'] ?? 0,
        'visibility' => round(($apiData['visibility'] ?? 10000) / 1000, 1),
        'sunrise' => $apiData['sys']['sunrise'] ?? time(),
        'sunset' => $apiData['sys']['sunset'] ?? time(),
        'timezone' => $apiData['timezone'] ?? 0,
        'timestamp' => time(),
        'dt' => $apiData['dt'] ?? time()
    ];
    
    cacheWeather($db, $lat, $lon, 'current', $data, 10);
    echo json_encode($data);
}

// ============================================
// FORECAST
// ============================================

function getWeatherForecast($db) {
    $lat = (float)($_GET['lat'] ?? -26.2041);
    $lon = (float)($_GET['lon'] ?? 28.0473);
    
    $cached = getCachedWeather($db, $lat, $lon, 'forecast');
    if ($cached) {
        echo json_encode($cached);
        return;
    }
    
    $apiKey = '563504b6b46d0e6bcf9a49e1cb6bc4f3';
    $url = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&appid={$apiKey}&units=metric&cnt=40";
    
    $response = curlGet($url);
    $apiData = json_decode($response, true);
    
    if (!isset($apiData['list'])) {
        throw new Exception($apiData['message'] ?? 'Invalid forecast response');
    }
    
    $forecast = processForecastData($apiData);
    
    $result = [
        'forecast' => $forecast,
        'location_name' => $apiData['city']['name'] ?? 'Unknown'
    ];
    
    cacheWeather($db, $lat, $lon, 'forecast', $result, 60);
    echo json_encode($result);
}

// ============================================
// HOURLY FORECAST
// ============================================

function getHourlyForecast($db) {
    $lat = (float)($_GET['lat'] ?? -26.2041);
    $lon = (float)($_GET['lon'] ?? 28.0473);
    
    $cached = getCachedWeather($db, $lat, $lon, 'hourly');
    if ($cached) {
        echo json_encode($cached);
        return;
    }
    
    $apiKey = '563504b6b46d0e6bcf9a49e1cb6bc4f3';
    $url = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&appid={$apiKey}&units=metric&cnt=8";
    
    $response = curlGet($url);
    $apiData = json_decode($response, true);
    
    if (!isset($apiData['list'])) {
        throw new Exception($apiData['message'] ?? 'Invalid hourly response');
    }
    
    $hourly = processHourlyData($apiData);
    
    $result = [
        'hourly' => $hourly,
        'location_name' => $apiData['city']['name'] ?? 'Unknown'
    ];
    
    cacheWeather($db, $lat, $lon, 'hourly', $result, 30);
    echo json_encode($result);
}

// ============================================
// DETAILED FORECAST
// ============================================

function getDetailedForecast($db) {
    $date = $_GET['date'] ?? date('Y-m-d');
    $lat = (float)($_GET['lat'] ?? -26.2041);
    $lon = (float)($_GET['lon'] ?? 28.0473);
    
    $cacheKey = "detailed_{$date}";
    $cached = getCachedWeather($db, $lat, $lon, $cacheKey);
    if ($cached) {
        echo json_encode($cached);
        return;
    }
    
    $apiKey = '563504b6b46d0e6bcf9a49e1cb6bc4f3';
    $url = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&appid={$apiKey}&units=metric&cnt=40";
    
    $response = curlGet($url);
    $apiData = json_decode($response, true);
    
    if (!isset($apiData['list'])) {
        throw new Exception($apiData['message'] ?? 'Invalid detailed response');
    }
    
    $targetDate = strtotime($date);
    $hourlyData = [];
    
    foreach ($apiData['list'] as $item) {
        if (date('Y-m-d', $item['dt']) === $date) {
            $hourlyData[] = [
                'time' => date('H:i', $item['dt']),
                'timestamp' => $item['dt'],
                'temperature' => round($item['main']['temp']),
                'feels_like' => round($item['main']['feels_like']),
                'temp_min' => round($item['main']['temp_min']),
                'temp_max' => round($item['main']['temp_max']),
                'condition' => $item['weather'][0]['main'],
                'description' => ucfirst($item['weather'][0]['description']),
                'icon' => $item['weather'][0]['icon'],
                'humidity' => $item['main']['humidity'],
                'pressure' => $item['main']['pressure'],
                'wind_speed' => round($item['wind']['speed'] * 3.6),
                'wind_direction' => $item['wind']['deg'],
                'wind_gust' => isset($item['wind']['gust']) ? round($item['wind']['gust'] * 3.6) : null,
                'precipitation' => round(($item['pop'] ?? 0) * 100),
                'clouds' => $item['clouds']['all'],
                'visibility' => isset($item['visibility']) ? round($item['visibility'] / 1000, 1) : 10
            ];
        }
    }
    
    $temps = array_column($hourlyData, 'temperature');
    $conditions = array_column($hourlyData, 'condition');
    $dominantCondition = 'Clear';
    
    if (!empty($conditions)) {
        $conditionCounts = array_count_values($conditions);
        arsort($conditionCounts);
        $dominantCondition = key($conditionCounts);
    }
    
    $result = [
        'date' => $date,
        'day_name' => date('l', $targetDate),
        'temp_max' => !empty($temps) ? max($temps) : 25,
        'temp_min' => !empty($temps) ? min($temps) : 18,
        'temp_avg' => !empty($temps) ? round(array_sum($temps) / count($temps)) : 22,
        'dominant_condition' => $dominantCondition,
        'hourly' => $hourlyData,
        'summary' => generateDaySummary($hourlyData, $dominantCondition),
        'location_name' => $apiData['city']['name'] ?? 'Unknown'
    ];
    
    cacheWeather($db, $lat, $lon, $cacheKey, $result, 120);
    echo json_encode($result);
}

// ============================================
// DATA PROCESSING
// ============================================

function processForecastData($apiData) {
    $dailyData = [];
    
    foreach ($apiData['list'] as $item) {
        $date = date('Y-m-d', $item['dt']);
        
        if (!isset($dailyData[$date])) {
            $dailyData[$date] = [
                'temps' => [],
                'conditions' => [],
                'icons' => [],
                'humidity' => [],
                'wind' => [],
                'precipitation' => [],
                'clouds' => [],
                'pressure' => []
            ];
        }
        
        $dailyData[$date]['temps'][] = $item['main']['temp'];
        $dailyData[$date]['conditions'][] = $item['weather'][0]['main'];
        $dailyData[$date]['icons'][] = $item['weather'][0]['icon'];
        $dailyData[$date]['humidity'][] = $item['main']['humidity'];
        $dailyData[$date]['wind'][] = $item['wind']['speed'] * 3.6;
        $dailyData[$date]['precipitation'][] = ($item['pop'] ?? 0) * 100;
        $dailyData[$date]['clouds'][] = $item['clouds']['all'];
        $dailyData[$date]['pressure'][] = $item['main']['pressure'];
    }
    
    $forecast = [];
    foreach ($dailyData as $date => $data) {
        $icons = $data['icons'];
        $dayIcons = array_filter($icons, fn($icon) => substr($icon, -1) === 'd');
        $icon = !empty($dayIcons) ? array_values($dayIcons)[0] : $icons[0];
        
        $conditionCounts = array_count_values($data['conditions']);
        arsort($conditionCounts);
        $condition = key($conditionCounts);
        
        $forecast[] = [
            'date' => $date,
            'day_name' => date('l', strtotime($date)),
            'temp_max' => round(max($data['temps'])),
            'temp_min' => round(min($data['temps'])),
            'temp_avg' => round(array_sum($data['temps']) / count($data['temps'])),
            'condition' => $condition,
            'description' => strtolower($condition),
            'icon' => $icon,
            'humidity' => round(array_sum($data['humidity']) / count($data['humidity'])),
            'humidity_min' => min($data['humidity']),
            'humidity_max' => max($data['humidity']),
            'wind_speed' => round(array_sum($data['wind']) / count($data['wind'])),
            'wind_max' => round(max($data['wind'])),
            'precipitation' => round(array_sum($data['precipitation']) / count($data['precipitation'])),
            'clouds' => round(array_sum($data['clouds']) / count($data['clouds'])),
            'pressure' => round(array_sum($data['pressure']) / count($data['pressure']))
        ];
    }
    
    return array_slice($forecast, 0, 7);
}

function processHourlyData($apiData) {
    $hourly = [];
    
    foreach ($apiData['list'] as $item) {
        $hourly[] = [
            'time' => date('H:i', $item['dt']),
            'time_12h' => date('g:i A', $item['dt']),
            'timestamp' => $item['dt'],
            'date' => date('Y-m-d', $item['dt']),
            'day' => date('D', $item['dt']),
            'temperature' => round($item['main']['temp']),
            'feels_like' => round($item['main']['feels_like']),
            'temp_min' => round($item['main']['temp_min']),
            'temp_max' => round($item['main']['temp_max']),
            'condition' => $item['weather'][0]['main'],
            'description' => ucfirst($item['weather'][0]['description']),
            'icon' => $item['weather'][0]['icon'],
            'humidity' => $item['main']['humidity'],
            'pressure' => $item['main']['pressure'],
            'wind_speed' => round($item['wind']['speed'] * 3.6),
            'wind_direction' => $item['wind']['deg'],
            'wind_gust' => isset($item['wind']['gust']) ? round($item['wind']['gust'] * 3.6) : null,
            'precipitation' => round(($item['pop'] ?? 0) * 100),
            'clouds' => $item['clouds']['all'],
            'visibility' => isset($item['visibility']) ? round($item['visibility'] / 1000, 1) : 10
        ];
    }
    
    return $hourly;
}

function generateDaySummary($hourlyData, $condition) {
    if (empty($hourlyData)) {
        return 'No detailed forecast available.';
    }
    
    $temps = array_column($hourlyData, 'temperature');
    $tempMax = max($temps);
    $tempMin = min($temps);
    $precips = array_column($hourlyData, 'precipitation');
    $maxPrecip = max($precips);
    $winds = array_column($hourlyData, 'wind_speed');
    $maxWind = max($winds);
    
    $summary = "Expect {$condition} conditions. ";
    $summary .= "Temperatures: {$tempMin}°C to {$tempMax}°C. ";
    
    if ($maxPrecip > 70) {
        $summary .= "High rain chance ({$maxPrecip}%). ";
    } elseif ($maxPrecip > 40) {
        $summary .= "Possible rain ({$maxPrecip}%). ";
    }
    
    if ($maxWind > 40) {
        $summary .= "Windy up to {$maxWind} km/h. ";
    }
    
    if ($tempMax > 30) {
        $summary .= "Stay hydrated.";
    } elseif ($tempMin < 10) {
        $summary .= "Bundle up.";
    }
    
    return $summary;
}