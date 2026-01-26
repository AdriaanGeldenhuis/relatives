<?php
/**
 * Places Repository
 *
 * Manages tracking_places table.
 */

class PlacesRepo
{
    private PDO $db;
    private TrackingCache $cache;

    public function __construct(PDO $db, TrackingCache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * Get all places for a family.
     */
    public function getAll(int $familyId): array
    {
        // Try cache
        $cached = $this->cache->getPlaces($familyId);
        if ($cached !== null) {
            return $cached;
        }

        $stmt = $this->db->prepare("
            SELECT p.*, u.full_name as created_by_name
            FROM tracking_places p
            LEFT JOIN users u ON p.created_by_user_id = u.id
            WHERE p.family_id = ?
            ORDER BY
                CASE p.category
                    WHEN 'home' THEN 1
                    WHEN 'work' THEN 2
                    WHEN 'school' THEN 3
                    ELSE 4
                END,
                p.label ASC
        ");
        $stmt->execute([$familyId]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->hydrate($row);
        }

        // Cache it
        $this->cache->setPlaces($familyId, $results);

        return $results;
    }

    /**
     * Get a single place.
     */
    public function get(int $id, int $familyId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM tracking_places
            WHERE id = ? AND family_id = ?
        ");
        $stmt->execute([$id, $familyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Create a new place.
     */
    public function create(int $familyId, int $userId, array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_places (
                family_id, label, category, lat, lng, radius_m,
                address, created_by_user_id, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )
        ");

        $stmt->execute([
            $familyId,
            $data['label'],
            $data['category'] ?? 'other',
            $data['lat'],
            $data['lng'],
            $data['radius_m'] ?? 100,
            $data['address'] ?? null,
            $userId
        ]);

        $id = (int)$this->db->lastInsertId();

        // Invalidate cache
        $this->cache->deletePlaces($familyId);

        return $id;
    }

    /**
     * Update a place.
     */
    public function update(int $id, int $familyId, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowedFields = ['label', 'category', 'lat', 'lng', 'radius_m', 'address'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $values[] = $id;
        $values[] = $familyId;

        $sql = "UPDATE tracking_places SET " . implode(', ', $fields) . " WHERE id = ? AND family_id = ?";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute($values);

        if ($result) {
            $this->cache->deletePlaces($familyId);
        }

        return $result && $stmt->rowCount() > 0;
    }

    /**
     * Delete a place.
     */
    public function delete(int $id, int $familyId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM tracking_places WHERE id = ? AND family_id = ?
        ");
        $result = $stmt->execute([$id, $familyId]);

        if ($result && $stmt->rowCount() > 0) {
            $this->cache->deletePlaces($familyId);
            return true;
        }

        return false;
    }

    /**
     * Find places near a location.
     */
    public function findNear(int $familyId, float $lat, float $lng, int $maxDistanceM = 500): array
    {
        $places = $this->getAll($familyId);
        $nearby = [];

        foreach ($places as $place) {
            $distance = $this->haversineDistance(
                $lat, $lng,
                $place['lat'], $place['lng']
            );

            // Check if within place radius or max distance
            $withinRadius = $distance <= $place['radius_m'];
            $withinMax = $distance <= $maxDistanceM;

            if ($withinRadius || $withinMax) {
                $place['distance_m'] = round($distance);
                $place['is_inside'] = $withinRadius;
                $nearby[] = $place;
            }
        }

        // Sort by distance
        usort($nearby, fn($a, $b) => $a['distance_m'] <=> $b['distance_m']);

        return $nearby;
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
     * Hydrate a row.
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'family_id' => (int)$row['family_id'],
            'label' => $row['label'],
            'category' => $row['category'],
            'lat' => (float)$row['lat'],
            'lng' => (float)$row['lng'],
            'radius_m' => (int)$row['radius_m'],
            'address' => $row['address'],
            'created_by_user_id' => $row['created_by_user_id'] ? (int)$row['created_by_user_id'] : null,
            'created_by_name' => $row['created_by_name'] ?? null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }
}
