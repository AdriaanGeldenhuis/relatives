<?php
declare(strict_types=1);

/**
 * Rate limiter for location uploads
 * Prevents too-frequent uploads to save bandwidth and battery
 */
class RateLimiter
{
    private TrackingCache $cache;
    private int $minInterval;

    public function __construct(TrackingCache $cache, int $minIntervalSeconds = 5)
    {
        $this->cache = $cache;
        $this->minInterval = $minIntervalSeconds;
    }

    /**
     * Check if a location upload is allowed.
     * Returns ['allowed' => true] or ['allowed' => false, 'retry_after' => int]
     */
    public function check(int $userId): array
    {
        $lastTs = $this->cache->getRateLimit($userId);
        $now = time();

        if ($lastTs !== null) {
            $elapsed = $now - $lastTs;
            if ($elapsed < $this->minInterval) {
                return [
                    'allowed' => false,
                    'retry_after' => $this->minInterval - $elapsed,
                ];
            }
        }

        $this->cache->setRateLimit($userId, $now, $this->minInterval * 2);

        return ['allowed' => true];
    }
}
