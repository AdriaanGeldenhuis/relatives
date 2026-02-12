<?php
/**
 * API Endpoint: /api/games/snake/leaderboard.php
 * Returns leaderboard data for solo, family, and global scopes.
 */

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_name('RELATIVES_SESSION');
    session_start();
}

// Set JSON response headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30, s-maxage=60, stale-while-revalidate=120');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

// Load bootstrap for database connection
require_once __DIR__ . '/../../../core/bootstrap.php';

// Get user with Auth
$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$userId = (int) $user['id'];
$familyId = isset($user['family_id']) ? (int) $user['family_id'] : null;

// Get range parameter
$range = $_GET['range'] ?? 'today';
if (!in_array($range, ['today', 'week'], true)) {
    $range = 'today';
}

// Calculate date ranges
$today = date('Y-m-d');
$todayStart = $today . ' 00:00:00';
$todayEnd = $today . ' 23:59:59';

// Calculate week start (Monday) and end (Sunday)
$weekStart = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
$weekEnd = date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59';

try {
    // Check if table exists first
    $tableExists = false;
    try {
        $db->query("SELECT 1 FROM snake_scores LIMIT 1");
        $tableExists = true;
    } catch (PDOException $e) {
        // Table doesn't exist yet
    }

    $result = [
        'range' => $range,
        'generated_at' => date('c'),
        'solo_personal_best' => 0,
        'solo_today_best' => 0,
        'family_today_top' => [],
        'family_week_top' => [],
        'global_today_top' => [],
        'global_week_top' => []
    ];

    if (!$tableExists) {
        // Return empty results if table doesn't exist yet
        echo json_encode($result);
        exit;
    }

    // Solo - Personal best (all time)
    $stmt = $db->prepare("
        SELECT MAX(score) as best_score
        FROM snake_scores
        WHERE user_id = :user_id AND flagged = 0
    ");
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $result['solo_personal_best'] = $row && $row['best_score'] ? (int) $row['best_score'] : 0;

    // Solo - Today's best
    $stmt = $db->prepare("
        SELECT MAX(score) as best_score
        FROM snake_scores
        WHERE user_id = :user_id
          AND flagged = 0
          AND created_at BETWEEN :start AND :end
    ");
    $stmt->execute([
        'user_id' => $userId,
        'start' => $todayStart,
        'end' => $todayEnd
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $result['solo_today_best'] = $row && $row['best_score'] ? (int) $row['best_score'] : 0;

    // Family - Today's top 10
    if ($familyId !== null) {
        $stmt = $db->prepare("
            SELECT
                s.user_id,
                u.full_name as display_name,
                MAX(s.score) as score
            FROM snake_scores s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.family_id = :family_id
              AND s.flagged = 0
              AND s.created_at BETWEEN :start AND :end
            GROUP BY s.user_id, u.full_name
            ORDER BY score DESC
            LIMIT 10
        ");
        $stmt->execute([
            'family_id' => $familyId,
            'start' => $todayStart,
            'end' => $todayEnd
        ]);
        $result['family_today_top'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Family - Week's top 10
        $stmt = $db->prepare("
            SELECT
                s.user_id,
                u.full_name as display_name,
                MAX(s.score) as score
            FROM snake_scores s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.family_id = :family_id
              AND s.flagged = 0
              AND s.created_at BETWEEN :start AND :end
            GROUP BY s.user_id, u.full_name
            ORDER BY score DESC
            LIMIT 10
        ");
        $stmt->execute([
            'family_id' => $familyId,
            'start' => $weekStart,
            'end' => $weekEnd
        ]);
        $result['family_week_top'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Global - Today's top 10
    $stmt = $db->prepare("
        SELECT
            s.user_id,
            u.full_name as display_name,
            MAX(s.score) as score
        FROM snake_scores s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.flagged = 0
          AND s.created_at BETWEEN :start AND :end
        GROUP BY s.user_id, u.full_name
        ORDER BY score DESC
        LIMIT 10
    ");
    $stmt->execute([
        'start' => $todayStart,
        'end' => $todayEnd
    ]);
    $result['global_today_top'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Global - Week's top 10
    $stmt = $db->prepare("
        SELECT
            s.user_id,
            u.full_name as display_name,
            MAX(s.score) as score
        FROM snake_scores s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.flagged = 0
          AND s.created_at BETWEEN :start AND :end
        GROUP BY s.user_id, u.full_name
        ORDER BY score DESC
        LIMIT 10
    ");
    $stmt->execute([
        'start' => $weekStart,
        'end' => $weekEnd
    ]);
    $result['global_week_top'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert score strings to integers
    foreach (['family_today_top', 'family_week_top', 'global_today_top', 'global_week_top'] as $key) {
        foreach ($result[$key] as &$entry) {
            $entry['score'] = (int) $entry['score'];
            $entry['user_id'] = (int) $entry['user_id'];
        }
    }

    echo json_encode($result);

} catch (PDOException $e) {
    error_log('Snake leaderboard error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'debug' => $e->getMessage()]);
    exit;
}
