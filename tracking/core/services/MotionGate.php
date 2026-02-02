<?php
/**
 * Motion Gate
 *
 * Controls Mode 2 (Motion-based) behavior.
 * Determines motion state and whether to store in history.
 *
 * IMPORTANT: We validate GPS-reported speed against actual position change
 * to filter out GPS noise. GPS can report high speeds while stationary.
 */

class MotionGate
{
    private SettingsRepo $settingsRepo;
    private LocationRepo $locationRepo;

    // If GPS speed differs from calculated speed by more than this factor, it's noise
    const SPEED_VALIDATION_FACTOR = 3.0;

    // Minimum distance (meters) to consider actual movement
    const MIN_MOVEMENT_M = 10;

    // Minimum time (seconds) between points for reliable speed calculation
    const MIN_TIME_FOR_SPEED = 5;

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
     *   'reason' => string,
     *   'calculated_speed_mps' => float|null (for debugging)
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
                'reason' => 'first_point',
                'calculated_speed_mps' => null
            ];
        }

        // Calculate distance between points
        $distance = $this->haversineDistance(
            $previous['lat'], $previous['lng'],
            $newLocation['lat'], $newLocation['lng']
        );

        // Calculate time since last update
        $timeDelta = Time::secondsSince($previous['recorded_at']);

        // Calculate actual speed from position change
        $calculatedSpeed = null;
        if ($timeDelta >= self::MIN_TIME_FOR_SPEED) {
            $calculatedSpeed = $distance / $timeDelta;
        }

        // Determine motion state using smart validation
        $motionState = $this->determineMotionState(
            $newLocation,
            $previous,
            $distance,
            $timeDelta,
            $calculatedSpeed,
            $settings
        );

        // Determine if we should store in history
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
            'time_delta_s' => $timeDelta,
            'calculated_speed_mps' => $calculatedSpeed !== null ? round($calculatedSpeed, 2) : null
        ];
    }

    /**
     * Determine motion state using validated speed.
     *
     * Priority:
     * 1. If position has actually changed significantly -> use calculated speed
     * 2. If GPS speed matches calculated speed (within tolerance) -> trust GPS
     * 3. If GPS reports high speed but no position change -> GPS noise, mark idle
     */
    private function determineMotionState(
        array $newLoc,
        array $prevLoc,
        float $distance,
        int $timeDelta,
        ?float $calculatedSpeed,
        array $settings
    ): string {
        $gpsSpeed = isset($newLoc['speed_mps']) ? (float)$newLoc['speed_mps'] : null;
        $speedThreshold = $settings['speed_threshold_mps'];
        $distanceThreshold = $settings['distance_threshold_m'];

        // Check accuracy - if poor accuracy, be conservative
        $accuracy = $newLoc['accuracy_m'] ?? 50;
        if ($accuracy > $settings['min_accuracy_m']) {
            return 'unknown';
        }

        // Case 1: Significant position change - definitely moving
        if ($distance >= $distanceThreshold) {
            return 'moving';
        }

        // Case 2: We have a reliable calculated speed
        if ($calculatedSpeed !== null && $timeDelta >= self::MIN_TIME_FOR_SPEED) {
            // If calculated speed shows movement, trust it
            if ($calculatedSpeed >= $speedThreshold) {
                return 'moving';
            }

            // If calculated speed is low but GPS reports high speed -> GPS noise
            if ($gpsSpeed !== null && $gpsSpeed >= $speedThreshold && $calculatedSpeed < $speedThreshold) {
                // GPS says moving, but position hasn't changed much
                // This is GPS noise - mark as idle
                error_log("MotionGate: GPS noise detected - GPS speed: {$gpsSpeed} m/s, calculated: {$calculatedSpeed} m/s, distance: {$distance}m");
                return 'idle';
            }
        }

        // Case 3: Very short time delta or no calculated speed available
        // Be more careful - check if GPS speed is reasonable
        if ($gpsSpeed !== null && $gpsSpeed >= $speedThreshold) {
            // GPS reports movement, but we can't verify with position change
            // If distance is essentially zero despite "high speed", it's noise
            if ($distance < self::MIN_MOVEMENT_M && $timeDelta > 0) {
                // GPS says moving fast, but hasn't moved 10m -> noise
                return 'idle';
            }

            // Some movement happened, tentatively trust GPS
            return 'moving';
        }

        // Case 4: Neither GPS nor calculated speed indicate movement
        return 'idle';
    }

    /**
     * Calculate Haversine distance between two points.
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Validate GPS-reported speed against calculated speed.
     *
     * @return bool True if GPS speed seems valid
     */
    private function isGpsSpeedValid(?float $gpsSpeed, ?float $calculatedSpeed): bool
    {
        if ($gpsSpeed === null) {
            return false;
        }

        if ($calculatedSpeed === null || $calculatedSpeed < 0.5) {
            // Can't validate, assume invalid if GPS reports high speed
            return $gpsSpeed < 5; // Under 18 km/h is probably okay
        }

        // Check if GPS speed is within reasonable range of calculated speed
        $ratio = $gpsSpeed / max($calculatedSpeed, 0.1);

        return $ratio < self::SPEED_VALIDATION_FACTOR;
    }
}
