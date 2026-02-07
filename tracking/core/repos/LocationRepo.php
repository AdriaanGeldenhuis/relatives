<?php
declare(strict_types=1);

class LocationRepo {
    private PDO $db;
    private TrackingCache $cache;

    public function __construct(PDO $db, TrackingCache $cache) {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * Upsert current location (one row per user)
     */
    public function upsertCurrent(int $userId, int $familyId, array $loc): void {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_current_locations
                (user_id, family_id, lat, lng, accuracy_m, altitude, speed, heading, battery, is_moving, source, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                lat = VALUES(lat), lng = VALUES(lng), accuracy_m = VALUES(accuracy_m),
                altitude = VALUES(altitude), speed = VALUES(speed), heading = VALUES(heading),
                battery = VALUES(battery), is_moving = VALUES(is_moving), source = VALUES(source),
                updated_at = NOW()
        ");
        $stmt->execute([
            $userId, $familyId,
            $loc['lat'], $loc['lng'], $loc['accuracy'],
            $loc['altitude'], $loc['speed'], $loc['heading'],
            $loc['battery'], $loc['is_moving'] ? 1 : 0, $loc['source'],
        ]);

        // Update cache
        $this->cache->setUserLocation($familyId, $userId, $loc);
    }

    /**
     * Store a history point
     */
    public function storeHistory(int $userId, int $familyId, array $loc): void {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_location_history
                (user_id, family_id, lat, lng, accuracy_m, altitude, speed, heading, battery, is_moving, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId, $familyId,
            $loc['lat'], $loc['lng'], $loc['accuracy'],
            $loc['altitude'], $loc['speed'], $loc['heading'],
            $loc['battery'], $loc['is_moving'] ? 1 : 0, $loc['source'],
        ]);
    }

    /**
     * Get current locations for all family members
     */
    public function getFamilyCurrent(int $familyId): array {
        // Get family member IDs
        $stmt = $this->db->prepare("SELECT id, full_name, avatar_color FROM users WHERE family_id = ? AND status = 'active'");
        $stmt->execute([$familyId]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($members)) return [];

        $userIds = array_column($members, 'id');
        $memberMap = [];
        foreach ($members as $m) {
            $memberMap[$m['id']] = $m;
        }

        // Try cache first
        $cached = $this->cache->getFamilyLocations($familyId, $userIds);

        // Get from DB for any missing
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->db->prepare("
            SELECT * FROM tracking_current_locations
            WHERE family_id = ? AND user_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$familyId], $userIds));
        $dbLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($dbLocations as $loc) {
            $uid = (int)$loc['user_id'];
            $member = $memberMap[$uid] ?? null;

            // Prefer cached data if fresher
            $cachedLoc = $cached[$uid] ?? null;

            $results[] = [
                'user_id' => $uid,
                'name' => $member['full_name'] ?? 'Unknown',
                'avatar_color' => $member['avatar_color'] ?? '#667eea',
                'lat' => (float)($cachedLoc['lat'] ?? $loc['lat']),
                'lng' => (float)($cachedLoc['lng'] ?? $loc['lng']),
                'accuracy' => (float)($cachedLoc['accuracy'] ?? $loc['accuracy_m']),
                'speed' => (float)($cachedLoc['speed'] ?? $loc['speed']),
                'heading' => isset($loc['heading']) ? (float)$loc['heading'] : null,
                'battery' => (int)($cachedLoc['battery'] ?? $loc['battery']),
                'is_moving' => (bool)($cachedLoc['moving'] ?? $loc['is_moving']),
                'updated_at' => $cachedLoc['ts'] ?? $loc['updated_at'],
            ];
        }

        // Add members with no location data
        $foundIds = array_column($results, 'user_id');
        foreach ($members as $m) {
            if (!in_array((int)$m['id'], $foundIds)) {
                $results[] = [
                    'user_id' => (int)$m['id'],
                    'name' => $m['full_name'],
                    'avatar_color' => $m['avatar_color'] ?? '#667eea',
                    'lat' => null,
                    'lng' => null,
                    'accuracy' => null,
                    'speed' => null,
                    'heading' => null,
                    'battery' => null,
                    'is_moving' => false,
                    'updated_at' => null,
                ];
            }
        }

        return $results;
    }

    /**
     * Get location history for a user
     */
    public function getHistory(int $userId, int $familyId, ?string $from = null, ?string $to = null, int $limit = 500): array {
        $sql = "SELECT * FROM tracking_location_history WHERE user_id = ? AND family_id = ?";
        $params = [$userId, $familyId];

        if ($from) {
            $sql .= " AND created_at >= ?";
            $params[] = $from;
        }
        if ($to) {
            $sql .= " AND created_at <= ?";
            $params[] = $to;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Prune old history
     */
    public function pruneHistory(int $retentionDays = 30): int {
        $stmt = $this->db->prepare("
            DELETE FROM tracking_location_history
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$retentionDays]);
        return $stmt->rowCount();
    }
}
