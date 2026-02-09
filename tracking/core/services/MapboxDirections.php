<?php
declare(strict_types=1);

/**
 * Mapbox Directions API integration
 * Fetches routes and caches results for 6 hours
 */
class MapboxDirections
{
    private TrackingCache $cache;
    private string $token;

    public function __construct(TrackingCache $cache)
    {
        $this->cache = $cache;
        $this->token = $_ENV['MAPBOX_TOKEN'] ?? '';
    }

    /**
     * Get directions between two points.
     *
     * @param float  $fromLat
     * @param float  $fromLng
     * @param float  $toLat
     * @param float  $toLng
     * @param string $profile driving|walking|cycling
     * @return array|null
     */
    public function getRoute(float $fromLat, float $fromLng, float $toLat, float $toLng, string $profile = 'driving'): ?array
    {
        if (empty($this->token)) {
            return null;
        }

        $validProfiles = ['driving', 'walking', 'cycling', 'driving-traffic'];
        if (!in_array($profile, $validProfiles, true)) {
            $profile = 'driving';
        }

        // Check cache
        $hash = md5("{$fromLat},{$fromLng},{$toLat},{$toLng}");
        $cached = $this->cache->getDirections($profile, $hash);
        if ($cached !== null) {
            return $cached;
        }

        // Call Mapbox API
        $coords = "{$fromLng},{$fromLat};{$toLng},{$toLat}";
        $url = "https://api.mapbox.com/directions/v5/mapbox/{$profile}/{$coords}"
            . "?access_token=" . urlencode($this->token)
            . "&geometries=geojson&overview=full&steps=false";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            error_log("MapboxDirections: cURL error: {$curlError}");
            return null;
        }

        if ($httpCode !== 200 || !$response) {
            error_log("MapboxDirections: HTTP {$httpCode} for {$profile} route. Response: " . substr((string)$response, 0, 500));
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data['routes'][0])) {
            $msg = $data['message'] ?? $data['code'] ?? 'no routes returned';
            error_log("MapboxDirections: {$msg}");
            return null;
        }

        $route = $data['routes'][0];
        $result = [
            'distance_m' => (float) $route['distance'],
            'duration_s' => (float) $route['duration'],
            'geometry' => $route['geometry'],
            'profile' => $profile,
        ];

        $this->cache->setDirections($profile, $hash, $result);

        return $result;
    }
}
