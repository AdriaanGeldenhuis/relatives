<?php
declare(strict_types=1);

/**
 * Alert rules and delivery log repository
 */
class AlertsRepo
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get alert rules for a family (creates defaults if not exists)
     */
    public function get(int $familyId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM tracking_alert_rules WHERE family_id = ?");
        $stmt->execute([$familyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->createDefaults($familyId);
            return [
                'family_id' => $familyId,
                'enabled' => true,
                'arrive_place_enabled' => true,
                'leave_place_enabled' => true,
                'enter_geofence_enabled' => true,
                'exit_geofence_enabled' => true,
                'cooldown_seconds' => 900,
                'quiet_hours_start' => null,
                'quiet_hours_end' => null,
            ];
        }

        return $row;
    }

    /**
     * Save alert rules
     */
    public function save(int $familyId, array $data): bool
    {
        $allowed = [
            'enabled', 'arrive_place_enabled', 'leave_place_enabled',
            'enter_geofence_enabled', 'exit_geofence_enabled',
            'cooldown_seconds', 'quiet_hours_start', 'quiet_hours_end',
        ];

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
        $sql = "UPDATE tracking_alert_rules SET " . implode(', ', $sets) . " WHERE family_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Log an alert delivery
     */
    public function logDelivery(int $familyId, string $ruleType, int $userId, int $targetId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_alert_deliveries (family_id, rule_type, user_id, target_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$familyId, $ruleType, $userId, $targetId]);
    }

    private function createDefaults(int $familyId): void
    {
        $stmt = $this->db->prepare("INSERT IGNORE INTO tracking_alert_rules (family_id) VALUES (?)");
        $stmt->execute([$familyId]);
    }
}
