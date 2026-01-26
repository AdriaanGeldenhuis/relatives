<?php
/**
 * Site Context
 *
 * Integrates with site's auth/session system.
 * NO CUSTOM AUTH - uses exactly what the site provides.
 *
 * @see /tracking/STACK_INTEGRATION.md
 */

class SiteContext
{
    private PDO $db;
    private ?array $user = null;
    private bool $loaded = false;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get current authenticated user.
     *
     * Returns user array with:
     * - id, family_id, family_name, role, name, email, avatar_color
     *
     * Returns null if not authenticated.
     */
    public function getUser(): ?array
    {
        if ($this->loaded) {
            return $this->user;
        }

        $this->loaded = true;
        $this->user = $this->loadUser();

        return $this->user;
    }

    /**
     * Get user ID (shortcut).
     */
    public function getUserId(): ?int
    {
        $user = $this->getUser();
        return $user ? (int)$user['id'] : null;
    }

    /**
     * Get family ID (shortcut).
     */
    public function getFamilyId(): ?int
    {
        $user = $this->getUser();
        return $user ? (int)$user['family_id'] : null;
    }

    /**
     * Check if user is authenticated.
     */
    public function isAuthenticated(): bool
    {
        return $this->getUser() !== null;
    }

    /**
     * Check if user has admin role (owner or admin).
     */
    public function isAdmin(): bool
    {
        $user = $this->getUser();
        return $user && in_array($user['role'], ['owner', 'admin']);
    }

    /**
     * Check if user is family owner.
     */
    public function isOwner(): bool
    {
        $user = $this->getUser();
        return $user && $user['role'] === 'owner';
    }

    /**
     * Check if user has location sharing enabled.
     */
    public function hasLocationSharing(): bool
    {
        $user = $this->getUser();
        return $user && !empty($user['location_sharing']);
    }

    /**
     * Get family members (with location_sharing enabled).
     */
    public function getFamilyMembers(bool $onlyWithSharing = true): array
    {
        $user = $this->getUser();
        if (!$user) {
            return [];
        }

        $sql = "
            SELECT id, full_name as name, avatar_color, has_avatar, role, location_sharing
            FROM users
            WHERE family_id = ? AND status = 'active'
        ";

        if ($onlyWithSharing) {
            $sql .= " AND location_sharing = 1";
        }

        $sql .= " ORDER BY role = 'owner' DESC, role = 'admin' DESC, full_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$user['family_id']]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Load user from session.
     * Follows site's exact auth pattern.
     */
    private function loadUser(): ?array
    {
        // Check session exists
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        // Try cached user data first (5-minute cache from site's Session class)
        if (isset($_SESSION['user_data_cached']) &&
            $_SESSION['user_data_cached'] === true &&
            isset($_SESSION['user_data_time']) &&
            (time() - $_SESSION['user_data_time']) < 300) {

            $userData = $_SESSION['user_data'];

            // Make sure we have required fields
            if (isset($userData['id'], $userData['family_id'])) {
                return [
                    'id' => (int)$userData['id'],
                    'family_id' => (int)$userData['family_id'],
                    'family_name' => $userData['family_name'] ?? '',
                    'role' => $userData['role'] ?? 'member',
                    'name' => $userData['name'] ?? '',
                    'email' => $userData['email'] ?? '',
                    'avatar_color' => $userData['avatar_color'] ?? '#667eea',
                    'location_sharing' => $userData['location_sharing'] ?? 1
                ];
            }
        }

        // No valid cache, query database
        return $this->loadUserFromDb();
    }

    /**
     * Load user from database.
     */
    private function loadUserFromDb(): ?array
    {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_token'])) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT
                u.id,
                u.family_id,
                u.role,
                u.full_name as name,
                u.email,
                u.avatar_color,
                u.has_avatar,
                u.location_sharing,
                u.status,
                f.name as family_name
            FROM users u
            JOIN families f ON u.family_id = f.id
            JOIN sessions s ON s.user_id = u.id
            WHERE u.id = ?
              AND s.session_token = ?
              AND s.expires_at > NOW()
              AND u.status = 'active'
            LIMIT 1
        ");

        $stmt->execute([
            $_SESSION['user_id'],
            hash('sha256', $_SESSION['session_token'])
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Cache for next request
        $_SESSION['user_data'] = [
            'id' => $row['id'],
            'family_id' => $row['family_id'],
            'family_name' => $row['family_name'],
            'role' => $row['role'],
            'name' => $row['name'],
            'email' => $row['email'],
            'avatar_color' => $row['avatar_color'],
            'location_sharing' => $row['location_sharing']
        ];
        $_SESSION['user_data_cached'] = true;
        $_SESSION['user_data_time'] = time();

        return [
            'id' => (int)$row['id'],
            'family_id' => (int)$row['family_id'],
            'family_name' => $row['family_name'],
            'role' => $row['role'],
            'name' => $row['name'],
            'email' => $row['email'],
            'avatar_color' => $row['avatar_color'],
            'location_sharing' => (int)$row['location_sharing']
        ];
    }
}
