<?php
/**
 * API Endpoint: /api/games/snake/submit_score.php
 * Accepts score submissions with basic anti-cheat validation.
 */

declare(strict_types=1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON response headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Get user data from session
$userId = (int) $_SESSION['user_id'];
$familyId = isset($_SESSION['family_id']) ? (int) $_SESSION['family_id'] : null;

// Parse JSON body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validate required fields
$requiredFields = ['score', 'mode', 'run_started_at', 'run_ended_at', 'device_id'];
foreach ($requiredFields as $field) {
    if (!isset($data[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: {$field}"]);
        exit;
    }
}

// Extract and validate score
$score = filter_var($data['score'], FILTER_VALIDATE_INT);
if ($score === false || $score < 0 || $score > 100000) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid score value']);
    exit;
}

// Extract mode
$mode = $data['mode'];
if ($mode !== 'classic') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid game mode']);
    exit;
}

// Parse and validate timestamps
$runStartedAt = strtotime($data['run_started_at']);
$runEndedAt = strtotime($data['run_ended_at']);

if ($runStartedAt === false || $runEndedAt === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid timestamp format']);
    exit;
}

// Calculate duration
$durationSeconds = $runEndedAt - $runStartedAt;

// Anti-cheat validation
$flagReason = null;

// Check 1: Duration must be positive and reasonable
if ($durationSeconds < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid run duration']);
    exit;
}

// Check 2: Score vs duration sanity check
// Each food gives 10 points, minimum ~0.5 seconds per food with speed increases
// Max realistic: about 2 foods per second at top speed
$foodsEaten = $score / 10;
$maxFoodsPerSecond = 2.5;

if ($foodsEaten > 0 && $durationSeconds > 0) {
    $foodsPerSecond = $foodsEaten / $durationSeconds;

    if ($foodsPerSecond > $maxFoodsPerSecond) {
        $flagReason = 'Score rate exceeds maximum possible';
    }
}

// Check 3: Minimum duration for score
// First few foods are slower, need at least 3 seconds to reasonably get 10 points
$minDurationForScore = max(3, ($foodsEaten - 1) * 0.3);
if ($score > 0 && $durationSeconds < $minDurationForScore) {
    $flagReason = 'Duration too short for score';
}

// Check 4: Future timestamps
$now = time();
if ($runStartedAt > $now + 60 || $runEndedAt > $now + 60) {
    $flagReason = 'Future timestamps detected';
}

// Check 5: Very old timestamps (more than 7 days)
$maxAge = 7 * 24 * 60 * 60;
if ($now - $runEndedAt > $maxAge) {
    $flagReason = 'Score too old';
}

// Validate device_id
$deviceId = substr(preg_replace('/[^a-zA-Z0-9]/', '', $data['device_id']), 0, 64);
if (strlen($deviceId) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid device ID']);
    exit;
}

// Extract optional seed
$seed = isset($data['seed']) ? substr(preg_replace('/[^a-zA-Z0-9\-]/', '', $data['seed']), 0, 16) : null;

// Database connection
try {
    $dbConfig = require __DIR__ . '/../../../config/database.php';

    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // Insert score
    $stmt = $pdo->prepare("
        INSERT INTO snake_scores
            (user_id, family_id, score, mode, run_started_at, run_ended_at, duration_seconds, device_id, seed, flagged, flag_reason, created_at)
        VALUES
            (:user_id, :family_id, :score, :mode, :run_started_at, :run_ended_at, :duration_seconds, :device_id, :seed, :flagged, :flag_reason, NOW())
    ");

    $stmt->execute([
        'user_id' => $userId,
        'family_id' => $familyId,
        'score' => $score,
        'mode' => $mode,
        'run_started_at' => date('Y-m-d H:i:s', $runStartedAt),
        'run_ended_at' => date('Y-m-d H:i:s', $runEndedAt),
        'duration_seconds' => $durationSeconds,
        'device_id' => $deviceId,
        'seed' => $seed,
        'flagged' => $flagReason !== null ? 1 : 0,
        'flag_reason' => $flagReason
    ]);

    $scoreId = $pdo->lastInsertId();

    // Return success
    echo json_encode([
        'ok' => true,
        'synced' => true,
        'score_id' => (int) $scoreId,
        'flagged' => $flagReason !== null
    ]);

} catch (PDOException $e) {
    error_log('Snake score submission error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
}
