<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/EventNoteLinks.php';

const NOTES_DEFAULT_COLOR = '#ffffff';
const NOTES_MAX_PHOTOS = 7;
const NOTES_MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

function notesImagePaths(?string $value): array
{
    if (!$value) {
        return [];
    }
    if ($value[0] === '~') {
        $compressed = base64_decode(substr($value, 1), true);
        $value = $compressed === false ? '' : (@gzinflate($compressed, 16384) ?: '');
    }
    $paths = json_decode($value, true);
    return array_values(array_filter(is_array($paths) ? $paths : [$value], 'notesIsMediaPath'));
}

function notesIsMediaPath($path): bool
{
    return is_string($path)
        && preg_match('~^/(?:saves|uploads)/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_.-]+\.[a-zA-Z0-9]+$~D', $path) === 1;
}

function notesPackImages(array $paths, int $maxLength): ?string
{
    if (!$paths) {
        return null;
    }
    $json = json_encode($paths, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($json) <= $maxLength) {
        return $json;
    }
    // Older installs use VARCHAR(255) for this column. Legacy filenames share long prefixes.
    $packed = '~' . base64_encode(gzdeflate($json));
    if (strlen($packed) > $maxLength) {
        throw new DomainException('These photo paths exceed the available storage. Remove an older attachment before adding more.');
    }
    return $packed;
}

function notesImageCapacity(PDO $db): int
{
    $column = $db->query("SHOW COLUMNS FROM notes LIKE 'image_path'")->fetch();
    if (!$column) {
        return 0;
    }
    return preg_match('/^varchar\((\d+)\)$/i', $column['Type'], $match) ? (int)$match[1] : 65535;
}

function notesVersion(array $note): string
{
    return hash('sha256', serialize([
        $note['title'], $note['body'], $note['color'], $note['image_path'] ?? null
    ]));
}

function notesClientData(array $note, array $eventTitles = []): array
{
    $audio = $note['audio_path'] ?? null;
    $audio = notesIsMediaPath($audio) && is_file(__DIR__ . '/..' . $audio) ? $audio : null;
    $userId = (int)$note['user_id'];
    $avatar = '/saves/' . $userId . '/avatar/avatar.webp';
    $avatarTime = $userId && is_file(__DIR__ . '/..' . $avatar) ? filemtime(__DIR__ . '/..' . $avatar) : false;
    return [
        'id' => (string)$note['id'],
        'type' => $note['type'],
        'title' => (string)$note['title'],
        'body' => str_replace(["\r\n", "\r"], "\n", (string)$note['body']),
        'eventLinks' => array_map(static fn(int $id) => ['id' => (string)$id, 'title' => $eventTitles[$id] ?? null],
            eventNoteIds($note['body'])),
        'color' => $note['color'] ?: NOTES_DEFAULT_COLOR,
        'pinned' => (bool)$note['pinned'],
        'images' => notesImagePaths($note['image_path'] ?? null),
        'audio' => $audio,
        'author' => $note['full_name'] ?: 'Family member',
        'avatar' => $avatarTime ? $avatar . '?v=' . $avatarTime : null,
        'created' => $note['created_at'] ? (new DateTimeImmutable($note['created_at']))->format(DATE_ATOM) : null,
        'updated' => $note['updated_at'] ? (new DateTimeImmutable($note['updated_at']))->format(DATE_ATOM) : null,
        'version' => notesVersion($note)
    ];
}

