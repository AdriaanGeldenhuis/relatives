<?php
declare(strict_types=1);

final class MessageChanges
{
    public static function installed(PDO $db): bool
    {
        return (bool) $db->query("SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_revisions'")->fetchColumn();
    }

    public static function selectRows(PDO $db, int $familyId, string $predicate, array $parameters, string $order): array
    {
        $stmt = $db->prepare("SELECT m.id, m.user_id, m.content, m.message_type, m.media_path,
            m.reply_to_message_id, m.created_at, m.edited_at, m.edit_count, m.pinned,
            u.full_name, u.avatar_color, u.has_avatar, replied.content AS reply_to_content
            FROM messages m JOIN users u ON m.user_id = u.id
            LEFT JOIN messages replied ON replied.id = m.reply_to_message_id
                AND replied.family_id = m.family_id AND replied.deleted_at IS NULL
            WHERE m.family_id = ? AND m.deleted_at IS NULL $predicate $order");
        $stmt->execute([$familyId, ...$parameters]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }

        $ids = array_column($rows, 'id');
        $reactionQuery = $db->prepare('SELECT message_id, user_id, emoji FROM message_reactions WHERE message_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY created_at, user_id');
        $reactionQuery->execute($ids);
        $reactions = [];
        foreach ($reactionQuery->fetchAll(PDO::FETCH_ASSOC) as $reaction) {
            $reactions[$reaction['message_id']][] = ['user_id' => $reaction['user_id'], 'emoji' => $reaction['emoji']];
        }
        foreach ($rows as &$row) {
            $row['reactions'] = $reactions[$row['id']] ?? [];
        }
        return $rows;
    }

    public static function delta(PDO $db, int $familyId, int $cursor): array
    {
        $stmt = $db->prepare('SELECT revision, message_id, deleted FROM message_changes
            WHERE family_id = ? AND revision > ? ORDER BY revision LIMIT 51');
        $stmt->execute([$familyId, $cursor]);
        $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$changes) {
            $seq = $db->prepare('SELECT revision FROM message_revisions WHERE family_id = ?');
            $seq->execute([$familyId]);
            $reset = (int) $seq->fetchColumn() !== $cursor;
            return ['reset' => $reset, 'cursor' => (string) $cursor, 'messages' => [], 'removed' => [], 'more' => false];
        }
        if ((int) $changes[0]['revision'] !== $cursor + 1) {
            return ['reset' => true, 'cursor' => (string) $cursor, 'messages' => [], 'removed' => [], 'more' => false];
        }
        $more = count($changes) > 50;
        $tail = array_slice($changes, 0, 50);
        $ids = array_values(array_unique(array_column($tail, 'message_id')));
        $messages = $ids
            ? self::selectRows($db, $familyId, 'AND m.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                $ids, 'ORDER BY m.id')
            : [];
        return [
            'messages' => $messages,
            'removed' => array_values(array_diff($ids, array_column($messages, 'id'))),
            'cursor' => (string) ($tail ? end($tail)['revision'] : $cursor),
            'more' => $more,
            'reset' => false,
        ];
    }
}
