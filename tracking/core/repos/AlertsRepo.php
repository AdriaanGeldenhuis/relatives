<?php
declare(strict_types=1);

class AlertsRepo {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getForFamily(int $familyId): array {
        $stmt = $this->db->prepare("
            SELECT a.*, u.full_name as created_by_name
            FROM tracking_alert_rules a
            LEFT JOIN users u ON a.created_by = u.id
            WHERE a.family_id = ?
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([$familyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(int $familyId, int $createdBy, array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_alert_rules (family_id, created_by, name, rule_type, target_user_id, conditions_json, notify_users_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $familyId, $createdBy,
            $data['name'],
            $data['rule_type'],
            $data['target_user_id'] ?? null,
            json_encode($data['conditions'] ?? []),
            json_encode($data['notify_users'] ?? []),
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, int $familyId, array $data): bool {
        $sets = [];
        $values = [];

        foreach (['name', 'rule_type', 'target_user_id', 'active'] as $key) {
            if (array_key_exists($key, $data)) {
                $sets[] = "$key = ?";
                $values[] = $data[$key];
            }
        }

        if (array_key_exists('conditions', $data)) {
            $sets[] = "conditions_json = ?";
            $values[] = json_encode($data['conditions']);
        }
        if (array_key_exists('notify_users', $data)) {
            $sets[] = "notify_users_json = ?";
            $values[] = json_encode($data['notify_users']);
        }

        if (empty($sets)) return false;

        $values[] = $id;
        $values[] = $familyId;

        $stmt = $this->db->prepare("UPDATE tracking_alert_rules SET " . implode(', ', $sets) . " WHERE id = ? AND family_id = ?");
        $stmt->execute($values);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $familyId): bool {
        $stmt = $this->db->prepare("DELETE FROM tracking_alert_rules WHERE id = ? AND family_id = ?");
        $stmt->execute([$id, $familyId]);
        return $stmt->rowCount() > 0;
    }
}
