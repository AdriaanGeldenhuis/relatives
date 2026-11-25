<?php
declare(strict_types=1);

function weatherCoordinate(mixed $value, int $limit): float
{
    if (!is_numeric($value) || !is_finite((float)$value) || abs((float)$value) > $limit) {
        throw new InvalidArgumentException('Invalid weather coordinates.');
    }
    return round((float)$value, 4);
}

function openWeather(string $path, array $parameters): array
{
    $apiKey = getenv('OPENWEATHER_API_KEY') ?: '';
    if ($apiKey === '') {
        throw new RuntimeException('Weather provider configuration is missing.');
    }
    $url = 'https://api.openweathermap.org/' . $path . '?' . http_build_query($parameters + ['appid' => $apiKey]);
    $request = curl_init($url);
    curl_setopt_array($request, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 9
    ]);
    $response = curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $errorNumber = curl_errno($request);
    if ($response === false || $status !== 200) {
        throw new RuntimeException("OpenWeather returned HTTP $status (cURL $errorNumber).");
    }
    $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Unexpected OpenWeather response.');
    }
    return $data;
}

function searchLocation(mixed $query): array
{
    if (!is_string($query) || !mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 150) {
        throw new InvalidArgumentException('Invalid location search.');
    }
    $query = trim($query);
    if (mb_strlen($query) < 2) {
        return [];
    }
    $results = [];
    foreach (openWeather('geo/1.0/direct', ['q' => $query, 'limit' => 5]) as $place) {
        if (!isset($place['name'], $place['lat'], $place['lon'])) {
            continue;
        }
        $state = $place['state'] ?? '';
        $country = $place['country'] ?? '';
        $results[] = [
            'name' => $place['name'],
            'state' => $state,
            'country' => $country,
            'lat' => $place['lat'],
            'lon' => $place['lon'],
            'display' => implode(', ', array_filter([$place['name'], $state, $country]))
        ];
    }
    return $results;
}

function getCachedWeather(PDO $db, float $lat, float $lon, string $type, bool $stale = false): ?array
{
    try {
        $freshness = $stale ? 'cached_at > NOW() - INTERVAL 6 HOUR' : 'expires_at > NOW()';
        $stmt = $db->prepare("SELECT weather_data FROM weather_cache
            WHERE location_lat = ? AND location_lon = ? AND cache_type = ? AND $freshness
            ORDER BY cached_at DESC, expires_at DESC LIMIT 1");
        $stmt->execute([$lat, $lon, $type]);
        $data = json_decode((string)$stmt->fetchColumn(), true);
        return is_array($data) ? $data : null;
    } catch (Throwable $e) {
        error_log('Weather cache read: ' . $e->getMessage());
        return null;
    }
}

function cacheWeather(PDO $db, float $lat, float $lon, string $type, array $data, int $minutes): void
{
    try {
        $stmt = $db->prepare("INSERT INTO weather_cache
            (location_lat, location_lon, cache_type, location_name, weather_data, cached_at, expires_at)
            VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE))
            ON DUPLICATE KEY UPDATE location_name = VALUES(location_name), weather_data = VALUES(weather_data),
                cached_at = VALUES(cached_at), expires_at = VALUES(expires_at)");
        $stmt->execute([$lat, $lon, $type, $data['location_name'] ?? null, json_encode($data, JSON_THROW_ON_ERROR), $minutes]);
    } catch (Throwable $e) {
        error_log('Weather cache write: ' . $e->getMessage());
    }
}

function getCurrentWeather(PDO $db, float $lat, float $lon): array
{
    $cached = getCachedWeather($db, $lat, $lon, 'current');
    if ($cached) {
        return $cached;
    }
    try {
        $apiData = openWeather('data/2.5/weather', ['lat' => $lat, 'lon' => $lon, 'units' => 'metric']);
    } catch (RuntimeException $error) {
        $old = getCachedWeather($db, $lat, $lon, 'current', true);
        if (!$old) throw $error;
        return $old + ['stale' => true];
    }
    if (!is_numeric($apiData['main']['temp'] ?? null)) {
        throw new RuntimeException('OpenWeather current conditions are missing.');
    }
    $data = [
        'location' => $apiData['name'] ?? 'Unknown',
        'location_name' => $apiData['name'] ?? 'Unknown',
        'country' => $apiData['sys']['country'] ?? '',
        'temperature' => round($apiData['main']['temp']),
        'feels_like' => isset($apiData['main']['feels_like']) ? round($apiData['main']['feels_like']) : null,
        'temp_min' => round($apiData['main']['temp_min'] ?? $apiData['main']['temp']),
        'temp_max' => round($apiData['main']['temp_max'] ?? $apiData['main']['temp']),
        'description' => ucfirst($apiData['weather'][0]['description'] ?? 'Conditions unavailable'),
        'icon' => $apiData['weather'][0]['icon'] ?? null,
        'condition' => $apiData['weather'][0]['main'] ?? null,
        'humidity' => $apiData['main']['humidity'] ?? null,
        'pressure' => $apiData['main']['pressure'] ?? null,
        'wind_speed' => isset($apiData['wind']['speed']) ? round($apiData['wind']['speed'] * 3.6) : null,
        'wind_direction' => $apiData['wind']['deg'] ?? null,
        'wind_gust' => isset($apiData['wind']['gust']) ? round($apiData['wind']['gust'] * 3.6) : null,
        'clouds' => $apiData['clouds']['all'] ?? null,
        'visibility' => isset($apiData['visibility']) ? round($apiData['visibility'] / 1000, 1) : null,
        'sunrise' => $apiData['sys']['sunrise'] ?? null,
        'sunset' => $apiData['sys']['sunset'] ?? null,
        'timezone' => $apiData['timezone'] ?? 0,
        'timestamp' => time(),
        'dt' => $apiData['dt'] ?? null
    ];

    cacheWeather($db, $lat, $lon, 'current', $data, 10);
    return $data;
}

