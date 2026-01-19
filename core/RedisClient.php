<?php
declare(strict_types=1);

/**
 * ============================================
 * REDIS CLIENT - Optional Redis Support
 * ============================================
 *
 * If Redis is not available (common on shared hosting),
 * all methods return null/false gracefully and the app
 * falls back to MySQL queries.
 *
 * Environment variables:
 *   REDIS_HOST (default: 127.0.0.1)
 *   REDIS_PORT (default: 6379)
 *   REDIS_PASS (optional)
 *   REDIS_PREFIX (default: relatives:)
 */

class RedisClient {
    private static ?RedisClient $instance = null;
    private ?Redis $redis = null;
    private bool $connected = false;
    private string $prefix;

    // Cache TTLs (in seconds)
    public const TTL_LOCATION = 3600;      // 1 hour (matches stale threshold)
    public const TTL_FAMILY_LOCATIONS = 300; // 5 minutes for family aggregate
    public const TTL_USER_SETTINGS = 600;   // 10 minutes

    private function __construct() {
        $this->prefix = $_ENV['REDIS_PREFIX'] ?? 'relatives:';
        $this->connect();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): RedisClient {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get raw Redis connection (or null if unavailable)
     */
    public function getRedis(): ?Redis {
        return $this->redis;
    }

    /**
     * Check if Redis is available
     */
    public function isAvailable(): bool {
        return $this->connected;
    }

    /**
     * Connect to Redis server
     */
    private function connect(): void {
        // Check if Redis extension is loaded
        if (!extension_loaded('redis')) {
            error_log('RedisClient: Redis extension not loaded, using MySQL fallback');
            return;
        }

        try {
            $this->redis = new Redis();

            $host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
            $port = (int)($_ENV['REDIS_PORT'] ?? 6379);
            $timeout = 2.0; // 2 second timeout

            // Try to connect
            $connected = @$this->redis->connect($host, $port, $timeout);

            if (!$connected) {
                error_log("RedisClient: Could not connect to $host:$port");
                $this->redis = null;
                return;
            }

            // Auth if password is set
            $password = $_ENV['REDIS_PASS'] ?? null;
            if ($password) {
                if (!$this->redis->auth($password)) {
                    error_log('RedisClient: Authentication failed');
                    $this->redis = null;
                    return;
                }
            }

            // Test connection
            $this->redis->ping();

            $this->connected = true;
            error_log('RedisClient: Connected successfully');

        } catch (Exception $e) {
            error_log('RedisClient: Connection error - ' . $e->getMessage());
            $this->redis = null;
            $this->connected = false;
        }
    }

    /**
     * Set user's current location in cache
     */
    public function setUserLocation(int $familyId, int $userId, array $locationData): bool {
        if (!$this->connected) return false;

        try {
            $key = $this->prefix . "track:family:{$familyId}:user:{$userId}";
            $value = json_encode($locationData);

            return $this->redis->setex($key, self::TTL_LOCATION, $value);

        } catch (Exception $e) {
            error_log('RedisClient::setUserLocation error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user's current location from cache
     */
    public function getUserLocation(int $familyId, int $userId): ?array {
        if (!$this->connected) return null;

        try {
            $key = $this->prefix . "track:family:{$familyId}:user:{$userId}";
            $value = $this->redis->get($key);

            if ($value === false) return null;

            return json_decode($value, true);

        } catch (Exception $e) {
            error_log('RedisClient::getUserLocation error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all family members' locations from cache
     * Returns array of [userId => locationData] or null if any miss
     */
    public function getFamilyLocations(int $familyId, array $userIds): ?array {
        if (!$this->connected || empty($userIds)) return null;

        try {
            $keys = [];
            foreach ($userIds as $userId) {
                $keys[] = $this->prefix . "track:family:{$familyId}:user:{$userId}";
            }

            $values = $this->redis->mget($keys);

            // Check if all values are present
            $result = [];
            foreach ($userIds as $index => $userId) {
                if ($values[$index] === false) {
                    // Cache miss - return null to trigger MySQL fallback
                    return null;
                }
                $result[$userId] = json_decode($values[$index], true);
            }

            return $result;

        } catch (Exception $e) {
            error_log('RedisClient::getFamilyLocations error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete user's location from cache
     */
    public function deleteUserLocation(int $familyId, int $userId): bool {
        if (!$this->connected) return false;

        try {
            $key = $this->prefix . "track:family:{$familyId}:user:{$userId}";
            return $this->redis->del($key) > 0;

        } catch (Exception $e) {
            error_log('RedisClient::deleteUserLocation error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generic get
     */
    public function get(string $key): ?string {
        if (!$this->connected) return null;

        try {
            $value = $this->redis->get($this->prefix . $key);
            return $value === false ? null : $value;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Generic set with TTL
     */
    public function set(string $key, string $value, int $ttl = 3600): bool {
        if (!$this->connected) return false;

        try {
            return $this->redis->setex($this->prefix . $key, $ttl, $value);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Generic delete
     */
    public function delete(string $key): bool {
        if (!$this->connected) return false;

        try {
            return $this->redis->del($this->prefix . $key) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if key exists
     */
    public function exists(string $key): bool {
        if (!$this->connected) return false;

        try {
            return $this->redis->exists($this->prefix . $key) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Increment a counter
     */
    public function incr(string $key): int {
        if (!$this->connected) return 0;

        try {
            return $this->redis->incr($this->prefix . $key);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Set expiry on existing key
     */
    public function expire(string $key, int $ttl): bool {
        if (!$this->connected) return false;

        try {
            return $this->redis->expire($this->prefix . $key, $ttl);
        } catch (Exception $e) {
            return false;
        }
    }
}
