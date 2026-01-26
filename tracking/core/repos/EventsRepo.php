<?php
/**
 * Events Repository
 *
 * Manages tracking_events table.
 */

class EventsRepo
{
    private PDO $db;

    // Event types
    const TYPE_LOCATION_UPDATE = 'location_update';
    const TYPE_ENTER_GEOFENCE = 'enter_geofence';
    const TYPE_EXIT_GEOFENCE = 'exit_geofence';
    const TYPE_ARRIVE_PLACE = 'arrive_place';
    const TYPE_LEAVE_PLACE = 'leave_place';
    const TYPE_SESSION_ON = 'session_on';
    const TYPE_SESSION_OFF = 'session_off';
    const TYPE_SETTINGS_CHANGE = 'settings_change';
    const TYPE_ALERT_TRIGGERED = 'alert_triggered';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Log an event.
     */
    public function log(int $familyId, ?int $userId, string $eventType, array $meta = [], ?string $occurredAt = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_events (
                family_id, user_id, event_type, meta_json, occurred_at, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, NOW()
            )
        ");

        $stmt->execute([
            $familyId,
            $userId,
            $eventType,
            !empty($meta) ? json_encode($meta) : null,
            $occurredAt ?? Time::now()
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Log geofence enter event.
     */
    public function logGeofenceEnter(int $familyId, int $userId, int $geofenceId, string $geofenceName, float $lat, float $lng): int
    {
        return $this->log($familyId, $userId, self::TYPE_ENTER_GEOFENCE, [
            'geofence_id' => $geofenceId,
            'geofence_name' => $geofenceName,
            'lat' => $lat,
            'lng' => $lng
        ]);
    }

    /**
     * Log geofence exit event.
     */
    public function logGeofenceExit(int $familyId, int $userId, int $geofenceId, string $geofenceName, float $lat, float $lng): int
    {
        return $this->log($familyId, $userId, self::TYPE_EXIT_GEOFENCE, [
            'geofence_id' => $geofenceId,
            'geofence_name' => $geofenceName,
            'lat' => $lat,
            'lng' => $lng
        ]);
    }

    /**
     * Log place arrival event.
     */
    public function logPlaceArrive(int $familyId, int $userId, int $placeId, string $placeLabel, float $lat, float $lng): int
    {
        return $this->log($familyId, $userId, self::TYPE_ARRIVE_PLACE, [
            'place_id' => $placeId,
            'place_label' => $placeLabel,
            'lat' => $lat,
            'lng' => $lng
        ]);
    }

    /**
     * Log place departure event.
     */
    public function logPlaceLeave(int $familyId, int $userId, int $placeId, string $placeLabel, float $lat, float $lng): int
    {
        return $this->log($familyId, $userId, self::TYPE_LEAVE_PLACE, [
            'place_id' => $placeId,
            'place_label' => $placeLabel,
            'lat' => $lat,
            'lng' => $lng
        ]);
    }

    /**
     * Log session start.
     */
    public function logSessionOn(int $familyId, int $userId): int
    {
        return $this->log($familyId, $userId, self::TYPE_SESSION_ON, [
            'started_by' => $userId
        ]);
    }

    /**
     * Log session end.
     */
    public function logSessionOff(int $familyId, ?int $userId = null): int
    {
        return $this->log($familyId, $userId, self::TYPE_SESSION_OFF, [
            'reason' => $userId ? 'manual' : 'expired'
        ]);
    }

    /**
     * Log settings change.
     */
    public function logSettingsChange(int $familyId, int $userId, array $changes): int
    {
        return $this->log($familyId, $userId, self::TYPE_SETTINGS_CHANGE, [
            'changed_by' => $userId,
            'fields' => array_keys($changes)
        ]);
    }

    /**
     * Log alert triggered.
     */
    public function logAlertTriggered(int $familyId, int $userId, string $ruleType, int $targetId, string $targetName): int
    {
        return $this->log($familyId, $userId, self::TYPE_ALERT_TRIGGERED, [
            'rule_type' => $ruleType,
            'target_id' => $targetId,
            'target_name' => $targetName
        ]);
    }

    /**
     * Get events for a family.
     */
    public function getList(int $familyId, array $options = []): array
    {
        $limit = min($options['limit'] ?? 50, 500);
        $offset = $options['offset'] ?? 0;
        $userId = $options['user_id'] ?? null;
        $eventTypes = $options['event_types'] ?? null;
        $startTime = $options['start_time'] ?? null;
        $endTime = $options['end_time'] ?? null;

        $sql = "
            SELECT
                e.*,
                u.full_name as user_name,
                u.avatar_color
            FROM tracking_events e
            LEFT JOIN users u ON e.user_id = u.id
            WHERE e.family_id = ?
        ";
        $params = [$familyId];

        if ($userId) {
            $sql .= " AND e.user_id = ?";
            $params[] = $userId;
        }

        if ($eventTypes && is_array($eventTypes)) {
            $placeholders = implode(',', array_fill(0, count($eventTypes), '?'));
            $sql .= " AND e.event_type IN ({$placeholders})";
            $params = array_merge($params, $eventTypes);
        }

        if ($startTime) {
            $sql .= " AND e.occurred_at >= ?";
            $params[] = $startTime;
        }

        if ($endTime) {
            $sql .= " AND e.occurred_at <= ?";
            $params[] = $endTime;
        }

        $sql .= " ORDER BY e.occurred_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->hydrate($row);
        }

        return $results;
    }

    /**
     * Get recent events (for dashboard).
     */
    public function getRecent(int $familyId, int $limit = 20): array
    {
        // Exclude high-frequency events
        return $this->getList($familyId, [
            'limit' => $limit,
            'event_types' => [
                self::TYPE_ENTER_GEOFENCE,
                self::TYPE_EXIT_GEOFENCE,
                self::TYPE_ARRIVE_PLACE,
                self::TYPE_LEAVE_PLACE,
                self::TYPE_SESSION_ON,
                self::TYPE_SESSION_OFF,
                self::TYPE_ALERT_TRIGGERED
            ]
        ]);
    }

    /**
     * Count events by type.
     */
    public function countByType(int $familyId, ?string $startTime = null): array
    {
        $sql = "
            SELECT event_type, COUNT(*) as count
            FROM tracking_events
            WHERE family_id = ?
        ";
        $params = [$familyId];

        if ($startTime) {
            $sql .= " AND occurred_at >= ?";
            $params[] = $startTime;
        }

        $sql .= " GROUP BY event_type";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $counts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['event_type']] = (int)$row['count'];
        }

        return $counts;
    }

    /**
     * Prune old events.
     */
    public function prune(int $retentionDays): int
    {
        $cutoff = Time::subSeconds($retentionDays * 86400);

        $stmt = $this->db->prepare("
            DELETE FROM tracking_events WHERE created_at < ? LIMIT 10000
        ");
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Hydrate an event row.
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'family_id' => (int)$row['family_id'],
            'user_id' => $row['user_id'] ? (int)$row['user_id'] : null,
            'user_name' => $row['user_name'] ?? null,
            'avatar_color' => $row['avatar_color'] ?? null,
            'event_type' => $row['event_type'],
            'meta' => $row['meta_json'] ? json_decode($row['meta_json'], true) : null,
            'occurred_at' => $row['occurred_at'],
            'created_at' => $row['created_at']
        ];
    }
}
