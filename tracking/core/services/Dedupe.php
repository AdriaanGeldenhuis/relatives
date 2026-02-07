<?php
declare(strict_types=1);

class Dedupe {
    private TrackingCache $cache;

    public function __construct(TrackingCache $cache) {
        $this->cache = $cache;
    }

    /**
     * Check if a location update is a duplicate (too close to last known position)
     * @return bool true if duplicate (should skip), false if new enough
     */
    public function isDuplicate(int $userId, float $lat, float $lng, float $minDistanceM = 10.0): bool {
        $key = $this->cache->dedupeKey($userId);
        $last = $this->cache->get($key);

        if ($last === null) {
            $this->cache->set($key, json_encode(['lat' => $lat, 'lng' => $lng, 'ts' => time()]), 300);
            return false;
        }

        $lastData = is_string($last) ? json_decode($last, true) : $last;
        if (!$lastData || !isset($lastData['lat'], $lastData['lng'])) {
            $this->cache->set($key, json_encode(['lat' => $lat, 'lng' => $lng, 'ts' => time()]), 300);
            return false;
        }

        $distance = geo_haversineDistance((float)$lastData['lat'], (float)$lastData['lng'], $lat, $lng);

        if ($distance < $minDistanceM) {
            return true; // Duplicate - too close
        }

        // Not a duplicate - update cache
        $this->cache->set($key, json_encode(['lat' => $lat, 'lng' => $lng, 'ts' => time()]), 300);
        return false;
    }
}
