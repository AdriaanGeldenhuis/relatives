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
     * Hysteresis buffer (metres) applied to circular boundaries so GPS jitter
     * near the edge does not flap enter/exit. Widened by the fix's own
     * accuracy: a 150m-accurate urban fix needs to clear the boundary by 150m
     * before we believe the transition.
     */
    private const MIN_HYSTERESIS_M = 30.0;

    /**
     * Process a new location against all geofences and places for the user's family.
     *
     * @param float|null $accuracyM Horizontal accuracy of this fix, used to
     *   widen the boundary hysteresis. Null = treat as a perfect fix.
     */
    public function process(int $familyId, int $userId, float $lat, float $lng, string $userName, ?float $accuracyM = null): void
    {
        $this->processGeofences($familyId, $userId, $lat, $lng, $userName, $accuracyM);
        $this->processPlaces($familyId, $userId, $lat, $lng, $userName, $accuracyM);
    }

    /** Buffer (m) to clear before a circular boundary transition is believed. */
    private function hysteresisBuffer(?float $accuracyM): float
    {
        return max(self::MIN_HYSTERESIS_M, $accuracyM ?? 0.0);
    }

    /**
     * Decide inside/outside for a circular boundary WITH hysteresis: to enter,
     * the fix must be inside by the buffer; to leave, outside by the buffer;
     * within the buffer band the previous state is held.
     */
    private function resolveCircularState(float $distance, float $radius, bool $wasInside, float $buffer): bool
    {
        if ($wasInside) {
            return $distance <= ($radius + $buffer);
        }
        return $distance <= max(0.0, $radius - $buffer);
    }

    private function processGeofences(int $familyId, int $userId, float $lat, float $lng, string $userName, ?float $accuracyM): void
    {
        $geofences = $this->geoRepo->listActive($familyId);
        if (empty($geofences)) {
            return;
        }

        // Load current states for this user
        $states = $this->loadGeofenceStates($userId);
        $buffer = $this->hysteresisBuffer($accuracyM);

        foreach ($geofences as $gf) {
            $gfId = (int) $gf['id'];
            $wasInside = (bool) ($states[$gfId] ?? false);
            $isInside = $this->isInsideGeofence($lat, $lng, $gf, $wasInside, $buffer);

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

    private function processPlaces(int $familyId, int $userId, float $lat, float $lng, string $userName, ?float $accuracyM): void
    {
        $places = $this->placesRepo->listAll($familyId);
        if (empty($places)) {
            return;
        }

        $states = $this->loadPlaceStates($userId);
        $buffer = $this->hysteresisBuffer($accuracyM);

        foreach ($places as $place) {
            $pId = (int) $place['id'];
            $wasInside = (bool) ($states[$pId] ?? false);
            $radius = (float) ($place['radius_m'] ?? 100);
            $distance = geo_haversineDistance((float) $place['lat'], (float) $place['lng'], $lat, $lng);
            $isInside = $this->resolveCircularState($distance, $radius, $wasInside, $buffer);

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

    private function isInsideGeofence(float $lat, float $lng, array $gf, bool $wasInside, float $buffer): bool
    {
        if ($gf['type'] === 'circle') {
            $distance = geo_haversineDistance(
                (float) $gf['center_lat'],
                (float) $gf['center_lng'],
                $lat,
                $lng
            );
            return $this->resolveCircularState($distance, (float) $gf['radius_m'], $wasInside, $buffer);
        }

        if ($gf['type'] === 'polygon' && !empty($gf['polygon_json'])) {
            $polygon = is_string($gf['polygon_json'])
                ? json_decode($gf['polygon_json'], true)
                : $gf['polygon_json'];
            if (is_array($polygon) && count($polygon) >= 3) {
                return geo_isPointInPolygon($lat, $lng, $this->normalizePolygon($polygon));
            }
        }

        return false;
    }

    /**
     * The frontend stores polygon vertices as [lat, lng] numeric pairs, but
     * geo_isPointInPolygon reads ['lat']/['lng'] keys — so every polygon
     * geofence silently never matched. Accept both shapes.
     */
    private function normalizePolygon(array $polygon): array
    {
        $out = [];
        foreach ($polygon as $p) {
            if (isset($p['lat'], $p['lng'])) {
                $out[] = ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']];
            } elseif (isset($p[0], $p[1])) {
                $out[] = ['lat' => (float) $p[0], 'lng' => (float) $p[1]];
            }
        }
        return $out;
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
        // Persistent per-user place state (tracking_place_state). The old
        // implementation inferred state from tracking_events in the last 24h,
        // so anyone who stayed at a place longer than a day re-fired
        // "arrived" every 24h and never fired "left".
        $stmt = $this->db->prepare("
            SELECT place_id, is_inside
            FROM tracking_place_state
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $states = [];
        foreach ($rows as $row) {
            $states[(int) $row['place_id']] = (bool) $row['is_inside'];
        }

        return $states;
    }

    private function updatePlaceState(int $familyId, int $placeId, int $userId, bool $isInside): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $enterCol = $isInside ? $now : null;
        $exitCol = $isInside ? null : $now;

        $stmt = $this->db->prepare("
            INSERT INTO tracking_place_state (family_id, place_id, user_id, is_inside, last_entered_at, last_exited_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_inside = VALUES(is_inside),
                last_entered_at = IF(VALUES(is_inside) = 1, VALUES(last_entered_at), last_entered_at),
                last_exited_at = IF(VALUES(is_inside) = 0, VALUES(last_exited_at), last_exited_at)
        ");
        $stmt->execute([$familyId, $placeId, $userId, (int) $isInside, $enterCol, $exitCol]);
    }
}
