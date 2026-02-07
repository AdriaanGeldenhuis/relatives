<?php
declare(strict_types=1);

class PlacesRepo {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getForFamily(int $familyId): array {
        $stmt = $this->db->prepare("
            SELECT p.*, u.full_name as created_by_name
            FROM tracking_places p
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.family_id = ?
            ORDER BY p.name ASC
        ");
        $stmt->execute([$familyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(int $familyId, int $createdBy, array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_places (family_id, created_by, name, icon, lat, lng, address, radius_m)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $familyId, $createdBy,
            $data['name'],
            $data['icon'] ?? null,
            $data['lat'],
            $data['lng'],
            $data['address'] ?? null,
            $data['radius_m'] ?? 100,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function delete(int $id, int $familyId): bool {
        $stmt = $this->db->prepare("DELETE FROM tracking_places WHERE id = ? AND family_id = ?");
        $stmt->execute([$id, $familyId]);
        return $stmt->rowCount() > 0;
    }
}
