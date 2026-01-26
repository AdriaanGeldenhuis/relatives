<?php
/**
 * Geofence Repository
 *
 * Manages tracking_geofences and tracking_geofence_state tables.
 */

class GeofenceRepo
{
    private PDO $db;
    private TrackingCache $cache;

    public function __construct(PDO $db, TrackingCache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * Get all geofences for a family.
     */
    public function getAll(int $familyId, bool $activeOnly = false): array
    {
        // Try cache (only for all/active)
        if (!$activeOnly) {
            $cached = $this->cache->getGeofences($familyId);
            if ($cached !== null) {
                return $cached;
            }
        }

        $sql = "
            SELECT g.*, u.full_name as created_by_name
            FROM tracking_geofences g
            LEFT JOIN users u ON g.created_by_user_id = u.id
            WHERE g.family_id = ?
        ";

        if ($activeOnly) {
            $sql .= " AND g.active = 1";
        }

        $sql .= " ORDER BY g.name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$familyId]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->hydrate($row);
        }

        // Cache all geofences
        if (!$activeOnly) {
            $this->cache->setGeofences($familyId, $results);
        }

        return $results;
    }

    /**
     * Get active geofences for a family (for processing).
     */
    public function getActive(int $familyId): array
    {
        return $this->getAll($familyId, true);
    }

    /**
     * Get a single geofence.
     */
    public function get(int $id, int $familyId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM tracking_geofences
            WHERE id = ? AND family_id = ?
        ");
        $stmt->execute([$id, $familyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Create a new geofence.
     */
    public function create(int $familyId, int $userId, array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_geofences (
                family_id, name, type, center_lat, center_lng, radius_m,
                polygon_json, active, created_by_user_id, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )
        ");

        $stmt->execute([
            $familyId,
            $data['name'],
            $data['type'] ?? 'circle',
            $data['center_lat'] ?? null,
            $data['center_lng'] ?? null,
            $data['radius_m'] ?? null,
            isset($data['polygon_json']) ? json_encode($data['polygon_json']) : null,
            $data['active'] ?? 1,
            $userId
        ]);

        $id = (int)$this->db->lastInsertId();

        // Invalidate cache
        $this->cache->deleteGeofences($familyId);

        return $id;
    }

    /**
     * Update a geofence.
     */
    public function update(int $id, int $familyId, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowedFields = ['name', 'type', 'center_lat', 'center_lng', 'radius_m', 'active'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (isset($data['polygon_json'])) {
            $fields[] = "polygon_json = ?";
            $values[] = json_encode($data['polygon_json']);
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $values[] = $id;
        $values[] = $familyId;

        $sql = "UPDATE tracking_geofences SET " . implode(', ', $fields) . " WHERE id = ? AND family_id = ?";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute($values);

        if ($result) {
            $this->cache->deleteGeofences($familyId);
        }

        return $result && $stmt->rowCount() > 0;
    }

    /**
     * Delete a geofence.
     */
    public function delete(int $id, int $familyId): bool
    {
        // Delete state entries first
        $stmt = $this->db->prepare("DELETE FROM tracking_geofence_state WHERE geofence_id = ?");
        $stmt->execute([$id]);

        // Delete geofence
        $stmt = $this->db->prepare("DELETE FROM tracking_geofences WHERE id = ? AND family_id = ?");
        $result = $stmt->execute([$id, $familyId]);

        if ($result && $stmt->rowCount() > 0) {
            $this->cache->deleteGeofences($familyId);
            return true;
        }

        return false;
    }

    // =========================================
    // GEOFENCE STATE
    // =========================================

    /**
     * Get geofence state for a user.
     */
    public function getUserState(int $userId): array
    {
        // Try cache
        $cached = $this->cache->getGeofenceState($userId);
        if ($cached !== null) {
            return $cached;
        }

        $stmt = $this->db->prepare("
            SELECT geofence_id, is_inside, last_entered_at, last_exited_at
            FROM tracking_geofence_state
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);

        $state = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $state[$row['geofence_id']] = [
                'is_inside' => (bool)$row['is_inside'],
                'last_entered_at' => $row['last_entered_at'],
                'last_exited_at' => $row['last_exited_at']
            ];
        }

        // Cache it
        $this->cache->setGeofenceState($userId, $state);

        return $state;
    }

    /**
     * Update geofence state for a user.
     */
    public function updateState(int $familyId, int $geofenceId, int $userId, bool $isInside): array
    {
        $now = Time::now();
        $currentState = $this->getUserState($userId);
        $previousInside = isset($currentState[$geofenceId]) ? $currentState[$geofenceId]['is_inside'] : false;

        // Determine transition
        $entered = !$previousInside && $isInside;
        $exited = $previousInside && !$isInside;

        // Upsert state
        $stmt = $this->db->prepare("
            INSERT INTO tracking_geofence_state (
                family_id, geofence_id, user_id, is_inside,
                last_entered_at, last_exited_at, updated_at
            ) VALUES (
                ?, ?, ?, ?,
                IF(? = 1, ?, NULL),
                IF(? = 0, ?, NULL),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                is_inside = VALUES(is_inside),
                last_entered_at = IF(VALUES(is_inside) = 1 AND is_inside = 0, VALUES(last_entered_at), last_entered_at),
                last_exited_at = IF(VALUES(is_inside) = 0 AND is_inside = 1, VALUES(last_exited_at), last_exited_at),
                updated_at = NOW()
        ");

        $stmt->execute([
            $familyId,
            $geofenceId,
            $userId,
            $isInside ? 1 : 0,
            $isInside ? 1 : 0,
            $now,
            $isInside ? 1 : 0,
            $now
        ]);

        // Invalidate cache
        $this->cache->deleteGeofenceState($userId);

        return [
            'geofence_id' => $geofenceId,
            'user_id' => $userId,
            'is_inside' => $isInside,
            'entered' => $entered,
            'exited' => $exited,
            'transition_at' => $now
        ];
    }

    /**
     * Check if a point is inside a geofence.
     */
    public function isPointInside(float $lat, float $lng, array $geofence): bool
    {
        if ($geofence['type'] === 'circle') {
            $distance = $this->haversineDistance(
                $lat, $lng,
                $geofence['center_lat'], $geofence['center_lng']
            );
            return $distance <= $geofence['radius_m'];
        }

        // Polygon support (future)
        if ($geofence['type'] === 'polygon' && !empty($geofence['polygon_json'])) {
            return $this->isPointInPolygon($lat, $lng, $geofence['polygon_json']);
        }

        return false;
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

    /**
     * Check if point is inside polygon (ray casting).
     */
    private function isPointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $n = count($polygon);
        if ($n < 3) {
            return false;
        }

        $inside = false;

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $polygon[$i]['lat'];
            $yi = $polygon[$i]['lng'];
            $xj = $polygon[$j]['lat'];
            $yj = $polygon[$j]['lng'];

            if ((($yi > $lng) !== ($yj > $lng)) &&
                ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Hydrate a geofence row.
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'family_id' => (int)$row['family_id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'center_lat' => $row['center_lat'] ? (float)$row['center_lat'] : null,
            'center_lng' => $row['center_lng'] ? (float)$row['center_lng'] : null,
            'radius_m' => $row['radius_m'] ? (int)$row['radius_m'] : null,
            'polygon_json' => $row['polygon_json'] ? json_decode($row['polygon_json'], true) : null,
            'active' => (bool)$row['active'],
            'created_by_user_id' => $row['created_by_user_id'] ? (int)$row['created_by_user_id'] : null,
            'created_by_name' => $row['created_by_name'] ?? null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }
}
