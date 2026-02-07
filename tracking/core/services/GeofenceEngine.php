<?php
declare(strict_types=1);

/**
 * Geofence processing engine
 * Checks location against all active geofences and places,
 * detects enter/exit transitions, fires events and alerts.
 */
class GeofenceEngine
{
    private PDO $db;
    private TrackingCache $cache;
    private GeofenceRepo $geoRepo;
    private PlacesRepo $placesRepo;
    private EventsRepo $eventsRepo;
    private AlertsEngine $alertsEngine;

    public function __construct(
        PDO $db,
        TrackingCache $cache,
        GeofenceRepo $geoRepo,
        PlacesRepo $placesRepo,
        EventsRepo $eventsRepo,
        AlertsEngine $alertsEngine
    ) {
        $this->db = $db;
        $this->cache = $cache;
        $this->geoRepo = $geoRepo;
        $this->placesRepo = $placesRepo;
        $this->eventsRepo = $eventsRepo;
        $this->alertsEngine = $alertsEngine;
    }

    /**
     * Process a new location against all geofences and places for the user's family.
     */
    public function process(int $familyId, int $userId, float $lat, float $lng, string $userName): void
    {
        $this->processGeofences($familyId, $userId, $lat, $lng, $userName);
        $this->processPlaces($familyId, $userId, $lat, $lng, $userName);
    }

    private function processGeofences(int $familyId, int $userId, float $lat, float $lng, string $userName): void
    {
        $geofences = $this->geoRepo->listActive($familyId);
        if (empty($geofences)) {
            return;
        }

        // Load current states for this user
        $states = $this->loadGeofenceStates($userId);

        foreach ($geofences as $gf) {
            $gfId = (int) $gf['id'];
            $wasInside = (bool) ($states[$gfId] ?? false);
            $isInside = $this->isInsideGeofence($lat, $lng, $gf);

            if ($isInside === $wasInside) {
                continue;
            }

            // State changed - update DB and cache
            $this->updateGeofenceState($familyId, $gfId, $userId, $isInside);

            $eventType = $isInside ? 'enter_geofence' : 'exit_geofence';
            $this->eventsRepo->insert($familyId, $userId, $eventType, [
                'geofence_id' => $gfId,
                'geofence_name' => $gf['name'],
                'user_name' => $userName,
                'lat' => $lat,
                'lng' => $lng,
            ]);

            $this->alertsEngine->fire($familyId, $userId, $eventType, $gfId, [
                'name' => $gf['name'],
                'user_name' => $userName,
            ]);
        }

        $this->cache->deleteGeofenceState($userId);
    }

    private function processPlaces(int $familyId, int $userId, float $lat, float $lng, string $userName): void
    {
        $places = $this->placesRepo->listAll($familyId);
        if (empty($places)) {
            return;
        }

        // Use geofence_state table with negative target_id to track place states
        $states = $this->loadPlaceStates($userId);

        foreach ($places as $place) {
            $pId = (int) $place['id'];
            $wasInside = (bool) ($states[$pId] ?? false);
            $radius = (float) ($place['radius_m'] ?? 100);
            $distance = geo_haversineDistance((float) $place['lat'], (float) $place['lng'], $lat, $lng);
            $isInside = $distance <= $radius;

            if ($isInside === $wasInside) {
                continue;
            }

            $this->updatePlaceState($familyId, $pId, $userId, $isInside);

            $eventType = $isInside ? 'arrive_place' : 'leave_place';
            $this->eventsRepo->insert($familyId, $userId, $eventType, [
                'place_id' => $pId,
                'place_name' => $place['label'],
                'user_name' => $userName,
                'lat' => $lat,
                'lng' => $lng,
            ]);

            $this->alertsEngine->fire($familyId, $userId, $eventType, $pId, [
                'name' => $place['label'],
                'user_name' => $userName,
            ]);
        }
    }

    private function isInsideGeofence(float $lat, float $lng, array $gf): bool
    {
        if ($gf['type'] === 'circle') {
            $distance = geo_haversineDistance(
                (float) $gf['center_lat'],
                (float) $gf['center_lng'],
                $lat,
                $lng
            );
            return $distance <= (float) $gf['radius_m'];
        }

        if ($gf['type'] === 'polygon' && !empty($gf['polygon_json'])) {
            $polygon = is_string($gf['polygon_json'])
                ? json_decode($gf['polygon_json'], true)
                : $gf['polygon_json'];
            if (is_array($polygon) && count($polygon) >= 3) {
                return geo_isPointInPolygon($lat, $lng, $polygon);
            }
        }

        return false;
    }

    private function loadGeofenceStates(int $userId): array
    {
        $cached = $this->cache->getGeofenceState($userId);
        if ($cached !== null) {
            return $cached;
        }

        $stmt = $this->db->prepare("
            SELECT geofence_id, is_inside
            FROM tracking_geofence_state
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $states = [];
        foreach ($rows as $row) {
            $states[(int) $row['geofence_id']] = (bool) $row['is_inside'];
        }

        $this->cache->setGeofenceState($userId, $states);
        return $states;
    }

    private function updateGeofenceState(int $familyId, int $gfId, int $userId, bool $isInside): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $enterCol = $isInside ? $now : null;
        $exitCol = $isInside ? null : $now;

        $stmt = $this->db->prepare("
            INSERT INTO tracking_geofence_state (family_id, geofence_id, user_id, is_inside, last_entered_at, last_exited_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_inside = VALUES(is_inside),
                last_entered_at = IF(VALUES(is_inside) = 1, VALUES(last_entered_at), last_entered_at),
                last_exited_at = IF(VALUES(is_inside) = 0, VALUES(last_exited_at), last_exited_at)
        ");
        $stmt->execute([$familyId, $gfId, $userId, (int) $isInside, $enterCol, $exitCol]);
    }

    private function loadPlaceStates(int $userId): array
    {
        // Reuse geofence_state with a convention: place states use negative geofence_id
        // This avoids another table. Alternatively, store in events-only approach.
        // For simplicity, use a separate query on tracking_events to determine last known state.
        $stmt = $this->db->prepare("
            SELECT
                JSON_EXTRACT(meta_json, '$.place_id') as place_id,
                event_type
            FROM tracking_events
            WHERE user_id = ?
              AND event_type IN ('arrive_place', 'leave_place')
              AND occurred_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY occurred_at DESC
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $states = [];
        foreach ($rows as $row) {
            $pId = (int) ($row['place_id'] ?? 0);
            if ($pId && !isset($states[$pId])) {
                $states[$pId] = $row['event_type'] === 'arrive_place';
            }
        }

        return $states;
    }

    private function updatePlaceState(int $familyId, int $placeId, int $userId, bool $isInside): void
    {
        // Place state is tracked via events only (no separate state table needed)
        // The loadPlaceStates method reads the latest event to determine state.
    }
}
