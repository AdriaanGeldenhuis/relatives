<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "TEST 1: Basic PHP works\n";

session_start();
echo "TEST 2: Session started\n";
echo "User ID: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n";

try {
    require_once __DIR__ . '/../../core/bootstrap.php';
    echo "TEST 3: Bootstrap loaded\n";
    echo "DB exists: " . (isset($db) ? 'YES' : 'NO') . "\n";
} catch (Exception $e) {
    echo "TEST 3 FAILED: " . $e->getMessage() . "\n";
    exit;
}

if (!isset($db)) {
    echo "DATABASE NOT SET!\n";
    exit;
}

echo "TEST 4: Trying OpenWeather API call\n";

$lat = -26.7096;
$lon = 27.7526;
$apiKey = '563504b6b46d0e6bcf9a49e1cb6bc4f3';
$url = "https://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lon}&appid={$apiKey}&units=metric";

echo "URL: $url\n";

$response = @file_get_contents($url);

if ($response === false) {
    $error = error_get_last();
    echo "API CALL FAILED:\n";
    print_r($error);
    exit;
}

echo "API Response received:\n";
echo substr($response, 0, 500) . "\n";

$data = json_decode($response, true);
echo "\nParsed data:\n";
print_r($data);

echo "\n\nTEST 5: Trying cache write\n";

try {
    $stmt = $db->prepare("
        INSERT INTO weather_cache 
        (location_lat, location_lon, cache_type, weather_data, cached_at, expires_at)
        VALUES (?, ?, 'test', ?, NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE))
    ");
    
    $stmt->execute([
        round($lat, 4),
        round($lon, 4),
        json_encode(['test' => 'data'])
    ]);
    
    echo "Cache write SUCCESS\n";
    echo "Last insert ID: " . $db->lastInsertId() . "\n";
    
} catch (Exception $e) {
    echo "Cache write FAILED: " . $e->getMessage() . "\n";
    echo "SQL Error: " . print_r($stmt->errorInfo(), true) . "\n";
}

echo "\nALL TESTS COMPLETE\n";