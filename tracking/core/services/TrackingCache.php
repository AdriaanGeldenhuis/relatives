<?php
declare(strict_types=1);

/**
 * Tracking-specific cache wrapper
 * Delegates to core Cache with tracking key prefixes
 *
 * Cache keys (see MEMCACHE.md):
 *   trk:live:{familyId}          - 300s  Live session state
 *   trk:cur:{userId}             - 120s  Current location
 *   trk:family_cur:{familyId}    - 10s   Family snapshot
 *   trk:settings:{familyId}      - 600s  Settings
 *   trk:rl:{userId}              - 300s  Rate limiter state
 *   trk:dd:{userId}              - var   Dedupe last point
 *   trk:geo:{familyId}           - 600s  Geofences list
 *   trk:geo_state:{userId}       - 600s  Geofence states
 *   trk:places:{familyId}        - 600s  Places list
 *   trk:dir:{profile}:{hash}     - 21600s Directions
 *   trk:alerts:{familyId}        - 600s  Alert rules
 *   trk:alerts_cd:{fam}:{rule}:{uid}:{tid} - cooldown  Alert cooldown
 */
class TrackingCache
{
    private Cache $cache;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    // --- Live Session ---

    public function getSession(int $familyId): ?array
    {
        return $this->decode($this->cache->get("trk:live:{$familyId}"));
    }

    public function setSession(int $familyId, array $data, int $ttl = 300): bool
    {
        return $this->cache->set("trk:live:{$familyId}", json_encode($data), $ttl);
    }

    public function deleteSession(int $familyId): bool
    {
        return $this->cache->delete("trk:live:{$familyId}");
    }

    // --- Current Location ---

    public function getCurrent(int $userId): ?array
    {
        return $this->decode($this->cache->get("trk:cur:{$userId}"));
    }

    public function setCurrent(int $userId, array $data): bool
    {
        return $this->cache->set("trk:cur:{$userId}", json_encode($data), 120);
    }

    // --- Family Snapshot ---

    public function getFamilySnapshot(int $familyId): ?array
    {
        return $this->decode($this->cache->get("trk:family_cur:{$familyId}"));
    }

    public function setFamilySnapshot(int $familyId, array $data): bool
    {
        return $this->cache->set("trk:family_cur:{$familyId}", json_encode($data), 10);
    }

    public function deleteFamilySnapshot(int $familyId): bool
    {
        return $this->cache->delete("trk:family_cur:{$familyId}");
    }

    // --- Settings ---

    public function getSettings(int $familyId): ?array
    {
        return $this->decode($this->cache->get("trk:settings:{$familyId}"));
    }

    public function setSettings(int $familyId, array $data): bool
    {
        return $this->cache->set("trk:settings:{$familyId}", json_encode($data), 600);
    }

    public function deleteSettings(int $familyId): bool
    {
        return $this->cache->delete("trk:settings:{$familyId}");
    }

    // --- Rate Limiter ---

    public function getRateLimit(int $userId): ?int
    {
        $val = $this->cache->get("trk:rl:{$userId}");
        return $val !== null ? (int) $val : null;
    }

    public function setRateLimit(int $userId, int $timestamp, int $ttl = 300): bool
    {
        return $this->cache->set("trk:rl:{$userId}", (string) $timestamp, $ttl);
    }

    // --- Dedupe ---

    public function getDedupePoint(int $userId): ?array
    {
        return $this->decode($this->cache->get("trk:dd:{$userId}"));
    }

    public function setDedupePoint(int $userId, array $point, int $ttl): bool
    {
        return $this->cache->set("trk:dd:{$userId}", json_encode($point), $ttl);
    }

    // --- Geofences ---

    public function getGeofences(int $familyId): ?array
    {
        return $this->decode($this->cache->get("trk:geo:{$familyId}"));
    }

    public function setGeofences(int $familyId, array $data): bool
    {
        return $this->cache->set("trk:geo:{$familyId}", json_encode($data), 600);
    }

    public function deleteGeofences(int $familyId): bool
    {
        return $this->cache->delete("trk:geo:{$familyId}");
    }

    // --- Geofence State ---

    public function getGeofenceState(int $userId): ?array
    {
        return $this->decode($this->cache->get("trk:geo_state:{$userId}"));
    }

    public function setGeofenceState(int $userId, array $data): bool
    {
        return $this->cache->set("trk:geo_state:{$userId}", json_encode($data), 600);
    }

    public function deleteGeofenceState(int $userId): bool
    {
        return $this->cache->delete("trk:geo_state:{$userId}");
    }

    // --- Places ---

    public function getPlaces(int $familyId): ?array
    {
        return $this->decode($this->cache->get("trk:places:{$familyId}"));
    }

    public function setPlaces(int $familyId, array $data): bool
    {
        return $this->cache->set("trk:places:{$familyId}", json_encode($data), 600);
    }

    public function deletePlaces(int $familyId): bool
    {
        return $this->cache->delete("trk:places:{$familyId}");
    }

    // --- Directions ---

    public function getDirections(string $profile, string $hash): ?array
    {
        return $this->decode($this->cache->get("trk:dir:{$profile}:{$hash}"));
    }

    public function setDirections(string $profile, string $hash, array $data): bool
    {
        return $this->cache->set("trk:dir:{$profile}:{$hash}", json_encode($data), 21600);
    }

    // --- Alert Rules ---

    public function getAlertRules(int $familyId): ?array
    {
        return $this->decode($this->cache->get("trk:alerts:{$familyId}"));
    }

    public function setAlertRules(int $familyId, array $data): bool
    {
        return $this->cache->set("trk:alerts:{$familyId}", json_encode($data), 600);
    }

    public function deleteAlertRules(int $familyId): bool
    {
        return $this->cache->delete("trk:alerts:{$familyId}");
    }

    // --- Alert Cooldown ---

    public function getAlertCooldown(int $familyId, string $rule, int $userId, int $targetId): bool
    {
        return $this->cache->get("trk:alerts_cd:{$familyId}:{$rule}:{$userId}:{$targetId}") !== null;
    }

    public function setAlertCooldown(int $familyId, string $rule, int $userId, int $targetId, int $ttl): bool
    {
        return $this->cache->set("trk:alerts_cd:{$familyId}:{$rule}:{$userId}:{$targetId}", '1', $ttl);
    }

    // --- Helpers ---

    private function decode($value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }
}
