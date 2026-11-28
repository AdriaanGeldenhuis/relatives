<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/classes/MessageAttachments.php';

header('Cache-Control: private, no-store');
$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    Response::error('Not authenticated', 401);
}
session_write_close();
$family = (int) $user['family_id'];
if (isset($_GET['account']) && $_GET['account'] !== $family . ':' . $user['id']) {
    Response::error('Your signed-in account has changed. Reopen Messages.', 409);
}

$query = is_string($_GET['query'] ?? null) ? mb_substr(trim($_GET['query']), 0, 200) : '';
$before = filter_input(INPUT_GET, 'before', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$sender = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$limit = max(1, min(100, filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 30));
$offset = max(0, min(10000, filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT) ?: 0));
$type = $_GET['type'] ?? null;
$conditions = ['m.family_id = ?', 'm.deleted_at IS NULL'];
$values = [$family];
$json = 'IF(JSON_VALID(m.media_path), m.media_path, NULL)';

if ($query !== '') {
    $escape = static fn (string $term) => '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $conditions[] = "(m.content LIKE ? ESCAPE '!' OR u.full_name LIKE ? ESCAPE '!'
        OR JSON_SEARCH(LOWER($json), 'one', ?, '!', '$[*].name') IS NOT NULL)";
    array_push($values, $escape($query), $escape($query), $escape(mb_strtolower($query)));
}
if ($sender) {
    $conditions[] = 'm.user_id = ?';
    $values[] = $sender;
}
foreach (['date_from' => '>=', 'date_to' => '<'] as $key => $operator) {
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        continue;
    }
    $value = $_GET[$key];
    $day = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
    if (!$day || $day->format('Y-m-d') !== $value) {
        Response::error('Choose a valid date.', 422);
    }
    $conditions[] = "m.created_at $operator ?";
    $values[] = ($key === 'date_to' ? $day->modify('+1 day') : $day)->format('Y-m-d');
}
if (in_array($type, ['image', 'video', 'audio', 'voice'], true)) {
    $kind = $type === 'voice' ? 'audio' : $type;
    $conditions[] = "(m.message_type IN (?, ?) OR JSON_SEARCH($json, 'one', ?, '!', '$[*].type') IS NOT NULL)";
    array_push($values, $type, $kind === 'audio' ? ($type === 'voice' ? 'audio' : 'voice') : $type, $kind . '/%');
} elseif (is_string($type) && $type !== '') {
    $conditions[] = 'm.message_type = ?';
    $values[] = $type;
}
if (isset($_GET['has_media'])) {
    $conditions[] = $_GET['has_media'] === '0' ? 'm.media_path IS NULL' : 'm.media_path IS NOT NULL';
}

try {
    $from = 'FROM messages m JOIN users u ON u.id = m.user_id WHERE ' . implode(' AND ', $conditions);
    $count = $db->prepare("SELECT COUNT(*) $from");
    $count->execute($values);
    $page = $db->prepare("SELECT m.id, m.user_id, m.message_type, m.content, m.media_path, m.media_thumbnail,
            m.reply_to_message_id, m.pinned, m.edited_at, m.edit_count, m.created_at, UNIX_TIMESTAMP(m.created_at) AS sent_timestamp,
            u.full_name, u.avatar_color
        $from" . ($before ? ' AND m.id < ?' : '') . ' ORDER BY m.id DESC LIMIT ? OFFSET ?');
    $page->execute([...$values, ...($before ? [$before] : []), $limit + 1, $offset]);
    $rows = $page->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    foreach ($rows as &$message) {
        $message = MessageAttachments::forClient($message);
        $message['id'] = (string) $message['id'];
        $text = $message['content'] ?? '';
        $match = $query !== '' ? mb_stripos($text, $query) : false;
        $start = $match === false ? 0 : max(0, $match - 70);
        $message['excerpt'] = ($start ? '…' : '') . mb_substr($text, $start, 230)
            . (mb_strlen($text) > $start + 230 ? '…' : '');
        $message['content_highlighted'] = htmlspecialchars($message['excerpt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    unset($message);
    Response::json([
        'success' => true,
        'results' => $rows,
        'total' => (int) $count->fetchColumn(),
        'next_before' => $more ? $rows[array_key_last($rows)]['id'] : null,
    ]);
} catch (Throwable $error) {
    error_log('Chat search failed: ' . $error->getMessage());
    Response::error('Could not search messages.', 500);
}
