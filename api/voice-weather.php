<?php
/**
 * ============================================
 * VOICE WEATHER RESPONSE API
 * Returns spoken weather description
 * ============================================
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../core/bootstrap.php';

$input = json_decode(file_get_contents('php://input'), true);
$tomorrow = $input['tomorrow'] ?? false;

$apiKey = '563504b6b46d0e6bcf9a49e1cb6bc4f3';

// Try to get user's location
session_start();
$lat = null;
$lon = null;

if (isset($_SESSION['user_id'])) {
    try {
        $auth = new Auth($db);
        $user = $auth->getCurrentUser();
        
        if ($user) {
            $stmt = $db->prepare("
                SELECT lat, lng 
                FROM locations 
                WHERE user_id = ? 
                ORDER BY recorded_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$user['id']]);
            $location = $stmt->fetch();
            
            if ($location) {
                $lat = (float)$location['lat'];
                $lon = (float)$location['lng'];
            }
        }
    } catch (Exception $e) {
        error_log('Weather location error: ' . $e->getMessage());
    }
}

// Fallback to Cape Town
if (!$lat || !$lon) {
    $lat = -33.9249;
    $lon = 18.4241;
}

// Fetch weather from OpenWeather
$weatherUrl = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&units=metric&appid={$apiKey}";

$ch = curl_init($weatherUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$weatherResponse = curl_exec($ch);
curl_close($ch);

$weatherData = json_decode($weatherResponse, true);

if (!$weatherData || !isset($weatherData['list'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch weather data'
    ]);
    exit;
}

// Determine which forecast to use
$targetTimestamp = $tomorrow ? strtotime('tomorrow noon') : time();
$closestForecast = null;
$smallestDiff = PHP_INT_MAX;

foreach ($weatherData['list'] as $forecast) {
    $diff = abs($forecast['dt'] - $targetTimestamp);
    if ($diff < $smallestDiff) {
        $smallestDiff = $diff;
        $closestForecast = $forecast;
    }
}

if (!$closestForecast) {
    $closestForecast = $weatherData['list'][0];
}

// Extract weather info
$temp = round($closestForecast['main']['temp']);
$feelsLike = round($closestForecast['main']['feels_like']);
$description = $closestForecast['weather'][0]['description'];
$humidity = $closestForecast['main']['humidity'];
$windSpeed = round($closestForecast['wind']['speed'] * 3.6); // m/s to km/h
$cityName = $weatherData['city']['name'] ?? 'your area';

// Build spoken response
$timePhrase = $tomorrow ? "tomorrow" : "today";
$spokenResponse = "The weather $timePhrase in $cityName is $description, " .
                  "with a temperature of $temp degrees Celsius. " .
                  "It feels like $feelsLike degrees. " .
                  "Humidity is at $humidity percent, " .
                  "and wind speed is $windSpeed kilometers per hour.";

// Add recommendation
if ($temp > 30) {
    $spokenResponse .= " It's quite hot, so stay hydrated and use sunscreen.";
} elseif ($temp < 15) {
    $spokenResponse .= " It's a bit chilly, you might want to bring a jacket.";
} elseif (strpos($description, 'rain') !== false) {
    $spokenResponse .= " Don't forget your umbrella!";
} else {
    $spokenResponse .= " Have a great day!";
}

echo json_encode([
    'success' => true,
    'spoken_response' => $spokenResponse,
    'data' => [
        'temp' => $temp,
        'feels_like' => $feelsLike,
        'description' => $description,
        'humidity' => $humidity,
        'wind_speed' => $windSpeed,
        'city' => $cityName
    ]
]);