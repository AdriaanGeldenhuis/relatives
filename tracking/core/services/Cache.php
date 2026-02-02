<?php
/**
 * Tracking Cache Service
 *
 * Clean wrapper around the site's Cache class optimized for Memcached.
 * All keys prefixed with "trk:" for namespacing.
 *
 * Features:
 * - Direct Memcached integration (via core Cache)
 * - No double JSON encoding - data stored natively
 * - Proper TTL management
 * - Silent failure (cache miss is normal)
 *
 * @see /tracking/MEMCACHE.md for key documentation
 */

class TrackingCache
{
    private $cache;

    // Default TTLs (seconds)
    const TTL_CURRENT = 120;           // Current location - 2 min
    const TTL_FAMILY_SNAPSHOT = 10;    // Family locations - 10 sec (high freshness)
    const TTL_SETTINGS = 600;          // Settings - 10 min
    const TTL_RATE_LIMIT = 300;        // Rate limiter - 5 min
    const TTL_GEOFENCES = 600;         // Geofences - 10 min
    const TTL_GEO_STATE = 600;         // Geofence state - 10 min
    const TTL_PLACES = 600;            // Places - 10 min
    const TTL_DIRECTIONS = 21600;      // Directions - 6 hours
    const TTL_ALERTS = 600;            // Alert rules - 10 min

    public function __construct($cache)
    {
        $this->cache = $cache;
    }

    /**
     * Check if cache is available
     */
    public function available(): bool
    {
        if (!$this->cache) {
            return false;
        }
        return method_exists($this->cache, 'available') ? $this->cache->available() : true;
    }

    /**
     * Get cache type (for debugging)
     */
    public function getType(): string
    {
        if (!$this->cache) {
            return 'none';
        }
        return method_exists($this->cache, 'getType') ? $this->cache->getType() : 'unknown';
    }

    // =========================================
    // LIVE SESSION (Mode 1)
    // =========================================

    public function getLiveSession(int $familyId): ?array
    {
        return $this->getArray("trk:live:{$familyId}");
    }

    public function setLiveSession(int $familyId, array $data, int $ttl): bool
    {
        return $this->setArray("trk:live:{$familyId}", $data, $ttl);
    }

    public function deleteLiveSession(int $familyId): bool
    {
        return $this->delete("trk:live:{$familyId}");
    }

    // =========================================
    // CURRENT LOCATION
    // =========================================

    public function getCurrentLocation(int $userId): ?array
    {
        return $this->getArray("trk:cur:{$userId}");
    }

    public function setCurrentLocation(int $userId, array $data): bool
    {
        return $this->setArray("trk:cur:{$userId}", $data, self::TTL_CURRENT);
    }

    public function deleteCurrentLocation(int $userId): bool
    {
        return $this->delete("trk:cur:{$userId}");
    }

    // =========================================
    // FAMILY SNAPSHOT
    // =========================================

    public function getFamilySnapshot(int $familyId): ?array
    {
        return $this->getArray("trk:family_cur:{$familyId}");
    }

    public function setFamilySnapshot(int $familyId, array $data): bool
    {
        return $this->setArray("trk:family_cur:{$familyId}", $data, self::TTL_FAMILY_SNAPSHOT);
    }

    public function deleteFamilySnapshot(int $familyId): bool
    {
        return $this->delete("trk:family_cur:{$familyId}");
    }

    // =========================================
    // SETTINGS
    // =========================================

    public function getSettings(int $familyId): ?array
    {
        return $this->getArray("trk:settings:{$familyId}");
    }

    public function setSettings(int $familyId, array $data): bool
    {
        return $this->setArray("trk:settings:{$familyId}", $data, self::TTL_SETTINGS);
    }

    public function deleteSettings(int $familyId): bool
    {
        return $this->delete("trk:settings:{$familyId}");
    }

    // =========================================
    // RATE LIMITER
    // =========================================

    public function getRateLimit(int $userId): ?array
    {
        return $this->getArray("trk:rl:{$userId}");
    }

    public function setRateLimit(int $userId, array $data): bool
    {
        return $this->setArray("trk:rl:{$userId}", $data, self::TTL_RATE_LIMIT);
    }

    // =========================================
    // DEDUPE
    // =========================================

    public function getDedupe(int $userId): ?array
    {
        return $this->getArray("trk:dd:{$userId}");
    }

    public function setDedupe(int $userId, array $data, int $ttl): bool
    {
        return $this->setArray("trk:dd:{$userId}", $data, $ttl);
    }

    // =========================================
    // GEOFENCES
    // =========================================

    public function getGeofences(int $familyId): ?array
    {
        return $this->getArray("trk:geo:{$familyId}");
    }

    public function setGeofences(int $familyId, array $data): bool
    {
        return $this->setArray("trk:geo:{$familyId}", $data, self::TTL_GEOFENCES);
    }

    public function deleteGeofences(int $familyId): bool
    {
        return $this->delete("trk:geo:{$familyId}");
    }

    // =========================================
    // GEOFENCE STATE
    // =========================================

    public function getGeofenceState(int $userId): ?array
    {
        return $this->getArray("trk:geo_state:{$userId}");
    }

    public function setGeofenceState(int $userId, array $data): bool
    {
        return $this->setArray("trk:geo_state:{$userId}", $data, self::TTL_GEO_STATE);
    }

