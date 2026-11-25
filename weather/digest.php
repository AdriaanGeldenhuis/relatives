<?php
declare(strict_types=1);

require_once __DIR__ . '/service.php';
require_once __DIR__ . '/location.php';
require_once __DIR__ . '/../core/NotificationManager.php';

function weatherDigest(PDO $db, array $user, bool $forecast, ?string $expiresAt = null): array
{
    $place = weatherLocationForUser($db, (int) $user['user_id'], (int) $user['family_id']);
    $current = getCurrentWeather($db, $place['lat'], $place['lon']);
    $name = $place['name'] ?? $current['location_name'];
    $label = !empty($current['stale']) ? 'Last available weather' : 'Weather';
    $body = sprintf('%s · %s°C, %s', $name, $current['temperature'], $current['description']);
    if ($forecast) {
        try {
            $period = getForecastData($db, $place['lat'], $place['lon']);
            $day = $period['forecast'][0] ?? null;
            if ($day && empty($period['stale'])) {
                $body .= sprintf('. High %s°C, low %s°C.', $day['temp_max'], $day['temp_min']);
            }
        } catch (RuntimeException) {
            // Current conditions can still be delivered if the forecast is unavailable.
        }
    }
    return [
        'user_id' => $user['user_id'], 'type' => 'weather',
        'title' => $label, 'message' => $body, 'expires_at' => $expiresAt,
        'action_url' => '/weather/',
        'data' => ['weather_digest' => 1, 'location' => $name],
    ];
}
