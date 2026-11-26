<?php
declare(strict_types=1);

final class GeofenceEngine
{
    private const LIVE_MS = 120000;
    private const MAX_REPLAY_MS = 86400000;
    private array $definitions = [];

    public function __construct(
        private PDO $db,
        private GeofenceRepo $geofences,
        private PlacesRepo $places,
        private EventsRepo $events,
        private AlertsEngine $alerts,
    ) {}

    public function resetSource(int $family, int $user): void
    {
        foreach (['tracking_geofence_state', 'tracking_place_state'] as $table) {
            $this->db->prepare("DELETE FROM $table WHERE family_id = ? AND user_id = ?")->execute([$family, $user]);
        }
    }

    /**
     * Called under the same family and member locks as location ingestion.
     * The repair job can initialize unknown states. Established state is
     * advanced exclusively by observed samples in the ingest path.
     */
    public function observe(
        int $family, int $user, string $name, array $fix, int $cutoff,
        bool $advancing = true, bool $baselineOnly = false,
    ): void {
        $capture = LocationQuality::capture($fix);
        $age = Time::nowMs() - $capture;
        $evidence = TrackingPolicy::geofenceEvidence($this->db);
        $accuracy = (float) ($fix['accuracy_m'] ?? 0);
        if ($capture < $cutoff || $age > self::MAX_REPLAY_MS || $accuracy <= 0
            || (!$evidence && (!$advancing || $age > self::LIVE_MS))) {
            return;
        }
        $this->definitions[$family] ??= [
            $this->geofences->listActive($family, false),
            $this->places->listAll($family, false),
        ];
        [$fences, $places] = $this->definitions[$family];
        $this->evaluate($family, $user, $name, $fix, $accuracy, $capture, $cutoff, $fences, true, $evidence, $baselineOnly, $advancing);
        $this->evaluate($family, $user, $name, $fix, $accuracy, $capture, $cutoff, $places, false, $evidence, $baselineOnly, $advancing);
    }

    private function evaluate(
        int $family, int $user, string $name, array $fix, float $accuracy, int $capture, int $cutoff,
        array $boundaries, bool $geofence, bool $evidence, bool $baselineOnly, bool $advancing,
    ): void {
        if (!$boundaries) {
            return;
        }
        $table = $geofence ? 'tracking_geofence_state' : 'tracking_place_state';
        $key = $geofence ? 'geofence_id' : 'place_id';
        $extra = $evidence ? ', definition_hash, observed_ms' : ', UNIX_TIMESTAMP(updated_at) * 1000 AS observed_ms';
        $stmt = $this->db->prepare("SELECT $key, is_inside$extra FROM $table WHERE family_id = ? AND user_id = ?");
        $stmt->execute([$family, $user]);
        $states = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, $key);
        $margin = max(8.0, $accuracy);

