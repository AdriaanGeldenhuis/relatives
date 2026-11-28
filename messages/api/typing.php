<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/CSRF.php';
require_once __DIR__ . '/classes/ChatTyping.php';

header('Cache-Control: private, no-store');
$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    Response::error('Not authenticated', 401);
}
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && !CSRF::validate($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    Response::error('Reload Messages to continue.', 403);
}
session_write_close();
$family = (int) $user['family_id'];
$userId = (int) $user['id'];

try {
    if ($method === 'GET') {
        Response::json(['success' => true, 'typing' => ChatTyping::members($db, $family, $userId)]);
    }
    if ($method !== 'POST') {
        Response::error('Method not allowed.', 405);
    }
    $input = json_decode(file_get_contents('php://input', false, null, 0, 512), true);
    if (!is_bool($input['typing'] ?? null)) {
        Response::error('Invalid typing status.', 422);
    }
    ChatTyping::update($db, $family, $userId, $input['typing']);
    Response::json(['success' => true]);
} catch (Throwable $error) {
    error_log('Chat typing status could not be updated: ' . $error->getMessage());
    Response::error('Typing status is unavailable.', 503);
}
