<?php
/**
 * Session Gate
 *
 * Controls Mode 1 (Family Live Session) behavior.
 * Determines if location uploads should be accepted based on session state.
 */

class SessionGate
{
    private SessionsRepo $sessionsRepo;
    private SettingsRepo $settingsRepo;

    public function __construct(SessionsRepo $sessionsRepo, SettingsRepo $settingsRepo)
    {
        $this->sessionsRepo = $sessionsRepo;
        $this->settingsRepo = $settingsRepo;
    }

    /**
     * Check if location upload should be accepted.
     *
     * Returns:
     * - ['allowed' => true] if upload is allowed
     * - ['allowed' => false, 'reason' => 'session_off'] if rejected
     */
    public function check(int $familyId): array
    {
        $settings = $this->settingsRepo->get($familyId);

        // Mode 2 (Motion-based) doesn't use session gating
        if ($settings['mode'] === 2) {
            return ['allowed' => true];
        }

        // Mode 1: Check session
        $isActive = $this->sessionsRepo->isActive($familyId);

        if (!$isActive) {
            return [
                'allowed' => false,
                'reason' => 'session_off',
                'message' => 'No active tracking session. Open the tracking page to start.'
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Get session status for API.
     */
    public function getStatus(int $familyId): array
    {
        $settings = $this->settingsRepo->get($familyId);
        $sessionStatus = $this->sessionsRepo->getStatus($familyId);

        return [
            'mode' => $settings['mode'],
            'session' => $sessionStatus,
            'should_track' => $settings['mode'] === 2 || $sessionStatus['active']
        ];
    }

    /**
     * Keepalive (start or refresh session).
     */
    public function keepalive(int $familyId, int $userId): array
    {
        $settings = $this->settingsRepo->get($familyId);
        $ttl = $settings['session_ttl_seconds'];

        return $this->sessionsRepo->keepalive($familyId, $userId, $ttl);
    }
}
