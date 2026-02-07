<?php
declare(strict_types=1);

class MotionGate {
    /**
     * Check if we should record this update based on motion
     * Returns true if we should store it, false if we can skip
     */
    public static function shouldStore(float $speed, bool $isMoving, float $accuracy): bool {
        // Always store if accuracy is poor - we need the data
        if ($accuracy > 50) return false;

        // Always store if moving
        if ($isMoving || $speed > 1.0) return true;

        // Stationary - store less frequently (handled by dedupe)
        return true;
    }
}