function notesSelect(PDO $db, int $familyId, ?int $noteId = null, bool $lock = false)
{
    $sql = 'SELECT n.*, u.full_name FROM notes n LEFT JOIN users u ON n.user_id = u.id WHERE n.family_id = ?';
    if ($noteId === null) {
        $stmt = $db->prepare($sql . ' ORDER BY n.created_at DESC, n.id DESC');
        $stmt->execute([$familyId]);
        return $stmt->fetchAll();
    }
    $stmt = $db->prepare($sql . ' AND n.id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$familyId, $noteId]);
    return $stmt->fetch();
}

function notesBoardData(PDO $db, int $familyId): array
{
    $notes = notesSelect($db, $familyId);
    $eventTitles = eventNoteTitles($db, $familyId, $notes);
    return array_map(static fn(array $note) => notesClientData($note, $eventTitles), $notes);
}

function notesPost(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    if (!is_string($value)) {
        throw new DomainException('Invalid note details.');
    }
    return $value;
}

function notesTitle(): string
{
    $title = trim(notesPost('title'));
    if (!preg_match('/^.{0,160}$/usD', $title)) {
        throw new DomainException('Use 160 characters or fewer for the title.');
    }
    return $title;
}

function notesBody(): string
{
    $body = str_replace(["\r\n", "\r"], "\n", notesPost('body'));
    if (strlen($body) > 65535 || !preg_match('//u', $body)) {
        throw new DomainException('This note is too long. Shorten it and try again.');
    }
    return $body;
}

function notesColor(): string
{
    $color = strtolower(notesPost('color', NOTES_DEFAULT_COLOR));
    if (!preg_match('/^#[\da-f]{6}$/D', $color)) {
        throw new DomainException('Choose a valid note color.');
    }
    return $color;
}

function notesPhotos(): array
{
    if (isset($_FILES['image'])) {
        return [$_FILES['image']];
    }
    $files = $_FILES['images'] ?? null;
    if (!$files) {
        return [];
    }
    if (!is_array($files['error']) || count($files['error']) > NOTES_MAX_PHOTOS) {
        throw new DomainException('You can attach up to seven photos.');
    }
    $photos = [];
    foreach ($files['error'] as $i => $error) {
        $photos[] = [
            'error' => $error,
            'size' => $files['size'][$i],
            'tmp_name' => $files['tmp_name'][$i]
        ];
    }
    return $photos;
}

function notesUploadMime(array $file): string
{
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > NOTES_MAX_UPLOAD_BYTES) {
        throw new DomainException('An attachment exceeds the file size limit.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || !$file['size']) {
        throw new DomainException('The attachment did not upload. Choose it again.');
    }
    return (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
}

function notesMediaPath(string $directory, string $extension): string
{
    $root = __DIR__ . '/..' . $directory;
    if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
        throw new RuntimeException('Unable to create notes media directory.');
    }
    return $directory . '/' . bin2hex(random_bytes(6)) . '.' . $extension;
}

function notesSavePhoto(array $file, int $userId, array &$created): string
{
    $decoder = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/gif' => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp'
    ][notesUploadMime($file)] ?? null;
    $dimensions = $decoder ? @getimagesize($file['tmp_name']) : false;
    if (!$dimensions || $dimensions[0] * $dimensions[1] > 24000000) {
        throw new DomainException('Use a JPG, PNG, GIF or WebP image of up to 24 megapixels.');
    }
    $image = @$decoder($file['tmp_name']);
    if (!$image) {
        throw new DomainException('A photo could not be read. Choose another image.');
    }
    $path = notesMediaPath('/saves/' . $userId . '/notes', 'webp');
    $filename = __DIR__ . '/..' . $path;
    $created[] = $path;
    imagepalettetotruecolor($image);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    if (!@imagewebp($image, $filename, 85) || !is_file($filename) || !filesize($filename)) {
        throw new RuntimeException('Unable to save note photo.');
    }
    return $path;
}

function notesSaveAudio(array $file, array &$created): string
{
    $extension = [
        'audio/webm' => 'webm',
        'video/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
        'video/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a'
    ][notesUploadMime($file)] ?? null;
    if (!$extension) {
        throw new DomainException('The recording format is unsupported. Try recording again.');
    }
    $path = notesMediaPath('/uploads/voice', $extension);
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/..' . $path)) {
        throw new RuntimeException('Unable to save note recording.');
    }
    $created[] = $path;
    return $path;
}

function notesRemoveMedia(array $paths): void
{
    foreach (array_unique(array_filter($paths, 'notesIsMediaPath')) as $path) {
        if (preg_match('~^/(?:saves/\d+/notes/|uploads/(?:notes|voice)/)[a-zA-Z0-9_-]+\.(?:webp|webm|m4a|ogg)$~D', $path)) {
            $file = __DIR__ . '/..' . $path;
            if (is_file($file) && !@unlink($file)) {
                error_log('Could not remove a note attachment.');
            }
        }
    }
}

function notesRespond(array $result, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}

