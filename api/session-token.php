<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_token'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

echo json_encode([
    'success' => true,
    'session_token' => $_SESSION['session_token']
]);
