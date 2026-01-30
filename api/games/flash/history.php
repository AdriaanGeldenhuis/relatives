<?php
declare(strict_types=1);

/**
 * ============================================
 * API: GET /api/games/flash/history.php
 * Returns user's last 14 days of results + family winners
 * ============================================
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, s-maxage=120, stale-while-revalidate=300');

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

    $days = min(max((int) ($_GET['days'] ?? 14), 1), 30);

    // Calculate date range
    $startDate = date('Y-m-d', strtotime("-{$days} days", strtotime($today)));

    // ========== USER'S RESULTS ==========
    $stmt = $db->prepare("
        SELECT
            a.challenge_date as date,
            a.score,
            a.base_score,
            a.speed_bonus,
            a.verdict,
            a.confidence,
            a.answered_in_ms,
            c.category,
            c.difficulty
        FROM flash_attempts a
        LEFT JOIN flash_daily_challenges c ON a.challenge_date = c.challenge_date
        WHERE a.user_id = ? AND a.challenge_date >= ?
        ORDER BY a.challenge_date DESC
    ");
    $stmt->execute([$userId, $startDate]);
    $userResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format user results and calculate family rank for each day
    $formattedResults = [];
    foreach ($userResults as $result) {
        $date = $result['date'];

        // Get user's family rank for this day
        $familyRank = null;
        if ($familyId > 0) {
            $stmt2 = $db->prepare("
                SELECT COUNT(*) + 1 as rank
                FROM flash_attempts a1
                WHERE a1.challenge_date = ? AND a1.family_id = ?
                AND (a1.score > ? OR (a1.score = ? AND a1.answered_in_ms < ?))
            ");
            $stmt2->execute([
                $date,
                $familyId,
                $result['score'],
                $result['score'],
                $result['answered_in_ms']
            ]);
            $familyRank = (int) $stmt2->fetchColumn();
        }

        $formattedResults[] = [
            'date' => $date,
            'score' => (int) $result['score'],
            'base_score' => (int) $result['base_score'],
            'speed_bonus' => (int) $result['speed_bonus'],
            'verdict' => $result['verdict'],
            'confidence' => (int) $result['confidence'],
            'answered_in_ms' => (int) $result['answered_in_ms'],
            'category' => $result['category'],
            'difficulty' => (int) $result['difficulty'],
            'rank_family' => $familyRank
        ];
    }

    // ========== FAMILY WINNERS ==========
    $familyWinners = [];
    if ($familyId > 0) {
        // Get top scorer per day for this family
        $stmt = $db->prepare("
            SELECT
                a.challenge_date as date,
                a.user_id,
                u.full_name as display_name,
                u.avatar_color,
                a.score
            FROM flash_attempts a
            JOIN users u ON a.user_id = u.id
            WHERE a.family_id = ? AND a.challenge_date >= ?
            AND a.score = (
                SELECT MAX(a2.score)
                FROM flash_attempts a2
                WHERE a2.family_id = a.family_id AND a2.challenge_date = a.challenge_date
            )
            ORDER BY a.challenge_date DESC
        ");
        $stmt->execute([$familyId, $startDate]);
        $winners = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Handle ties by picking fastest answerer
        $winnersByDate = [];
        foreach ($winners as $winner) {
            $date = $winner['date'];
            if (!isset($winnersByDate[$date])) {
                $winnersByDate[$date] = [
                    'date' => $date,
                    'user_id' => (int) $winner['user_id'],
                    'display_name' => $winner['display_name'],
                    'avatar_color' => $winner['avatar_color'] ?? '#667eea',
                    'initials' => strtoupper(substr($winner['display_name'] ?? '?', 0, 1)),
                    'score' => (int) $winner['score'],
                    'is_current_user' => (int) $winner['user_id'] === $userId
                ];
            }
        }
        $familyWinners = array_values($winnersByDate);
    }

    // ========== STREAK CALENDAR ==========
    // Build a calendar showing which days were played
    $streakCalendar = [];
    $currentDate = new DateTime($startDate);
    $endDate = new DateTime($today);

    // Get all played dates
    $stmt = $db->prepare("
        SELECT DISTINCT challenge_date
        FROM flash_attempts
        WHERE user_id = ? AND challenge_date >= ?
    ");
    $stmt->execute([$userId, $startDate]);
    $playedDates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $playedSet = array_flip($playedDates);

    while ($currentDate <= $endDate) {
        $dateStr = $currentDate->format('Y-m-d');
        $streakCalendar[] = [
            'date' => $dateStr,
            'played' => isset($playedSet[$dateStr]),
            'day_name' => $currentDate->format('D'),
            'day_num' => $currentDate->format('j')
        ];
        $currentDate->modify('+1 day');
    }

    // ========== STATISTICS ==========
    $stats = [
        'days_played' => count($userResults),
        'days_in_range' => $days,
        'total_score' => 0,
        'avg_score' => 0,
        'correct_count' => 0,
        'partial_count' => 0,
        'incorrect_count' => 0,
        'best_score_in_range' => 0,
        'best_date' => null,
        'current_streak' => 0,
        'family_wins' => 0
    ];

    foreach ($formattedResults as $result) {
        $stats['total_score'] += $result['score'];

        if ($result['score'] > $stats['best_score_in_range']) {
            $stats['best_score_in_range'] = $result['score'];
            $stats['best_date'] = $result['date'];
        }

        switch ($result['verdict']) {
            case 'correct':
                $stats['correct_count']++;
                break;
            case 'partial':
                $stats['partial_count']++;
                break;
            case 'incorrect':
                $stats['incorrect_count']++;
                break;
        }

        if ($result['rank_family'] === 1) {
            $stats['family_wins']++;
        }
    }

    if (count($formattedResults) > 0) {
        $stats['avg_score'] = round($stats['total_score'] / count($formattedResults), 1);
    }

    // Get current streak from user stats
    $stmt = $db->prepare("SELECT user_streak FROM flash_user_stats WHERE user_id = ?");
    $stmt->execute([$userId]);
    $stats['current_streak'] = (int) ($stmt->fetchColumn() ?: 0);

    // Build response
    $response = [
        'success' => true,
        'user_results' => $formattedResults,
        'family_winners' => $familyWinners,
        'streak_calendar' => $streakCalendar,
        'stats' => $stats,
        'date_range' => [
            'start' => $startDate,
            'end' => $today
        ]
    ];

    echo json_encode($response, JSON_THROW_ON_ERROR);

} catch (Exception $e) {
    error_log('Flash history error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
