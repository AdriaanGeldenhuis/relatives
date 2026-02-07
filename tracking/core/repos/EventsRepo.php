<?php
declare(strict_types=1);

class EventsRepo {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function create(int $familyId, int $userId, string $eventType, string $title, ?string $description = null, ?float $lat = null, ?float $lng = null, ?array $meta = null): int {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_events (family_id, user_id, event_type, title, description, lat, lng, meta_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $familyId, $userId, $eventType, $title, $description, $lat, $lng,
            $meta ? json_encode($meta) : null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function getForFamily(int $familyId, int $limit = 50, int $offset = 0, ?string $type = null): array {
        $sql = "
            SELECT e.*, u.full_name, u.avatar_color
            FROM tracking_events e
            LEFT JOIN users u ON e.user_id = u.id
            WHERE e.family_id = ?
        ";
        $params = [$familyId];

        if ($type) {
            $sql .= " AND e.event_type = ?";
            $params[] = $type;
        }

        $sql .= " ORDER BY e.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pruneOld(int $days = 90): int {
        $stmt = $this->db->prepare("DELETE FROM tracking_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}
