<?php
declare(strict_types=1);

class SiteContext {
    public int $userId;
    public int $familyId;
    public string $userName;
    public string $userRole;
    public array $user;

    private function __construct(array $user) {
        $this->userId = (int)$user['id'];
        $this->familyId = (int)$user['family_id'];
        $this->userName = $user['name'] ?? $user['full_name'] ?? 'User';
        $this->userRole = $user['role'] ?? 'member';
        $this->user = $user;
    }

    /**
     * Require auth - returns SiteContext or sends 401 error response and exits
     */
    public static function require(PDO $db): self {
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            Response::error('unauthorized', 401);
        }

        $auth = new Auth($db);
        $user = $auth->getCurrentUser();

        if (!$user) {
            Response::error('unauthorized', 401);
        }

        return new self($user);
    }

    /**
     * Optional auth - returns SiteContext or null
     */
    public static function optional(PDO $db): ?self {
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            return null;
        }

        try {
            $auth = new Auth($db);
            $user = $auth->getCurrentUser();
            return $user ? new self($user) : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
