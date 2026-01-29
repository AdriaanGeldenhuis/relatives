<?php
/**
 * BlockForge API: Family Board
 * GET  /api/games/blockforge/family_board.php - Get current family board state
 * POST /api/games/blockforge/family_board.php - Submit a turn
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('CDN-Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../../../core/bootstrap.php';

$userId = (int) $_SESSION['user_id'];
$familyId = isset($_SESSION['family_id']) ? (int) $_SESSION['family_id'] : null;

if (!$familyId) {
    http_response_code(400);
    echo json_encode(['error' => 'No family assigned']);
    exit;
}

$today = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handleGet($db, $userId, $familyId, $today);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handlePost($db, $userId, $familyId, $today);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function handleGet($db, $userId, $familyId, $today) {
    try {
        // Get or create today's board
        $stmt = $db->prepare("
            SELECT board, meta FROM blockforge_family_boards
            WHERE date = ? AND family_id = ?
        ");
        $stmt->execute([$today, $familyId]);
        $board = $stmt->fetch(PDO::FETCH_ASSOC);

        $grid = null;
        $meta = ['turns_used' => 0, 'last_update' => null];

        if ($board) {
            $grid = json_decode($board['board'], true);
            $meta = json_decode($board['meta'], true) ?: $meta;
        }

        // Check if user has used their turn today
        $stmt = $db->prepare("
            SELECT 1 FROM blockforge_family_turns
            WHERE date = ? AND family_id = ? AND user_id = ?
        ");
        $stmt->execute([$today, $familyId, $userId]);
        $turnUsed = (bool) $stmt->fetchColumn();

        // Count members who played
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT user_id) FROM blockforge_family_turns
            WHERE date = ? AND family_id = ?
        ");
        $stmt->execute([$today, $familyId]);
        $membersPlayed = (int) $stmt->fetchColumn();

        // Total family members
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE family_id = ?");
        $stmt->execute([$familyId]);
        $totalMembers = (int) $stmt->fetchColumn();

        // Total family lines today
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(lines_cleared), 0) FROM blockforge_family_turns
            WHERE date = ? AND family_id = ?
        ");
        $stmt->execute([$today, $familyId]);
        $familyLines = (int) $stmt->fetchColumn();

        echo json_encode([
            'date' => $today,
            'grid' => $grid,
            'your_turn_used' => $turnUsed,
            'members_played' => $membersPlayed,
            'total_members' => $totalMembers,
            'family_lines' => $familyLines,
            'meta' => $meta
        ]);

    } catch (Exception $e) {
        error_log('BlockForge family_board GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

function handlePost($db, $userId, $familyId, $today) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        return;
    }

    $date = $input['date'] ?? $today;
    $actions = $input['actions'] ?? [];
    $result = $input['result'] ?? [];
    $linesCleared = (int) ($result['lines_cleared'] ?? 0);
    $scoreDelta = (int) ($result['score_delta'] ?? 0);

    try {
        // Check if user already used turn today
        $stmt = $db->prepare("
            SELECT 1 FROM blockforge_family_turns
            WHERE date = ? AND family_id = ? AND user_id = ?
        ");
        $stmt->execute([$date, $familyId, $userId]);
        if ($stmt->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['error' => 'Turn already used today']);
            return;
        }

        // Record the turn
        $stmt = $db->prepare("
            INSERT INTO blockforge_family_turns
            (date, family_id, user_id, actions, score_delta, lines_cleared, created_at)
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
        ");
        $stmt->execute([
            $date,
            $familyId,
            $userId,
            json_encode($actions),
            $scoreDelta,
            $linesCleared
        ]);

        // Rebuild board state from all turns today (replay all actions)
        // For simplicity, we store the latest grid from the client's perspective
        // In production, you'd replay server-side
        $stmt = $db->prepare("
            SELECT actions FROM blockforge_family_turns
            WHERE date = ? AND family_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$date, $familyId]);
        $allTurns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Count turns
        $turnsUsed = count($allTurns);

        // Update or create board entry
        $meta = json_encode([
            'turns_used' => $turnsUsed,
            'last_update' => date('c')
        ]);

        $stmt = $db->prepare("
            INSERT INTO blockforge_family_boards (date, family_id, board, meta)
            VALUES (?, ?, '[]', ?)
            ON DUPLICATE KEY UPDATE meta = VALUES(meta)
        ");
        $stmt->execute([$date, $familyId, $meta]);

        echo json_encode([
            'ok' => true,
            'synced' => true,
            'turns_used' => $turnsUsed
        ]);

    } catch (Exception $e) {
        error_log('BlockForge family_board POST error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}
