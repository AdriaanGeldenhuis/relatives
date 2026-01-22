<?php
/**
 * API Endpoint: /api/games/snake/leaderboard.php
 * Returns leaderboard data for solo, family, and global scopes.
 */

declare(strict_types=1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON response headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: max-age=30'); // Cache for 30 seconds

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

// Get user data from session
$userId = (int) $_SESSION['user_id'];
$familyId = isset($_SESSION['family_id']) ? (int) $_SESSION['family_id'] : null;

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

    $result = [
        'range' => $range,
        'generated_at' => date('c')
    ];

    // Solo - Personal best (all time)
    $stmt = $pdo->prepare("
        SELECT MAX(score) as best_score
        FROM snake_scores
        WHERE user_id = :user_id AND flagged = 0
    ");
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch();
    $result['solo_personal_best'] = $row ? (int) $row['best_score'] : 0;

    // Solo - Today's best
    $stmt = $pdo->prepare("
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
    $row = $stmt->fetch();
    $result['solo_today_best'] = $row ? (int) $row['best_score'] : 0;

    // Family - Today's top 10
    if ($familyId !== null) {
        $stmt = $pdo->prepare("
            SELECT
                s.user_id,
                u.display_name,
                MAX(s.score) as score
            FROM snake_scores s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.family_id = :family_id
              AND s.flagged = 0
              AND s.created_at BETWEEN :start AND :end
            GROUP BY s.user_id, u.display_name
            ORDER BY score DESC
            LIMIT 10
        ");
        $stmt->execute([
            'family_id' => $familyId,
            'start' => $todayStart,
            'end' => $todayEnd
        ]);
        $result['family_today_top'] = $stmt->fetchAll();

        // Family - Week's top 10
        $stmt = $pdo->prepare("
            SELECT
                s.user_id,
                u.display_name,
                MAX(s.score) as score
            FROM snake_scores s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.family_id = :family_id
              AND s.flagged = 0
              AND s.created_at BETWEEN :start AND :end
            GROUP BY s.user_id, u.display_name
            ORDER BY score DESC
            LIMIT 10
        ");
        $stmt->execute([
            'family_id' => $familyId,
            'start' => $weekStart,
            'end' => $weekEnd
        ]);
        $result['family_week_top'] = $stmt->fetchAll();
    } else {
        $result['family_today_top'] = [];
        $result['family_week_top'] = [];
    }

    // Global - Today's top 10
    $stmt = $pdo->prepare("
        SELECT
            s.user_id,
            u.display_name,
            MAX(s.score) as score
        FROM snake_scores s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.flagged = 0
          AND s.created_at BETWEEN :start AND :end
        GROUP BY s.user_id, u.display_name
        ORDER BY score DESC
        LIMIT 10
    ");
    $stmt->execute([
        'start' => $todayStart,
        'end' => $todayEnd
    ]);
    $result['global_today_top'] = $stmt->fetchAll();

    // Global - Week's top 10
    $stmt = $pdo->prepare("
        SELECT
            s.user_id,
            u.display_name,
            MAX(s.score) as score
        FROM snake_scores s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.flagged = 0
          AND s.created_at BETWEEN :start AND :end
        GROUP BY s.user_id, u.display_name
        ORDER BY score DESC
        LIMIT 10
    ");
    $stmt->execute([
        'start' => $weekStart,
        'end' => $weekEnd
    ]);
    $result['global_week_top'] = $stmt->fetchAll();

    // Convert score strings to integers
    foreach (['family_today_top', 'family_week_top', 'global_today_top', 'global_week_top'] as $key) {
        foreach ($result[$key] as &$entry) {
            $entry['score'] = (int) $entry['score'];
            $entry['user_id'] = (int) $entry['user_id'];
        }
    }

    echo json_encode($result, JSON_THROW_ON_ERROR);

} catch (PDOException $e) {
    error_log('Snake leaderboard error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
}
