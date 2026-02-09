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
        $stmt = $this->db->prepare("
            INSERT INTO tracking_current
                (user_id, family_id, lat, lng, accuracy_m, speed_mps, bearing_deg, altitude_m,
                 motion_state, recorded_at, device_id, platform, app_version)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                lat = VALUES(lat), lng = VALUES(lng),
                accuracy_m = VALUES(accuracy_m), speed_mps = VALUES(speed_mps),
                bearing_deg = VALUES(bearing_deg), altitude_m = VALUES(altitude_m),
                motion_state = VALUES(motion_state), recorded_at = VALUES(recorded_at),
                device_id = VALUES(device_id), platform = VALUES(platform),
                app_version = VALUES(app_version)
        ");

        $stmt->execute([
            $userId, $familyId,
            $loc['lat'], $loc['lng'],
            $loc['accuracy_m'], $loc['speed_mps'],
            $loc['bearing_deg'], $loc['altitude_m'],
            $motionState, $loc['recorded_at'],
            $loc['device_id'], $loc['platform'], $loc['app_version'],
        ]);

        // Update cache
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

        // Invalidate family snapshot
        $this->cache->deleteFamilySnapshot($familyId);
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
        // Check cache
        $cached = $this->cache->getFamilySnapshot($familyId);
        if ($cached !== null) {
            return $cached;
        }

        $stmt = $this->db->prepare("
            SELECT u.id AS user_id, u.full_name AS name, u.avatar_color, u.has_avatar,
                   tc.lat, tc.lng, tc.accuracy_m, tc.speed_mps,
                   tc.bearing_deg, tc.altitude_m, tc.motion_state,
                   tc.recorded_at, tc.updated_at, tc.device_id, tc.platform
            FROM users u
            LEFT JOIN tracking_current tc ON tc.user_id = u.id
            WHERE u.family_id = ? AND u.status = 'active'
            ORDER BY tc.updated_at DESC
        ");
        $stmt->execute([$familyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->cache->setFamilySnapshot($familyId, $rows);
        return $rows;
    }

    /**
     * Get location history for a user within a time range
     */
    public function getHistory(int $familyId, int $userId, string $from, string $to, int $limit = 500): array
    {
        $stmt = $this->db->prepare("
            SELECT lat, lng, accuracy_m, speed_mps, bearing_deg, altitude_m,
                   motion_state, recorded_at
            FROM tracking_locations
            WHERE family_id = ? AND user_id = ?
              AND recorded_at BETWEEN ? AND ?
            ORDER BY recorded_at ASC
            LIMIT ?
        ");
        $stmt->execute([$familyId, $userId, $from, $to, $limit]);
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
