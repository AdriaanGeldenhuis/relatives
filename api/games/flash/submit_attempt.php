<?php
declare(strict_types=1);

/**
 * ============================================
 * API: POST /api/games/flash/submit_attempt.php
 * Submit user's answer for today's Flash Challenge
 * ============================================
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('CDN-Cache-Control: no-store');

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Load dependencies
require_once __DIR__ . '/../../../core/bootstrap.php';
require_once __DIR__ . '/FlashHelper.php';

try {
    $helper = new FlashHelper($db);
    $today = $helper->getTodayDate();
    $userId = (int) $_SESSION['user_id'];
    $familyId = (int) ($_SESSION['family_id'] ?? 0);
    $displayName = $_SESSION['display_name'] ?? 'Player';

    // Parse JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        exit;
    }

    // Validate required fields
    $requiredFields = ['challenge_date', 'answer_text', 'started_at', 'answered_at', 'ended_at'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Missing required field: {$field}"]);
            exit;
        }
    }

    // Validate challenge_date is today
    $challengeDate = $input['challenge_date'];
    if ($challengeDate !== $today) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid challenge date. You can only submit for today\'s challenge.'
        ]);
        exit;
    }

    // Check if already attempted
    $existingAttempt = $helper->getUserAttempt($userId, $today);
    if ($existingAttempt) {
        // Return 409 with existing attempt data
        $fullChallenge = $helper->getFullChallenge($today);
        $ranks = $helper->getUserRanks($userId, $familyId, $today);

        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Already submitted an attempt for today',
            'attempt' => [
                'verdict' => $existingAttempt['verdict'],
                'confidence' => (int) $existingAttempt['confidence'],
                'reason' => $existingAttempt['reason'],
                'score' => (int) $existingAttempt['score'],
                'base_score' => (int) $existingAttempt['base_score'],
                'speed_bonus' => (int) $existingAttempt['speed_bonus'],
                'answer_text' => $existingAttempt['answer_text'],
                'normalized_answer' => $existingAttempt['normalized_answer'],
                'correct_answers' => $fullChallenge['valid_answers'] ?? [],
                'answered_in_ms' => (int) $existingAttempt['answered_in_ms']
            ],
            'ranks' => $ranks
        ]);
        exit;
    }

    // Validate answer length
    $answerText = trim($input['answer_text']);
    if (mb_strlen($answerText) > 200) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Answer too long (max 200 characters)']);
        exit;
    }

    // Parse timestamps
    $startedAt = strtotime($input['started_at']);
    $answeredAt = strtotime($input['answered_at']);
    $endedAt = strtotime($input['ended_at']);

    if (!$startedAt || !$answeredAt || !$endedAt) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid timestamp format']);
        exit;
    }

    // Anti-cheat: Validate timestamp order
    if ($startedAt > $answeredAt || $answeredAt > $endedAt) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid timestamp sequence']);
        exit;
    }

    // Anti-cheat: Validate duration (should be around 30 seconds, with tolerance)
    $duration = $endedAt - $startedAt;
    if ($duration < 1 || $duration > 60) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid game duration']);
        exit;
    }

    // Calculate answered_in_ms
    $answeredInMs = ($answeredAt - $startedAt) * 1000;
    if ($answeredInMs < 0 || $answeredInMs > 35000) {
        // Allow slight tolerance (35 seconds) for network delays
        $answeredInMs = min(max($answeredInMs, 0), 30000);
    }

    // Get full challenge with answers
    $fullChallenge = $helper->getFullChallenge($today);
    if (!$fullChallenge) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Challenge not found']);
        exit;
    }

    // Validate the answer
    $validationResult = $helper->validateAnswer(
        $answerText,
        $fullChallenge['valid_answers'],
        $fullChallenge['partial_rules']
    );

    // Calculate score
    $scoreResult = $helper->calculateScore($validationResult['verdict'], (int) $answeredInMs);

    // Insert attempt
    $deviceId = $input['device_id'] ?? null;

    $stmt = $db->prepare("
        INSERT INTO flash_attempts (
            challenge_date, user_id, family_id, device_id,
            answer_text, normalized_answer, verdict, confidence, reason,
            base_score, speed_bonus, score,
            started_at, answered_at, ended_at, answered_in_ms
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $today,
        $userId,
        $familyId ?: null,
        $deviceId,
        $answerText,
        $validationResult['normalized_answer'],
        $validationResult['verdict'],
        $validationResult['confidence'],
        $validationResult['reason'],
        $scoreResult['base_score'],
        $scoreResult['speed_bonus'],
        $scoreResult['score'],
        date('Y-m-d H:i:s', $startedAt),
        date('Y-m-d H:i:s', $answeredAt),
        date('Y-m-d H:i:s', $endedAt),
        (int) $answeredInMs
    ]);

    $attemptId = (int) $db->lastInsertId();

    // Log validation method
    $helper->logValidation($attemptId, $validationResult['method']);

    // Update user stats
    $helper->updateUserStats($userId, $validationResult['verdict'], $scoreResult['score'], $today);

    // Get updated stats
    $userStreak = $helper->getUserStreak($userId);
    $familyStats = $familyId > 0 ? $helper->getFamilyParticipation($familyId, $today) : null;
    $ranks = $helper->getUserRanks($userId, $familyId, $today);

    // Check if this is a personal best
    $stmt = $db->prepare("SELECT personal_best_score, personal_best_date FROM flash_user_stats WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userStats = $stmt->fetch(PDO::FETCH_ASSOC);
    $isPersonalBest = $userStats && $userStats['personal_best_date'] === $today;

    // Check if family winner
    $isFamilyWinner = $familyId > 0 && $ranks['family_today_rank'] === 1;

    // Build response
    $response = [
        'success' => true,
        'verdict' => $validationResult['verdict'],
        'confidence' => $validationResult['confidence'],
        'reason' => $validationResult['reason'],
        'score' => $scoreResult['score'],
        'base_score' => $scoreResult['base_score'],
        'speed_bonus' => $scoreResult['speed_bonus'],
        'normalized_answer' => $validationResult['normalized_answer'],
        'correct_answers' => $fullChallenge['valid_answers'],
        'streak' => [
            'user_streak' => $userStreak,
            'family_participation_percent' => $familyStats['participation_percent'] ?? 0
        ],
        'ranks' => [
            'solo_today_rank' => 1, // First attempt of the day
            'family_today_rank' => $ranks['family_today_rank'],
            'global_today_rank' => $ranks['global_today_rank']
        ],
        'achievements' => [
            'is_personal_best' => $isPersonalBest,
            'is_family_winner' => $isFamilyWinner
        ]
    ];

    echo json_encode($response, JSON_THROW_ON_ERROR);

} catch (PDOException $e) {
    // Handle duplicate key (race condition)
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Already submitted an attempt for today'
        ]);
        exit;
    }

    error_log('Flash submit_attempt DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);

} catch (Exception $e) {
    error_log('Flash submit_attempt error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
