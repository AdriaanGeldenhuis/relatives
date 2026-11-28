<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/components/avatar.php';
require_once __DIR__ . '/MessageAttachments.php';

final class MessageChanges
{
    private const PAGE_SIZE = 50;

    public static function installed(PDO $db): bool
    {
        return (bool) $db->query("SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_revisions'")->fetchColumn();
    }

    public static function recordReaction(PDO $db, int $familyId, int $messageId): void
    {
        $db->prepare('INSERT INTO message_revisions (family_id, revision) VALUES (?, 1)
            ON DUPLICATE KEY UPDATE revision = revision + 1')->execute([$familyId]);
        $db->prepare('INSERT INTO message_changes (family_id, revision, message_id)
            SELECT family_id, revision, ? FROM message_revisions WHERE family_id = ?')->execute([$messageId, $familyId]);
    }

    public static function selectRows(PDO $db, int $familyId, string $predicate, array $parameters, string $order): array
    {
        $stmt = $db->prepare("SELECT m.id, m.user_id, m.content, m.message_type, m.media_path, m.media_thumbnail,
            m.reply_to_message_id, m.created_at, m.edited_at, m.edit_count, m.pinned,
            UNIX_TIMESTAMP(m.created_at) AS sent_timestamp, u.full_name, u.avatar_color,
            LEFT(replied.content, 180) AS reply_to_content, quoted.full_name AS reply_to_name,
            replied.message_type AS reply_to_type
            FROM messages m JOIN users u ON m.user_id = u.id
            LEFT JOIN messages replied ON replied.id = m.reply_to_message_id
                AND replied.family_id = m.family_id AND replied.deleted_at IS NULL
            LEFT JOIN users quoted ON quoted.id = replied.user_id
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
        $avatars = [];
        foreach ($rows as &$row) {
            $row['reactions'] = $reactions[$row['id']] ?? [];
            $userId = (int)$row['user_id'];
            if (!isset($avatars[$userId])) {
                $path = avatarPath($userId);
                $avatars[$userId] = is_file($path) ? (int)filemtime($path) : 0;
            }
            $row['avatar_version'] = $avatars[$userId];
            $row['has_avatar'] = $row['avatar_version'] > 0;
            $row = MessageAttachments::forClient($row);
        }
        return $rows;
    }

    public static function revision(PDO $db, int $family): int
    {
        $stmt = $db->prepare('SELECT revision FROM message_revisions WHERE family_id = ?');
        $stmt->execute([$family]);
        return (int) $stmt->fetchColumn();
    }

    public static function history(PDO $db, int $family, int $before, int $after, int $focus): array
    {
        if ($focus) {
            $target = self::selectRows($db, $family, 'AND m.id = ?', [$focus], '');
            if ($target) {
                $past = self::selectRows($db, $family, 'AND m.id < ?', [$focus], 'ORDER BY m.id DESC LIMIT 26');
                $future = self::selectRows($db, $family, 'AND m.id > ?', [$focus], 'ORDER BY m.id LIMIT 26');
                return [
                    'messages' => [...array_reverse(array_slice($past, 0, 25)), ...$target, ...array_slice($future, 0, 25)],
                    'has_older' => count($past) > 25,
                    'has_newer' => count($future) > 25,
                    'focused' => true,
                ];
            }
        }

        $limit = self::PAGE_SIZE + 1;
        if ($after) {
            $rows = self::selectRows($db, $family, 'AND m.id > ?', [$after], "ORDER BY m.id LIMIT $limit");
            return ['messages' => array_slice($rows, 0, self::PAGE_SIZE), 'has_newer' => count($rows) > self::PAGE_SIZE];
        }
        $rows = self::selectRows($db, $family, $before ? 'AND m.id < ?' : '', $before ? [$before] : [], "ORDER BY m.id DESC LIMIT $limit");
        return [
            'messages' => array_reverse(array_slice($rows, 0, self::PAGE_SIZE)),
            'has_older' => count($rows) > self::PAGE_SIZE,
            'has_newer' => false,
            'focused' => false,
        ];
    }

    public static function latestId(PDO $db, int $family): string
    {
        $stmt = $db->prepare('SELECT MAX(id) FROM messages WHERE family_id = ? AND deleted_at IS NULL');
        $stmt->execute([$family]);
        return (string) ($stmt->fetchColumn() ?: 0);
    }

    public static function clientId(mixed $id): ?string
    {
        return is_string($id) && preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $id)
            ? strtolower($id) : null;
    }

    public static function acknowledged(PDO $db, int $family, int $user, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $slots = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT r.client_id FROM message_send_receipts r
            JOIN messages m ON m.id = r.message_id AND m.family_id = ?
            WHERE r.user_id = ? AND r.client_id IN ($slots)");
        $stmt->execute([$family, $user, ...$ids]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function delta(PDO $db, int $familyId, int $cursor, int $revision): array
    {
        $unchanged = ['cursor' => (string) $cursor, 'messages' => [], 'removed' => [], 'more' => false];
        if ($cursor >= $revision) {
            return $unchanged + ['reset' => $cursor !== $revision];
        }
        $stmt = $db->prepare('SELECT revision, message_id, deleted FROM message_changes
            WHERE family_id = ? AND revision > ? ORDER BY revision LIMIT 51');
        $stmt->execute([$familyId, $cursor]);
        $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$changes || (int) $changes[0]['revision'] !== $cursor + 1) {
            return $unchanged + ['reset' => true];
        }
        $more = count($changes) > 50;
        $tail = array_slice($changes, 0, 50);
        $ids = array_values(array_unique(array_column($tail, 'message_id')));
        $messages = self::selectRows($db, $familyId, 'AND m.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids, 'ORDER BY m.id');
        return [
            'messages' => $messages,
            'removed' => array_values(array_diff($ids, array_column($messages, 'id'))),
            'cursor' => (string) ($tail ? end($tail)['revision'] : $cursor),
            'more' => $more,
            'reset' => false,
        ];
    }

    public static function markRead(PDO $db, int $family, int $user, array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->beginTransaction();
        try {
            $select = $db->prepare("SELECT id FROM messages
                WHERE family_id = ? AND deleted_at IS NULL AND id IN ($placeholders) ORDER BY id");
            $select->execute([$family, ...$ids]);
            $visible = $select->fetchAll(PDO::FETCH_COLUMN);
            $marked = $notifications = 0;
            if ($visible) {
                $slots = implode(',', array_fill(0, count($visible), '?'));
                $insert = $db->prepare("INSERT INTO message_reads (message_id, user_id, read_at)
                    SELECT id, ?, NOW() FROM messages WHERE family_id = ? AND user_id != ?
                        AND deleted_at IS NULL AND id IN ($slots) ORDER BY id
                    ON DUPLICATE KEY UPDATE read_at = message_reads.read_at");
                $insert->execute([$user, $family, $user, ...$visible]);
                $marked = $insert->rowCount();

                $paths = array_map(static fn ($id) => '/messages/?message=' . $id, $visible);
                $update = $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW()
                    WHERE user_id = ? AND type = 'message' AND is_read = 0
                    AND (action_url IN ($slots) OR (action_url = '/messages/' AND
                        JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(data_json), data_json, NULL), '$.message_id')) IN ($slots)))");
                $update->execute([$user, ...$paths, ...array_map('strval', $visible)]);
                $notifications = $update->rowCount();
            }
            $db->commit();
            return ['message_ids' => array_map('strval', $visible), 'marked_count' => $marked, 'notifications_read' => $notifications];
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
}