function notesHandlePost(PDO $db, array $user, int $imageCapacity, string $token): void
{
    if (!$_POST && !$_FILES) {
        notesRespond(['success' => false, 'error' => 'The request is empty or the attachments are too large.'], 413);
    }

    $familyId = (int)$user['family_id'];
    $userId = (int)$user['id'];
    $created = [];
    $removed = [];
    try {
        if (!hash_equals($token, notesPost('csrf_token'))) {
            notesRespond(['success' => false, 'error' => 'Reload Notes before trying again.'], 403);
        }
        $action = notesPost('action');
        $editing = $action === 'update_note';
        if (!in_array($action, ['create_note', 'update_note', 'delete_note', 'toggle_pin', 'set_task'], true)) {
            throw new DomainException('Unknown note action.');
        }

        if ($action !== 'create_note') {
            $noteId = (int)notesPost('note_id');
            $db->beginTransaction();
            $note = notesSelect($db, $familyId, $noteId, true);
            if (!$note) {
                $db->rollBack();
                notesRespond(['success' => false, 'error' => 'This note is no longer available.'], 404);
            }
            if ($action !== 'toggle_pin' && !hash_equals(notesVersion($note), notesPost('version'))) {
                $db->rollBack();
                notesRespond([
                    'success' => false,
                    'error' => 'Someone changed this note. Review the latest version before trying again.',
                    'note' => notesClientData($note, eventNoteTitles($db, $familyId, [$note]))
                ], 409);
            }
        }

        if ($action === 'create_note' || $editing) {
            $type = $editing ? $note['type'] : notesPost('type', 'text');
            if (!in_array($type, ['text', 'voice'], true)) {
                throw new DomainException('Choose a valid note type.');
            }
            $title = notesTitle();
            $body = notesBody();
            $color = notesColor();
            $audio = $note['audio_path'] ?? null;
            $paths = notesImagePaths($note['image_path'] ?? null);
            $photos = notesPhotos();

            if ($editing && isset($_POST['remove_images'])) {
                if (!is_array($_POST['remove_images'])) {
                    throw new DomainException('Invalid attachment selection.');
                }
                $removed = array_values(array_intersect($paths, array_filter($_POST['remove_images'], 'is_string')));
                $paths = array_values(array_diff($paths, $removed));
            }
            if ($photos && !$imageCapacity) {
                throw new DomainException('Photo attachments are unavailable on this installation.');
            }
            if (count($paths) + count($photos) > NOTES_MAX_PHOTOS) {
                throw new DomainException('You can attach up to seven photos.');
            }
            if ($type === 'text' && $title === '' && trim($body) === '' && !$paths && !$photos) {
                throw new DomainException('Add a title, some text, or a photo.');
            }
            foreach ($photos as $file) {
                $paths[] = notesSavePhoto($file, $userId, $created);
            }
            if ($type === 'voice' && !$editing) {
                if (!isset($_FILES['audio'])) {
                    throw new DomainException('Record a voice note first.');
                }
                $audio = notesSaveAudio($_FILES['audio'], $created);
            }
            $fields = ['title' => $title, 'body' => $body, 'color' => $color, 'audio_path' => $audio];
            if ($imageCapacity) {
                $fields['image_path'] = notesPackImages($paths, $imageCapacity);
            }
            if ($editing) {
                $set = implode(', ', array_map(fn($key) => "$key = ?", array_keys($fields)));
                $db->prepare("UPDATE notes SET $set, updated_at = NOW() WHERE id = ? AND family_id = ?")
                    ->execute([...array_values($fields), $noteId, $familyId]);
            } else {
                $fields += ['type' => $type, 'family_id' => $familyId, 'user_id' => $userId];
                $columns = implode(', ', array_keys($fields));
                $placeholders = implode(', ', array_fill(0, count($fields), '?'));
                $db->beginTransaction();
                $eventId = notesPost('event_id');
                if ($eventId !== '') {
                    $id = eventNoteId($eventId);
                    if (!$id || !eventNotePreview($db, $familyId, $id, true)) {
                        throw new DomainException('This calendar event is no longer available.');
                    }
                    $fields['body'] = eventNoteWithLink($body, $id);
                }
                $db->prepare("INSERT INTO notes ($columns) VALUES ($placeholders)")->execute(array_values($fields));
                $noteId = (int)$db->lastInsertId();
            }
        } elseif ($action === 'delete_note') {
            $removed = array_merge(notesImagePaths($note['image_path'] ?? null), [$note['audio_path']]);
            $db->prepare('DELETE FROM notes WHERE id = ? AND family_id = ?')->execute([$noteId, $familyId]);
        } elseif ($action === 'toggle_pin') {
            $db->prepare('UPDATE notes SET pinned = ?, updated_at = updated_at WHERE id = ? AND family_id = ?')
                ->execute([(int)(!(bool)$note['pinned']), $noteId, $familyId]);
        } elseif ($action === 'set_task') {
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$note['body']));
            $index = filter_var(notesPost('line'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $checked = notesPost('checked');
            if ($note['type'] !== 'text' || $index === false || !in_array($checked, ['0', '1'], true)
                || !isset($lines[$index]) || !preg_match('/^([ \t]*[-*] \[)([ xX])(\][ \t]+\S.*)$/uD', $lines[$index], $task)) {
                throw new DomainException('This checklist item is no longer available.');
            }
            $lines[$index] = $task[1] . ($checked === '1' ? 'x' : ' ') . $task[3];
            $db->prepare('UPDATE notes SET body = ?, updated_at = NOW() WHERE id = ? AND family_id = ?')
                ->execute([implode("\n", $lines), $noteId, $familyId]);
        }

        $result = ['success' => true, 'note_id' => (string)$noteId];
        if ($action !== 'delete_note') {
            $saved = notesSelect($db, $familyId, $noteId);
            $result['note'] = notesClientData($saved, eventNoteTitles($db, $familyId, [$saved]));
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        notesRemoveMedia($created);
        if ($e instanceof DomainException) {
            notesRespond(['success' => false, 'error' => $e->getMessage()], 400);
        }
        error_log('Notes request failed: ' . $e->getMessage());
        notesRespond(['success' => false, 'error' => 'Could not save this change. Try again.'], 500);
    }
    notesRemoveMedia($removed);

    if ($action === 'create_note') {
        try {
            require_once __DIR__ . '/../core/NotificationManager.php';
            require_once __DIR__ . '/../core/NotificationTriggers.php';
            (new NotificationTriggers($db))->onNoteCreated($noteId, $userId, $familyId, $title, $type);
        } catch (Throwable $e) {
            error_log('Note notification error: ' . $e->getMessage());
        }
    }
    notesRespond($result);
}
