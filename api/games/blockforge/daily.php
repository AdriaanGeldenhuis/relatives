<?php
/**
 * BlockForge API: Daily Challenge
 * GET /api/games/blockforge/daily.php
 * Returns today's seed and rules.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=600');

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

$today = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');

try {
    // Check if today's daily exists
    $stmt = $db->prepare("SELECT date, seed, rules FROM blockforge_daily WHERE date = ?");
    $stmt->execute([$today]);
    $daily = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$daily) {
        // Generate today's seed (deterministic from date)
        $seed = hash('sha256', 'blockforge_daily_' . $today . '_v1');
        $seed = substr($seed, 0, 16);

        $rules = json_encode([
            'time_limit_ms' => 120000,
            'pieces_limit' => 200
        ]);

        // Insert
        $stmt = $db->prepare("
            INSERT IGNORE INTO blockforge_daily (date, seed, rules)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$today, $seed, $rules]);

        $daily = [
            'date' => $today,
            'seed' => $seed,
            'rules' => $rules
        ];
    }

    $modeRules = json_decode($daily['rules'], true) ?: [
        'time_limit_ms' => 120000,
        'pieces_limit' => 200
    ];

    echo json_encode([
        'date' => $daily['date'],
        'seed' => $daily['seed'],
        'mode_rules' => $modeRules
    ]);

} catch (Exception $e) {
    error_log('BlockForge daily error: ' . $e->getMessage());

    // Fallback: generate seed without DB
    $seed = substr(hash('sha256', 'blockforge_daily_' . $today . '_v1'), 0, 16);
    echo json_encode([
        'date' => $today,
        'seed' => $seed,
        'mode_rules' => [
            'time_limit_ms' => 120000,
            'pieces_limit' => 200
        ]
    ]);
}
