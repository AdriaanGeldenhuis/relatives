<?php
declare(strict_types=1);

class TrackingCache {
    private Cache $cache;

    public function __construct(Cache $cache) {
        $this->cache = $cache;
    }

    public function setUserLocation(int $familyId, int $userId, array $data): bool {
        return $this->cache->setUserLocation($familyId, $userId, $data);
    }

    public function getUserLocation(int $familyId, int $userId): ?array {
        return $this->cache->getUserLocation($familyId, $userId);
    }

    public function getFamilyLocations(int $familyId, array $userIds): array {
        return $this->cache->getFamilyLocations($familyId, $userIds);
    }

    public function get(string $key) {
        return $this->cache->get($key);
    }

    public function set(string $key, $value, int $ttl = 3600): bool {
        return $this->cache->set($key, $value, $ttl);
    }

    public function delete(string $key): bool {
        return $this->cache->delete($key);
    }

    /**
     * Rate limit key helper
     */
    public function rateKey(string $action, int $userId): string {
        return "track:rate:{$action}:{$userId}";
    }

    /**
     * Dedupe key helper
     */
    public function dedupeKey(int $userId): string {
        return "track:dedupe:{$userId}";
    }

    /**
     * Session keepalive key
     */
    public function sessionKey(int $userId): string {
        return "track:session:{$userId}";
    }
}
