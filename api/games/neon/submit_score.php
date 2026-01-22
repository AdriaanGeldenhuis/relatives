<?php
/**
 * API Endpoint: /api/games/neon/submit_score.php
 * Accepts Neon Nibbler score submissions with anti-cheat validation.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../core/bootstrap.php';

$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$userId = (int) $user['id'];
$familyId = isset($user['family_id']) ? (int) $user['family_id'] : null;

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validate fields
$score = isset($data['score']) ? (int) $data['score'] : 0;
$levelReached = isset($data['level_reached']) ? (int) $data['level_reached'] : 1;
$dotsCollected = isset($data['dots_collected']) ? (int) $data['dots_collected'] : 0;
$durationMs = isset($data['duration_ms']) ? (int) $data['duration_ms'] : 0;
$deviceId = isset($data['device_id']) ? substr((string) $data['device_id'], 0, 64) : '';

// Anti-cheat: basic validation
if ($score < 0 || $score > 999999) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid score']);
    exit;
}

if ($levelReached < 1 || $levelReached > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid level']);
    exit;
}

// Max score per minute check (generous: ~5000 per minute max)
if ($durationMs > 0 && $score > 0) {
    $minutes = max(1, $durationMs / 60000);
    $scorePerMin = $score / $minutes;
    if ($scorePerMin > 8000) {
        // Flag but still save
        $flagged = true;
    }
}

$flagged = isset($flagged) ? 1 : 0;

try {
    // Create table if not exists
    $db->exec("
        CREATE TABLE IF NOT EXISTS neon_scores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            family_id INT NULL,
            score INT NOT NULL DEFAULT 0,
            level_reached INT NOT NULL DEFAULT 1,
            dots_collected INT NOT NULL DEFAULT 0,
            duration_ms INT NOT NULL DEFAULT 0,
            device_id VARCHAR(64) DEFAULT '',
            flagged TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_score (user_id, score),
            INDEX idx_family_score (family_id, score),
            INDEX idx_created (created_at),
            INDEX idx_user_date (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $db->prepare("
        INSERT INTO neon_scores (user_id, family_id, score, level_reached, dots_collected, duration_ms, device_id, flagged)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $familyId, $score, $levelReached, $dotsCollected, $durationMs, $deviceId, $flagged]);

    echo json_encode(['ok' => true, 'synced' => true]);

} catch (Exception $e) {
    error_log('Neon score submit error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