        foreach ($boundaries as $boundary) {
            $id = (int) $boundary['id'];
            $revision = (int) ($boundary['definition_ms'] ?? 0);
            $state = $states[$id] ?? null;
            $hash = hash('sha256', json_encode([
                $revision, $boundary['type'] ?? 'circle', $boundary['center_lat'] ?? $boundary['lat'] ?? null,
                $boundary['center_lng'] ?? $boundary['lng'] ?? null, $boundary['radius_m'], $boundary['polygon_json'] ?? null,
            ], JSON_THROW_ON_ERROR));
            $known = $state !== null && (int) $state['observed_ms'] >= max($cutoff, $revision)
                && (!$evidence || $hash === $state['definition_hash']);
            if ($capture < $revision || (!$known && !$advancing)
                || ($known && ($baselineOnly || ($evidence && $capture <= $state['observed_ms'])))) {
                continue;
            }

            $distance = self::signedDistance((float) $fix['lat'], (float) $fix['lng'], $boundary);
            if ($distance === null || abs($distance) <= $margin) {
                if ($known && $evidence) {
                    // Retain the confirmed side and advance the observation watermark.
                    $this->save($table, $key, $family, $id, $user, (bool) $state['is_inside'], $capture, $hash, false, true);
                }
                continue;
            }

            $inside = $distance > 0;
            $crossed = $known && !$baselineOnly && $inside !== (bool) $state['is_inside'];
            $this->save($table, $key, $family, $id, $user, $inside, $capture, $hash, $crossed, $evidence);
            if (!$crossed) {
                continue;
            }

            $type = $geofence ? ($inside ? 'enter_geofence' : 'exit_geofence') : ($inside ? 'arrive_place' : 'leave_place');
            $label = $geofence ? $boundary['name'] : $boundary['label'];
            $eventId = $this->events->insert($family, $user, $type, [
                $key => $id,
                ($geofence ? 'geofence_name' : 'place_name') => $label,
                'user_name' => $name, 'captured_at_ms' => $capture,
            ], $capture);
            $this->alerts->record($family, $user, $name, $type, $label, $eventId, $capture);
            TrackingPolicy::metric('boundary_crossing', [
                'type' => $type, 'capture_to_commit_ms' => max(0, Time::nowMs() - $capture),
            ]);
        }
    }

    private function save(
        string $table, string $key, int $family, int $id, int $user, bool $inside,
        int $capture, string $hash, bool $crossed, bool $evidence,
    ): void {
        $columns = $evidence ? ', observed_ms, definition_hash' : '';
        $values = $evidence ? ', ?, ?' : '';
        $update = $evidence ? ', observed_ms = VALUES(observed_ms), definition_hash = VALUES(definition_hash)' : '';
        $stmt = $this->db->prepare("
            INSERT INTO $table (family_id, $key, user_id, is_inside, last_entered_at, last_exited_at$columns)
            VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?)$values)
            ON DUPLICATE KEY UPDATE is_inside = VALUES(is_inside),
                last_entered_at = COALESCE(VALUES(last_entered_at), last_entered_at),
                last_exited_at = COALESCE(VALUES(last_exited_at), last_exited_at)$update
        ");
        $seconds = intdiv($capture, 1000);
        $params = [$family, $id, $user, (int) $inside, $crossed && $inside ? $seconds : null,
            $crossed && !$inside ? $seconds : null];
        $stmt->execute($evidence ? [...$params, $capture, $hash] : $params);
    }

    /** Distance to the boundary in metres, positive inside. */
    public static function signedDistance(float $lat, float $lng, array $boundary): ?float
    {
        if (($boundary['type'] ?? 'circle') === 'circle') {
            $centerLat = $boundary['center_lat'] ?? $boundary['lat'] ?? null;
            $centerLng = $boundary['center_lng'] ?? $boundary['lng'] ?? null;
            if (!isset($centerLat, $centerLng, $boundary['radius_m'])) {
                return null;
            }
            return (float) $boundary['radius_m']
                - geo_haversineDistance($lat, $lng, (float) $centerLat, (float) $centerLng);
        }
        $raw = $boundary['polygon_json'] ?? null;
        $points = TrackingValidator::polygon(is_string($raw) ? json_decode($raw, true) : $raw);
        if ($points === null) {
            return null;
        }

        $inside = false;
        $closest = INF;
        $scale = cos(deg2rad($lat)) * 111195;
        // Unwrap vertices together so a polygon crossing the date line stays small.
        $reference = $points[0][1];
        $relative = static fn(float $value, float $origin): float => fmod($value - $origin + 540.0, 360.0) - 180.0;
        $origin = $relative($lng, $reference);
        $project = static fn(array $point): array => [
            ($relative($point[1], $reference) - $origin) * $scale, ($point[0] - $lat) * 111195,
        ];
        [$ax, $ay] = $project($points[count($points) - 1]);
        foreach ($points as $point) {
            [$bx, $by] = $project($point);
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $length = $dx * $dx + $dy * $dy;
            $fraction = $length > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $length)) : 0;
            $closest = min($closest, hypot($ax + $fraction * $dx, $ay + $fraction * $dy));
            if (($ay > 0) !== ($by > 0) && $ax - $ay * $dx / $dy > 0) {
                $inside = !$inside;
            }
            [$ax, $ay] = [$bx, $by];
        }
        return $inside ? $closest : -$closest;
    }
}
