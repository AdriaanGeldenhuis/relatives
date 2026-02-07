<?php
declare(strict_types=1);

/**
 * Mode 2: Motion-based tracking gate
 * Determines whether a location represents real movement
 * and whether it should be stored in history.
 */
class MotionGate
{
    private float $speedThresholdMps;
    private float $distanceThresholdM;

    public function __construct(float $speedThresholdMps = 1.0, float $distanceThresholdM = 50.0)
    {
        $this->speedThresholdMps = $speedThresholdMps;
        $this->distanceThresholdM = $distanceThresholdM;
    }

    /**
     * Evaluate whether the device is moving based on GPS data.
     *
     * @param array $location  Normalized location from TrackingValidator
     * @param array|null $prev Previous location (from cache/DB)
     * @return array ['motion_state' => 'moving'|'idle'|'unknown', 'store_history' => bool]
     */
    public function evaluate(array $location, ?array $prev): array
    {
        $gpsSpeed = $location['speed_mps'];
        $isMovingByGps = $gpsSpeed !== null && $gpsSpeed >= $this->speedThresholdMps;

        // If no previous point, trust GPS
        if ($prev === null) {
            $state = $isMovingByGps ? 'moving' : 'unknown';
            return ['motion_state' => $state, 'store_history' => true];
        }

        // Calculate distance and time from previous point
        $distance = geo_haversineDistance(
            (float) $prev['lat'],
            (float) $prev['lng'],
            $location['lat'],
            $location['lng']
        );

        $prevTs = isset($prev['recorded_at'])
            ? Time::parse($prev['recorded_at'])
            : (isset($prev['ts']) ? Time::parse($prev['ts']) : time() - 60);
        $timeDelta = max(1, Time::parse($location['recorded_at']) - $prevTs);

        // Calculated speed from displacement
        $calcSpeed = $distance / $timeDelta;

        // Noise filter: if GPS says fast but calculated says near-stationary, it's drift
        if ($isMovingByGps && $calcSpeed < ($this->speedThresholdMps * 0.3)) {
            return ['motion_state' => 'idle', 'store_history' => false];
        }

        // Determine motion state
        $isMoving = $isMovingByGps || $distance >= $this->distanceThresholdM;

        if ($isMoving) {
            return ['motion_state' => 'moving', 'store_history' => true];
        }

        // Idle: store a heartbeat point if enough time has passed (5 min)
        $storeHeartbeat = $timeDelta >= 300;
        return ['motion_state' => 'idle', 'store_history' => $storeHeartbeat];
    }
}
