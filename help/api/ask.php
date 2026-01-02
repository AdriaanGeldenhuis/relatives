<?php
/**
 * ============================================
 * HELP API ENDPOINT
 * Handles AI chat requests with rate limiting
 * ============================================ */

declare(strict_types=1);

header('Content-Type: application/json');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session check
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Please log in to use help.'
    ]);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

// Load config
$config = require __DIR__ . '/../config.php';

// Read JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['message'])) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid request'
    ]);
    exit;
}

$userMessage = trim($data['message']);

// Validate message
if (empty($userMessage)) {
    echo json_encode([
        'ok' => false,
        'error' => 'Message cannot be empty'
    ]);
    exit;
}

if (strlen($userMessage) > 500) {
    echo json_encode([
        'ok' => false,
        'error' => 'Message too long (max 500 characters)'
    ]);
    exit;
}

// Rate limiting
$userId = $_SESSION['user_id'];
$rateLimitKey = "help_rate_limit_{$userId}";

if (!isset($_SESSION[$rateLimitKey])) {
    $_SESSION[$rateLimitKey] = [
        'count' => 0,
        'reset_time' => time() + $config['rate_limit_window']
    ];
}

// Reset if window expired
if (time() > $_SESSION[$rateLimitKey]['reset_time']) {
    $_SESSION[$rateLimitKey] = [
        'count' => 0,
        'reset_time' => time() + $config['rate_limit_window']
    ];
}

// Check limit
if ($_SESSION[$rateLimitKey]['count'] >= $config['rate_limit_requests']) {
    $timeLeft = $_SESSION[$rateLimitKey]['reset_time'] - time();
    $minutesLeft = ceil($timeLeft / 60);
    
    http_response_code(429);
    echo json_encode([
        'ok' => false,
        'error' => "Rate limit exceeded. Please try again in {$minutesLeft} minute(s)."
    ]);
    exit;
}

// Increment rate limit counter
$_SESSION[$rateLimitKey]['count']++;
$rateLimitRemaining = $config['rate_limit_requests'] - $_SESSION[$rateLimitKey]['count'];

// Read instruction file
if (!file_exists($config['instruction_file'])) {
    error_log('Help: instruction.txt not found');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'System configuration error. Please contact support.'
    ]);
    exit;
}

$instructionContent = file_get_contents($config['instruction_file']);

if ($instructionContent === false) {
    error_log('Help: Failed to read instruction.txt');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'System error. Please try again.'
    ]);
    exit;
}

// Prepare OpenAI request
$apiKey = $config['openai_api_key'];

if (empty($apiKey) || $apiKey === 'PASTE_YOUR_NEW_KEY_HERE') {
    error_log('Help: OpenAI API key not configured');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'AI service not configured. Please contact administrator.'
    ]);
    exit;
}

$requestBody = [
    'model' => $config['model'],
    'messages' => [
        [
            'role' => 'system',
            'content' => $instructionContent
        ],
        [
            'role' => 'user',
            'content' => $userMessage
        ]
    ],
    'max_tokens' => $config['max_tokens'],
    'temperature' => $config['temperature']
];

// Make API call
$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($requestBody),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Handle cURL errors
if ($response === false) {
    error_log("Help: cURL error: {$curlError}");
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Network error. Please try again.'
    ]);
    exit;
}

// Parse response
$responseData = json_decode($response, true);

if ($httpCode !== 200) {
    $errorMsg = $responseData['error']['message'] ?? 'Unknown error';
    error_log("Help: OpenAI API error ({$httpCode}): {$errorMsg}");
    
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'AI service temporarily unavailable. Please try again in a moment.'
    ]);
    exit;
}

// Extract answer
if (!isset($responseData['choices'][0]['message']['content'])) {
    error_log('Help: Invalid OpenAI response structure');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid response from AI. Please try again.'
    ]);
    exit;
}

$answer = trim($responseData['choices'][0]['message']['content']);

// Log conversation (optional)
if ($config['log_conversations']) {
    $logDir = dirname($config['log_file']);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $logEntry = json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $userId,
        'question' => $userMessage,
        'answer' => $answer,
        'tokens_used' => $responseData['usage']['total_tokens'] ?? 0
    ]) . PHP_EOL;
    
    @file_put_contents($config['log_file'], $logEntry, FILE_APPEND | LOCK_EX);
}

// Return success
echo json_encode([
    'ok' => true,
    'answer' => $answer,
    'meta' => [
        'rate_limit_remaining' => $rateLimitRemaining,
        'tokens_used' => $responseData['usage']['total_tokens'] ?? 0,
        'model' => $config['model']
    ]
]);