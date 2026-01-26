<?php
/**
 * Location Repository
 *
 * Manages tracking_current and tracking_locations tables.
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
     * Update or insert current location for a user.
     */
    public function upsertCurrent(int $userId, int $familyId, array $data): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_current (
                user_id, family_id, lat, lng, accuracy_m, speed_mps,
                bearing_deg, altitude_m, motion_state, recorded_at, updated_at,
                device_id, platform, app_version
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?
            )
            ON DUPLICATE KEY UPDATE
                lat = VALUES(lat),
                lng = VALUES(lng),
                accuracy_m = VALUES(accuracy_m),
                speed_mps = VALUES(speed_mps),
                bearing_deg = VALUES(bearing_deg),
                altitude_m = VALUES(altitude_m),
                motion_state = VALUES(motion_state),
                recorded_at = VALUES(recorded_at),
                updated_at = NOW(),
                device_id = VALUES(device_id),
                platform = VALUES(platform),
                app_version = VALUES(app_version)
        ");

        $result = $stmt->execute([
            $userId,
            $familyId,
            $data['lat'],
            $data['lng'],
            $data['accuracy_m'] ?? null,
            $data['speed_mps'] ?? null,
            $data['bearing_deg'] ?? null,
            $data['altitude_m'] ?? null,
            $data['motion_state'] ?? 'unknown',
            $data['recorded_at'],
            $data['device_id'] ?? null,
            $data['platform'] ?? null,
            $data['app_version'] ?? null
        ]);

        if ($result) {
            // Update cache
            $this->cache->setCurrentLocation($userId, [
                'user_id' => $userId,
                'family_id' => $familyId,
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'accuracy_m' => $data['accuracy_m'] ?? null,
                'speed_mps' => $data['speed_mps'] ?? null,
                'bearing_deg' => $data['bearing_deg'] ?? null,
                'motion_state' => $data['motion_state'] ?? 'unknown',
                'recorded_at' => $data['recorded_at'],
                'updated_at' => Time::now()
            ]);

            // Invalidate family snapshot
            $this->cache->deleteFamilySnapshot($familyId);
        }

        return $result;
    }

    /**
     * Insert into location history.
     */
    public function insertHistory(int $userId, int $familyId, array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_locations (
                family_id, user_id, lat, lng, accuracy_m, speed_mps,
                bearing_deg, altitude_m, motion_state, recorded_at, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )
        ");

        $stmt->execute([
            $familyId,
            $userId,
            $data['lat'],
            $data['lng'],
            $data['accuracy_m'] ?? null,
            $data['speed_mps'] ?? null,
            $data['bearing_deg'] ?? null,
            $data['altitude_m'] ?? null,
            $data['motion_state'] ?? 'unknown',
            $data['recorded_at']
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get current location for a user.
     */
    public function getCurrent(int $userId): ?array
    {
        // Try cache
        $cached = $this->cache->getCurrentLocation($userId);
        if ($cached !== null) {
            return $cached;
        }

        // Query DB
        $stmt = $this->db->prepare("
            SELECT * FROM tracking_current WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $data = $this->hydrateCurrent($row);

        // Cache it
        $this->cache->setCurrentLocation($userId, $data);

        return $data;
    }

    /**
     * Get current locations for all family members.
     */
    public function getFamilyCurrent(int $familyId): array
    {
        // Try cache
        $cached = $this->cache->getFamilySnapshot($familyId);
        if ($cached !== null) {
            return $cached;
        }

        // Query with user info (all active family members)
        $stmt = $this->db->prepare("
            SELECT
                tc.*,
                u.full_name as name,
                u.avatar_color,
                u.has_avatar
            FROM tracking_current tc
            JOIN users u ON tc.user_id = u.id
            WHERE tc.family_id = ?
              AND u.status = 'active'
            ORDER BY tc.updated_at DESC
        ");
        $stmt->execute([$familyId]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = [
                'user_id' => (int)$row['user_id'],
                'name' => $row['name'],
                'avatar_color' => $row['avatar_color'],
                'has_avatar' => (bool)$row['has_avatar'],
                'lat' => (float)$row['lat'],
                'lng' => (float)$row['lng'],
                'accuracy_m' => $row['accuracy_m'] ? (float)$row['accuracy_m'] : null,
                'speed_mps' => $row['speed_mps'] ? (float)$row['speed_mps'] : null,
                'bearing_deg' => $row['bearing_deg'] ? (float)$row['bearing_deg'] : null,
                'motion_state' => $row['motion_state'],
                'recorded_at' => $row['recorded_at'],
                'updated_at' => $row['updated_at']
            ];
        }

        // Cache it
        $this->cache->setFamilySnapshot($familyId, $results);

        return $results;
    }

    /**
     * Get location history for a user.
     */
    public function getHistory(int $userId, int $familyId, array $options = []): array
    {
        $limit = min($options['limit'] ?? 100, 1000);
        $offset = $options['offset'] ?? 0;
        $startTime = $options['start_time'] ?? null;
        $endTime = $options['end_time'] ?? null;

        $sql = "
            SELECT * FROM tracking_locations
            WHERE user_id = ? AND family_id = ?
        ";
        $params = [$userId, $familyId];

        if ($startTime) {
            $sql .= " AND recorded_at >= ?";
            $params[] = $startTime;
        }

        if ($endTime) {
            $sql .= " AND recorded_at <= ?";
            $params[] = $endTime;
        }

        $sql .= " ORDER BY recorded_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->hydrateHistory($row);
        }

        return $results;
    }

    /**
     * Get history for entire family (for trails).
     */
    public function getFamilyHistory(int $familyId, array $options = []): array
    {
        $limit = min($options['limit'] ?? 500, 2000);
        $startTime = $options['start_time'] ?? Time::subSeconds(3600); // Last hour default
        $endTime = $options['end_time'] ?? null;
        $userIds = $options['user_ids'] ?? null;

        $sql = "
            SELECT
                tl.*,
                u.full_name as name,
                u.avatar_color
            FROM tracking_locations tl
            JOIN users u ON tl.user_id = u.id
            WHERE tl.family_id = ?
              AND tl.recorded_at >= ?
              AND u.status = 'active'
        ";
        $params = [$familyId, $startTime];

        if ($endTime) {
            $sql .= " AND tl.recorded_at <= ?";
            $params[] = $endTime;
        }

        if ($userIds && is_array($userIds)) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $sql .= " AND tl.user_id IN ({$placeholders})";
            $params = array_merge($params, $userIds);
        }

        $sql .= " ORDER BY tl.recorded_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'name' => $row['name'],
                'avatar_color' => $row['avatar_color'],
                'lat' => (float)$row['lat'],
                'lng' => (float)$row['lng'],
                'motion_state' => $row['motion_state'],
                'recorded_at' => $row['recorded_at']
            ];
        }

        return $results;
    }

    /**
     * Prune old history records.
     */
    public function pruneHistory(int $retentionDays): int
    {
        $cutoff = Time::subSeconds($retentionDays * 86400);

        $stmt = $this->db->prepare("
            DELETE FROM tracking_locations WHERE created_at < ? LIMIT 10000
        ");
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Hydrate current location row.
     */
    private function hydrateCurrent(array $row): array
    {
        return [
            'user_id' => (int)$row['user_id'],
            'family_id' => (int)$row['family_id'],
            'lat' => (float)$row['lat'],
            'lng' => (float)$row['lng'],
            'accuracy_m' => $row['accuracy_m'] ? (float)$row['accuracy_m'] : null,
            'speed_mps' => $row['speed_mps'] ? (float)$row['speed_mps'] : null,
            'bearing_deg' => $row['bearing_deg'] ? (float)$row['bearing_deg'] : null,
            'altitude_m' => $row['altitude_m'] ? (float)$row['altitude_m'] : null,
            'motion_state' => $row['motion_state'],
            'recorded_at' => $row['recorded_at'],
            'updated_at' => $row['updated_at'],
            'device_id' => $row['device_id'],
            'platform' => $row['platform'],
            'app_version' => $row['app_version']
        ];
    }

    /**
     * Hydrate history row.
     */
    private function hydrateHistory(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'family_id' => (int)$row['family_id'],
            'lat' => (float)$row['lat'],
            'lng' => (float)$row['lng'],
            'accuracy_m' => $row['accuracy_m'] ? (float)$row['accuracy_m'] : null,
            'speed_mps' => $row['speed_mps'] ? (float)$row['speed_mps'] : null,
            'bearing_deg' => $row['bearing_deg'] ? (float)$row['bearing_deg'] : null,
            'altitude_m' => $row['altitude_m'] ? (float)$row['altitude_m'] : null,
            'motion_state' => $row['motion_state'],
            'recorded_at' => $row['recorded_at'],
            'created_at' => $row['created_at']
        ];
    }
}
