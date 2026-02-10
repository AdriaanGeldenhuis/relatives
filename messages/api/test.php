<?php
header('Content-Type: application/json');

// Session warmup endpoint - starts a session to prime the cookie
session_start();

echo json_encode([
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s')
]);