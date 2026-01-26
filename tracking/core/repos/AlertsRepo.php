<?php
/**
 * Alerts Repository
 *
 * Manages tracking_alert_rules and tracking_alert_deliveries tables.
 */

class AlertsRepo
{
    private PDO $db;
    private TrackingCache $cache;

    // Rule types
    const RULE_ARRIVE_PLACE = 'arrive_place';
    const RULE_LEAVE_PLACE = 'leave_place';
    const RULE_ENTER_GEOFENCE = 'enter_geofence';
    const RULE_EXIT_GEOFENCE = 'exit_geofence';

    // Default settings
    const DEFAULTS = [
        'enabled' => true,
        'arrive_place_enabled' => true,
        'leave_place_enabled' => true,
        'enter_geofence_enabled' => true,
        'exit_geofence_enabled' => true,
        'cooldown_seconds' => 900, // 15 min
        'quiet_hours_start' => null,
        'quiet_hours_end' => null
    ];

    public function __construct(PDO $db, TrackingCache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * Get alert rules for a family.
     */
    public function getRules(int $familyId): array
    {
        // Try cache
        $cached = $this->cache->getAlertRules($familyId);
        if ($cached !== null) {
            return $cached;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM tracking_alert_rules WHERE family_id = ?
        ");
        $stmt->execute([$familyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // Create with defaults
            $row = $this->createDefaults($familyId);
        }

        $rules = $this->hydrateRules($row);

        // Cache it
        $this->cache->setAlertRules($familyId, $rules);

        return $rules;
    }

    /**
     * Save alert rules.
     */
    public function saveRules(int $familyId, array $data): array
    {
        // Ensure row exists
        $this->getRules($familyId);

        $fields = [];
        $values = [];

        $allowedFields = [
            'enabled', 'arrive_place_enabled', 'leave_place_enabled',
            'enter_geofence_enabled', 'exit_geofence_enabled',
            'cooldown_seconds', 'quiet_hours_start', 'quiet_hours_end'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return $this->getRules($familyId);
        }

        $fields[] = "updated_at = NOW()";
        $values[] = $familyId;

        $sql = "UPDATE tracking_alert_rules SET " . implode(', ', $fields) . " WHERE family_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        // Invalidate cache
        $this->cache->deleteAlertRules($familyId);

        return $this->getRules($familyId);
    }

    /**
     * Check if a specific rule type is enabled.
     */
    public function isRuleEnabled(int $familyId, string $ruleType): bool
    {
        $rules = $this->getRules($familyId);

        if (!$rules['enabled']) {
            return false;
        }

        // Check quiet hours
        if (Time::isInQuietHours($rules['quiet_hours_start'], $rules['quiet_hours_end'])) {
            return false;
        }

        $fieldMap = [
            self::RULE_ARRIVE_PLACE => 'arrive_place_enabled',
            self::RULE_LEAVE_PLACE => 'leave_place_enabled',
            self::RULE_ENTER_GEOFENCE => 'enter_geofence_enabled',
            self::RULE_EXIT_GEOFENCE => 'exit_geofence_enabled'
        ];

        $field = $fieldMap[$ruleType] ?? null;
        return $field && ($rules[$field] ?? false);
    }

    /**
     * Check if alert is in cooldown.
     */
    public function isInCooldown(int $familyId, string $ruleType, int $userId, int $targetId): bool
    {
        // Try cache first
        $cached = $this->cache->getAlertCooldown($familyId, $ruleType, $userId, $targetId);
        if ($cached !== null) {
            return true; // If key exists, still in cooldown
        }

        // Check DB
        $rules = $this->getRules($familyId);
        $cooldownSeconds = $rules['cooldown_seconds'];

        $stmt = $this->db->prepare("
            SELECT 1 FROM tracking_alert_deliveries
            WHERE family_id = ?
              AND rule_type = ?
              AND user_id = ?
              AND target_id = ?
              AND delivered_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            LIMIT 1
        ");
        $stmt->execute([$familyId, $ruleType, $userId, $targetId, $cooldownSeconds]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Record an alert delivery.
     */
    public function recordDelivery(int $familyId, string $ruleType, int $userId, int $targetId, string $channel = 'inapp'): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_alert_deliveries (
                family_id, rule_type, user_id, target_id, delivered_at, channel
            ) VALUES (?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([$familyId, $ruleType, $userId, $targetId, $channel]);

        $id = (int)$this->db->lastInsertId();

        // Set cooldown in cache
        $rules = $this->getRules($familyId);
        $this->cache->setAlertCooldown($familyId, $ruleType, $userId, $targetId, $rules['cooldown_seconds']);

        return $id;
    }

    /**
     * Get recent deliveries.
     */
    public function getDeliveries(int $familyId, array $options = []): array
    {
        $limit = min($options['limit'] ?? 50, 200);
        $startTime = $options['start_time'] ?? Time::subSeconds(86400); // Last 24h

        $stmt = $this->db->prepare("
            SELECT
                ad.*,
                u.full_name as user_name
            FROM tracking_alert_deliveries ad
            JOIN users u ON ad.user_id = u.id
            WHERE ad.family_id = ?
              AND ad.delivered_at >= ?
            ORDER BY ad.delivered_at DESC
            LIMIT ?
        ");
        $stmt->execute([$familyId, $startTime, $limit]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = [
                'id' => (int)$row['id'],
                'rule_type' => $row['rule_type'],
                'user_id' => (int)$row['user_id'],
                'user_name' => $row['user_name'],
                'target_id' => (int)$row['target_id'],
                'delivered_at' => $row['delivered_at'],
                'channel' => $row['channel']
            ];
        }

        return $results;
    }

    /**
     * Prune old deliveries.
     */
    public function pruneDeliveries(int $retentionDays = 30): int
    {
        $cutoff = Time::subSeconds($retentionDays * 86400);

        $stmt = $this->db->prepare("
            DELETE FROM tracking_alert_deliveries WHERE delivered_at < ? LIMIT 10000
        ");
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Create default rules for a family.
     */
    private function createDefaults(int $familyId): array
    {
        $defaults = self::DEFAULTS;

        $stmt = $this->db->prepare("
            INSERT INTO tracking_alert_rules (
                family_id, enabled, arrive_place_enabled, leave_place_enabled,
                enter_geofence_enabled, exit_geofence_enabled, cooldown_seconds,
                quiet_hours_start, quiet_hours_end, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE family_id = family_id
        ");

        $stmt->execute([
            $familyId,
            $defaults['enabled'] ? 1 : 0,
            $defaults['arrive_place_enabled'] ? 1 : 0,
            $defaults['leave_place_enabled'] ? 1 : 0,
            $defaults['enter_geofence_enabled'] ? 1 : 0,
            $defaults['exit_geofence_enabled'] ? 1 : 0,
            $defaults['cooldown_seconds'],
            $defaults['quiet_hours_start'],
            $defaults['quiet_hours_end']
        ]);

        // Query back
        $stmt = $this->db->prepare("SELECT * FROM tracking_alert_rules WHERE family_id = ?");
        $stmt->execute([$familyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Hydrate rules row.
     */
    private function hydrateRules(array $row): array
    {
        return [
            'family_id' => (int)$row['family_id'],
            'enabled' => (bool)$row['enabled'],
            'arrive_place_enabled' => (bool)$row['arrive_place_enabled'],
            'leave_place_enabled' => (bool)$row['leave_place_enabled'],
            'enter_geofence_enabled' => (bool)$row['enter_geofence_enabled'],
            'exit_geofence_enabled' => (bool)$row['exit_geofence_enabled'],
            'cooldown_seconds' => (int)$row['cooldown_seconds'],
            'quiet_hours_start' => $row['quiet_hours_start'],
            'quiet_hours_end' => $row['quiet_hours_end'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }
}
