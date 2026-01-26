<?php
/**
 * Rate Limiter
 *
 * Prevents too frequent location uploads.
 */

class RateLimiter
{
    private TrackingCache $cache;
    private SettingsRepo $settingsRepo;

    public function __construct(TrackingCache $cache, SettingsRepo $settingsRepo)
    {
        $this->cache = $cache;
        $this->settingsRepo = $settingsRepo;
    }

    /**
     * Check if request should be rate limited.
     *
     * @param int $userId
     * @param int $familyId
     * @return array ['allowed' => bool, 'reason' => string, 'retry_after' => int]
     */
    public function check(int $userId, int $familyId): array
    {
        $settings = $this->settingsRepo->get($familyId);
        $limitSeconds = $settings['rate_limit_seconds'];

        // No rate limiting configured
        if ($limitSeconds <= 0) {
            return ['allowed' => true];
        }

        $data = $this->cache->getRateLimit($userId);

        if ($data === null) {
            // First request, allow and set
            $this->cache->setRateLimit($userId, [
                'last_accepted_at' => time(),
                'count_in_window' => 1
            ]);
            return ['allowed' => true];
        }

        $lastAccepted = $data['last_accepted_at'] ?? 0;
        $elapsed = time() - $lastAccepted;

        if ($elapsed < $limitSeconds) {
            return [
                'allowed' => false,
                'reason' => 'rate_limited',
                'message' => "Too frequent. Try again in " . ($limitSeconds - $elapsed) . " seconds.",
                'retry_after' => $limitSeconds - $elapsed
            ];
        }

        // Enough time passed, allow and update
        $this->cache->setRateLimit($userId, [
            'last_accepted_at' => time(),
            'count_in_window' => ($data['count_in_window'] ?? 0) + 1
        ]);

        return ['allowed' => true];
    }

    /**
     * Record a successful request (if not using check()).
     */
    public function record(int $userId): void
    {
        $data = $this->cache->getRateLimit($userId);

        $this->cache->setRateLimit($userId, [
            'last_accepted_at' => time(),
            'count_in_window' => ($data['count_in_window'] ?? 0) + 1
        ]);
    }

    /**
     * Get current rate limit state for debugging.
     */
    public function getState(int $userId): array
    {
        $data = $this->cache->getRateLimit($userId);

        if (!$data) {
            return [
                'last_accepted_at' => null,
                'count_in_window' => 0,
                'seconds_since_last' => null
            ];
        }

        return [
            'last_accepted_at' => $data['last_accepted_at'],
            'count_in_window' => $data['count_in_window'],
            'seconds_since_last' => time() - $data['last_accepted_at']
        ];
    }
}
