<?php
/**
 * BlockForge API: Leaderboard
 * GET /api/games/blockforge/leaderboard.php?mode=solo|daily|family&range=today|week|all
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30, s-maxage=60, stale-while-revalidate=120');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

$mode = $_GET['mode'] ?? 'solo';
$range = $_GET['range'] ?? 'today';

$validModes = ['solo', 'daily', 'family'];
$validRanges = ['today', 'week', 'all'];

if (!in_array($mode, $validModes)) $mode = 'solo';
if (!in_array($range, $validRanges)) $range = 'today';

// Build date filter
$dateFilter = '';
$params = [$mode];

switch ($range) {
    case 'today':
        $dateFilter = 'AND DATE(s.created_at) = CURDATE()';
        break;
    case 'week':
        $dateFilter = 'AND s.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
        break;
    case 'all':
        $dateFilter = '';
        break;
}

try {
    $entries = [];

    if ($mode === 'family' && $familyId) {
        // Family leaderboard: top scores from family members
        $sql = "
            SELECT u.full_name as display_name, MAX(s.score) as score,
                   MAX(s.lines_cleared) as lines_cleared, MAX(s.level_reached) as level_reached
            FROM blockforge_scores s
            JOIN users u ON s.user_id = u.id
            WHERE s.mode = ? AND s.family_id = ? {$dateFilter}
            GROUP BY s.user_id, u.full_name
            ORDER BY score DESC
            LIMIT 10
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$familyId]));
    } else {
        // Solo/Daily global leaderboard
        $sql = "
            SELECT u.full_name as display_name, MAX(s.score) as score,
                   MAX(s.lines_cleared) as lines_cleared, MAX(s.level_reached) as level_reached
            FROM blockforge_scores s
            JOIN users u ON s.user_id = u.id
            WHERE s.mode = ? {$dateFilter}
            GROUP BY s.user_id, u.full_name
            ORDER BY score DESC
            LIMIT 10
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }

    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert types
    foreach ($entries as &$entry) {
        $entry['score'] = (int) $entry['score'];
        $entry['lines_cleared'] = (int) $entry['lines_cleared'];
        $entry['level_reached'] = (int) $entry['level_reached'];
    }
    unset($entry);

    echo json_encode([
        'mode' => $mode,
        'range' => $range,
        'entries' => $entries
    ]);

} catch (Exception $e) {
    error_log('BlockForge leaderboard error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
