<?php
declare(strict_types=1);

/**
 * ============================================
 * API: GET /api/games/flash/get_daily.php
 * Returns today's Flash Challenge (without answers)
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

// Only GET allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

    // Get today's challenge
    $challenge = $helper->getDailyChallenge($today);

    // If no challenge exists, try to generate one
    if (!$challenge) {
        $challenge = $helper->generateDailyChallenge($today);

        if (!$challenge) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'error' => 'Daily challenge not available. Please try again later.'
            ]);
            exit;
        }
    }

    // Check if user has already attempted today
    $existingAttempt = $helper->getUserAttempt($userId, $today);
    $hasPlayed = $existingAttempt !== null;

    // Get user's streak
    $userStreak = $helper->getUserStreak($userId);

    // Get family participation
    $familyStats = $familyId > 0 ? $helper->getFamilyParticipation($familyId, $today) : null;

    // Build response
    $response = [
        'success' => true,
        'challenge' => [
            'challenge_date' => $challenge['challenge_date'],
            'question' => $challenge['question'],
            'answer_type' => $challenge['answer_type'],
            'difficulty' => (int) $challenge['difficulty'],
            'category' => $challenge['category'],
            'format_hint' => $challenge['format_hint']
        ],
        'user_status' => [
            'has_played_today' => $hasPlayed,
            'user_streak' => $userStreak
        ]
    ];

    // Include attempt data if already played
    if ($hasPlayed && $existingAttempt) {
        // Get full challenge to reveal answers
        $fullChallenge = $helper->getFullChallenge($today);

        $response['attempt'] = [
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
        ];

        // Get ranks
        $ranks = $helper->getUserRanks($userId, $familyId, $today);
        $response['ranks'] = $ranks;
    }

    // Include family stats
    if ($familyStats) {
        $response['family_status'] = $familyStats;
    }

    echo json_encode($response, JSON_THROW_ON_ERROR);

} catch (Exception $e) {
    error_log('Flash get_daily error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error. Please try again.'
    ]);
}
