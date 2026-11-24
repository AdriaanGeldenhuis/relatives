<?php
declare(strict_types=1);

function eventNoteId($value): ?int
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
}

function eventNoteMarker(int $eventId): string
{
    return '[Calendar event](/calendar/?event=' . $eventId . ')';
}

function eventNoteIds(?string $body): array
{
    preg_match_all('/^\[Calendar event\]\(\/calendar\/\?event=([1-9][0-9]*)\)\r?$/m', $body ?? '', $matches);
    return array_values(array_unique(array_filter(array_map('eventNoteId', $matches[1]))));
}

function eventNoteWithLink(?string $body, int $eventId): string
{
    $body ??= '';
    if (in_array($eventId, eventNoteIds($body), true)) {
        return $body;
    }
    // Keep the relationship as a portable Markdown link inside the note.
    $separator = $body === '' ? '' : "\n\n";
    $body .= $separator . eventNoteMarker($eventId);
    if (strlen($body) > 65535) {
        throw new DomainException('The note is too long to add a calendar link.');
    }
    return $body;
}

function eventNoteWithoutLink(string $body, int $eventId): string
{
    $marker = preg_quote(eventNoteMarker($eventId), '/');
    // Remove its separator along with the link when it was appended at the end.
    $body = preg_replace('/(?:\r?\n){1,2}' . $marker . '(?:\r?\n)?\z/', '', $body);
    return preg_replace('/^' . $marker . '(?:\r?\n|\z)/m', '', $body);
}

function eventNoteLabel(array $note): string
{
    $title = trim((string)$note['title']);
    return $title !== '' ? $title : ($note['type'] === 'voice' ? 'Voice note' : 'Untitled note');
}

function eventNoteFind(PDO $db, int $familyId, int $noteId, bool $lock = false): ?array
{
    $stmt = $db->prepare('SELECT id, type, title, body FROM notes WHERE family_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$familyId, $noteId]);
    return $stmt->fetch() ?: null;
}

function eventNotePreview(PDO $db, int $familyId, int $eventId, bool $lock = false): ?array
{
    $stmt = $db->prepare("SELECT id, title, starts_at, ends_at FROM events WHERE family_id = ? AND id = ? AND status != 'cancelled'" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$familyId, $eventId]);
    return $stmt->fetch() ?: null;
}

function eventNoteUpdateLink(PDO $db, int $familyId, int $eventId, int $noteId, bool $link): void
{
    $note = eventNoteFind($db, $familyId, $noteId, true);
    if (!$note) {
        throw new DomainException('This note is no longer available.');
    }
    $body = (string)$note['body'];
    $updated = $link ? eventNoteWithLink($body, $eventId) : eventNoteWithoutLink($body, $eventId);
    if ($updated !== $body) {
        $stmt = $db->prepare('UPDATE notes SET body = ?, updated_at = NOW() WHERE family_id = ? AND id = ?');
        $stmt->execute([$updated, $familyId, $noteId]);
    }
}

function eventNoteList(PDO $db, int $familyId, int $eventId): array
{
    $stmt = $db->prepare('SELECT id, type, title, CASE WHEN body LIKE ? THEN body END AS body
        FROM notes WHERE family_id = ? ORDER BY updated_at DESC, created_at DESC, id DESC');
    $stmt->execute(['%' . eventNoteMarker($eventId) . '%', $familyId]);
    $linked = [];
    $available = [];
    foreach ($stmt->fetchAll() as $note) {
        $item = ['id' => (string)$note['id'], 'title' => eventNoteLabel($note)];
        if (in_array($eventId, eventNoteIds($note['body']), true)) {
            $linked[] = $item;
        } else {
            $available[] = $item;
        }
    }
    return ['linked' => $linked, 'available' => $available];
}

function eventNoteTitles(PDO $db, int $familyId, array $notes): array
{
    $ids = [];
    foreach ($notes as $note) {
        foreach (eventNoteIds($note['body']) as $id) {
            $ids[$id] = $id;
        }
    }
    if (!$ids) {
        return [];
    }
    $titles = [];
    foreach (array_chunk($ids, 500) as $batch) {
        $in = implode(',', array_fill(0, count($batch), '?'));
        $stmt = $db->prepare("SELECT id, title FROM events WHERE family_id = ? AND status != 'cancelled' AND id IN ($in)");
        $stmt->execute([$familyId, ...$batch]);
        $titles += $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $titles;
}
