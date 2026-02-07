<?php
declare(strict_types=1);

/**
 * Family tracking settings repository
 */
class SettingsRepo
{
    private PDO $db;
    private TrackingCache $cache;

    private const DEFAULTS = [
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
        'events_retention_days' => 90,
    ];

    public function __construct(PDO $db, TrackingCache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    /**
     * Get settings for a family, creating defaults if not exists
     */
    public function get(int $familyId): array
    {
        $cached = $this->cache->getSettings($familyId);
        if ($cached !== null) {
            return array_merge(self::DEFAULTS, $cached);
        }

        $stmt = $this->db->prepare("SELECT * FROM tracking_family_settings WHERE family_id = ?");
        $stmt->execute([$familyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->createDefaults($familyId);
            $settings = array_merge(self::DEFAULTS, ['family_id' => $familyId]);
        } else {
            $settings = array_merge(self::DEFAULTS, $row);
        }

        $this->cache->setSettings($familyId, $settings);
        return $settings;
    }

    /**
     * Update settings for a family
     */
    public function save(int $familyId, array $data): bool
    {
        $allowed = array_keys(self::DEFAULTS);
        $sets = [];
        $params = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $sets[] = "{$key} = ?";
                $params[] = $value;
            }
        }

        if (empty($sets)) {
            return false;
        }

        $params[] = $familyId;
        $sql = "UPDATE tracking_family_settings SET " . implode(', ', $sets) . " WHERE family_id = ?";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute($params);

        if ($result) {
            $this->cache->deleteSettings($familyId);
        }

        return $result;
    }

    private function createDefaults(int $familyId): void
    {
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO tracking_family_settings (family_id) VALUES (?)
        ");
        $stmt->execute([$familyId]);
    }

    public static function getDefaults(): array
    {
        return self::DEFAULTS;
    }
}
