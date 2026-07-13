<?php
declare(strict_types=1);

/**
 * ============================================
 * GAMES HUB - Landing Page v1.0
 * Family Gaming Center with Leaderboards
 * ============================================
 */

// Start session first
require_once __DIR__ . '/../core/session_boot.php';

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

// Get family leaderboard for each game (top 5 per game)
$familyLeaderboard = [
    'all' => [],
    'snake' => [],
    'neon' => [],
    'flash' => [],
    'blockforge' => []
];

try {
    // Snake family leaderboard
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
    $familyLeaderboard['snake'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Family snake leaderboard error: ' . $e->getMessage());
}

try {
    // Neon family leaderboard
    $stmt = $db->prepare("
        SELECT u.id as user_id, u.full_name, u.avatar_color, MAX(n.score) as best_score
        FROM neon_scores n
        JOIN users u ON n.user_id = u.id
        WHERE n.family_id = ? AND n.flagged = 0
        GROUP BY n.user_id, u.id, u.full_name, u.avatar_color
        ORDER BY best_score DESC
        LIMIT 5
    ");
    $stmt->execute([$user['family_id']]);
    $familyLeaderboard['neon'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Family neon leaderboard error: ' . $e->getMessage());
}

try {
    // Flash family leaderboard
    $stmt = $db->prepare("
        SELECT u.id as user_id, u.full_name, u.avatar_color, MAX(fa.score) as best_score
        FROM flash_attempts fa
        JOIN users u ON fa.user_id = u.id
        WHERE fa.family_id = ?
        GROUP BY fa.user_id, u.id, u.full_name, u.avatar_color
        ORDER BY best_score DESC
        LIMIT 5
    ");
    $stmt->execute([$user['family_id']]);
    $familyLeaderboard['flash'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Family flash leaderboard error: ' . $e->getMessage());
}

try {
    // BlockForge family leaderboard
    $stmt = $db->prepare("
        SELECT u.id as user_id, u.full_name, u.avatar_color, MAX(b.score) as best_score
        FROM blockforge_scores b
        JOIN users u ON b.user_id = u.id
        WHERE b.family_id = ? AND b.flagged = 0
        GROUP BY b.user_id, u.id, u.full_name, u.avatar_color
        ORDER BY best_score DESC
        LIMIT 5
    ");
    $stmt->execute([$user['family_id']]);
    $familyLeaderboard['blockforge'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Family blockforge leaderboard error: ' . $e->getMessage());
}

// Calculate combined "All Games" family leaderboard
try {
    $userTotals = [];
    foreach (['snake', 'neon', 'flash', 'blockforge'] as $game) {
        foreach ($familyLeaderboard[$game] as $entry) {
            $uid = $entry['user_id'];
            if (!isset($userTotals[$uid])) {
                $userTotals[$uid] = [
                    'user_id' => $uid,
                    'full_name' => $entry['full_name'],
                    'avatar_color' => $entry['avatar_color'],
                    'best_score' => 0
                ];
            }
            $userTotals[$uid]['best_score'] += (int)$entry['best_score'];
        }
    }
    usort($userTotals, fn($a, $b) => $b['best_score'] - $a['best_score']);
    $familyLeaderboard['all'] = array_slice($userTotals, 0, 5);
} catch (Exception $e) {
    error_log('Family combined leaderboard error: ' . $e->getMessage());
}

// Encode for JavaScript
$familyLeaderboardJson = json_encode($familyLeaderboard);

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
$pageCSS = [
    '/shared/css/aurora.css',
    '/games/css/games.css'
];

require_once __DIR__ . '/../shared/components/header.php';
?>

<!-- Aurora background -->
<div class="aurora" aria-hidden="true">
    <div class="aurora-blob aurora-a"></div>
    <div class="aurora-blob aurora-b"></div>
    <div class="aurora-blob aurora-c"></div>
    <div class="stars stars-a"></div>
    <div class="stars stars-b"></div>
    <div class="shooting-star"></div>
    <div class="aurora-grid"></div>
</div>

<main class="main-content">
    <div class="hub">

        <!-- Compact page header -->
        <header class="page-head" style="--glow:#a78bfa; --page-glow: rgba(167, 139, 250, 0.16)">
            <span class="ph-glyph" aria-hidden="true">🎮</span>
            <div class="ph-titles">
                <h1>Games</h1>
                <p class="ph-sub"><?php echo count($games); ?> games · play, compete, brag</p>
            </div>
            <div class="ph-actions">
                <a href="#familyLeaderboard" class="pill-btn">
                    <span aria-hidden="true">🏆</span> Leaderboards
                </a>
            </div>
        </header>

        <!-- Your stats -->
        <section class="games-stats-bar">
            <div class="stat-item">
                <span class="stat-icon" aria-hidden="true">🎯</span>
                <span class="stat-value"><?php echo number_format($userStats['total_games']); ?></span>
                <span class="stat-label">Played</span>
            </div>
            <div class="stat-item">
                <span class="stat-icon" aria-hidden="true">🏆</span>
                <span class="stat-value"><?php echo number_format($userStats['best_snake_score']); ?></span>
                <span class="stat-label">Best score</span>
            </div>
            <div class="stat-item">
                <span class="stat-icon" aria-hidden="true">⭐</span>
                <span class="stat-value"><?php echo number_format($userStats['total_score']); ?></span>
                <span class="stat-label">Total points</span>
            </div>
            <div class="stat-item">
                <span class="stat-icon" aria-hidden="true">📅</span>
                <span class="stat-value"><?php echo $userStats['games_today']; ?></span>
                <span class="stat-label">Today</span>
            </div>
        </section>

        <!-- Games Grid -->
        <section class="games-section">
            <div class="section-header">
                <h2><span aria-hidden="true">🕹️</span> Pick a game</h2>
            </div>

            <div class="games-grid">
                <?php foreach ($games as $game): ?>
                    <?php
                    $cardClasses = 'game-card';
                    if (!$game['available']) $cardClasses .= ' coming-soon';
                    if (!empty($game['highlight'])) $cardClasses .= ' highlight';
                    ?>
                    <?php if ($game['available']): ?>
                        <a href="<?php echo htmlspecialchars($game['url']); ?>" class="<?php echo $cardClasses; ?>" style="--glow: <?php echo htmlspecialchars($game['color']); ?>">
                    <?php else: ?>
                        <div class="<?php echo $cardClasses; ?>" style="--glow: <?php echo htmlspecialchars($game['color']); ?>">
                    <?php endif; ?>

                        <div class="game-card-header">
                            <?php if (!$game['available']): ?>
                                <span class="coming-soon-badge">Coming Soon</span>
                            <?php elseif (!empty($game['played_today'])): ?>
                                <span class="played-today-badge"><span>✓</span> Played today</span>
                            <?php endif; ?>
                            <span class="game-chip" aria-hidden="true"><span class="game-icon"><?php echo $game['icon']; ?></span></span>
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
                            <span class="play-btn <?php echo $game['available'] ? 'active' : 'disabled'; ?>">
                                <?php if ($game['available']): ?>
                                    <span aria-hidden="true">▶</span> Play now
                                <?php else: ?>
                                    <span aria-hidden="true">🔒</span> Coming soon
                                <?php endif; ?>
                            </span>
                        </div>

                    <?php if ($game['available']): ?>
                        </a>
                    <?php else: ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Family Leaderboard -->
        <section class="leaderboard-section panel" id="familyLeaderboard" style="--glow:#f472b6">
            <div class="global-leaderboard-header">
                <h2><span aria-hidden="true">👨‍👩‍👧‍👦</span> Family Leaderboard</h2>
                <div class="game-switcher" id="familyGameSwitcher">
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

            <ul class="leaderboard-list" id="familyLeaderboardList">
                <!-- Populated by JavaScript -->
            </ul>

            <div class="empty-leaderboard" id="emptyFamilyLeaderboard" style="display: none;">
                <div class="empty-leaderboard-icon">🏆</div>
                <p>No scores yet! Be the first to play and claim the top spot.</p>
            </div>
        </section>

        <!-- Global Family Leaderboard -->
        <section class="global-leaderboard-section panel" id="globalLeaderboard" style="--glow:#38bdf8">
            <div class="global-leaderboard-header">
                <h2><span aria-hidden="true">🌍</span> Global Family Rankings</h2>
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

    </div>
</main>

<script>
(function() {
    const globalLeaderboardData = <?php echo $globalLeaderboardJson; ?>;
    const familyLeaderboardData = <?php echo $familyLeaderboardJson; ?>;
    const currentFamilyId = <?php echo (int)$user['family_id']; ?>;
    const currentUserId = <?php echo (int)$user['id']; ?>;

    function getRankClass(index) {
        if (index === 0) return 'gold';
        if (index === 1) return 'silver';
        if (index === 2) return 'bronze';
        return 'default';
    }

    function formatNumber(num) {
        return new Intl.NumberFormat().format(num);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Global Family Rankings
    function renderGlobalLeaderboard(game) {
        const list = document.getElementById('globalLeaderboardList');
        const empty = document.getElementById('emptyGlobalLeaderboard');
        const data = globalLeaderboardData[game] || [];

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

    // Family Leaderboard (members within family)
    function renderFamilyLeaderboard(game) {
        const list = document.getElementById('familyLeaderboardList');
        const empty = document.getElementById('emptyFamilyLeaderboard');
        const data = familyLeaderboardData[game] || [];

        if (data.length === 0) {
            list.style.display = 'none';
            empty.style.display = 'block';
            return;
        }

        list.style.display = 'block';
        empty.style.display = 'none';

        list.innerHTML = data.map((entry, index) => {
            const isCurrentUser = parseInt(entry.user_id) === currentUserId;
            const avatarColor = entry.avatar_color || '#667eea';
            const initial = (entry.full_name || '?').charAt(0).toUpperCase();
            const avatarUrl = `/saves/${entry.user_id}/avatar/avatar.webp`;

            return `
                <li class="leaderboard-item ${isCurrentUser ? 'current-user' : ''}">
                    <span class="leaderboard-rank ${getRankClass(index)}">${index + 1}</span>
                    <div class="leaderboard-avatar" style="background: ${avatarColor}">
                        <img src="${avatarUrl}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" onerror="this.style.display='none';this.parentNode.innerHTML='${initial}';">
                    </div>
                    <div class="leaderboard-info">
                        <div class="leaderboard-name">${escapeHtml(entry.full_name)}</div>
                    </div>
                    <div class="leaderboard-score">${formatNumber(parseInt(entry.best_score))}</div>
                </li>
            `;
        }).join('');
    }

    // Initialize global leaderboard game switcher
    document.querySelectorAll('#globalLeaderboard .game-switch-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('#globalLeaderboard .game-switch-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            renderGlobalLeaderboard(this.dataset.game);
        });
    });

    // Initialize family leaderboard game switcher
    document.querySelectorAll('#familyGameSwitcher .game-switch-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('#familyGameSwitcher .game-switch-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            renderFamilyLeaderboard(this.dataset.game);
        });
    });

    // Initial render
    renderGlobalLeaderboard('all');
    renderFamilyLeaderboard('all');
})();
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