    public function deleteGeofenceState(int $userId): bool
    {
        return $this->delete("trk:geo_state:{$userId}");
    }

    // =========================================
    // PLACES
    // =========================================

    public function getPlaces(int $familyId): ?array
    {
        return $this->getArray("trk:places:{$familyId}");
    }

    public function setPlaces(int $familyId, array $data): bool
    {
        return $this->setArray("trk:places:{$familyId}", $data, self::TTL_PLACES);
    }

    public function deletePlaces(int $familyId): bool
    {
        return $this->delete("trk:places:{$familyId}");
    }

    // =========================================
    // DIRECTIONS
    // =========================================

    public function getDirections(string $profile, float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        $hash = $this->directionsHash($fromLat, $fromLng, $toLat, $toLng);
        return $this->getArray("trk:dir:{$profile}:{$hash}");
    }

    public function setDirections(string $profile, float $fromLat, float $fromLng, float $toLat, float $toLng, array $data): bool
    {
        $hash = $this->directionsHash($fromLat, $fromLng, $toLat, $toLng);
        return $this->setArray("trk:dir:{$profile}:{$hash}", $data, self::TTL_DIRECTIONS);
    }

    /**
     * Generate consistent hash for direction coordinates
     */
    private function directionsHash(float $fromLat, float $fromLng, float $toLat, float $toLng): string
    {
        // Round to 5 decimal places for consistent hashing (~1m precision)
        $coords = sprintf("%.5f,%.5f|%.5f,%.5f", $fromLat, $fromLng, $toLat, $toLng);
        return substr(md5($coords), 0, 16);
    }

    // =========================================
    // ALERT RULES
    // =========================================

    public function getAlertRules(int $familyId): ?array
    {
        return $this->getArray("trk:alerts:{$familyId}");
    }

    public function setAlertRules(int $familyId, array $data): bool
    {
        return $this->setArray("trk:alerts:{$familyId}", $data, self::TTL_ALERTS);
    }

    public function deleteAlertRules(int $familyId): bool
    {
        return $this->delete("trk:alerts:{$familyId}");
    }

    // =========================================
    // ALERT COOLDOWN
    // =========================================

    public function getAlertCooldown(int $familyId, string $rule, int $userId, int $targetId): ?int
    {
        $key = "trk:alerts_cd:{$familyId}:{$rule}:{$userId}:{$targetId}";
        $value = $this->get($key);
        return is_numeric($value) ? (int)$value : null;
    }

    public function setAlertCooldown(int $familyId, string $rule, int $userId, int $targetId, int $cooldownSeconds): bool
    {
        $key = "trk:alerts_cd:{$familyId}:{$rule}:{$userId}:{$targetId}";
        return $this->set($key, time(), $cooldownSeconds);
    }

    // =========================================
    // LOW-LEVEL METHODS
    // =========================================

    /**
     * Get raw value from cache
     */
    private function get(string $key)
    {
        if (!$this->cache) {
            return null;
        }

        try {
            return $this->cache->get($key);
        } catch (\Exception $e) {
            error_log("TrackingCache get error [{$key}]: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get array value from cache (handles JSON decoding if needed)
     */
    private function getArray(string $key): ?array
    {
        $value = $this->get($key);

        if ($value === null || $value === false) {
            return null;
        }

        // If already an array (Memcached stores PHP types natively)
        if (is_array($value)) {
            return $value;
        }

        // If string (MySQL fallback stores JSON)
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Set raw value in cache
     */
    private function set(string $key, $value, int $ttl): bool
    {
        if (!$this->cache) {
            return false;
        }

        try {
            return (bool)$this->cache->set($key, $value, $ttl);
        } catch (\Exception $e) {
            error_log("TrackingCache set error [{$key}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Set array value in cache
     * Stores as JSON string for compatibility with MySQL fallback
     */
    private function setArray(string $key, array $value, int $ttl): bool
    {
        if (!$this->cache) {
            return false;
        }

        try {
            // Store as JSON for compatibility (Memcached can store arrays natively,
            // but MySQL fallback needs JSON)
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                error_log("TrackingCache JSON encode error [{$key}]: " . json_last_error_msg());
                return false;
            }
            return (bool)$this->cache->set($key, $json, $ttl);
        } catch (\Exception $e) {
            error_log("TrackingCache setArray error [{$key}]: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete value from cache
     */
    private function delete(string $key): bool
    {
        if (!$this->cache) {
            return false;
        }

        try {
            return (bool)$this->cache->delete($key);
        } catch (\Exception $e) {
            error_log("TrackingCache delete error [{$key}]: " . $e->getMessage());
            return false;
        }
    }

    // =========================================
    // UTILITY METHODS
    // =========================================

    /**
     * Invalidate all caches for a family
     * Useful when settings change significantly
     */
    public function invalidateFamily(int $familyId): void
    {
        $this->deleteSettings($familyId);
        $this->deleteGeofences($familyId);
        $this->deletePlaces($familyId);
        $this->deleteAlertRules($familyId);
        $this->deleteFamilySnapshot($familyId);
        $this->deleteLiveSession($familyId);
    }

    /**
     * Invalidate all caches for a user
     */
    public function invalidateUser(int $userId): void
    {
        $this->deleteCurrentLocation($userId);
        $this->deleteGeofenceState($userId);
    }
}
