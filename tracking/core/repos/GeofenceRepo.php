<?php
declare(strict_types=1);

class GeofenceRepo {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getForFamily(int $familyId): array {
        $stmt = $this->db->prepare("
            SELECT g.*, u.full_name as created_by_name
            FROM tracking_geofences g
            LEFT JOIN users u ON g.created_by = u.id
            WHERE g.family_id = ?
            ORDER BY g.created_at DESC
        ");
        $stmt->execute([$familyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id, int $familyId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM tracking_geofences WHERE id = ? AND family_id = ?");
        $stmt->execute([$id, $familyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(int $familyId, int $createdBy, array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_geofences (family_id, created_by, name, type, lat, lng, radius_m, polygon_json, color, notify_enter, notify_exit)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $familyId, $createdBy,
            $data['name'],
            $data['type'] ?? 'circle',
            $data['lat'] ?? null,
            $data['lng'] ?? null,
            $data['radius_m'] ?? 200,
            isset($data['polygon']) ? json_encode($data['polygon']) : null,
            $data['color'] ?? '#667eea',
            $data['notify_enter'] ?? 1,
            $data['notify_exit'] ?? 1,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, int $familyId, array $data): bool {
        $allowed = ['name', 'type', 'lat', 'lng', 'radius_m', 'color', 'notify_enter', 'notify_exit', 'active'];
        $sets = [];
        $values = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $sets[] = "$key = ?";
                $values[] = $data[$key];
            }
        }

        if (array_key_exists('polygon', $data)) {
            $sets[] = "polygon_json = ?";
            $values[] = json_encode($data['polygon']);
        }

        if (empty($sets)) return false;

        $values[] = $id;
        $values[] = $familyId;

        $stmt = $this->db->prepare("UPDATE tracking_geofences SET " . implode(', ', $sets) . " WHERE id = ? AND family_id = ?");
        $stmt->execute($values);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $familyId): bool {
        // Delete states first
        $stmt = $this->db->prepare("DELETE FROM tracking_geofence_states WHERE geofence_id = ?");
        $stmt->execute([$id]);

        $stmt = $this->db->prepare("DELETE FROM tracking_geofences WHERE id = ? AND family_id = ?");
        $stmt->execute([$id, $familyId]);
        return $stmt->rowCount() > 0;
    }
}
