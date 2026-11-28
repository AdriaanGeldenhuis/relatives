<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/PushOutbox.php';

header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}
$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    Response::error('not_authenticated', 401);
}
$hash = hash('sha256', (string) $_SESSION['session_token']);
session_write_close();
$data = json_decode(file_get_contents('php://input', false, null, 0, 1024), true);
$receipt = $data['receipt'] ?? null;
if (!is_string($receipt) || !preg_match('/^[a-f0-9]{32}$/D', $receipt)) {
    Response::error('invalid_receipt', 422);
}
if (!PushOutbox::available($db)) {
    Response::error('push_unavailable', 503);
}
$outbox = new PushOutbox($db);
$userId = (int) $user['id'];
if (($data['action'] ?? null) === 'mark_read') {
    require_once __DIR__ . '/../../messages/api/classes/MessageChanges.php';
    $messageId = $outbox->chatMessageForReceipt($receipt, $userId, $hash);
    if (!$messageId) {
        http_response_code(404);
        exit;
    }
    try {
        $read = MessageChanges::markRead($db, (int) $user['family_id'], $userId, [$messageId]);
        http_response_code($read['message_ids'] ? 204 : 404);
    } catch (Throwable $error) {
        error_log('Chat alert action failed: ' . $error->getMessage());
        Response::error('Could not mark the message read.', 500);
    }
    exit;
}
http_response_code($outbox->receipt($receipt, $userId, $hash) ? 204 : 404);