function getForecastData(PDO $db, float $lat, float $lon): array
{
    $cached = getCachedWeather($db, $lat, $lon, 'forecast_v2');
    if ($cached) {
        return $cached;
    }

    try {
        $data = openWeather('data/2.5/forecast', ['lat' => $lat, 'lon' => $lon, 'units' => 'metric', 'cnt' => 40]);
    } catch (RuntimeException $error) {
        $old = getCachedWeather($db, $lat, $lon, 'forecast_v2', true);
        if (!$old) throw $error;
        return $old + ['stale' => true];
    }
    if (empty($data['list']) || !is_array($data['list'])) {
        throw new RuntimeException('OpenWeather forecast is missing.');
    }
    $offset = (int)($data['city']['timezone'] ?? 0);
    $hourly = [];
    $days = [];
    foreach ($data['list'] as $item) {
        $hour = forecastInterval($item, $offset);
        $hourly[] = $hour;
        $days[$hour['date']][] = $hour;
    }
    $forecast = [];
    foreach ($days as $date => $hours) {
        $forecast[] = summarizeForecastDay($date, $hours);
    }
    $result = [
        'forecast' => $forecast,
        'hourly' => $hourly,
        'location_name' => $data['city']['name'] ?? 'Unknown',
        'timezone' => $offset,
        'fetched_at' => time()
    ];
    cacheWeather($db, $lat, $lon, 'forecast_v2', $result, 30);
    return $result;
}

function forecastInterval(array $item, int $offset): array
{
    $timestamp = $item['dt'];
    $local = $timestamp + $offset;
    $temperature = $item['main']['temp'];
    $humidity = $item['main']['humidity'];
    return [
        'time' => gmdate('H:i', $local),
        'time_12h' => gmdate('g:i A', $local),
        'timestamp' => $timestamp,
        'date' => gmdate('Y-m-d', $local),
        'day' => gmdate('D', $local),
        'temperature' => round($temperature),
        'feels_like' => round($item['main']['feels_like']),
        'temp_min' => round($item['main']['temp_min'] ?? $temperature),
        'temp_max' => round($item['main']['temp_max'] ?? $temperature),
        'condition' => $item['weather'][0]['main'],
        'description' => ucfirst($item['weather'][0]['description']),
        'icon' => $item['weather'][0]['icon'],
        'humidity' => $humidity,
        'pressure' => $item['main']['pressure'],
        'wind_speed' => round($item['wind']['speed'] * 3.6),
        'wind_direction' => $item['wind']['deg'],
        'wind_gust' => isset($item['wind']['gust']) ? round($item['wind']['gust'] * 3.6) : null,
        'precipitation' => isset($item['pop']) ? round($item['pop'] * 100) : null,
        'rain_mm' => round($item['rain']['3h'] ?? $item['rain']['1h'] ?? 0, 1),
        'snow_mm' => round($item['snow']['3h'] ?? $item['snow']['1h'] ?? 0, 1),
        'clouds' => $item['clouds']['all'],
        'visibility' => isset($item['visibility']) ? round($item['visibility'] / 1000, 1) : null,
        'dew_point' => round($temperature - ((100 - $humidity) / 5))
    ];
}

function summarizeForecastDay(string $date, array $hours): array
{
    $average = static fn(array $values): float => round(array_sum($values) / count($values));
    $daylight = array_values(array_filter($hours, static fn(array $hour): bool => str_ends_with($hour['icon'], 'd')));
    $representativeHours = $daylight ?: $hours;
    $conditions = array_count_values(array_column($representativeHours, 'condition'));
    arsort($conditions);
    $condition = array_key_first($conditions);
    $representative = array_values(array_filter($representativeHours, static fn(array $hour): bool => $hour['condition'] === $condition))[0];
    $humidity = array_column($hours, 'humidity');
    $wind = array_column($hours, 'wind_speed');
    $rain = array_filter(array_column($hours, 'precipitation'), static fn($chance): bool => $chance !== null);

    return [
        'date' => $date,
        'day_name' => gmdate('l', strtotime($date . ' UTC')),
        'temp_max' => max(array_column($hours, 'temp_max')),
        'temp_min' => min(array_column($hours, 'temp_min')),
        'temp_avg' => $average(array_column($hours, 'temperature')),
        'condition' => $condition,
        'description' => $representative['description'],
        'icon' => $representative['icon'],
        'humidity' => $average($humidity),
        'humidity_min' => min($humidity),
        'humidity_max' => max($humidity),
        'wind_speed' => $average($wind),
        'wind_max' => max($wind),
        'precipitation' => $rain ? max($rain) : null,
        'clouds' => $average(array_column($hours, 'clouds')),
        'pressure' => $average(array_column($hours, 'pressure'))
    ];
}

function getDetailedForecast(array $data, string $date): array
{
    foreach ($data['forecast'] as $day) {
        if ($day['date'] === $date) {
            return $day + [
                'dominant_condition' => $day['condition'],
                'hourly' => array_values(array_filter($data['hourly'], static fn(array $hour): bool => $hour['date'] === $date)),
                'summary' => $day['description'] . '. Peak precipitation chance: ' .
                    ($day['precipitation'] === null ? 'unavailable' : $day['precipitation'] . '%') . '.',
                'location_name' => $data['location_name']
            ];
        }
    }
    throw new OutOfBoundsException('No forecast is available for that date.');
}
