<?php
/**
 * Cache Abstraction Layer - Memcached with MySQL fallback
 *
 * Priority:
 * 1. Memcached (if installed and available)
 * 2. MySQL cache table (fallback)
 *
 * Rules:
 * - Cache is NEVER source of truth
 * - Cache failure is silent
 * - Cache miss is normal
 * - Always fall back to database for data
 *
 * @version 2.0
 */

class Cache {
    private static ?Cache $instance = null;
    private ?Memcached $memcached = null;
    private ?PDO $db = null;
    private bool $memcachedAvailable = false;
    private string $cacheType = 'none';

    // Cache key prefixes for tracking
    const PREFIX_USER_LOCATION = 'track:family:%d:user:%d';

    // Default TTL: 1 hour
    const DEFAULT_TTL = 3600;

    private function __construct(?PDO $db = null) {
        $this->db = $db;
        $this->connectMemcached();

        // Determine active cache type
        if ($this->memcachedAvailable) {
            $this->cacheType = 'memcached';
        } elseif ($this->db !== null) {
            $this->cacheType = 'mysql';
        }
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(?PDO $db = null): Cache {
        if (self::$instance === null) {
            self::$instance = new Cache($db);
        }
        return self::$instance;
    }

    /**
     * Initialize with database connection (for MySQL fallback)
     */
    public static function init(PDO $db): Cache {
        return self::getInstance($db);
    }

    /**
     * Attempt to connect to Memcached
     */
    private function connectMemcached(): void {
        // Check if Memcached extension is loaded
        if (!class_exists('Memcached')) {
            error_log('Cache: Memcached extension not installed');
            return;
        }

        try {
            $this->memcached = new Memcached();

            // Get config from environment or use defaults
            $host = $_ENV['MEMCACHED_HOST'] ?? '127.0.0.1';
            $port = (int)($_ENV['MEMCACHED_PORT'] ?? 11211);

            // Add server
            $this->memcached->addServer($host, $port);

            // Test connection with a simple operation
            $testKey = 'cache:ping:' . time();
            $this->memcached->set($testKey, 'pong', 10);
            $result = $this->memcached->get($testKey);
            $this->memcached->delete($testKey);

            if ($result === 'pong') {
                $this->memcachedAvailable = true;
                error_log("Cache: Memcached connected to {$host}:{$port}");
            } else {
                error_log("Cache: Memcached connection test failed");
                $this->memcached = null;
            }
        } catch (Exception $e) {
            error_log('Cache: Memcached error - ' . $e->getMessage());
            $this->memcached = null;
            $this->memcachedAvailable = false;
        }
    }

    /**
     * Check if any cache is available
     */
    public function available(): bool {
        return $this->cacheType !== 'none';
    }

    /**
     * Get current cache type
     */
    public function getType(): string {
        return $this->cacheType;
    }

    /**
     * Get value from cache
     */
    public function get(string $key) {
        // Try Memcached first
        if ($this->memcachedAvailable) {
            try {
                $value = $this->memcached->get($key);
                if ($this->memcached->getResultCode() !== Memcached::RES_NOTFOUND) {
                    return $value;
                }
            } catch (Exception $e) {
                error_log("Cache: Memcached get error - " . $e->getMessage());
            }
        }

        // Fall back to MySQL
        if ($this->db !== null) {
            try {
                $stmt = $this->db->prepare("
                    SELECT cache_value FROM cache
                    WHERE cache_key = ? AND expires_at > NOW()
                    LIMIT 1
                ");
                $stmt->execute([$key]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result) {
                    return json_decode($result['cache_value'], true);
                }
            } catch (Exception $e) {
                // Silent fail - cache table might not exist
            }
        }

        return null;
    }

    /**
     * Set value in cache
     */
    public function set(string $key, $value, int $ttl = self::DEFAULT_TTL): bool {
        $success = false;

        // Try Memcached first
        if ($this->memcachedAvailable) {
            try {
                $success = $this->memcached->set($key, $value, $ttl);
            } catch (Exception $e) {
                error_log("Cache: Memcached set error - " . $e->getMessage());
            }
        }

        // Also write to MySQL (backup cache)
        if ($this->db !== null) {
            try {
                $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
                $jsonValue = is_string($value) ? $value : json_encode($value);

                // Upsert
                $stmt = $this->db->prepare("
                    INSERT INTO cache (cache_key, cache_value, expires_at)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), expires_at = VALUES(expires_at)
                ");
                $stmt->execute([$key, $jsonValue, $expiresAt]);
                $success = true;
            } catch (Exception $e) {
                // Silent fail - cache table might not exist
            }
        }

        return $success;
    }

    /**
     * Delete value from cache
     */
    public function delete(string $key): bool {
        $success = false;

        if ($this->memcachedAvailable) {
            try {
                $this->memcached->delete($key);
                $success = true;
            } catch (Exception $e) {
                // Silent fail
            }
        }

        if ($this->db !== null) {
            try {
                $stmt = $this->db->prepare("DELETE FROM cache WHERE cache_key = ?");
                $stmt->execute([$key]);
                $success = true;
            } catch (Exception $e) {
                // Silent fail
            }
        }

        return $success;
    }

    // =========================================================================
    // TRACKING-SPECIFIC METHODS
    // =========================================================================

    /**
     * Cache a user's current location
     */
    public function setUserLocation(int $familyId, int $userId, array $locationData): bool {
        $key = sprintf(self::PREFIX_USER_LOCATION, $familyId, $userId);

        // Consistent format as per recipe
        $data = [
            'lat' => (float)($locationData['lat'] ?? $locationData['latitude'] ?? 0),
            'lng' => (float)($locationData['lng'] ?? $locationData['longitude'] ?? 0),
            'speed' => (float)($locationData['speed'] ?? 0),
            'accuracy' => (float)($locationData['accuracy'] ?? 0),
            'heading' => isset($locationData['heading']) ? (float)$locationData['heading'] : null,
            'altitude' => isset($locationData['altitude']) ? (float)$locationData['altitude'] : null,
            'battery' => (int)($locationData['battery'] ?? 0),
            'moving' => (bool)($locationData['moving'] ?? $locationData['is_moving'] ?? false),
            'ts' => $locationData['ts'] ?? $locationData['timestamp'] ?? date('Y-m-d H:i:s')
        ];

        return $this->set($key, json_encode($data), self::DEFAULT_TTL);
    }

    /**
     * Get a user's cached location
     */
    public function getUserLocation(int $familyId, int $userId): ?array {
        $key = sprintf(self::PREFIX_USER_LOCATION, $familyId, $userId);
        $data = $this->get($key);

        if ($data === null) {
            return null;
        }

        // Handle both string and array (Memcached vs MySQL)
        if (is_string($data)) {
            return json_decode($data, true);
        }

        return $data;
    }

    /**
     * Get cached locations for multiple users
     * Returns only users that have cached data
     */
    public function getFamilyLocations(int $familyId, array $userIds): array {
        $locations = [];

        foreach ($userIds as $userId) {
            $location = $this->getUserLocation($familyId, $userId);
            if ($location !== null) {
                $locations[$userId] = $location;
            }
        }

        return $locations;
    }

    /**
     * Clear expired entries (MySQL only)
     */
    public function clearExpired(): bool {
        if ($this->db === null) {
            return false;
        }

        try {
            $stmt = $this->db->prepare("DELETE FROM cache WHERE expires_at < NOW()");
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
