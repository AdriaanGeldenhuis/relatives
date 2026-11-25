<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/classes/MessageChanges.php';

header('Cache-Control: private, no-store');
$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    Response::error('Not authenticated', 401);
}
session_write_close();
$family = (int) $user['family_id'];
$since = filter_input(INPUT_GET, 'since', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
$cursor = filter_input(INPUT_GET, 'cursor', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$focus = filter_input(INPUT_GET, 'message', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
try {
    $withChanges = MessageChanges::installed($db);
    $db->beginTransaction();
    if ($withChanges && is_int($cursor)) {
        $data = MessageChanges::delta($db, $family, $cursor);
    } else {
        $revision = null;
        if ($withChanges) {
            $stmt = $db->prepare('SELECT revision FROM message_revisions WHERE family_id = ?');
            $stmt->execute([$family]);
            $revision = (string) ($stmt->fetchColumn() ?: 0);
        }
        if ($since > 0) {
            $messages = MessageChanges::selectRows($db, $family, 'AND m.id > ?', [$since], 'ORDER BY m.id LIMIT 50');
        } else {
            $messages = array_reverse(MessageChanges::selectRows($db, $family, '', [], 'ORDER BY m.id DESC LIMIT 100'));
        }
        if ($focus && !in_array($focus, array_column($messages, 'id'))) {
            $messages = array_merge(MessageChanges::selectRows($db, $family, 'AND m.id = ?', [$focus], ''), $messages);
        }
        $data = ['messages' => $messages, 'cursor' => $revision];
    }
    $db->commit();
    Response::json(['success' => true, 'count' => count($data['messages']), 'since' => $since, 'timestamp' => time()] + $data);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Messages could not be fetched: ' . $e->getMessage());
    Response::error('Failed to fetch messages', 500);
}
