<?php
declare(strict_types=1);

/**
 * Mode 1: Live Session Gate
 * Manages family tracking sessions. Devices should only upload
 * locations when an active session exists.
 */
class SessionGate
{
    private TrackingCache $cache;
    private SessionsRepo $repo;
    private int $ttlSeconds;

    public function __construct(TrackingCache $cache, SessionsRepo $repo, int $ttlSeconds = 300)
    {
        $this->cache = $cache;
        $this->repo = $repo;
        $this->ttlSeconds = $ttlSeconds;
    }

    /**
     * Check if a live session is active for this family.
     */
    public function isActive(int $familyId): bool
    {
        // Check cache first
        $cached = $this->cache->getSession($familyId);
        if ($cached !== null && ($cached['active'] ?? false)) {
            return true;
        }

        // Fallback to DB
        $session = $this->repo->getActive($familyId);
        if ($session) {
            $this->cache->setSession($familyId, ['active' => true], $this->ttlSeconds);
            return true;
        }

        return false;
    }

    /**
     * Start or extend a live session (called by keepalive)
     */
    public function keepalive(int $familyId, int $userId): array
    {
        $session = $this->repo->getActive($familyId);

        if ($session) {
            $this->repo->ping($session['id'], $this->ttlSeconds);
        } else {
            $session = $this->repo->create($familyId, $userId, $this->ttlSeconds);
        }

        $this->cache->setSession($familyId, ['active' => true], $this->ttlSeconds);

        return [
            'active' => true,
            'expires_in' => $this->ttlSeconds,
        ];
    }

    /**
     * End a live session
     */
    public function end(int $familyId): void
    {
        $this->repo->deactivateAll($familyId);
        $this->cache->deleteSession($familyId);
    }

    /**
     * Get session status for a family
     */
    public function getStatus(int $familyId, array $settings): array
    {
        $mode = (int) ($settings['mode'] ?? 1);

        if ($mode === 2) {
            return [
                'mode' => 2,
                'should_track' => true,
                'moving_interval' => (int) ($settings['moving_interval_seconds'] ?? 30),
                'idle_interval' => (int) ($settings['idle_interval_seconds'] ?? 300),
            ];
        }

        $active = $this->isActive($familyId);
        return [
            'mode' => 1,
            'should_track' => $active,
            'keepalive_interval' => (int) ($settings['keepalive_interval_seconds'] ?? 30),
            'session_ttl' => (int) ($settings['session_ttl_seconds'] ?? 300),
        ];
    }
}
