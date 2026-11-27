<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
require_once __DIR__ . '/../../core/session_boot.php';

$action = $_GET['action'] ?? '';
if ($action !== 'welcome' && empty($_SESSION['user_id'])) {
    weatherResponse(['error' => 'Sign in to load weather.'], 401);
}

try {
    require_once __DIR__ . '/../../core/bootstrap.php';
} catch (Throwable $e) {
    error_log('Weather bootstrap failed: ' . $e->getMessage());
    weatherResponse(['error' => 'Weather is temporarily unavailable.'], 500);
}

require_once __DIR__ . '/../service.php';
require_once __DIR__ . '/../location.php';
require_once __DIR__ . '/../../core/CSRF.php';

try {
    if ($action === 'welcome') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            header('Allow: GET');
            weatherResponse(['error' => 'Method not allowed.'], 405);
        }
        // The public welcome page shares a single city and cannot query arbitrary places.
        session_write_close();
        $current = getCurrentWeather($db, WEATHER_DEFAULT_PLACE['lat'], WEATHER_DEFAULT_PLACE['lon']);
        header('Cache-Control: private, max-age=300');
        weatherResponse($current);
    }
    $user = (new Session($db))->validate();
    if (!$user) {
        unset($_SESSION['user_id']);
        weatherResponse(['error' => 'Sign in to load weather.'], 401);
    }
    if ($action === 'save-city') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            weatherResponse(['error' => 'Method not allowed.'], 405);
        }
        if (!CSRF::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            weatherResponse(['error' => 'Reload Weather and try again.'], 403);
        }
        session_write_close();
        $city = json_decode(file_get_contents('php://input', false, null, 0, 2048), true);
        $name = is_string($city['name'] ?? null) ? trim($city['name']) : '';
        if ($name === '' || mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Invalid city.');
        }
        $lat = weatherCoordinate($city['lat'] ?? null, 90);
        $lon = weatherCoordinate($city['lon'] ?? null, 180);
        $db->prepare('INSERT INTO weather_saved_cities (user_id, name, lat, lon) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), lat = VALUES(lat), lon = VALUES(lon)')
            ->execute([$user['id'], $name, $lat, $lon]);
        weatherResponse(['success' => true]);
    }
    session_write_close();
    if ($action === 'search') {
        weatherResponse(['results' => searchLocation($_GET['q'] ?? '')]);
    }
    if (!in_array($action, ['current', 'forecast', 'hourly', 'detailed'], true)) {
        throw new InvalidArgumentException('Invalid weather action.');
    }

    $lat = weatherCoordinate($_GET['lat'] ?? WEATHER_DEFAULT_PLACE['lat'], 90);
    $lon = weatherCoordinate($_GET['lon'] ?? WEATHER_DEFAULT_PLACE['lon'], 180);
    if ($action === 'current') {
        weatherResponse(getCurrentWeather($db, $lat, $lon));
    }
    $date = $_GET['date'] ?? null;
    if ($action === 'detailed' && $date !== null) {
        $parsedDate = is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid forecast date.');
        }
    }

    $data = getForecastData($db, $lat, $lon);
    if ($action === 'hourly') {
        weatherResponse(['hourly' => array_slice($data['hourly'], 0, 8), 'location_name' => $data['location_name']]);
    }
    if ($action === 'detailed') {
        $date ??= gmdate('Y-m-d', time() + $data['timezone']);
        weatherResponse(getDetailedForecast($data, $date));
    }
    if (($_GET['include'] ?? '') !== 'hourly') {
        unset($data['hourly']);
    }
    weatherResponse($data);
} catch (InvalidArgumentException $e) {
    weatherResponse(['error' => $e->getMessage()], 400);
} catch (OutOfBoundsException $e) {
    weatherResponse(['error' => $e->getMessage()], 404);
} catch (Throwable $e) {
    error_log('Weather API: ' . $e->getMessage());
    weatherResponse(['error' => 'Weather data is temporarily unavailable.'], 502);
}

function weatherResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
