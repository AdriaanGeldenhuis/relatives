<?php
/**
 * Settings Repository
 *
 * Manages tracking_family_settings table.
 */

class SettingsRepo
{
    private PDO $db;
    private TrackingCache $cache;

    // Default settings
    const DEFAULTS = [
        'mode' => 1,
        'session_ttl_seconds' => 300,
        'keepalive_interval_seconds' => 30,
        'moving_interval_seconds' => 30,
        'idle_interval_seconds' => 300,
        'speed_threshold_mps' => 1.0,
        'distance_threshold_m' => 50,
        'min_accuracy_m' => 100,
        'dedupe_radius_m' => 10,
        'dedupe_time_seconds' => 60,
        'rate_limit_seconds' => 5,
        'units' => 'metric',
        'map_style' => 'streets',
        'history_retention_days' => 30,
        'events_retention_days' => 90
    ];

    public function __construct(PDO $db, TrackingCache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * Get settings for a family.
     * Creates with defaults if not exists.
     */
    public function get(int $familyId): array
    {
        // Try cache first
        $cached = $this->cache->getSettings($familyId);
        if ($cached !== null) {
            return $cached;
        }

        // Query DB
        $stmt = $this->db->prepare("
            SELECT * FROM tracking_family_settings WHERE family_id = ?
        ");
        $stmt->execute([$familyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // Create with defaults
            $row = $this->createDefaults($familyId);
        }

        // Convert to proper types
        $settings = $this->hydrate($row);

        // Cache it
        $this->cache->setSettings($familyId, $settings);

        return $settings;
    }

    /**
     * Save settings for a family.
     */
    public function save(int $familyId, array $data): array
    {
        // Ensure row exists
        $current = $this->get($familyId);

        // Build update
        $fields = [];
        $values = [];

        $allowedFields = array_keys(self::DEFAULTS);
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return $current;
        }

        $fields[] = "updated_at = NOW()";
        $values[] = $familyId;

        $sql = "UPDATE tracking_family_settings SET " . implode(', ', $fields) . " WHERE family_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        // Invalidate cache
        $this->cache->deleteSettings($familyId);

        // Return fresh
        return $this->get($familyId);
    }

    /**
     * Create default settings for a family.
     */
    private function createDefaults(int $familyId): array
    {
        $defaults = self::DEFAULTS;

        $stmt = $this->db->prepare("
            INSERT INTO tracking_family_settings (
                family_id, mode, session_ttl_seconds, keepalive_interval_seconds,
                moving_interval_seconds, idle_interval_seconds, speed_threshold_mps,
                distance_threshold_m, min_accuracy_m, dedupe_radius_m,
                dedupe_time_seconds, rate_limit_seconds, units, map_style,
                history_retention_days, events_retention_days, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )
            ON DUPLICATE KEY UPDATE family_id = family_id
        ");

        $stmt->execute([
            $familyId,
            $defaults['mode'],
            $defaults['session_ttl_seconds'],
            $defaults['keepalive_interval_seconds'],
            $defaults['moving_interval_seconds'],
            $defaults['idle_interval_seconds'],
            $defaults['speed_threshold_mps'],
            $defaults['distance_threshold_m'],
            $defaults['min_accuracy_m'],
            $defaults['dedupe_radius_m'],
            $defaults['dedupe_time_seconds'],
            $defaults['rate_limit_seconds'],
            $defaults['units'],
            $defaults['map_style'],
            $defaults['history_retention_days'],
            $defaults['events_retention_days']
        ]);

        // Query back
        $stmt = $this->db->prepare("SELECT * FROM tracking_family_settings WHERE family_id = ?");
        $stmt->execute([$familyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Convert DB row to proper types.
     */
    private function hydrate(array $row): array
    {
        return [
            'family_id' => (int)$row['family_id'],
            'mode' => (int)$row['mode'],
            'session_ttl_seconds' => (int)$row['session_ttl_seconds'],
            'keepalive_interval_seconds' => (int)$row['keepalive_interval_seconds'],
            'moving_interval_seconds' => (int)$row['moving_interval_seconds'],
            'idle_interval_seconds' => (int)$row['idle_interval_seconds'],
            'speed_threshold_mps' => (float)$row['speed_threshold_mps'],
            'distance_threshold_m' => (int)$row['distance_threshold_m'],
            'min_accuracy_m' => (int)$row['min_accuracy_m'],
            'dedupe_radius_m' => (int)$row['dedupe_radius_m'],
            'dedupe_time_seconds' => (int)$row['dedupe_time_seconds'],
            'rate_limit_seconds' => (int)$row['rate_limit_seconds'],
            'units' => $row['units'],
            'map_style' => $row['map_style'],
            'history_retention_days' => (int)$row['history_retention_days'],
            'events_retention_days' => (int)$row['events_retention_days'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }
}
