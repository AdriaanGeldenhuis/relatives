<?php
declare(strict_types=1);

/**
 * Live tracking sessions repository (Mode 1)
 */
class SessionsRepo
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get active session for a family
     */
    public function getActive(int $familyId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM tracking_family_sessions
            WHERE family_id = ? AND active = 1 AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$familyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create a new session
     */
    public function create(int $familyId, int $userId, int $ttl): array
    {
        // Deactivate old sessions first
        $this->deactivateAll($familyId);

        $stmt = $this->db->prepare("
            INSERT INTO tracking_family_sessions (family_id, started_by_user_id, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
        ");
        $stmt->execute([$familyId, $userId, $ttl]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'family_id' => $familyId,
            'active' => true,
        ];
    }

    /**
     * Ping (extend) a session
     */
    public function ping(int $sessionId, int $ttl): void
    {
        $stmt = $this->db->prepare("
            UPDATE tracking_family_sessions
            SET last_ping_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
            WHERE id = ?
        ");
        $stmt->execute([$ttl, $sessionId]);
    }

    /**
     * Deactivate all sessions for a family
     */
    public function deactivateAll(int $familyId): void
    {
        $stmt = $this->db->prepare("
            UPDATE tracking_family_sessions SET active = 0 WHERE family_id = ? AND active = 1
        ");
        $stmt->execute([$familyId]);
    }

    /**
     * Clean up expired sessions
     */
    public function pruneExpired(): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM tracking_family_sessions WHERE expires_at < NOW()
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
