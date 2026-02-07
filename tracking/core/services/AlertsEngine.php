<?php
declare(strict_types=1);

class AlertsEngine {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Process alert rules for a location update
     */
    public function process(int $userId, int $familyId, array $location): array {
        $triggered = [];

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM tracking_alert_rules
                WHERE family_id = ? AND active = 1
                AND (target_user_id IS NULL OR target_user_id = ?)
            ");
            $stmt->execute([$familyId, $userId]);
            $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rules as $rule) {
                $conditions = json_decode($rule['conditions_json'], true);
                if (!$conditions) continue;

                $ruleTriggered = false;

                switch ($rule['rule_type']) {
                    case 'speed':
                        $maxSpeed = (float)($conditions['max_speed_kmh'] ?? 120);
                        $speedKmh = ($location['speed'] ?? 0) * 3.6; // m/s to km/h
                        if ($speedKmh > $maxSpeed) {
                            $ruleTriggered = true;
                        }
                        break;

                    case 'battery':
                        $minBattery = (int)($conditions['min_battery'] ?? 15);
                        if (($location['battery'] ?? 100) < $minBattery) {
                            $ruleTriggered = true;
                        }
                        break;

                    case 'inactivity':
                        // Handled by cron, not real-time
                        break;
                }

                if ($ruleTriggered) {
                    $triggered[] = [
                        'rule_id' => $rule['id'],
                        'name' => $rule['name'],
                        'type' => $rule['rule_type'],
                    ];
                }
            }
        } catch (\Exception $e) {
            error_log('AlertsEngine::process error: ' . $e->getMessage());
        }

        return $triggered;
    }
}
