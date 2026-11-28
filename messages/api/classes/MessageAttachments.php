<?php
declare(strict_types=1);

final class MessageAttachments
{
    private const FILE_LIMIT = 50 * 1024 * 1024;

    public const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
        'audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav',
        'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/x-wav' => 'wav',
        'application/pdf' => 'pdf', 'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/plain' => 'txt', 'text/csv' => 'csv', 'application/rtf' => 'rtf',
    ];

    public static function maxFileBytes(): int
    {
        $configured = ini_parse_quantity((string) ini_get('upload_max_filesize'));
        return $configured > 0 ? min(self::FILE_LIMIT, $configured) : self::FILE_LIMIT;
    }

    public static function maxPostBytes(): int
    {
        return max(0, ini_parse_quantity((string) ini_get('post_max_size')));
    }

    public static function storagePath(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $path = '/' . ltrim($value, '/');
        return preg_match('~^/uploads/messages/(?:[0-9]{4}/(?:0[1-9]|1[0-2])/)?[A-Za-z0-9][A-Za-z0-9_.-]{0,190}$~D', $path)
            ? $path : null;
    }

    public static function filename(string $name, string $fallback): string
    {
        $name = basename(str_replace('\\', '/', mb_scrub($name, 'UTF-8')));
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $name));
        return $name === '' ? $fallback : mb_substr($name, 0, 240);
    }

    public static function entries(array $message): array
    {
        $stored = $message['media_path'] ?? '';
        $legacy = self::storagePath($stored);
        $items = $legacy === null ? json_decode((string) $stored, true) : [['path' => $legacy]];
        if (!is_array($items) || !array_is_list($items)) {
            return [];
        }

        $entries = [];
        foreach (array_slice($items, 0, 10) as $index => $item) {
            $path = self::storagePath($item['path'] ?? null);
            if ($path === null) {
                continue;
            }
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = is_string($item['type'] ?? null) ? $item['type'] : '';
            if ((self::EXTENSIONS[$mime] ?? null) !== $extension) {
                $mime = match ($extension) {
                    'jpeg' => 'image/jpeg',
                    'webm' => in_array($message['message_type'], ['voice', 'audio'], true) ? 'audio/webm' : 'video/webm',
                    default => array_search($extension, self::EXTENSIONS, true) ?: 'application/octet-stream',
                };
            }
            $file = basename($path);
            $name = is_string($item['name'] ?? null) ? self::filename($item['name'], $file) : $file;
            $entries[$index] = [
                'path' => $path, 'name' => $name, 'type' => $mime,
                'size' => max(0, (int) ($item['size'] ?? 0)),
                'width' => max(0, (int) ($item['width'] ?? 0)),
                'height' => max(0, (int) ($item['height'] ?? 0)),
            ];
        }
        return $entries;
    }

    public static function forClient(array $message): array
    {
        $attachments = [];
        $thumbnail = self::storagePath($message['media_thumbnail'] ?? null);
        $prefix = '/messages/api/media.php?message=' . $message['id'];
        foreach (self::entries($message) as $index => $entry) {
            $entry['path'] = $prefix . '&file=' . $index;
            if ($index === 0 && $thumbnail !== null && str_starts_with($entry['type'], 'image/')) {
                $entry['thumbnail'] = $entry['path'] . '&thumb=1';
            }
            $attachments[] = $entry;
        }

        // Older chat pages use media_path. Current pages use the normalized attachments.
        $message['attachments'] = $attachments;
        $message['media_thumbnail'] = $attachments[0]['thumbnail'] ?? null;
        $message['media_path'] = !$attachments ? null : (
            in_array($message['message_type'], ['file', 'multi'], true)
                ? json_encode($attachments, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
                : $attachments[0]['path']
        );
        return $message;
    }

    public static function findLegacy(PDO $db, int $family, string $path): ?array
    {
        $escaped = strtr($path, ['!' => '!!', '_' => '!_', '%' => '!%']);
        $stmt = $db->prepare("SELECT media_path, media_thumbnail, message_type FROM messages
            WHERE family_id = ? AND deleted_at IS NULL AND (
                media_path IN (?, ?) OR media_thumbnail = ? OR
                JSON_SEARCH(IF(JSON_VALID(media_path), media_path, NULL), 'one', ?, '!', '$[*].path') IS NOT NULL
            )");
        $stmt->execute([$family, $path, ltrim($path, '/'), $path, $escaped]);
        while ($message = $stmt->fetch()) {
            foreach (self::entries($message) as $index => $entry) {
                if ($entry['path'] === $path) {
                    return $entry;
                }
                if ($index === 0 && self::storagePath($message['media_thumbnail']) === $path
                    && str_starts_with($entry['type'], 'image/')) {
                    return ['path' => $path] + $entry;
                }
            }
        }
        return null;
    }
}
