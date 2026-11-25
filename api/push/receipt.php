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
http_response_code((new PushOutbox($db))->receipt($receipt, (int) $user['id'], $hash) ? 204 : 404);
