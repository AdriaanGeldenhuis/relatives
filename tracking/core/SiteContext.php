<?php
declare(strict_types=1);

/**
 * SiteContext - Authentication wrapper for tracking endpoints
 * Validates session, loads user + family, checks location_sharing
 */
class SiteContext
{
    public int $userId;
    public int $familyId;
    public string $role;
    public string $name;
    public bool $locationSharing;
    public PDO $db;

    private function __construct(PDO $db, array $user)
    {
        $this->db = $db;
        $this->userId = (int) $user['id'];
        $this->familyId = (int) $user['family_id'];
        $this->role = $user['role'];
        $this->name = $user['name'] ?? $user['full_name'] ?? 'User';
        $this->locationSharing = (bool) ($user['location_sharing'] ?? true);
    }

    /**
     * Build context from current session. Returns null on auth failure.
     */
    public static function fromSession(PDO $db): ?self
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $stmt = $db->prepare("
            SELECT u.id, u.family_id, u.role, u.full_name AS name,
                   u.location_sharing, u.status
            FROM users u
            WHERE u.id = ? AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        return new self($db, $user);
    }

    /**
     * Require authenticated context or die with 401
     */
    public static function require(PDO $db): self
    {
        $ctx = self::fromSession($db);
        if (!$ctx) {
            Response::error('not_authenticated', 401);
        }
        return $ctx;
    }

    /**
     * Require location sharing enabled or die with 403
     */
    public function requireLocationSharing(): void
    {
        if (!$this->locationSharing) {
            Response::error('location_sharing_disabled', 403);
        }
    }

    /**
     * Check if user is owner or admin
     */
    public function isAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }
}
