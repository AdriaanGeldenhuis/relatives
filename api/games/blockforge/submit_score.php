<?php
/**
 * BlockForge API: Submit Score
 * POST /api/games/blockforge/submit_score.php
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_name('RELATIVES_SESSION');
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('CDN-Cache-Control: no-store');

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

$userId = (int) $_SESSION['user_id'];
$familyId = isset($_SESSION['family_id']) ? (int) $_SESSION['family_id'] : null;

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$mode = $input['mode'] ?? '';
$score = (int) ($input['score'] ?? 0);
$linesCleared = (int) ($input['lines_cleared'] ?? 0);
$levelReached = (int) ($input['level_reached'] ?? 0);
$durationMs = (int) ($input['duration_ms'] ?? 0);
$seed = $input['seed'] ?? '';
$deviceId = $input['device_id'] ?? '';

// Validate mode
$validModes = ['solo', 'daily', 'family'];
if (!in_array($mode, $validModes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid mode']);
    exit;
}

// Basic anti-cheat: validate score thresholds
if ($durationMs > 0 && $score > 0) {
    // Max theoretical score per second ~500 (quad + combo at high level)
    $maxScorePerSec = 500;
    $durationSec = $durationMs / 1000;
    $maxPossible = $durationSec * $maxScorePerSec;
    if ($score > $maxPossible && $durationSec < 5) {
        http_response_code(400);
        echo json_encode(['error' => 'Score validation failed']);
        exit;
    }
}

// Validate lines vs max possible
if ($linesCleared > 0 && $durationMs > 0) {
    // Max lines per second ~4
    $maxLinesPerSec = 4;
    $maxLines = ($durationMs / 1000) * $maxLinesPerSec;
    if ($linesCleared > $maxLines + 10) {
        http_response_code(400);
        echo json_encode(['error' => 'Lines validation failed']);
        exit;
    }
}

try {
    // Insert score
    $stmt = $db->prepare("
        INSERT INTO blockforge_scores
        (user_id, family_id, mode, score, lines_cleared, level_reached, duration_ms, seed, device_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
    ");
    $stmt->execute([
        $userId,
        $familyId,
        $mode,
        $score,
        $linesCleared,
        $levelReached,
        $durationMs,
        $seed,
        $deviceId
    ]);

    // Calculate ranks
    $ranks = [];

    // Solo rank (user's rank among all for this mode today)
    $stmt = $db->prepare("
        SELECT COUNT(*) + 1 as rank FROM blockforge_scores
        WHERE mode = ? AND DATE(created_at) = CURDATE() AND score > ?
    ");
    $stmt->execute([$mode, $score]);
    $ranks['solo'] = (int) $stmt->fetchColumn();

    // Family rank
    if ($familyId) {
        $stmt = $db->prepare("
            SELECT COUNT(*) + 1 as rank FROM blockforge_scores
            WHERE mode = ? AND family_id = ? AND DATE(created_at) = CURDATE() AND score > ?
        ");
        $stmt->execute([$mode, $familyId, $score]);
        $ranks['family'] = (int) $stmt->fetchColumn();
    }

    // Global rank
    $stmt = $db->prepare("
        SELECT COUNT(*) + 1 as rank FROM blockforge_scores
        WHERE mode = ? AND score > ?
    ");
    $stmt->execute([$mode, $score]);
    $ranks['global'] = (int) $stmt->fetchColumn();

    echo json_encode([
        'ok' => true,
        'synced' => true,
        'ranks' => $ranks
    ]);

} catch (Exception $e) {
    error_log('BlockForge submit_score error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
