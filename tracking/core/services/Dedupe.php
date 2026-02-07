<?php
declare(strict_types=1);

/**
 * Location deduplication
 * Skips near-duplicate GPS points to reduce noise and storage
 */
class Dedupe
{
    private TrackingCache $cache;
    private int $radiusM;
    private int $timeSeconds;

    public function __construct(TrackingCache $cache, int $radiusM = 10, int $timeSeconds = 60)
    {
        $this->cache = $cache;
        $this->radiusM = $radiusM;
        $this->timeSeconds = $timeSeconds;
    }

    /**
     * Check if a location is a duplicate of the last known point.
     * Returns true if it IS a duplicate (should be skipped).
     */
    public function isDuplicate(int $userId, float $lat, float $lng, string $recordedAt): bool
    {
        $last = $this->cache->getDedupePoint($userId);
        if ($last === null) {
            $this->remember($userId, $lat, $lng, $recordedAt);
            return false;
        }

        $distance = geo_haversineDistance(
            (float) $last['lat'],
            (float) $last['lng'],
            $lat,
            $lng
        );

        $timeDiff = abs(time() - Time::parse($last['ts']));

        if ($distance < $this->radiusM && $timeDiff < $this->timeSeconds) {
            return true;
        }

        $this->remember($userId, $lat, $lng, $recordedAt);
        return false;
    }

    private function remember(int $userId, float $lat, float $lng, string $ts): void
    {
        $this->cache->setDedupePoint($userId, [
            'lat' => $lat,
            'lng' => $lng,
            'ts' => $ts,
        ], $this->timeSeconds * 2);
    }
}
