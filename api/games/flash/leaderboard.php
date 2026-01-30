<?php
declare(strict_types=1);

/**
 * ============================================
 * API: GET /api/games/flash/leaderboard.php
 * Returns leaderboards: Solo, Family, Global
 * ============================================
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30, s-maxage=60, stale-while-revalidate=120');

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

    $range = $_GET['range'] ?? 'today';

    // ========== SOLO LEADERBOARD ==========
    // Today's attempt
    $stmt = $db->prepare("
        SELECT score, base_score, speed_bonus, verdict, answered_in_ms, created_at
        FROM flash_attempts
        WHERE user_id = ? AND challenge_date = ?
    ");
    $stmt->execute([$userId, $today]);
    $todayAttempt = $stmt->fetch(PDO::FETCH_ASSOC);

    // Personal best
    $stmt = $db->prepare("
        SELECT personal_best_score, personal_best_date, user_streak, total_games, total_correct
        FROM flash_user_stats
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $userStats = $stmt->fetch(PDO::FETCH_ASSOC);

    $solo = [
        'today' => $todayAttempt ? [
            'score' => (int) $todayAttempt['score'],
            'base_score' => (int) $todayAttempt['base_score'],
            'speed_bonus' => (int) $todayAttempt['speed_bonus'],
            'verdict' => $todayAttempt['verdict'],
            'answered_in_ms' => (int) $todayAttempt['answered_in_ms']
        ] : null,
        'personal_best' => $userStats ? [
            'score' => (int) $userStats['personal_best_score'],
            'date' => $userStats['personal_best_date'],
            'streak' => (int) $userStats['user_streak'],
            'total_games' => (int) $userStats['total_games'],
            'accuracy' => $userStats['total_games'] > 0
                ? round(($userStats['total_correct'] / $userStats['total_games']) * 100, 1)
                : 0
        ] : null
    ];

    // ========== FAMILY LEADERBOARD ==========
    $family = [
        'today_top' => [],
        'winner' => null
    ];

    if ($familyId > 0) {
        // Today's family top 10
        $stmt = $db->prepare("
            SELECT
                a.user_id,
                u.full_name as display_name,
                u.avatar_color,
                a.score,
                a.verdict,
                a.answered_in_ms
            FROM flash_attempts a
            JOIN users u ON a.user_id = u.id
            WHERE a.family_id = ? AND a.challenge_date = ?
            ORDER BY a.score DESC, a.answered_in_ms ASC
            LIMIT 10
        ");
        $stmt->execute([$familyId, $today]);
        $familyTop = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($familyTop as $index => $entry) {
            $family['today_top'][] = [
                'rank' => $index + 1,
                'user_id' => (int) $entry['user_id'],
                'display_name' => $entry['display_name'],
                'avatar_color' => $entry['avatar_color'] ?? '#667eea',
                'initials' => strtoupper(substr($entry['display_name'] ?? '?', 0, 1)),
                'score' => (int) $entry['score'],
                'verdict' => $entry['verdict'],
                'answered_in_ms' => (int) $entry['answered_in_ms'],
                'is_current_user' => (int) $entry['user_id'] === $userId
            ];
        }

        // Family winner (top scorer)
        if (!empty($family['today_top'])) {
            $family['winner'] = $family['today_top'][0];
        }

        // Family participation stats
        $familyStats = $helper->getFamilyParticipation($familyId, $today);
        $family['participation'] = $familyStats;
    }

    // ========== GLOBAL LEADERBOARD ==========
    // Today's global top 10
    $stmt = $db->prepare("
        SELECT
            a.user_id,
            u.full_name as display_name,
            u.avatar_color,
            a.score,
            a.verdict,
            a.answered_in_ms
        FROM flash_attempts a
        JOIN users u ON a.user_id = u.id
        WHERE a.challenge_date = ?
        ORDER BY a.score DESC, a.answered_in_ms ASC
        LIMIT 10
    ");
    $stmt->execute([$today]);
    $globalTop = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $global = [
        'today_top' => []
    ];

    foreach ($globalTop as $index => $entry) {
        $global['today_top'][] = [
            'rank' => $index + 1,
            'user_id' => (int) $entry['user_id'],
            'display_name' => $entry['display_name'],
            'avatar_color' => $entry['avatar_color'] ?? '#667eea',
            'initials' => strtoupper(substr($entry['display_name'] ?? '?', 0, 1)),
            'score' => (int) $entry['score'],
            'verdict' => $entry['verdict'],
            'answered_in_ms' => (int) $entry['answered_in_ms'],
            'is_current_user' => (int) $entry['user_id'] === $userId
        ];
    }

    // Get total players today
    $stmt = $db->prepare("SELECT COUNT(*) FROM flash_attempts WHERE challenge_date = ?");
    $stmt->execute([$today]);
    $global['total_players_today'] = (int) $stmt->fetchColumn();

    // Get user's global rank if they played
    if ($todayAttempt) {
        $ranks = $helper->getUserRanks($userId, $familyId, $today);
        $global['user_rank'] = $ranks['global_today_rank'];
        if ($familyId > 0) {
            $family['user_rank'] = $ranks['family_today_rank'];
        }
    }

    // Build response
    $response = [
        'success' => true,
        'challenge_date' => $today,
        'solo' => $solo,
        'family' => $family,
        'global' => $global
    ];

    echo json_encode($response, JSON_THROW_ON_ERROR);

} catch (Exception $e) {
    error_log('Flash leaderboard error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
