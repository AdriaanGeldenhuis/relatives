<?php
/**
 * Tracking Cache Service
 *
 * Wraps the site's Cache class with tracking-specific key patterns.
 * All keys prefixed with "trk:" for namespacing.
 *
 * @see /tracking/MEMCACHE.md for key documentation
 */

class TrackingCache
{
    private $cache;

    // Default TTLs (seconds)
    const TTL_CURRENT = 120;
    const TTL_FAMILY_SNAPSHOT = 10;
    const TTL_SETTINGS = 600;
    const TTL_RATE_LIMIT = 300;
    const TTL_GEOFENCES = 600;
    const TTL_GEO_STATE = 600;
    const TTL_PLACES = 600;
    const TTL_DIRECTIONS = 21600;
    const TTL_ALERTS = 600;

    public function __construct($cache)
    {
        $this->cache = $cache;
    }

    /**
     * Check if cache is available
     */
    public function available(): bool
    {
        return $this->cache && method_exists($this->cache, 'available')
            ? $this->cache->available()
            : false;
    }

    // =========================================
    // LIVE SESSION (Mode 1)
    // =========================================

    public function getLiveSession(int $familyId): ?array
    {
        return $this->get("trk:live:{$familyId}");
    }

    public function setLiveSession(int $familyId, array $data, int $ttl): bool
    {
        return $this->set("trk:live:{$familyId}", $data, $ttl);
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
        return $this->get("trk:cur:{$userId}");
    }

    public function setCurrentLocation(int $userId, array $data): bool
    {
        return $this->set("trk:cur:{$userId}", $data, self::TTL_CURRENT);
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
        return $this->get("trk:family_cur:{$familyId}");
    }

    public function setFamilySnapshot(int $familyId, array $data): bool
    {
        return $this->set("trk:family_cur:{$familyId}", $data, self::TTL_FAMILY_SNAPSHOT);
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
        return $this->get("trk:settings:{$familyId}");
    }

    public function setSettings(int $familyId, array $data): bool
    {
        return $this->set("trk:settings:{$familyId}", $data, self::TTL_SETTINGS);
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
        return $this->get("trk:rl:{$userId}");
    }

    public function setRateLimit(int $userId, array $data): bool
    {
        return $this->set("trk:rl:{$userId}", $data, self::TTL_RATE_LIMIT);
    }

    // =========================================
    // DEDUPE
    // =========================================

    public function getDedupe(int $userId): ?array
    {
        return $this->get("trk:dd:{$userId}");
    }

    public function setDedupe(int $userId, array $data, int $ttl): bool
    {
        return $this->set("trk:dd:{$userId}", $data, $ttl);
    }

    // =========================================
    // GEOFENCES
    // =========================================

    public function getGeofences(int $familyId): ?array
    {
        return $this->get("trk:geo:{$familyId}");
    }

    public function setGeofences(int $familyId, array $data): bool
    {
        return $this->set("trk:geo:{$familyId}", $data, self::TTL_GEOFENCES);
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
        return $this->get("trk:geo_state:{$userId}");
    }

    public function setGeofenceState(int $userId, array $data): bool
    {
        return $this->set("trk:geo_state:{$userId}", $data, self::TTL_GEO_STATE);
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
        return $this->get("trk:places:{$familyId}");
    }

    public function setPlaces(int $familyId, array $data): bool
    {
        return $this->set("trk:places:{$familyId}", $data, self::TTL_PLACES);
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
        $hash = md5("{$fromLat},{$fromLng}|{$toLat},{$toLng}");
        return $this->get("trk:dir:{$profile}:{$hash}");
    }

    public function setDirections(string $profile, float $fromLat, float $fromLng, float $toLat, float $toLng, array $data): bool
    {
        $hash = md5("{$fromLat},{$fromLng}|{$toLat},{$toLng}");
        return $this->set("trk:dir:{$profile}:{$hash}", $data, self::TTL_DIRECTIONS);
    }

    // =========================================
    // ALERT RULES
    // =========================================

    public function getAlertRules(int $familyId): ?array
    {
        return $this->get("trk:alerts:{$familyId}");
    }

    public function setAlertRules(int $familyId, array $data): bool
    {
        return $this->set("trk:alerts:{$familyId}", $data, self::TTL_ALERTS);
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
        return $this->get("trk:alerts_cd:{$familyId}:{$rule}:{$userId}:{$targetId}");
    }

    public function setAlertCooldown(int $familyId, string $rule, int $userId, int $targetId, int $cooldownSeconds): bool
    {
        $key = "trk:alerts_cd:{$familyId}:{$rule}:{$userId}:{$targetId}";
        return $this->set($key, time(), $cooldownSeconds);
    }

    // =========================================
    // LOW-LEVEL METHODS
    // =========================================

    private function get(string $key)
    {
        if (!$this->cache) {
            return null;
        }

        $value = $this->cache->get($key);

        // Decode JSON if stored as string
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return $decoded !== null ? $decoded : $value;
        }

        return $value;
    }

    private function set(string $key, $value, int $ttl): bool
    {
        if (!$this->cache) {
            return false;
        }

        // Encode arrays/objects as JSON
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        }

        return $this->cache->set($key, $value, $ttl);
    }

    private function delete(string $key): bool
    {
        if (!$this->cache) {
            return false;
        }

        return $this->cache->delete($key);
    }
}
