<?php
/**
 * API Endpoint: /api/games/neon/leaderboard.php
 * Returns solo/family/global leaderboards for Neon Nibbler.
 * Query: ?range=today|week|all
 */

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

$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$userId = (int) $user['id'];
$familyId = isset($user['family_id']) ? (int) $user['family_id'] : null;
$range = $_GET['range'] ?? 'today';

// Build date filter
$dateFilter = '';
switch ($range) {
    case 'today':
        $dateFilter = "AND DATE(s.created_at) = CURDATE()";
        break;
    case 'week':
        $dateFilter = "AND s.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        break;
    case 'all':
    default:
        $dateFilter = "";
        break;
}

$result = [
    'solo' => [],
    'family' => [],
    'global' => [],
    'personal_best' => 0,
    'personal_today' => 0
];

try {
    // Personal best (all time)
    $stmt = $db->prepare("SELECT MAX(score) as best FROM neon_scores WHERE user_id = ? AND flagged = 0");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $result['personal_best'] = (int)($row['best'] ?? 0);

    // Personal today best
    $stmt = $db->prepare("SELECT MAX(score) as best FROM neon_scores WHERE user_id = ? AND flagged = 0 AND DATE(created_at) = CURDATE()");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $result['personal_today'] = (int)($row['best'] ?? 0);

    // Solo top 10 (user's own scores in range)
    $stmt = $db->prepare("
        SELECT score, level_reached, created_at
        FROM neon_scores
        WHERE user_id = ? AND flagged = 0 $dateFilter
        ORDER BY score DESC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $result['solo'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Family top 10
    if ($familyId) {
        $stmt = $db->prepare("
            SELECT u.full_name, MAX(s.score) as best_score, MAX(s.level_reached) as best_level
            FROM neon_scores s
            JOIN users u ON s.user_id = u.id
            WHERE s.family_id = ? AND s.flagged = 0 $dateFilter
            GROUP BY s.user_id, u.full_name
            ORDER BY best_score DESC
            LIMIT 10
        ");
        $stmt->execute([$familyId]);
        $result['family'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Global top 10
    $stmt = $db->prepare("
        SELECT u.full_name, MAX(s.score) as best_score, MAX(s.level_reached) as best_level
        FROM neon_scores s
        JOIN users u ON s.user_id = u.id
        WHERE s.flagged = 0 $dateFilter
        GROUP BY s.user_id, u.full_name
        ORDER BY best_score DESC
        LIMIT 10
    ");
    $stmt->execute();
    $result['global'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($result);

} catch (Exception $e) {
    error_log('Neon leaderboard error: ' . $e->getMessage());
    // Return empty results if table doesn't exist yet
    echo json_encode($result);
}
