<?php
declare(strict_types=1);

class MapboxDirections {
    private string $token;

    public function __construct(?string $token = null) {
        $this->token = $token ?? ($_ENV['MAPBOX_TOKEN'] ?? '');
    }

    /**
     * Get directions between two points
     */
    public function getRoute(float $fromLat, float $fromLng, float $toLat, float $toLng, string $profile = 'driving'): ?array {
        if (empty($this->token)) {
            return null;
        }

        $validProfiles = ['driving', 'walking', 'cycling', 'driving-traffic'];
        if (!in_array($profile, $validProfiles)) {
            $profile = 'driving';
        }

        $url = sprintf(
            'https://api.mapbox.com/directions/v5/mapbox/%s/%f,%f;%f,%f?geometries=geojson&overview=full&steps=true&access_token=%s',
            $profile,
            $fromLng, $fromLat,
            $toLng, $toLat,
            $this->token
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("MapboxDirections error: HTTP {$httpCode}");
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || empty($data['routes'])) {
            return null;
        }

        $route = $data['routes'][0];
        return [
            'distance_m' => (float)$route['distance'],
            'duration_s' => (float)$route['duration'],
            'geometry' => $route['geometry'],
            'steps' => array_map(function($leg) {
                return array_map(function($step) {
                    return [
                        'instruction' => $step['maneuver']['instruction'] ?? '',
                        'distance_m' => $step['distance'],
                        'duration_s' => $step['duration'],
                    ];
                }, $leg['steps'] ?? []);
            }, $route['legs'] ?? []),
        ];
    }
}
