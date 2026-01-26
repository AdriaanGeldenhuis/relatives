<?php
/**
 * Sessions Repository
 *
 * Manages tracking_family_sessions table (Mode 1 live sessions).
 */

class SessionsRepo
{
    private PDO $db;
    private TrackingCache $cache;

    public function __construct(PDO $db, TrackingCache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * Get active session for a family.
     */
    public function getActive(int $familyId): ?array
    {
        // Try cache first
        $cached = $this->cache->getLiveSession($familyId);
        if ($cached !== null && $cached['active']) {
            return $cached;
        }

        // Query DB
        $stmt = $this->db->prepare("
            SELECT * FROM tracking_family_sessions
            WHERE family_id = ? AND active = 1 AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$familyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Check if family has an active session.
     */
    public function isActive(int $familyId): bool
    {
        // Try cache
        $cached = $this->cache->getLiveSession($familyId);
        if ($cached !== null) {
            return $cached['active'] && !Time::isExpired($cached['expires_at']);
        }

        // Query DB
        $stmt = $this->db->prepare("
            SELECT 1 FROM tracking_family_sessions
            WHERE family_id = ? AND active = 1 AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$familyId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Start or refresh a live session.
     * Returns the session data.
     */
    public function keepalive(int $familyId, int $userId, int $ttlSeconds): array
    {
        $expiresAt = Time::addSeconds($ttlSeconds);

        // Check for existing active session
        $existing = $this->getActive($familyId);

        if ($existing) {
            // Update existing
            $stmt = $this->db->prepare("
                UPDATE tracking_family_sessions
                SET last_ping_at = NOW(), expires_at = ?
                WHERE id = ?
            ");
            $stmt->execute([$expiresAt, $existing['id']]);

            $session = [
                'id' => $existing['id'],
                'family_id' => $familyId,
                'active' => true,
                'started_by' => $existing['started_by'],
                'started_at' => $existing['created_at'],
                'last_ping_at' => Time::now(),
                'expires_at' => $expiresAt
            ];
        } else {
            // Create new session
            $stmt = $this->db->prepare("
                INSERT INTO tracking_family_sessions (
                    family_id, active, started_by_user_id,
                    last_ping_at, expires_at, created_at
                ) VALUES (?, 1, ?, NOW(), ?, NOW())
            ");
            $stmt->execute([$familyId, $userId, $expiresAt]);

            $session = [
                'id' => (int)$this->db->lastInsertId(),
                'family_id' => $familyId,
                'active' => true,
                'started_by' => $userId,
                'started_at' => Time::now(),
                'last_ping_at' => Time::now(),
                'expires_at' => $expiresAt
            ];
        }

        // Update cache
        $this->cache->setLiveSession($familyId, $session, $ttlSeconds);

        return $session;
    }

    /**
     * End all active sessions for a family.
     */
    public function endAll(int $familyId): int
    {
        $stmt = $this->db->prepare("
            UPDATE tracking_family_sessions
            SET active = 0
            WHERE family_id = ? AND active = 1
        ");
        $stmt->execute([$familyId]);

        // Clear cache
        $this->cache->deleteLiveSession($familyId);

        return $stmt->rowCount();
    }

    /**
     * End expired sessions (cleanup job).
     */
    public function endExpired(): int
    {
        $stmt = $this->db->prepare("
            UPDATE tracking_family_sessions
            SET active = 0
            WHERE active = 1 AND expires_at < NOW()
        ");
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Get session status for API response.
     */
    public function getStatus(int $familyId): array
    {
        $session = $this->getActive($familyId);

        if (!$session) {
            return [
                'active' => false,
                'session_id' => null,
                'started_at' => null,
                'expires_at' => null,
                'expires_in_seconds' => 0
            ];
        }

        return [
            'active' => true,
            'session_id' => $session['id'],
            'started_at' => $session['created_at'],
            'expires_at' => $session['expires_at'],
            'expires_in_seconds' => max(0, Time::secondsUntil($session['expires_at']))
        ];
    }

    /**
     * Hydrate a row.
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'family_id' => (int)$row['family_id'],
            'active' => (bool)$row['active'],
            'started_by' => $row['started_by_user_id'] ? (int)$row['started_by_user_id'] : null,
            'last_ping_at' => $row['last_ping_at'],
            'expires_at' => $row['expires_at'],
            'created_at' => $row['created_at']
        ];
    }
}
