<?php
declare(strict_types=1);

/**
 * Tracking events repository
 */
class EventsRepo
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Insert a tracking event
     */
    public function insert(int $familyId, ?int $userId, string $eventType, array $meta = []): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO tracking_events (family_id, user_id, event_type, meta_json, occurred_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $familyId,
            $userId,
            $eventType,
            !empty($meta) ? json_encode($meta) : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * List recent events for a family
     */
    public function list(int $familyId, int $limit = 50, int $offset = 0, ?string $eventType = null): array
    {
        $sql = "
            SELECT te.*, u.full_name AS user_name, u.avatar_color
            FROM tracking_events te
            LEFT JOIN users u ON te.user_id = u.id
            WHERE te.family_id = ?
        ";
        $params = [$familyId];

        if ($eventType) {
            $sql .= " AND te.event_type = ?";
            $params[] = $eventType;
        }

        $sql .= " ORDER BY te.occurred_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Prune old events
     */
    public function prune(int $retentionDays): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM tracking_events
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$retentionDays]);
        return $stmt->rowCount();
    }
}
