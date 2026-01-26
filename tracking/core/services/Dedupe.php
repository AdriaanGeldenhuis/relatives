<?php
/**
 * Dedupe Service
 *
 * Prevents storing near-identical location points.
 */

class Dedupe
{
    private TrackingCache $cache;
    private SettingsRepo $settingsRepo;

    public function __construct(TrackingCache $cache, SettingsRepo $settingsRepo)
    {
        $this->cache = $cache;
        $this->settingsRepo = $settingsRepo;
    }

    /**
     * Check if location is a duplicate.
     *
     * @param int $userId
     * @param int $familyId
     * @param array $location ['lat' => float, 'lng' => float, 'recorded_at' => string]
     * @return array ['is_duplicate' => bool, 'reason' => string]
     */
    public function check(int $userId, int $familyId, array $location): array
    {
        $settings = $this->settingsRepo->get($familyId);
        $radiusM = $settings['dedupe_radius_m'];
        $timeSeconds = $settings['dedupe_time_seconds'];

        // Dedupe disabled
        if ($radiusM <= 0 && $timeSeconds <= 0) {
            return ['is_duplicate' => false];
        }

        $lastPoint = $this->cache->getDedupe($userId);

        if ($lastPoint === null) {
            // First point, not a duplicate
            $this->recordPoint($userId, $familyId, $location);
            return ['is_duplicate' => false];
        }

        // Check time
        $lastTime = $lastPoint['recorded_at'] ?? 0;
        $currentTime = is_numeric($location['recorded_at'])
            ? $location['recorded_at']
            : strtotime($location['recorded_at']);

        $timeDelta = abs($currentTime - $lastTime);

        if ($timeSeconds > 0 && $timeDelta < $timeSeconds) {
            // Within time threshold, check distance
            $distance = $this->haversineDistance(
                $lastPoint['lat'], $lastPoint['lng'],
                $location['lat'], $location['lng']
            );

            if ($radiusM > 0 && $distance < $radiusM) {
                return [
                    'is_duplicate' => true,
                    'reason' => 'too_similar',
                    'distance_m' => round($distance, 1),
                    'time_delta_s' => $timeDelta
                ];
            }
        }

        // Not a duplicate, record this point
        $this->recordPoint($userId, $familyId, $location);
        return ['is_duplicate' => false];
    }

    /**
     * Record a point for future dedupe checks.
     */
    private function recordPoint(int $userId, int $familyId, array $location): void
    {
        $settings = $this->settingsRepo->get($familyId);
        $ttl = max(60, $settings['dedupe_time_seconds'] * 2);

        $recordedAt = is_numeric($location['recorded_at'])
            ? $location['recorded_at']
            : strtotime($location['recorded_at']);

        $this->cache->setDedupe($userId, [
            'lat' => $location['lat'],
            'lng' => $location['lng'],
            'recorded_at' => $recordedAt
        ], $ttl);
    }

    /**
     * Clear dedupe cache for a user (e.g., on settings change).
     */
    public function clear(int $userId): void
    {
        // The cache key will naturally expire
        // Could add explicit delete if needed
    }

    /**
     * Calculate Haversine distance.
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
