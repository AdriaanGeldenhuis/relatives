<?php
declare(strict_types=1);

/**
 * FixQualityGate - Server-side location quality scoring and promotion
 *
 * Determines whether a location fix is good enough to become the
 * "current best known position" in tracking_current.
 *
 * Rules:
 * - accuracy_m > 200 → store in history, do NOT promote to current
 * - accuracy_m > 100 AND last_best accuracy < 50 AND last_best age < 10 min → do NOT promote
 * - Unrealistic speed (> 180 km/h while is_moving=false) → store but don't promote
 * - Good fix → promote to tracking_current + cache
 */

class FixQualityGate
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Compute a quality score (0-100) for a location fix
     */
    public function computeScore(array $fix): int
    {
        $score = 100;
        $accuracy = $fix['accuracy_m'] ?? null;

        // Accuracy bucket scoring
        if ($accuracy === null) {
            $score -= 30; // Unknown accuracy is suspect
        } elseif ($accuracy <= 10) {
            $score -= 0;  // Excellent GPS
        } elseif ($accuracy <= 25) {
            $score -= 5;  // Good GPS
        } elseif ($accuracy <= 50) {
            $score -= 10; // Acceptable
        } elseif ($accuracy <= 100) {
            $score -= 25; // Marginal
        } elseif ($accuracy <= 200) {
            $score -= 50; // Poor (likely network/cell)
        } else {
            $score -= 70; // Terrible (cell tower)
        }

        // Speed sanity
        $speedKmh = $fix['speed_kmh'] ?? 0;
        $isMoving = (bool)($fix['is_moving'] ?? false);

        if ($speedKmh > 300) {
            $score -= 40; // Impossible speed
        } elseif ($speedKmh > 180 && !$isMoving) {
            $score -= 30; // High speed while "stationary" = GPS jump
        }

        return max(0, min(100, $score));
    }

    /**
     * Determine the fix source based on accuracy
     */
    public function determineSource(?int $accuracy): string
    {
        if ($accuracy === null) return 'unknown';
        if ($accuracy <= 20) return 'gps';
        if ($accuracy <= 50) return 'fused';
        return 'network';
    }

    /**
     * Check whether this fix should be promoted to tracking_current.
     *
     * @param array $fix The new fix data
     * @param int $userId The user ID
     * @return bool True if fix should be promoted
     */
    public function shouldPromote(array $fix, int $userId): bool
    {
        $accuracy = $fix['accuracy_m'] ?? null;
        $speedKmh = $fix['speed_kmh'] ?? 0;
        $isMoving = (bool)($fix['is_moving'] ?? false);

        // Rule 1: accuracy > 200 → never promote
        if ($accuracy !== null && $accuracy > 200) {
            return false;
        }

        // Rule 3: Unrealistic speed while stationary → don't promote
        if ($speedKmh > 180 && !$isMoving) {
            return false;
        }

        // Rule 2: accuracy > 100 AND last best was good AND recent → don't promote
        if ($accuracy !== null && $accuracy > 100) {
            $lastBest = $this->getLastBest($userId);
            if ($lastBest) {
                $lastAccuracy = $lastBest['accuracy_m'] ?? 999;
                $lastAge = $lastBest['age_seconds'] ?? 99999;

                if ($lastAccuracy < 50 && $lastAge < 600) {
                    // We have a good recent fix, don't overwrite with garbage
                    return false;
                }
            }
        }

        // Additional: Check for impossible jump (teleportation)
        if ($accuracy !== null && $accuracy <= 200) {
            $lastBest = $lastBest ?? $this->getLastBest($userId);
            if ($lastBest && $lastBest['age_seconds'] !== null && $lastBest['age_seconds'] < 300) {
                // Calculate implied speed between last best and this fix
                $distance = $this->haversineDistance(
                    (float)$lastBest['latitude'], (float)$lastBest['longitude'],
                    (float)$fix['latitude'], (float)$fix['longitude']
                );
                $timeDelta = max(1, $lastBest['age_seconds']);
                $impliedSpeedKmh = ($distance / $timeDelta) * 3.6;

                // If implied speed > 180 km/h and not marked as moving, it's a jump
                if ($impliedSpeedKmh > 180 && !$isMoving) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Promote a fix to tracking_current (upsert)
     */
    public function promote(array $fix, int $userId, int $deviceId, int $familyId): bool
    {
        $accuracy = $fix['accuracy_m'] ?? null;
        $score = $this->computeScore($fix);
        $source = $this->determineSource($accuracy);

        $stmt = $this->db->prepare("
            INSERT INTO tracking_current
                (user_id, device_id, family_id, latitude, longitude, accuracy_m,
                 speed_kmh, heading_deg, altitude_m, is_moving, battery_level,
                 fix_quality_score, fix_source, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                device_id = VALUES(device_id),
                family_id = VALUES(family_id),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                accuracy_m = VALUES(accuracy_m),
                speed_kmh = VALUES(speed_kmh),
                heading_deg = VALUES(heading_deg),
                altitude_m = VALUES(altitude_m),
                is_moving = VALUES(is_moving),
                battery_level = VALUES(battery_level),
                fix_quality_score = VALUES(fix_quality_score),
                fix_source = VALUES(fix_source),
                updated_at = NOW()
        ");

        return $stmt->execute([
            $userId,
            $deviceId,
            $familyId,
            $fix['latitude'],
            $fix['longitude'],
            $accuracy,
            $fix['speed_kmh'] ?? null,
            $fix['heading_deg'] ?? null,
            $fix['altitude_m'] ?? null,
            (int)($fix['is_moving'] ?? 0),
            $fix['battery_level'] ?? null,
            $score,
            $source
        ]);
    }

    /**
     * Get the last best fix for a user from tracking_current
     */
    private function getLastBest(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT latitude, longitude, accuracy_m,
                   TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age_seconds
            FROM tracking_current
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Haversine distance in meters between two lat/lng points
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
}
