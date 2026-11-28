<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/classes/MessageChanges.php';
require_once __DIR__ . '/classes/ChatTyping.php';

header('Cache-Control: private, no-store');
$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    Response::error('Not authenticated', 401);
}
session_write_close();

$family = (int) $user['family_id'];
$userId = (int) $user['id'];
if (isset($_GET['account']) && $_GET['account'] !== $family . ':' . $userId) {
    Response::error('Your signed-in account has changed. Reopen Messages.', 409);
}

$positiveId = ['options' => ['min_range' => 1]];
$before = filter_input(INPUT_GET, 'before', FILTER_VALIDATE_INT, $positiveId) ?: 0;
$after = filter_input(INPUT_GET, 'after', FILTER_VALIDATE_INT, $positiveId) ?: 0;
$focus = filter_input(INPUT_GET, 'message', FILTER_VALIDATE_INT, $positiveId) ?: 0;
$since = filter_input(INPUT_GET, 'since', FILTER_VALIDATE_INT, $positiveId) ?: 0;
$cursor = filter_input(INPUT_GET, 'cursor', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$pending = is_string($_GET['pending'] ?? null) ? explode(',', $_GET['pending'], 21) : [];
$pending = array_values(array_unique(array_filter(array_map([MessageChanges::class, 'clientId'], array_slice($pending, 0, 20)))));

try {
    $db->beginTransaction();
    if ($before || $after) {
        $data = MessageChanges::history($db, $family, $before, $after, 0);
    } else {
        try {
            $revision = MessageChanges::revision($db, $family);
        } catch (PDOException $error) {
            if ($error->getCode() !== '42S02') {
                throw $error;
            }
            $revision = null;
        }
        if (is_int($cursor) && $revision !== null) {
            $data = MessageChanges::delta($db, $family, $cursor, $revision);
        } elseif ($since) {
            $messages = MessageChanges::selectRows($db, $family, 'AND m.id > ?', [$since], 'ORDER BY m.id LIMIT 51');
            $data = ['messages' => array_slice($messages, 0, 50), 'more' => count($messages) > 50,
                'cursor' => $revision === null ? null : (string) $revision];
        } else {
            $data = MessageChanges::history($db, $family, 0, 0, $focus);
            $data['cursor'] = $revision === null ? null : (string) $revision;
            $data['latest_message_id'] = $data['has_newer']
                ? MessageChanges::latestId($db, $family)
                : (string) ($data['messages'][array_key_last($data['messages'])]['id'] ?? 0);
        }
        if (($pending || ($_GET['typing'] ?? null) === '1') && $revision !== null) {
            if ($pending) {
                $data['acknowledged'] = MessageChanges::acknowledged($db, $family, $userId, $pending);
            }
            if (($_GET['typing'] ?? null) === '1') {
                $data['typing'] = ChatTyping::members($db, $family, $userId);
            }
        }
    }
    $db->commit();
    Response::json(['success' => true, 'count' => count($data['messages']), 'since' => $since, 'timestamp' => time()] + $data);
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Messages could not be fetched: ' . $error->getMessage());
    Response::error('Failed to fetch messages', 500);
}
