<?php
declare(strict_types=1);

/**
 * Location data access - current positions and history
 */
class LocationRepo
{
    private PDO $db;
    private TrackingCache $cache;

    public function __construct(PDO $db, TrackingCache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * Upsert current location for a user
     */
    public function upsertCurrent(int $userId, int $familyId, array $loc, string $motionState): void
    {
        // Different devices can upload concurrently, and offline batches can
        // replay earlier fixes. Preserve the newest recorded position.
        $existing = $this->getCurrent($userId);
        $isNewer = $existing === null
            || !isset($existing['recorded_at'])
            || Time::parse($loc['recorded_at']) >= Time::parse($existing['recorded_at']);

        // The IF() guards against the concurrent-writer race at the DB level
        // too: an interleaved older write can't clobber a newer row.
        $stmt = $this->db->prepare("
            INSERT INTO tracking_current
                (user_id, family_id, lat, lng, accuracy_m, speed_mps, bearing_deg, altitude_m,
                 motion_state, recorded_at, device_id, platform, app_version)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                lat = IF(VALUES(recorded_at) >= recorded_at, VALUES(lat), lat),
                lng = IF(VALUES(recorded_at) >= recorded_at, VALUES(lng), lng),
                accuracy_m = IF(VALUES(recorded_at) >= recorded_at, VALUES(accuracy_m), accuracy_m),
                speed_mps = IF(VALUES(recorded_at) >= recorded_at, VALUES(speed_mps), speed_mps),
                bearing_deg = IF(VALUES(recorded_at) >= recorded_at, VALUES(bearing_deg), bearing_deg),
                altitude_m = IF(VALUES(recorded_at) >= recorded_at, VALUES(altitude_m), altitude_m),
                motion_state = IF(VALUES(recorded_at) >= recorded_at, VALUES(motion_state), motion_state),
                device_id = IF(VALUES(recorded_at) >= recorded_at, VALUES(device_id), device_id),
                platform = IF(VALUES(recorded_at) >= recorded_at, VALUES(platform), platform),
                app_version = IF(VALUES(recorded_at) >= recorded_at, VALUES(app_version), app_version),
                recorded_at = IF(VALUES(recorded_at) >= recorded_at, VALUES(recorded_at), recorded_at)
        ");

        $stmt->execute([
            $userId, $familyId,
            $loc['lat'], $loc['lng'],
            $loc['accuracy_m'], $loc['speed_mps'],
            $loc['bearing_deg'], $loc['altitude_m'],
            $motionState, $loc['recorded_at'],
            $loc['device_id'], $loc['platform'], $loc['app_version'],
        ]);

        // Only advance the cache for a newer fix so it mirrors the DB guard.
        if ($isNewer) {
            $this->cache->setCurrent($userId, [
                'lat' => $loc['lat'],
                'lng' => $loc['lng'],
                'accuracy_m' => $loc['accuracy_m'],
                'speed_mps' => $loc['speed_mps'],
                'bearing_deg' => $loc['bearing_deg'],
                'altitude_m' => $loc['altitude_m'],
                'motion_state' => $motionState,
                'recorded_at' => $loc['recorded_at'],
            ]);
        }


    }

    /**
     * Insert a point into location history
     */
    public function insertHistory(int $familyId, int $userId, array $loc, string $motionState): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_locations
                (family_id, user_id, lat, lng, accuracy_m, speed_mps, bearing_deg, altitude_m,
                 motion_state, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $familyId, $userId,
            $loc['lat'], $loc['lng'],
            $loc['accuracy_m'], $loc['speed_mps'],
            $loc['bearing_deg'], $loc['altitude_m'],
            $motionState, $loc['recorded_at'],
        ]);
    }

    /**
     * Get current locations for all family members
     */
    public function getFamilyCurrentLocations(int $familyId): array
    {
        $stmt = $this->db->prepare("
            SELECT u.id AS user_id, u.full_name AS name, u.avatar_color, u.has_avatar,
                   tc.lat, tc.lng, tc.accuracy_m, tc.speed_mps,
                   tc.bearing_deg, tc.altitude_m, tc.motion_state,
                   tc.recorded_at, tc.updated_at, tc.device_id, tc.platform
            FROM users u
            LEFT JOIN tracking_current tc ON tc.user_id = u.id AND u.location_sharing = 1
            WHERE u.family_id = ? AND u.status = 'active'
            ORDER BY tc.updated_at DESC
        ");
        $stmt->execute([$familyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get location history for a user within a time range
     */
    public function getHistory(int $familyId, int $userId, string $from, string $to, int $viewerId, int $limit = 500): array
    {
        $stmt = $this->db->prepare("
            SELECT t.lat, t.lng, t.accuracy_m, t.speed_mps, t.bearing_deg, t.altitude_m,
                   t.motion_state, t.recorded_at
            FROM tracking_locations t
            JOIN users u ON u.id = t.user_id AND u.family_id = t.family_id AND u.status = 'active'
            WHERE t.family_id = ? AND t.user_id = ?
              AND (u.location_sharing = 1 OR u.id = ?)
              AND t.recorded_at BETWEEN ? AND ?
            ORDER BY t.recorded_at ASC
            LIMIT ?
        ");
        $stmt->execute([$familyId, $userId, $viewerId, $from, $to, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get current location for a single user (from cache or DB)
     */
    public function getCurrent(int $userId): ?array
    {
        $cached = $this->cache->getCurrent($userId);
        if ($cached !== null) {
            return $cached;
        }

        $stmt = $this->db->prepare("
            SELECT lat, lng, accuracy_m, speed_mps, bearing_deg, altitude_m,
                   motion_state, recorded_at
            FROM tracking_current
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Prune old location history
     */
    public function pruneHistory(int $retentionDays): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM tracking_locations
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$retentionDays]);
        return $stmt->rowCount();
    }
}
