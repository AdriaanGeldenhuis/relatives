<?php
header('Content-Type: application/json');

// Session warmup endpoint - starts a session to prime the cookie
require_once __DIR__ . '/../../core/session_boot.php';

echo json_encode([
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s')
]);