<?php
/**
 * Mapbox Directions Service
 *
 * Fetches routes between locations using Mapbox Directions API.
 */

class MapboxDirections
{
    private TrackingCache $cache;
    private ?string $apiKey;

    const API_URL = 'https://api.mapbox.com/directions/v5/mapbox';

    const PROFILE_DRIVING = 'driving';
    const PROFILE_WALKING = 'walking';
    const PROFILE_CYCLING = 'cycling';

    public function __construct(TrackingCache $cache)
    {
        $this->cache = $cache;
        // Try both env variable names for flexibility
        $this->apiKey = $_ENV['MAPBOX_API_KEY'] ?? $_ENV['MAPBOX_TOKEN'] ?? null;
    }

    /**
     * Get directions between two points.
     *
     * @param float $fromLat
     * @param float $fromLng
     * @param float $toLat
     * @param float $toLng
     * @param string $profile driving|walking|cycling
     * @return array|null Route data or null on error
     */
    public function getRoute(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        string $profile = self::PROFILE_DRIVING
    ): ?array {
        // Check API key
        if (!$this->apiKey) {
            return null;
        }

        // Validate profile
        if (!in_array($profile, [self::PROFILE_DRIVING, self::PROFILE_WALKING, self::PROFILE_CYCLING])) {
            $profile = self::PROFILE_DRIVING;
        }

        // Check cache
        $cached = $this->cache->getDirections($profile, $fromLat, $fromLng, $toLat, $toLng);
        if ($cached !== null) {
            return $cached;
        }

        // Build API request
        $coordinates = "{$fromLng},{$fromLat};{$toLng},{$toLat}";
        $url = self::API_URL . "/{$profile}/{$coordinates}";
        $params = [
            'access_token' => $this->apiKey,
            'geometries' => 'geojson',
            'overview' => 'full',
            'steps' => 'false' // Set to 'true' if you need turn-by-turn
        ];

        $fullUrl = $url . '?' . http_build_query($params);

        // Make request
        $response = $this->fetch($fullUrl);

        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);

        if (!isset($data['routes'][0])) {
            return null;
        }

        $route = $data['routes'][0];

        // Format result
        $result = [
            'profile' => $profile,
            'from' => ['lat' => $fromLat, 'lng' => $fromLng],
            'to' => ['lat' => $toLat, 'lng' => $toLng],
            'distance_m' => (int)$route['distance'],
            'duration_s' => (int)$route['duration'],
            'distance_text' => $this->formatDistance($route['distance']),
            'duration_text' => $this->formatDuration($route['duration']),
            'geometry' => $route['geometry'],
            'fetched_at' => Time::now()
        ];

        // Cache it
        $this->cache->setDirections($profile, $fromLat, $fromLng, $toLat, $toLng, $result);

        return $result;
    }

    /**
     * Get directions to a person's current location.
     *
     * @param float $fromLat Starting location lat
     * @param float $fromLng Starting location lng
     * @param array $toUser User array with lat/lng
     * @param string $profile
     * @return array|null
     */
    public function getRouteToPerson(
        float $fromLat,
        float $fromLng,
        array $toUser,
        string $profile = self::PROFILE_DRIVING
    ): ?array {
        if (!isset($toUser['lat'], $toUser['lng'])) {
            return null;
        }

        $route = $this->getRoute(
            $fromLat, $fromLng,
            (float)$toUser['lat'], (float)$toUser['lng'],
            $profile
        );

        if ($route) {
            $route['to_user'] = [
                'user_id' => $toUser['user_id'] ?? null,
                'name' => $toUser['name'] ?? 'Unknown'
            ];
        }

        return $route;
    }

    /**
     * Format distance for display.
     */
    private function formatDistance(float $meters): string
    {
        if ($meters < 1000) {
            return round($meters) . ' m';
        }

        $km = $meters / 1000;
        if ($km < 10) {
            return number_format($km, 1) . ' km';
        }

        return number_format($km, 0) . ' km';
    }

    /**
     * Format duration for display.
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return 'Less than 1 min';
        }

        $minutes = round($seconds / 60);

        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($mins === 0) {
            return $hours . ' hr';
        }

        return $hours . ' hr ' . $mins . ' min';
    }

    /**
     * Make HTTP request.
     */
    private function fetch(string $url): ?string
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Relatives-Tracking/2.0'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error || $httpCode !== 200) {
            error_log("MapboxDirections: API error - HTTP {$httpCode}, Error: {$error}");
            return null;
        }

        return $response;
    }

    /**
     * Check if API is available.
     */
    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }
}
