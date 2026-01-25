<?php
declare(strict_types=1);

require_once __DIR__ . '/../GeoUtils.php';

/**
 * FixQualityGate - Server-side location quality scoring and promotion
 *
 * Determines whether a location fix is good enough to become the
 * "current best known position" in tracking_current.
 *
 * Rules:
 * - accuracy_m > 200 → store in history, touch only (keeps status alive, no marker move)
 * - accuracy_m > 100 AND last_best accuracy < 50 AND last_best age < 10 min → touch only
 * - Unrealistic speed (> 180 km/h while is_moving=false) → reject (GPS jump)
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
     * Returns:
     *   'promote' = full update (position + timestamp)
     *   'touch'   = timestamp-only update (heartbeat alive, position unchanged)
     *   'reject'  = don't touch tracking_current at all
     *
     * @param array $fix The new fix data
     * @param int $userId The user ID
     * @return string 'promote', 'touch', or 'reject'
     */
    public function shouldPromote(array $fix, int $userId): string
    {
        $accuracy = $fix['accuracy_m'] ?? null;
        $speedKmh = $fix['speed_kmh'] ?? 0;
        $isMoving = (bool)($fix['is_moving'] ?? false);

        // Rule 1: accuracy > 200 → touch only (garbage GPS but device is alive)
        if ($accuracy !== null && $accuracy > 200) {
            return 'touch';
        }

        // Rule 3: Unrealistic speed while stationary → reject
        if ($speedKmh > 180 && !$isMoving) {
            return 'reject';
        }

        // Rule 2: accuracy > 100 AND last best was good AND recent → touch only
        if ($accuracy !== null && $accuracy > 100) {
            $lastBest = $this->getLastBest($userId);
            if ($lastBest) {
                $lastAccuracy = $lastBest['accuracy_m'] ?? 999;
                $lastAge = $lastBest['age_seconds'] ?? 99999;

                if ($lastAccuracy < 50 && $lastAge < 600) {
                    // We have a good recent fix, keep position but refresh heartbeat
                    return 'touch';
                }
            }
        }

        // Rule 4: GPS drift suppression for stationary users
        // If not moving, don't change position but DO refresh timestamp (heartbeat)
        if (!$isMoving) {
            $lastBest = $lastBest ?? $this->getLastBest($userId);
            if ($lastBest && $lastBest['latitude'] !== null) {
                $distance = geo_haversineDistance(
                    (float)$lastBest['latitude'], (float)$lastBest['longitude'],
                    (float)$fix['latitude'], (float)$fix['longitude']
                );
                // Use the larger of current accuracy or 30m as drift threshold
                $driftThreshold = max(30, $accuracy ?? 30);
                if ($distance < $driftThreshold) {
                    // Position hasn't changed meaningfully - keep marker stable
                    // but still touch timestamp so status stays alive
                    return 'touch';
                }
            }
        }

        // Rule 5: Check for impossible jump (teleportation)
        $lastBest = $lastBest ?? $this->getLastBest($userId);
        if ($lastBest && $lastBest['age_seconds'] !== null && $lastBest['age_seconds'] < 300) {
            $distance = $distance ?? geo_haversineDistance(
                (float)$lastBest['latitude'], (float)$lastBest['longitude'],
                (float)$fix['latitude'], (float)$fix['longitude']
            );
            $timeDelta = max(1, $lastBest['age_seconds']);
            $impliedSpeedKmh = ($distance / $timeDelta) * 3.6;

            // If implied speed > 180 km/h and not marked as moving, it's a jump
            if ($impliedSpeedKmh > 180 && !$isMoving) {
                return 'reject';
            }
        }

        return 'promote';
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
     * Touch tracking_current: refresh updated_at + battery without changing position.
     * Keeps the user's status alive (heartbeat) without jiggling the marker.
     */
    public function touch(array $fix, int $userId, int $deviceId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE tracking_current
            SET updated_at = NOW(),
                battery_level = COALESCE(?, battery_level),
                is_moving = ?
            WHERE user_id = ?
        ");

        return $stmt->execute([
            $fix['battery_level'] ?? null,
            (int)($fix['is_moving'] ?? 0),
            $userId
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

}
