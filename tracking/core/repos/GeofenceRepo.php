<?php
declare(strict_types=1);

/**
 * Geofences repository
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
     * List active geofences for a family
     */
    public function listActive(int $familyId, bool $useCache = true): array
    {
        $cached = $useCache ? $this->cache->getGeofences($familyId) : null;
        if ($cached !== null) {
            return $cached;
        }

        $timestamp = 'UNIX_TIMESTAMP(COALESCE(updated_at, created_at)) * 1000';
        $revision = TrackingPolicy::hasColumn($this->db, 'tracking_geofences', 'revision_ms')
            ? "COALESCE(revision_ms, $timestamp)" : $timestamp;
        $stmt = $this->db->prepare("
            SELECT id, name, type, center_lat, center_lng, radius_m, polygon_json, active,
                   created_by_user_id, created_at, $revision AS definition_ms
            FROM tracking_geofences
            WHERE family_id = ? AND active = 1
            ORDER BY name ASC
        ");
        $stmt->execute([$familyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($useCache) $this->cache->setGeofences($familyId, $rows);
        return $rows;
    }

    /**
     * List all geofences (including inactive)
     */
    public function listAll(int $familyId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM tracking_geofences WHERE family_id = ? ORDER BY name ASC
        ");
        $stmt->execute([$familyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create a geofence
     */
    public function create(int $familyId, int $userId, array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_geofences
                (family_id, name, type, center_lat, center_lng, radius_m, polygon_json, created_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $familyId,
            $data['name'],
            $data['type'],
            $data['center_lat'],
            $data['center_lng'],
            $data['radius_m'],
            $data['polygon_json'],
            $userId,
        ]);

        $this->cache->deleteGeofences($familyId);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a geofence
     */
    public function update(int $familyId, int $id, array $data): bool
    {
        // COALESCE keeps the current active flag when the caller doesn't send
        // one — the edit form omits it, so `?? 1` silently re-armed geofences
        // an admin had deactivated every time they were edited.
        $versioned = TrackingPolicy::hasColumn($this->db, 'tracking_geofences', 'revision_ms');
        $revision = $versioned ? ', revision_ms = GREATEST(COALESCE(revision_ms, 0) + 1, ?)' : '';
        $stmt = $this->db->prepare("
            UPDATE tracking_geofences
            SET name = ?, type = ?, center_lat = ?, center_lng = ?, radius_m = ?,
                polygon_json = ?, active = COALESCE(?, active)$revision
            WHERE id = ? AND family_id = ?
        ");
        $params = [
            $data['name'],
            $data['type'],
            $data['center_lat'],
            $data['center_lng'],
            $data['radius_m'],
            $data['polygon_json'],
            $data['active'] ?? null,
        ];
        if ($versioned) $params[] = Time::nowMs();
        $stmt->execute([...$params, $id, $familyId]);
        $found = $stmt->rowCount() > 0;
        if (!$found && !$versioned) {
            $lookup = $this->db->prepare('SELECT 1 FROM tracking_geofences WHERE family_id = ? AND id = ?');
            $lookup->execute([$familyId, $id]);
            $found = (bool) $lookup->fetchColumn();
        }

        $this->cache->deleteGeofences($familyId);
        return $found;
    }

    /**
     * Delete a geofence
     */
    public function delete(int $familyId, int $id): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM tracking_geofences WHERE id = ? AND family_id = ?
        ");
        $result = $stmt->execute([$id, $familyId]);

        $this->cache->deleteGeofences($familyId);
        return $result && $stmt->rowCount() > 0;
    }
}
