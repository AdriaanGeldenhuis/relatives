<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/CSRF.php';
require_once __DIR__ . '/classes/MessageChanges.php';

header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}
$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    Response::error('Not authenticated', 401);
}
if (!CSRF::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    Response::error('Reload chat and try again.', 403);
}
session_write_close();

$input = json_decode(file_get_contents('php://input', false, null, 0, 4096), true);
$ids = $input['message_ids'] ?? [$input['message_id'] ?? null];
if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > 100) {
    Response::error('Choose up to 100 messages.', 422);
}
foreach ($ids as &$id) {
    $id = (is_int($id) || is_string($id))
        ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    if ($id === false) {
        Response::error('Invalid message ID.', 422);
    }
}
unset($id);

try {
    $read = MessageChanges::markRead($db, (int) $user['family_id'], (int) $user['id'], array_values(array_unique($ids)));
    if (!$read['message_ids'] && !isset($input['message_ids'])) {
        Response::error('Message not found.', 404);
    }
    Response::json(['success' => true] + $read);
} catch (Throwable $error) {
    error_log('Message read could not be saved: ' . $error->getMessage());
    Response::error('Could not mark messages read.', 500);
}
