<?php
declare(strict_types=1);

/**
 * Saved places repository
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
     * List all places for a family
     */
    public function listAll(int $familyId): array
    {
        $cached = $this->cache->getPlaces($familyId);
        if ($cached !== null) {
            return $cached;
        }

        $stmt = $this->db->prepare("
            SELECT id, label, category, lat, lng, radius_m, address, created_by_user_id, created_at
            FROM tracking_places
            WHERE family_id = ?
            ORDER BY label ASC
        ");
        $stmt->execute([$familyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->cache->setPlaces($familyId, $rows);
        return $rows;
    }

    /**
     * Create a place
     */
    public function create(int $familyId, int $userId, array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_places
                (family_id, label, category, lat, lng, radius_m, address, created_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $familyId,
            $data['label'],
            $data['category'],
            $data['lat'],
            $data['lng'],
            $data['radius_m'],
            $data['address'],
            $userId,
        ]);

        $this->cache->deletePlaces($familyId);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Delete a place
     */
    public function delete(int $familyId, int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM tracking_places WHERE id = ? AND family_id = ?");
        $result = $stmt->execute([$id, $familyId]);

        $this->cache->deletePlaces($familyId);
        return $result && $stmt->rowCount() > 0;
    }
}
