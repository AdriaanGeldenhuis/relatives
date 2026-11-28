<?php
declare(strict_types=1);

final class ChatTyping
{
    public static function members(PDO $db, int $family, int $user): array
    {
        $stmt = $db->prepare("SELECT u.id, u.full_name AS name FROM typing_indicators t
            JOIN users u ON u.id = t.user_id AND u.family_id = t.family_id AND u.status = 'active'
            LEFT JOIN user_privacy_settings p ON p.user_id = u.id
            WHERE t.family_id = ? AND t.user_id != ? AND COALESCE(p.show_online_status, 1) = 1
                AND t.updated_at >= NOW() - INTERVAL 7 SECOND
            ORDER BY t.updated_at DESC, u.id LIMIT 10");
        $stmt->execute([$family, $user]);
        return $stmt->fetchAll();
    }

    public static function update(PDO $db, int $family, int $user, bool $typing): void
    {
        $sql = $typing
            ? 'INSERT INTO typing_indicators (family_id, user_id, updated_at) VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()'
            : 'DELETE FROM typing_indicators WHERE family_id = ? AND user_id = ?';
        $db->prepare($sql)->execute([$family, $user]);
    }
}
