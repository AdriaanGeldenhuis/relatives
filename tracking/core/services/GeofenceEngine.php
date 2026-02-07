<?php
declare(strict_types=1);

class GeofenceEngine {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Process geofences for a location update
     * Returns array of events [{type: 'enter'|'exit', geofence: {...}}]
     */
    public function process(int $userId, int $familyId, float $lat, float $lng): array {
        $events = [];

        try {
            // Get active geofences for this family
            $stmt = $this->db->prepare("
                SELECT g.*, gs.is_inside
                FROM tracking_geofences g
                LEFT JOIN tracking_geofence_states gs ON g.id = gs.geofence_id AND gs.user_id = ?
                WHERE g.family_id = ? AND g.active = 1
            ");
            $stmt->execute([$userId, $familyId]);
            $geofences = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($geofences as $gf) {
                $wasInside = (bool)($gf['is_inside'] ?? false);
                $isInside = $this->isInsideGeofence($lat, $lng, $gf);

                if ($isInside && !$wasInside) {
                    // Entered geofence
                    $this->updateState($gf['id'], $userId, true);
                    if ($gf['notify_enter']) {
                        $events[] = ['type' => 'enter', 'geofence' => $gf];
                    }
                } elseif (!$isInside && $wasInside) {
                    // Exited geofence
                    $this->updateState($gf['id'], $userId, false);
                    if ($gf['notify_exit']) {
                        $events[] = ['type' => 'exit', 'geofence' => $gf];
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('GeofenceEngine::process error: ' . $e->getMessage());
        }

        return $events;
    }

    /**
     * Check if a point is inside a geofence
     */
    private function isInsideGeofence(float $lat, float $lng, array $geofence): bool {
        if ($geofence['type'] === 'circle') {
            $distance = geo_haversineDistance($lat, $lng, (float)$geofence['lat'], (float)$geofence['lng']);
            return $distance <= (int)$geofence['radius_m'];
        }

        if ($geofence['type'] === 'polygon' && !empty($geofence['polygon_json'])) {
            $polygon = json_decode($geofence['polygon_json'], true);
            if (is_array($polygon) && count($polygon) >= 3) {
                return geo_isPointInPolygon($lat, $lng, $polygon);
            }
        }

        return false;
    }

    /**
     * Update geofence state
     */
    private function updateState(int $geofenceId, int $userId, bool $isInside): void {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_geofence_states (geofence_id, user_id, is_inside, entered_at, exited_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                is_inside = VALUES(is_inside),
                entered_at = IF(VALUES(is_inside) = 1, NOW(), entered_at),
                exited_at = IF(VALUES(is_inside) = 0, NOW(), exited_at),
                updated_at = NOW()
        ");
        $stmt->execute([
            $geofenceId,
            $userId,
            $isInside ? 1 : 0,
            $isInside ? date('Y-m-d H:i:s') : null,
            !$isInside ? date('Y-m-d H:i:s') : null,
        ]);
    }
}
