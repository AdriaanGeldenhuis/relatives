<?php
declare(strict_types=1);

/**
 * ============================================
 * GAMES HUB - Landing Page v1.0
 * Family Gaming Center with Leaderboards
 * ============================================
 */

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Quick session check
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: /login.php', true, 302);
    exit;
}

// Load bootstrap
require_once __DIR__ . '/../core/bootstrap.php';

// Validate session with database
try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();

    if (!$user) {
        header('Location: /login.php?session_expired=1', true, 302);
        exit;
    }

} catch (Exception $e) {
    error_log('Games page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

// Get user's game stats
$userStats = [
    'total_games' => 0,
    'total_score' => 0,
    'best_snake_score' => 0,
    'games_today' => 0
];

try {
    // Snake best score
    $stmt = $db->prepare("
        SELECT MAX(score) as best_score, COUNT(*) as total_games, SUM(score) as total_score
        FROM snake_scores
        WHERE user_id = ? AND flagged = 0
    ");
    $stmt->execute([$user['id']]);
    $snakeStats = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($snakeStats) {
        $userStats['best_snake_score'] = (int)($snakeStats['best_score'] ?? 0);
        $userStats['total_games'] = (int)($snakeStats['total_games'] ?? 0);
        $userStats['total_score'] = (int)($snakeStats['total_score'] ?? 0);
    }

    // Games played today
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM snake_scores
        WHERE user_id = ? AND flagged = 0 AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute([$user['id']]);
    $userStats['games_today'] = (int)$stmt->fetchColumn();

    // Flash Challenge stats
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as total_flash, MAX(score) as best_flash_score
            FROM flash_attempts
            WHERE user_id = ?
        ");
        $stmt->execute([$user['id']]);
        $flashStats = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($flashStats) {
            $userStats['total_games'] += (int)($flashStats['total_flash'] ?? 0);
            $userStats['total_score'] += (int)($flashStats['best_flash_score'] ?? 0);
        }
    } catch (Exception $e) {
        // Flash tables may not exist yet
    }

    // Neon Nibbler stats
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as total_neon, MAX(score) as best_neon_score
            FROM neon_scores
            WHERE user_id = ? AND flagged = 0
        ");
        $stmt->execute([$user['id']]);
        $neonStats = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($neonStats) {
            $userStats['total_games'] += (int)($neonStats['total_neon'] ?? 0);
            $userStats['total_score'] += (int)($neonStats['best_neon_score'] ?? 0);
        }
    } catch (Exception $e) {
        // Neon table may not exist yet
    }

} catch (Exception $e) {
    error_log('Game stats error: ' . $e->getMessage());
}

// Check if user has played Flash Challenge today
$flashPlayedToday = false;
try {
    $today = (new DateTime('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d');
    $stmt = $db->prepare("SELECT 1 FROM flash_attempts WHERE user_id = ? AND challenge_date = ? LIMIT 1");
    $stmt->execute([$user['id'], $today]);
    $flashPlayedToday = (bool)$stmt->fetchColumn();
} catch (Exception $e) {
    // Flash tables may not exist yet
}

// Get family leaderboard (top 5)
$familyLeaderboard = [];
try {
    $stmt = $db->prepare("
        SELECT u.id as user_id, u.full_name, u.avatar_color, MAX(s.score) as best_score
        FROM snake_scores s
        JOIN users u ON s.user_id = u.id
        WHERE s.family_id = ? AND s.flagged = 0
        GROUP BY s.user_id, u.id, u.full_name, u.avatar_color
        ORDER BY best_score DESC
        LIMIT 5
    ");
    $stmt->execute([$user['family_id']]);
    $familyLeaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Family leaderboard error: ' . $e->getMessage());
}

// Get Global Family Leaderboard (all families combined scores)
$globalFamilyLeaderboard = [
    'all' => [],
    'snake' => [],
    'neon' => [],
    'flash' => [],
    'blockforge' => []
];

try {
    // Snake - Family combined scores (sum of best scores per user)
    $stmt = $db->prepare("
        SELECT f.id as family_id, f.name as family_name,
               SUM(user_best.best_score) as total_score,
               COUNT(DISTINCT user_best.user_id) as member_count
        FROM families f
        INNER JOIN (
            SELECT s.family_id, s.user_id, MAX(s.score) as best_score
            FROM snake_scores s
            WHERE s.flagged = 0
            GROUP BY s.family_id, s.user_id
        ) user_best ON f.id = user_best.family_id
        GROUP BY f.id, f.name
        ORDER BY total_score DESC
        LIMIT 10
    ");
    $stmt->execute();
    $globalFamilyLeaderboard['snake'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Global snake leaderboard error: ' . $e->getMessage());
}

try {
    // Neon Nibbler - Family combined scores
    $stmt = $db->prepare("
        SELECT f.id as family_id, f.name as family_name,
               SUM(user_best.best_score) as total_score,
               COUNT(DISTINCT user_best.user_id) as member_count
        FROM families f
        INNER JOIN (
            SELECT n.family_id, n.user_id, MAX(n.score) as best_score
            FROM neon_scores n
            WHERE n.flagged = 0
            GROUP BY n.family_id, n.user_id
        ) user_best ON f.id = user_best.family_id
        GROUP BY f.id, f.name
        ORDER BY total_score DESC
        LIMIT 10
    ");
    $stmt->execute();
    $globalFamilyLeaderboard['neon'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Global neon leaderboard error: ' . $e->getMessage());
}

try {
    // Flash Challenge - Family combined scores (sum of best scores per user)
    $stmt = $db->prepare("
        SELECT f.id as family_id, f.name as family_name,
               SUM(user_best.best_score) as total_score,
               COUNT(DISTINCT user_best.user_id) as member_count
        FROM families f
        INNER JOIN (
            SELECT fa.family_id, fa.user_id, MAX(fa.score) as best_score
            FROM flash_attempts fa
            GROUP BY fa.family_id, fa.user_id
        ) user_best ON f.id = user_best.family_id
        GROUP BY f.id, f.name
        ORDER BY total_score DESC
        LIMIT 10
    ");
    $stmt->execute();
    $globalFamilyLeaderboard['flash'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Global flash leaderboard error: ' . $e->getMessage());
}

try {
    // BlockForge - Family combined scores
    $stmt = $db->prepare("
        SELECT f.id as family_id, f.name as family_name,
               SUM(user_best.best_score) as total_score,
               COUNT(DISTINCT user_best.user_id) as member_count
        FROM families f
        INNER JOIN (
            SELECT b.family_id, b.user_id, MAX(b.score) as best_score
            FROM blockforge_scores b
            WHERE b.flagged = 0
            GROUP BY b.family_id, b.user_id
        ) user_best ON f.id = user_best.family_id
        GROUP BY f.id, f.name
        ORDER BY total_score DESC
        LIMIT 10
    ");
    $stmt->execute();
    $globalFamilyLeaderboard['blockforge'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Global blockforge leaderboard error: ' . $e->getMessage());
}

// Calculate combined "All Games" leaderboard
try {
    $familyTotals = [];

    // Aggregate all game scores per family
    foreach (['snake', 'neon', 'flash', 'blockforge'] as $game) {
        foreach ($globalFamilyLeaderboard[$game] as $entry) {
            $fid = $entry['family_id'];
            if (!isset($familyTotals[$fid])) {
                $familyTotals[$fid] = [
                    'family_id' => $fid,
                    'family_name' => $entry['family_name'],
                    'total_score' => 0,
                    'games_played' => 0
                ];
            }
            $familyTotals[$fid]['total_score'] += (int)$entry['total_score'];
            $familyTotals[$fid]['games_played']++;
        }
    }

    // Sort by total score and take top 10
    usort($familyTotals, fn($a, $b) => $b['total_score'] - $a['total_score']);
    $globalFamilyLeaderboard['all'] = array_slice($familyTotals, 0, 10);
} catch (Exception $e) {
    error_log('Global combined leaderboard error: ' . $e->getMessage());
}

// Encode for JavaScript
$globalLeaderboardJson = json_encode($globalFamilyLeaderboard);

// Available games
$games = [
    [
        'id' => 'flash',
        'name' => 'Flash Challenge',
        'icon' => '⚡',
        'description' => 'Daily 30-second trivia! Answer fast, compete with family, climb the leaderboard.',
        'url' => '/games/flash_challenge/',
        'color' => '#667eea',
        'features' => ['Voice Input', 'Daily Challenge', 'Family Leaderboard'],
        'available' => true,
        'played_today' => $flashPlayedToday,
        'highlight' => true
    ],
    [
        'id' => 'snake',
        'name' => 'Snake Classic',
        'icon' => '🐍',
        'description' => 'Nokia 3310 style snake game. Eat food, grow longer, avoid walls!',
        'url' => '/games/snake/',
        'color' => '#4ecca3',
        'features' => ['Offline Play', 'Family Leaderboard', 'Daily Challenges'],
        'available' => true
    ],
    [
        'id' => 'neon',
        'name' => 'Neon Nibbler',
        'icon' => '💠',
        'description' => 'Neon maze chase! Collect spark dots, dodge sentinels, grab pulse orbs!',
        'url' => '/games/neon_nibbler/',
        'color' => '#00f5ff',
        'features' => ['Offline Play', 'Pulse Mode', 'Multiple Levels'],
        'available' => true,
        'highlight' => true
    ],
    [
        'id' => 'blockforge',
        'name' => 'BlockForge',
        'icon' => '🧱',
        'description' => 'Premium neon block puzzle! Solo endless, Daily Challenge, and Family Board modes.',
        'url' => '/games/blockforge/',
        'color' => '#00f5ff',
        'features' => ['Offline Play', 'Daily Challenge', 'Family Board'],
        'available' => true,
        'highlight' => true
    ],
    [
        'id' => '30seconds',
        'name' => '30 Seconds Party',
        'icon' => '⏱️',
        'description' => 'Teams of 2 explain words in 30 seconds. Voice detection catches forbidden words!',
        'url' => '/games/30_seconds_offline/',
        'color' => '#764ba2',
        'features' => ['Voice Detection', 'Teams of 2', 'Offline Mode'],
        'available' => true,
        'highlight' => true
    ],
    [
        'id' => 'memory',
        'name' => 'Memory Match',
        'icon' => '🧠',
        'description' => 'Classic tile-matching puzzle! Match free tiles, clear the board, 3 difficulty levels.',
        'url' => '/games/mahjong_solitaire/',
        'color' => '#4ade80',
        'features' => ['3 Layouts', 'Hint System', 'Beautiful 4K Graphics'],
        'available' => true,
        'highlight' => true
    ]
];

$pageTitle = 'Games';
$activePage = 'games';
$cacheVersion = '1.0.0';
$pageCSS = [];

require_once __DIR__ . '/../shared/components/header.php';
?>

<style>
    /* Dark theme background like schedule */
    .bg-animation {
        position: fixed;
        inset: 0;
        z-index: -1;
        pointer-events: none;
        background: linear-gradient(180deg, #0f0c29 0%, #1a1a2e 50%, #16213e 100%);
    }

    .games-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
        position: relative;
        z-index: 1;
    }

    /* Compact hero like schedule's greeting-card */
    .games-hero {
        background: rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 20px;
        text-align: center;
    }

    .games-hero-icon {
        font-size: 36px;
        margin-bottom: 8px;
        filter: drop-shadow(0 4px 12px rgba(255, 255, 255, 0.3));
    }

    .games-hero h1 {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 1.5rem;
        font-weight: 900;
        margin-bottom: 4px;
        background: linear-gradient(135deg, #fff 0%, #667eea 50%, #764ba2 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .games-hero p {
        color: rgba(255, 255, 255, 0.7);
        font-size: 0.85rem;
        margin: 0;
    }

    .user-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        margin-bottom: 24px;
    }

    @media (max-width: 600px) {
        .user-stats {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    .stat-box {
        background: rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: 16px;
        padding: 16px;
        text-align: center;
        transition: transform 0.3s, box-shadow 0.3s;
    }

    .stat-box:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        background: rgba(255, 255, 255, 0.12);
    }

    .stat-box-icon {
        font-size: 24px;
        margin-bottom: 8px;
    }

    .stat-box-value {
        font-size: 1.5rem;
        font-weight: 800;
        color: #fff;
    }

    .stat-box-label {
        font-size: 0.75rem;
        color: rgba(255, 255, 255, 0.6);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .section-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }

    .section-header h2 {
        font-size: 1.1rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 8px;
        color: rgba(255, 255, 255, 0.9);
    }

    .games-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 24px;
    }

    .game-card {
        background: rgba(255, 255, 255, 0.06);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 16px;
        overflow: hidden;
        transition: transform 0.3s, box-shadow 0.3s, background 0.3s;
        text-decoration: none;
        color: inherit;
        display: block;
    }

    .game-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        background: rgba(255, 255, 255, 0.1);
    }

    .game-card.coming-soon {
        opacity: 0.5;
        cursor: default;
    }

    .game-card.coming-soon:hover {
        transform: none;
        box-shadow: none;
        background: rgba(255, 255, 255, 0.06);
    }

    .game-card-header {
        padding: 16px;
        text-align: center;
        position: relative;
    }

    .game-icon {
        font-size: 36px;
        margin-bottom: 8px;
        display: block;
    }

    .game-name {
        font-size: 0.95rem;
        font-weight: 700;
        color: #fff;
        margin-bottom: 4px;
    }

    .game-description {
        font-size: 0.75rem;
        color: rgba(255, 255, 255, 0.6);
        line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .coming-soon-badge {
        position: absolute;
        top: 8px;
        right: 8px;
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
        font-size: 0.6rem;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 10px;
        text-transform: uppercase;
    }

    .played-today-badge {
        position: absolute;
        top: 8px;
        right: 8px;
        background: linear-gradient(135deg, #4ecca3, #3db892);
        color: #1a1a2e;
        font-size: 0.6rem;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        gap: 2px;
    }

    .game-card.highlight {
        border: 1px solid rgba(102, 126, 234, 0.4);
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
    }

    .game-card.highlight:hover {
        border-color: rgba(102, 126, 234, 0.6);
        box-shadow: 0 8px 24px rgba(102, 126, 234, 0.2);
    }

    .daily-tag {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: #fff;
        font-size: 0.55rem;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 4px;
        text-transform: uppercase;
        margin-left: 4px;
        vertical-align: middle;
    }

    .game-card-features {
        padding: 10px 16px;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
    }

    .feature-tag {
        background: rgba(255, 255, 255, 0.08);
        color: rgba(255, 255, 255, 0.7);
        font-size: 0.6rem;
        font-weight: 600;
        padding: 2px 6px;
        border-radius: 12px;
    }

    .game-card-action {
        padding: 10px 16px;
        background: rgba(0, 0, 0, 0.15);
        text-align: center;
    }

    .play-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 20px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.8rem;
        transition: all 0.3s;
        border: none;
        cursor: pointer;
    }

    .play-btn.active {
        background: linear-gradient(135deg, #4ecca3, #3db892);
        color: #1a1a2e;
    }

    .play-btn.active:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 16px rgba(78, 204, 163, 0.4);
    }

    .play-btn.disabled {
        background: rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.4);
        cursor: not-allowed;
        font-size: 0.7rem;
    }

    .leaderboard-section {
        background: rgba(255, 255, 255, 0.06);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 30px;
    }

    .leaderboard-list {
        list-style: none;
    }

    .leaderboard-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    .leaderboard-item:last-child {
        border-bottom: none;
    }

    .leaderboard-rank {
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-weight: 800;
        font-size: 0.875rem;
    }

    .leaderboard-rank.gold {
        background: linear-gradient(135deg, #ffd700, #ffb700);
        color: #1a1a2e;
    }

    .leaderboard-rank.silver {
        background: linear-gradient(135deg, #c0c0c0, #a8a8a8);
        color: #1a1a2e;
    }

    .leaderboard-rank.bronze {
        background: linear-gradient(135deg, #cd7f32, #b8722c);
        color: #fff;
    }

    .leaderboard-rank.default {
        background: rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.7);
    }

    .leaderboard-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        color: #fff;
        font-size: 1rem;
    }

    .leaderboard-info {
        flex: 1;
    }

    .leaderboard-name {
        font-weight: 600;
        color: #fff;
    }

    .leaderboard-score {
        font-size: 1.125rem;
        font-weight: 800;
        color: #4ecca3;
    }

    .empty-leaderboard {
        text-align: center;
        padding: 40px 20px;
        color: rgba(255, 255, 255, 0.6);
    }

    .empty-leaderboard-icon {
        font-size: 48px;
        margin-bottom: 12px;
    }

    /* Global Leaderboard Styles */
    .global-leaderboard-section {
        background: rgba(255, 255, 255, 0.06);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 30px;
    }

    .global-leaderboard-header {
        display: flex;
        flex-direction: column;
        gap: 16px;
        margin-bottom: 20px;
    }

    .global-leaderboard-header h2 {
        font-size: 1.1rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 8px;
        color: rgba(255, 255, 255, 0.9);
        margin: 0;
    }

    .game-switcher {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        background: rgba(0, 0, 0, 0.3);
        padding: 8px;
        border-radius: 16px;
    }

    .game-switch-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        background: transparent;
        border: none;
        border-radius: 12px;
        color: rgba(255, 255, 255, 0.8);
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .game-switch-btn:hover {
        background: rgba(255, 255, 255, 0.08);
        color: #fff;
    }

    .game-switch-btn.active {
        background: #4a4a6a;
        color: #fff;
    }

    .game-switch-btn .game-icon {
        font-size: 1.1rem;
    }

    /* Colored icons for game buttons */
    .game-switch-btn[data-game="all"] .game-icon { color: #f5a623; }
    .game-switch-btn[data-game="snake"] .game-icon { color: #4ecca3; }
    .game-switch-btn[data-game="neon"] .game-icon { color: #00f5ff; }
    .game-switch-btn[data-game="flash"] .game-icon { color: #f5a623; }
    .game-switch-btn[data-game="blockforge"] .game-icon { color: #e879f9; }

    .global-leaderboard-list {
        list-style: none;
    }

    .global-leaderboard-item {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 14px 16px;
        background: rgba(255, 255, 255, 0.04);
        border-radius: 12px;
        margin-bottom: 8px;
        transition: all 0.3s;
    }

    .global-leaderboard-item:hover {
        background: rgba(255, 255, 255, 0.08);
        transform: translateX(4px);
    }

    .global-leaderboard-item:last-child {
        margin-bottom: 0;
    }

    .global-leaderboard-item.current-family {
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.2), rgba(118, 75, 162, 0.2));
        border: 1px solid rgba(102, 126, 234, 0.3);
    }

    .global-rank {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-weight: 800;
        font-size: 0.9rem;
        flex-shrink: 0;
    }

    .global-rank.gold {
        background: linear-gradient(135deg, #ffd700, #ffb700);
        color: #1a1a2e;
        box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
    }

    .global-rank.silver {
        background: linear-gradient(135deg, #c0c0c0, #a8a8a8);
        color: #1a1a2e;
    }

    .global-rank.bronze {
        background: linear-gradient(135deg, #cd7f32, #b8722c);
        color: #fff;
    }

    .global-rank.default {
        background: rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.7);
    }

    .global-family-info {
        flex: 1;
        min-width: 0;
    }

    .global-family-name {
        font-weight: 700;
        color: #fff;
        font-size: 1rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .global-family-meta {
        font-size: 0.75rem;
        color: rgba(255, 255, 255, 0.5);
        margin-top: 2px;
    }

    .global-family-score {
        font-size: 1.25rem;
        font-weight: 800;
        color: #4ecca3;
        text-align: right;
        flex-shrink: 0;
    }

    .global-family-score span {
        display: block;
        font-size: 0.65rem;
        font-weight: 500;
        color: rgba(255, 255, 255, 0.5);
        text-transform: uppercase;
    }

    .empty-global-leaderboard {
        text-align: center;
        padding: 40px 20px;
        color: rgba(255, 255, 255, 0.6);
    }

    .empty-global-leaderboard-icon {
        font-size: 48px;
        margin-bottom: 12px;
    }

    @media (max-width: 600px) {
        .global-leaderboard-header {
            flex-direction: column;
            align-items: stretch;
        }

        .game-switcher {
            width: 100%;
            overflow-x: auto;
            padding: 6px;
            -webkit-overflow-scrolling: touch;
            flex-wrap: nowrap;
        }

        .game-switch-btn {
            padding: 8px 12px;
            font-size: 0.75rem;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .game-switch-btn .btn-text {
            display: none;
        }

        .global-leaderboard-item {
            padding: 12px;
            gap: 12px;
        }

        .global-family-score {
            font-size: 1.1rem;
        }
    }
</style>

<!-- Dark Background -->
<div class="bg-animation"></div>

<main class="main-content">
    <div class="games-container">

        <!-- Hero Section (Schedule style) -->
        <section class="games-hero">
            <div class="games-hero-icon">🎮</div>
            <h1>Family Game Center</h1>
            <p>Play, compete, and have fun with your family!</p>
        </section>

        <!-- User Stats -->
        <section class="user-stats">
            <div class="stat-box">
                <div class="stat-box-icon">🎯</div>
                <div class="stat-box-value"><?php echo number_format($userStats['total_games']); ?></div>
                <div class="stat-box-label">Games Played</div>
            </div>
            <div class="stat-box">
                <div class="stat-box-icon">🏆</div>
                <div class="stat-box-value"><?php echo number_format($userStats['best_snake_score']); ?></div>
                <div class="stat-box-label">Best Score</div>
            </div>
            <div class="stat-box">
                <div class="stat-box-icon">⭐</div>
                <div class="stat-box-value"><?php echo number_format($userStats['total_score']); ?></div>
                <div class="stat-box-label">Total Points</div>
            </div>
            <div class="stat-box">
                <div class="stat-box-icon">📅</div>
                <div class="stat-box-value"><?php echo $userStats['games_today']; ?></div>
                <div class="stat-box-label">Today</div>
            </div>
        </section>

        <!-- Games Grid -->
        <section>
            <div class="section-header">
                <h2><span>🕹️</span> Available Games</h2>
            </div>

            <div class="games-grid">
                <?php foreach ($games as $game): ?>
                    <?php
                    $cardClasses = 'game-card';
                    if (!$game['available']) $cardClasses .= ' coming-soon';
                    if (!empty($game['highlight'])) $cardClasses .= ' highlight';
                    ?>
                    <?php if ($game['available']): ?>
                        <a href="<?php echo htmlspecialchars($game['url']); ?>" class="<?php echo $cardClasses; ?>">
                    <?php else: ?>
                        <div class="<?php echo $cardClasses; ?>">
                    <?php endif; ?>

                        <div class="game-card-header">
                            <?php if (!$game['available']): ?>
                                <span class="coming-soon-badge">Coming Soon</span>
                            <?php elseif (!empty($game['played_today'])): ?>
                                <span class="played-today-badge"><span>✓</span> Played Today</span>
                            <?php endif; ?>
                            <span class="game-icon"><?php echo $game['icon']; ?></span>
                            <h3 class="game-name">
                                <?php echo htmlspecialchars($game['name']); ?>
                                <?php if ($game['id'] === 'flash'): ?>
                                    <span class="daily-tag">Daily</span>
                                <?php endif; ?>
                            </h3>
                            <p class="game-description"><?php echo htmlspecialchars($game['description']); ?></p>
                        </div>

                        <div class="game-card-features">
                            <?php foreach ($game['features'] as $feature): ?>
                                <span class="feature-tag"><?php echo htmlspecialchars($feature); ?></span>
                            <?php endforeach; ?>
                        </div>

                        <div class="game-card-action">
                            <button class="play-btn <?php echo $game['available'] ? 'active' : 'disabled'; ?>">
                                <?php if ($game['available']): ?>
                                    <span>▶</span> Play Now
                                <?php else: ?>
                                    <span>🔒</span> Coming Soon
                                <?php endif; ?>
                            </button>
                        </div>

                    <?php if ($game['available']): ?>
                        </a>
                    <?php else: ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Global Family Leaderboard -->
        <section class="global-leaderboard-section" id="globalLeaderboard">
            <div class="global-leaderboard-header">
                <h2><span>🌍</span> Global Family Rankings</h2>
                <div class="game-switcher">
                    <button class="game-switch-btn active" data-game="all">
                        <span class="game-icon">🏆</span>
                        <span class="btn-text">All Games</span>
                    </button>
                    <button class="game-switch-btn" data-game="snake">
                        <span class="game-icon">🐍</span>
                        <span class="btn-text">Snake</span>
                    </button>
                    <button class="game-switch-btn" data-game="neon">
                        <span class="game-icon">💠</span>
                        <span class="btn-text">Neon</span>
                    </button>
                    <button class="game-switch-btn" data-game="flash">
                        <span class="game-icon">⚡</span>
                        <span class="btn-text">Flash</span>
                    </button>
                    <button class="game-switch-btn" data-game="blockforge">
                        <span class="game-icon">🧱</span>
                        <span class="btn-text">BlockForge</span>
                    </button>
                </div>
            </div>

            <ul class="global-leaderboard-list" id="globalLeaderboardList">
                <!-- Populated by JavaScript -->
            </ul>

            <div class="empty-global-leaderboard" id="emptyGlobalLeaderboard" style="display: none;">
                <div class="empty-global-leaderboard-icon">🏆</div>
                <p>No scores yet for this game! Be the first family to compete.</p>
            </div>
        </section>

        <!-- Family Leaderboard -->
        <section class="leaderboard-section">
            <div class="section-header">
                <h2><span>👨‍👩‍👧‍👦</span> Family Leaderboard</h2>
            </div>

            <?php if (!empty($familyLeaderboard)): ?>
                <ul class="leaderboard-list">
                    <?php foreach ($familyLeaderboard as $index => $entry): ?>
                        <li class="leaderboard-item">
                            <span class="leaderboard-rank <?php
                                echo $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : 'default'));
                            ?>">
                                <?php echo $index + 1; ?>
                            </span>
                            <div class="leaderboard-avatar" style="background: <?php echo htmlspecialchars($entry['avatar_color'] ?? '#667eea'); ?>">
                                <?php
                                $avatarPath = __DIR__ . "/../saves/{$entry['user_id']}/avatar/avatar.webp";
                                if (file_exists($avatarPath)):
                                ?>
                                    <img src="/saves/<?php echo $entry['user_id']; ?>/avatar/avatar.webp?v=<?php echo filemtime($avatarPath); ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($entry['full_name'] ?? '?', 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="leaderboard-info">
                                <div class="leaderboard-name"><?php echo htmlspecialchars($entry['full_name']); ?></div>
                            </div>
                            <div class="leaderboard-score"><?php echo number_format((int)$entry['best_score']); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="empty-leaderboard">
                    <div class="empty-leaderboard-icon">🏆</div>
                    <p>No scores yet! Be the first to play and claim the top spot.</p>
                </div>
            <?php endif; ?>
        </section>

    </div>
</main>

<script>
(function() {
    const leaderboardData = <?php echo $globalLeaderboardJson; ?>;
    const currentFamilyId = <?php echo (int)$user['family_id']; ?>;

    const gameNames = {
        all: 'All Games',
        snake: 'Snake Classic',
        neon: 'Neon Nibbler',
        flash: 'Flash Challenge',
        blockforge: 'BlockForge'
    };

    function getRankClass(index) {
        if (index === 0) return 'gold';
        if (index === 1) return 'silver';
        if (index === 2) return 'bronze';
        return 'default';
    }

    function formatNumber(num) {
        return new Intl.NumberFormat().format(num);
    }

    function renderLeaderboard(game) {
        const list = document.getElementById('globalLeaderboardList');
        const empty = document.getElementById('emptyGlobalLeaderboard');
        const data = leaderboardData[game] || [];

        if (data.length === 0) {
            list.style.display = 'none';
            empty.style.display = 'block';
            return;
        }

        list.style.display = 'block';
        empty.style.display = 'none';

        list.innerHTML = data.map((entry, index) => {
            const isCurrentFamily = parseInt(entry.family_id) === currentFamilyId;
            const memberCount = entry.member_count || entry.games_played || 0;
            const metaText = game === 'all'
                ? `${memberCount} game${memberCount !== 1 ? 's' : ''} played`
                : `${memberCount} member${memberCount !== 1 ? 's' : ''}`;

            return `
                <li class="global-leaderboard-item ${isCurrentFamily ? 'current-family' : ''}">
                    <span class="global-rank ${getRankClass(index)}">${index + 1}</span>
                    <div class="global-family-info">
                        <div class="global-family-name">${escapeHtml(entry.family_name)}</div>
                        <div class="global-family-meta">${metaText}</div>
                    </div>
                    <div class="global-family-score">
                        ${formatNumber(parseInt(entry.total_score))}
                        <span>points</span>
                    </div>
                </li>
            `;
        }).join('');
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize game switcher
    document.querySelectorAll('.game-switch-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            // Update active state
            document.querySelectorAll('.game-switch-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            // Render new leaderboard
            const game = this.dataset.game;
            renderLeaderboard(game);
        });
    });

    // Initial render
    renderLeaderboard('all');
})();
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
