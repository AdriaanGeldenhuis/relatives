<?php
declare(strict_types=1);

class SessionsRepo {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function start(int $userId, int $familyId, string $mode = 'live', int $intervalSeconds = 30): int {
        // Stop any existing active sessions
        $this->stopAll($userId);

        $expiresAt = date('Y-m-d H:i:s', time() + 7200); // 2 hour default

        $stmt = $this->db->prepare("
            INSERT INTO tracking_sessions (user_id, family_id, status, mode, interval_seconds, started_at, expires_at)
            VALUES (?, ?, 'active', ?, ?, NOW(), ?)
        ");
        $stmt->execute([$userId, $familyId, $mode, $intervalSeconds, $expiresAt]);

        return (int)$this->db->lastInsertId();
    }

    public function stop(int $userId): bool {
        $stmt = $this->db->prepare("
            UPDATE tracking_sessions SET status = 'stopped', stopped_at = NOW()
            WHERE user_id = ? AND status = 'active'
        ");
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    public function stopAll(int $userId): void {
        $stmt = $this->db->prepare("
            UPDATE tracking_sessions SET status = 'stopped', stopped_at = NOW()
            WHERE user_id = ? AND status = 'active'
        ");
        $stmt->execute([$userId]);
    }

    public function getActive(int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM tracking_sessions
            WHERE user_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY started_at DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        return $session ?: null;
    }

    public function getActiveForFamily(int $familyId): array {
        $stmt = $this->db->prepare("
            SELECT ts.*, u.full_name
            FROM tracking_sessions ts
            JOIN users u ON ts.user_id = u.id
            WHERE ts.family_id = ? AND ts.status = 'active' AND (ts.expires_at IS NULL OR ts.expires_at > NOW())
        ");
        $stmt->execute([$familyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cleanupExpired(): int {
        $stmt = $this->db->prepare("
            UPDATE tracking_sessions SET status = 'expired'
            WHERE status = 'active' AND expires_at < NOW()
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
