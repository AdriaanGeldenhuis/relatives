<?php
declare(strict_types=1);

class RateLimiter {
    private TrackingCache $cache;

    public function __construct(TrackingCache $cache) {
        $this->cache = $cache;
    }

    /**
     * Check if action is rate limited
     * @return bool true if allowed, false if rate limited
     */
    public function allow(string $action, int $userId, int $maxPerMinute = 10): bool {
        $key = $this->cache->rateKey($action, $userId);
        $current = $this->cache->get($key);

        if ($current === null) {
            $this->cache->set($key, 1, 60);
            return true;
        }

        if ((int)$current >= $maxPerMinute) {
            return false;
        }

        $this->cache->set($key, (int)$current + 1, 60);
        return true;
    }
}
