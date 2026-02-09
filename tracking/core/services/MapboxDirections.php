<?php
declare(strict_types=1);

/**
 * Directions service — road-following routes with turn-by-turn steps
 * Tries Mapbox Directions API first, falls back to OSRM (free)
 * Caches successful results via TrackingCache
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
     * Get directions between two points with turn-by-turn steps.
     * Tries Mapbox first, falls back to OSRM if Mapbox fails.
     */
    public function getRoute(float $fromLat, float $fromLng, float $toLat, float $toLng, string $profile = 'driving'): ?array
    {
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

        // Try Mapbox first
        $result = null;
        if (!empty($this->token)) {
            $result = $this->fetchMapbox($fromLat, $fromLng, $toLat, $toLng, $profile);
        }

        // Fall back to OSRM
        if ($result === null) {
            $osrmProfile = in_array($profile, ['driving', 'driving-traffic']) ? 'car' : ($profile === 'cycling' ? 'bike' : 'foot');
            $result = $this->fetchOSRM($fromLat, $fromLng, $toLat, $toLng, $osrmProfile, $profile);
        }

        if ($result !== null) {
            $this->cache->setDirections($profile, $hash, $result);
        }

        return $result;
    }

    private function fetchMapbox(float $fromLat, float $fromLng, float $toLat, float $toLng, string $profile): ?array
    {
        $coords = "{$fromLng},{$fromLat};{$toLng},{$toLat}";
        $url = "https://api.mapbox.com/directions/v5/mapbox/{$profile}/{$coords}"
            . "?access_token=" . urlencode($this->token)
            . "&geometries=geojson&overview=full&steps=true";

        $data = $this->curlGet($url, 'Mapbox');
        if ($data === null || empty($data['routes'][0])) {
            if ($data !== null) {
                $msg = $data['message'] ?? $data['code'] ?? 'no routes returned';
                error_log("MapboxDirections: {$msg}");
            }
            return null;
        }

        $route = $data['routes'][0];
        $steps = [];
        if (!empty($route['legs'])) {
            foreach ($route['legs'] as $leg) {
                foreach ($leg['steps'] ?? [] as $s) {
                    $m = $s['maneuver'] ?? [];
                    $steps[] = [
                        'instruction' => $m['instruction'] ?? '',
                        'distance_m'  => (float) ($s['distance'] ?? 0),
                        'duration_s'  => (float) ($s['duration'] ?? 0),
                        'maneuver'    => ($m['type'] ?? '') . (isset($m['modifier']) ? '-' . $m['modifier'] : ''),
                        'name'        => $s['name'] ?? '',
                        'location'    => $m['location'] ?? null,
                    ];
                }
            }
        }

        return [
            'distance_m' => (float) $route['distance'],
            'duration_s' => (float) $route['duration'],
            'geometry'   => $route['geometry'],
            'profile'    => $profile,
            'steps'      => $steps,
        ];
    }

    private function fetchOSRM(float $fromLat, float $fromLng, float $toLat, float $toLng, string $osrmProfile, string $originalProfile): ?array
    {
        $coords = "{$fromLng},{$fromLat};{$toLng},{$toLat}";
        $url = "https://router.project-osrm.org/route/v1/{$osrmProfile}/{$coords}"
            . "?overview=full&geometries=geojson&steps=true";

        $data = $this->curlGet($url, 'OSRM');
        if ($data === null || ($data['code'] ?? '') !== 'Ok' || empty($data['routes'][0])) {
            if ($data !== null) {
                error_log("OSRM: " . ($data['message'] ?? $data['code'] ?? 'no routes'));
            }
            return null;
        }

        $route = $data['routes'][0];
        $steps = [];
        if (!empty($route['legs'])) {
            foreach ($route['legs'] as $leg) {
                foreach ($leg['steps'] ?? [] as $s) {
                    $m = $s['maneuver'] ?? [];
                    $type = $m['type'] ?? '';
                    $modifier = $m['modifier'] ?? '';
                    $name = $s['name'] ?? '';

                    $steps[] = [
                        'instruction' => self::buildOSRMInstruction($type, $modifier, $name),
                        'distance_m'  => (float) ($s['distance'] ?? 0),
                        'duration_s'  => (float) ($s['duration'] ?? 0),
                        'maneuver'    => $type . ($modifier ? '-' . $modifier : ''),
                        'name'        => $name,
                        'location'    => $m['location'] ?? null,
                    ];
                }
            }
        }

        return [
            'distance_m' => (float) $route['distance'],
            'duration_s' => (float) $route['duration'],
            'geometry'   => $route['geometry'],
            'profile'    => $originalProfile,
            'steps'      => $steps,
        ];
    }

    private static function buildOSRMInstruction(string $type, string $modifier, string $name): string
    {
        $road = $name !== '' ? " onto {$name}" : '';

        switch ($type) {
            case 'depart':
                return "Head" . ($modifier ? ' ' . $modifier : '') . $road;
            case 'arrive':
                return "Arrive at destination" . ($modifier === 'left' ? ' on the left' : ($modifier === 'right' ? ' on the right' : ''));
            case 'turn':
                return "Turn " . ($modifier ?: 'ahead') . $road;
            case 'new name':
            case 'continue':
                return "Continue" . $road;
            case 'merge':
                return "Merge" . ($modifier ? ' ' . $modifier : '') . $road;
            case 'on ramp':
            case 'ramp':
                return "Take the ramp" . ($modifier ? ' on the ' . $modifier : '') . $road;
            case 'off ramp':
                return "Take the exit" . $road;
            case 'fork':
                return "Keep " . ($modifier ?: 'ahead') . $road;
            case 'end of road':
                return "Turn " . ($modifier ?: 'ahead') . $road;
            case 'roundabout':
            case 'rotary':
                return "Enter the roundabout and exit" . $road;
            case 'exit roundabout':
            case 'exit rotary':
                return "Exit the roundabout" . $road;
            default:
                return ($modifier ? ucfirst($modifier) : 'Continue') . $road;
        }
    }

    private function curlGet(string $url, string $label): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: FamilyTracker/1.0'],
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            error_log("{$label}: cURL error: {$curlError}");
            return null;
        }

        if ($httpCode !== 200 || !$response) {
            error_log("{$label}: HTTP {$httpCode}. Response: " . substr((string)$response, 0, 500));
            return null;
        }

        return json_decode($response, true);
    }
}
