<?php
declare(strict_types=1);

class SessionGate {
    private TrackingCache $cache;
    private PDO $db;

    public function __construct(TrackingCache $cache, PDO $db) {
        $this->cache = $cache;
        $this->db = $db;
    }

    /**
     * Check if user has an active live session
     */
    public function isActive(int $userId): bool {
        // Check cache first
        $key = $this->cache->sessionKey($userId);
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return (bool)$cached;
        }

        // Fall back to DB
        $stmt = $this->db->prepare("
            SELECT id FROM tracking_sessions
            WHERE user_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $active = (bool)$stmt->fetch();

        $this->cache->set($key, $active ? '1' : '0', 120);
        return $active;
    }

    /**
     * Keepalive - extend session
     */
    public function keepalive(int $userId): void {
        $key = $this->cache->sessionKey($userId);
        $this->cache->set($key, '1', 120);

        try {
            $stmt = $this->db->prepare("
                UPDATE tracking_sessions
                SET last_keepalive = NOW()
                WHERE user_id = ? AND status = 'active'
            ");
            $stmt->execute([$userId]);
        } catch (\Exception $e) {
            error_log('SessionGate::keepalive error: ' . $e->getMessage());
        }
    }
}
