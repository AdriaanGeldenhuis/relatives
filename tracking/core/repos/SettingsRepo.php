<?php
declare(strict_types=1);

class SettingsRepo {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getForFamily(int $familyId): array {
        $stmt = $this->db->prepare("SELECT * FROM tracking_settings WHERE family_id = ? LIMIT 1");
        $stmt->execute([$familyId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            // Return defaults
            return [
                'family_id' => $familyId,
                'update_interval' => 30,
                'history_retention_days' => 30,
                'distance_unit' => 'km',
                'map_style' => 'streets',
                'show_speed' => 1,
                'show_battery' => 1,
                'show_accuracy' => 0,
                'geofence_notifications' => 1,
                'low_battery_alert' => 1,
                'low_battery_threshold' => 15,
            ];
        }

        return $settings;
    }

    public function save(int $familyId, array $data): bool {
        $allowed = ['update_interval', 'history_retention_days', 'distance_unit', 'map_style',
                     'show_speed', 'show_battery', 'show_accuracy', 'geofence_notifications',
                     'low_battery_alert', 'low_battery_threshold'];

        $filtered = array_intersect_key($data, array_flip($allowed));
        if (empty($filtered)) return false;

        $sets = [];
        $values = [];
        foreach ($filtered as $key => $value) {
            $sets[] = "$key = ?";
            $values[] = $value;
        }

        $setStr = implode(', ', $sets);
        $values[] = $familyId;

        // Try update first
        $stmt = $this->db->prepare("UPDATE tracking_settings SET $setStr WHERE family_id = ?");
        $stmt->execute($values);

        if ($stmt->rowCount() === 0) {
            // Insert new
            $filtered['family_id'] = $familyId;
            $cols = implode(', ', array_keys($filtered));
            $placeholders = implode(', ', array_fill(0, count($filtered), '?'));
            $stmt = $this->db->prepare("INSERT INTO tracking_settings ($cols) VALUES ($placeholders)");
            $stmt->execute(array_values($filtered));
        }

        return true;
    }
}
