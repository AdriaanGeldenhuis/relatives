<?php
/**
 * Motion Gate
 *
 * Controls Mode 2 (Motion-based) behavior.
 * Determines motion state and whether to store in history.
 */

class MotionGate
{
    private SettingsRepo $settingsRepo;
    private LocationRepo $locationRepo;

    public function __construct(SettingsRepo $settingsRepo, LocationRepo $locationRepo)
    {
        $this->settingsRepo = $settingsRepo;
        $this->locationRepo = $locationRepo;
    }

    /**
     * Determine motion state and history eligibility.
     *
     * @param int $familyId
     * @param int $userId
     * @param array $newLocation The new location point
     * @return array [
     *   'motion_state' => 'moving'|'idle'|'unknown',
     *   'store_history' => bool,
     *   'reason' => string
     * ]
     */
    public function evaluate(int $familyId, int $userId, array $newLocation): array
    {
        $settings = $this->settingsRepo->get($familyId);

        // Get previous location
        $previous = $this->locationRepo->getCurrent($userId);

        // If no previous, mark as unknown and store
        if (!$previous) {
            return [
                'motion_state' => 'unknown',
                'store_history' => true,
                'reason' => 'first_point'
            ];
        }

        // Calculate metrics
        $distance = $this->haversineDistance(
            $previous['lat'], $previous['lng'],
            $newLocation['lat'], $newLocation['lng']
        );

        $timeDelta = Time::secondsSince($previous['recorded_at']);

        // Determine motion state
        $motionState = $this->determineMotionState(
            $newLocation,
            $previous,
            $distance,
            $timeDelta,
            $settings
        );

        // Determine if we should store in history
        // Mode 2: only store when moving
        // Mode 1: always store (already passed session gate)
        $storeHistory = true;
        $reason = 'accepted';

        if ($settings['mode'] === 2) {
            if ($motionState === 'idle') {
                $storeHistory = false;
                $reason = 'idle_no_history';
            }
        }

        return [
            'motion_state' => $motionState,
            'store_history' => $storeHistory,
            'reason' => $reason,
            'distance_m' => round($distance, 1),
            'time_delta_s' => $timeDelta
        ];
    }

    /**
     * Determine motion state based on multiple signals.
     */
    private function determineMotionState(
        array $newLoc,
        array $prevLoc,
        float $distance,
        int $timeDelta,
        array $settings
    ): string {
        // If device reports speed, use it
        if (isset($newLoc['speed_mps']) && $newLoc['speed_mps'] !== null) {
            $speedMps = (float)$newLoc['speed_mps'];
            if ($speedMps >= $settings['speed_threshold_mps']) {
                return 'moving';
            }
        }

        // Calculate implied speed from distance/time
        if ($timeDelta > 0) {
            $impliedSpeed = $distance / $timeDelta;
            if ($impliedSpeed >= $settings['speed_threshold_mps']) {
                return 'moving';
            }
        }

        // Check distance threshold
        if ($distance >= $settings['distance_threshold_m']) {
            return 'moving';
        }

        // Check accuracy - if poor accuracy, be conservative
        $accuracy = $newLoc['accuracy_m'] ?? null;
        if ($accuracy && $accuracy > $settings['min_accuracy_m']) {
            // Poor accuracy, can't determine state well
            return 'unknown';
        }

        // No significant movement
        return 'idle';
    }

    /**
     * Calculate Haversine distance.
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
