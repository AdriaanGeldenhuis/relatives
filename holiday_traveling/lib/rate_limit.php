<?php
/**
 * Holiday Traveling - Rate Limiting Helper
 * Generic rate limiting for API endpoints
 */
declare(strict_types=1);

class HT_RateLimit {
    /**
     * Check if action is within rate limit
     *
     * @param string $action Action identifier (e.g., 'ai_generate', 'api_call')
     * @param int $maxRequests Maximum requests allowed
     * @param int $windowSeconds Time window in seconds
     * @param int|null $userId User ID (null = use session user)
     * @return bool True if within limit
     */
    public static function check(string $action, int $maxRequests, int $windowSeconds, ?int $userId = null): bool {
        $userId = $userId ?? HT_Auth::userId();
        if (!$userId) {
            return false;
        }

        $key = self::getKey($action, $userId);
        $now = time();

        // Use simple in-memory + DB tracking
        $record = self::getRecord($key);

        if (!$record) {
            return true; // No record = no requests yet
        }

        // Check if window has expired
        if ($now - $record['window_start'] > $windowSeconds) {
            return true; // Window expired, allow
        }

        return $record['count'] < $maxRequests;
    }

    /**
     * Increment rate limit counter
     */
    public static function increment(string $action, int $windowSeconds, ?int $userId = null): void {
        $userId = $userId ?? HT_Auth::userId();
        if (!$userId) {
            return;
        }

        $key = self::getKey($action, $userId);
        $now = time();

        $record = self::getRecord($key);

        if (!$record || ($now - $record['window_start']) > $windowSeconds) {
            // Start new window
            self::setRecord($key, [
                'count' => 1,
                'window_start' => $now
            ]);
        } else {
            // Increment existing
            self::setRecord($key, [
                'count' => $record['count'] + 1,
                'window_start' => $record['window_start']
            ]);
        }
    }

    /**
     * Check and increment in one call
     * Returns true if allowed, false if rate limited
     */
    public static function attempt(string $action, int $maxRequests, int $windowSeconds, ?int $userId = null): bool {
        if (!self::check($action, $maxRequests, $windowSeconds, $userId)) {
            return false;
        }

        self::increment($action, $windowSeconds, $userId);
        return true;
    }

    /**
     * Get remaining requests
     */
    public static function remaining(string $action, int $maxRequests, int $windowSeconds, ?int $userId = null): int {
        $userId = $userId ?? HT_Auth::userId();
        if (!$userId) {
            return 0;
        }

        $key = self::getKey($action, $userId);
        $now = time();

        $record = self::getRecord($key);

        if (!$record || ($now - $record['window_start']) > $windowSeconds) {
            return $maxRequests;
        }

        return max(0, $maxRequests - $record['count']);
    }

    /**
     * Get time until rate limit resets
     */
    public static function resetIn(string $action, int $windowSeconds, ?int $userId = null): int {
        $userId = $userId ?? HT_Auth::userId();
        if (!$userId) {
            return 0;
        }

        $key = self::getKey($action, $userId);
        $now = time();

        $record = self::getRecord($key);

        if (!$record) {
            return 0;
        }

        $windowEnd = $record['window_start'] + $windowSeconds;
        return max(0, $windowEnd - $now);
    }

    /**
     * Reset rate limit for action
     */
    public static function reset(string $action, ?int $userId = null): void {
        $userId = $userId ?? HT_Auth::userId();
        if (!$userId) {
            return;
        }

        $key = self::getKey($action, $userId);
        self::deleteRecord($key);
    }

    /**
     * Generate cache key
     */
    private static function getKey(string $action, int $userId): string {
        return "ht_ratelimit:{$action}:{$userId}";
    }

    /**
     * Get rate limit record (uses session for simplicity)
     */
    private static function getRecord(string $key): ?array {
        return $_SESSION[$key] ?? null;
    }

    /**
     * Set rate limit record
     */
    private static function setRecord(string $key, array $data): void {
        $_SESSION[$key] = $data;
    }

    /**
     * Delete rate limit record
     */
    private static function deleteRecord(string $key): void {
        unset($_SESSION[$key]);
    }
}
