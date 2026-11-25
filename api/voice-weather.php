<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../weather/location.php';
require_once __DIR__ . '/../weather/service.php';

header('Cache-Control: private, no-store');
$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    Response::error('Sign in to load weather.', 401);
}
session_write_close();
try {
    $place = weatherLocationForUser($db, (int) $user['id'], (int) $user['family_id']);
    $forecast = getForecastData($db, $place['lat'], $place['lon']);
    $hour = $forecast['hourly'][0] ?? null;
    if (!$hour) {
        throw new RuntimeException('No weather forecast');
    }
    $name = $place['name'] ?? $forecast['location_name'];
    $spoken = sprintf('%s in %s is %s and %s degrees Celsius.',
        empty($forecast['stale']) ? 'The forecast' : 'The last available forecast',
        $name, strtolower($hour['description']), round($hour['temperature']));
    Response::json(['success' => true, 'spoken_response' => $spoken, 'data' => [
        'temp' => $hour['temperature'], 'feels_like' => $hour['feels_like'] ?? null,
        'description' => $hour['description'], 'humidity' => $hour['humidity'] ?? null,
        'wind_speed' => $hour['wind_speed'] ?? null, 'city' => $name,
        'stale' => !empty($forecast['stale']),
    ]]);
} catch (Throwable) {
    Response::error('Weather is temporarily unavailable.', 503);
}
