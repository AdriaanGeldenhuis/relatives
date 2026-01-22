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

} catch (Exception $e) {
    error_log('Game stats error: ' . $e->getMessage());
}

// Get family leaderboard (top 5)
$familyLeaderboard = [];
try {
    $stmt = $db->prepare("
        SELECT u.full_name, u.avatar_color, MAX(s.score) as best_score
        FROM snake_scores s
        JOIN users u ON s.user_id = u.id
        WHERE s.family_id = ? AND s.flagged = 0
        GROUP BY s.user_id, u.full_name, u.avatar_color
        ORDER BY best_score DESC
        LIMIT 5
    ");
    $stmt->execute([$user['family_id']]);
    $familyLeaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Family leaderboard error: ' . $e->getMessage());
}

// Available games
$games = [
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
        'id' => 'memory',
        'name' => 'Memory Match',
        'icon' => '🧠',
        'description' => 'Test your memory with this classic card matching game.',
        'url' => '/games/memory/',
        'color' => '#9b59b6',
        'features' => ['Multiple Levels', 'Time Challenge', 'Family Competition'],
        'available' => false
    ],
    [
        'id' => 'trivia',
        'name' => 'Family Trivia',
        'icon' => '❓',
        'description' => 'Quiz night! Test your knowledge across various categories.',
        'url' => '/games/trivia/',
        'color' => '#e74c3c',
        'features' => ['Multiplayer', 'Custom Questions', 'Weekly Tournaments'],
        'available' => false
    ],
    [
        'id' => 'puzzle',
        'name' => 'Sliding Puzzle',
        'icon' => '🧩',
        'description' => 'Classic sliding tile puzzle with family photos.',
        'url' => '/games/puzzle/',
        'color' => '#3498db',
        'features' => ['Custom Images', 'Difficulty Levels', 'Speed Records'],
        'available' => false
    ]
];

$pageTitle = 'Games';
$activePage = 'games';
$cacheVersion = '1.0.0';
$pageCSS = [];

require_once __DIR__ . '/../shared/components/header.php';
?>

<style>
    .games-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }

    .games-hero {
        text-align: center;
        padding: 40px 20px;
        margin-bottom: 30px;
    }

    .games-hero-icon {
        font-size: 64px;
        margin-bottom: 16px;
        animation: bounce 2s ease-in-out infinite;
    }

    @keyframes bounce {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }

    .games-hero h1 {
        font-size: 2rem;
        font-weight: 800;
        margin-bottom: 8px;
        background: linear-gradient(135deg, #fff, rgba(255,255,255,0.8));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .games-hero p {
        color: rgba(255, 255, 255, 0.8);
        font-size: 1rem;
    }

    .user-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        margin-bottom: 30px;
    }

    @media (max-width: 600px) {
        .user-stats {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    .stat-box {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 16px;
        padding: 16px;
        text-align: center;
        transition: transform 0.3s, box-shadow 0.3s;
    }

    .stat-box:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
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
        color: rgba(255, 255, 255, 0.7);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .section-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
    }

    .section-header h2 {
        font-size: 1.25rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .games-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 40px;
    }

    .game-card {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 20px;
        overflow: hidden;
        transition: transform 0.3s, box-shadow 0.3s;
        text-decoration: none;
        color: inherit;
        display: block;
    }

    .game-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 16px 48px rgba(0, 0, 0, 0.25);
    }

    .game-card.coming-soon {
        opacity: 0.7;
        cursor: default;
    }

    .game-card.coming-soon:hover {
        transform: none;
        box-shadow: none;
    }

    .game-card-header {
        padding: 24px;
        text-align: center;
        position: relative;
    }

    .game-icon {
        font-size: 56px;
        margin-bottom: 12px;
        display: block;
    }

    .game-name {
        font-size: 1.25rem;
        font-weight: 700;
        color: #fff;
        margin-bottom: 4px;
    }

    .game-description {
        font-size: 0.875rem;
        color: rgba(255, 255, 255, 0.7);
        line-height: 1.5;
    }

    .coming-soon-badge {
        position: absolute;
        top: 12px;
        right: 12px;
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
        font-size: 0.7rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 20px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .game-card-features {
        padding: 16px 24px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .feature-tag {
        background: rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.9);
        font-size: 0.7rem;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 12px;
    }

    .game-card-action {
        padding: 16px 24px;
        background: rgba(255, 255, 255, 0.05);
        text-align: center;
    }

    .play-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 32px;
        border-radius: 12px;
        font-weight: 700;
        font-size: 1rem;
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
        box-shadow: 0 8px 24px rgba(78, 204, 163, 0.4);
    }

    .play-btn.disabled {
        background: rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.5);
        cursor: not-allowed;
    }

    .leaderboard-section {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.2);
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
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
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

    .cta-section {
        text-align: center;
        padding: 40px 20px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 20px;
        margin-bottom: 30px;
    }

    .cta-section h3 {
        font-size: 1.5rem;
        margin-bottom: 8px;
    }

    .cta-section p {
        color: rgba(255, 255, 255, 0.7);
        margin-bottom: 20px;
    }

    .cta-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 14px 32px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: #fff;
        font-weight: 700;
        font-size: 1rem;
        border-radius: 12px;
        text-decoration: none;
        transition: all 0.3s;
    }

    .cta-btn:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 32px rgba(102, 126, 234, 0.4);
    }
</style>

<main class="main-content">
    <div class="games-container">

        <!-- Hero Section -->
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
                    <?php if ($game['available']): ?>
                        <a href="<?php echo htmlspecialchars($game['url']); ?>" class="game-card">
                    <?php else: ?>
                        <div class="game-card coming-soon">
                    <?php endif; ?>

                        <div class="game-card-header">
                            <?php if (!$game['available']): ?>
                                <span class="coming-soon-badge">Coming Soon</span>
                            <?php endif; ?>
                            <span class="game-icon"><?php echo $game['icon']; ?></span>
                            <h3 class="game-name"><?php echo htmlspecialchars($game['name']); ?></h3>
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
                                <?php echo strtoupper(substr($entry['full_name'] ?? '?', 0, 1)); ?>
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

        <!-- CTA Section -->
        <section class="cta-section">
            <h3>🐍 Ready to Play?</h3>
            <p>Challenge your family members and climb the leaderboard!</p>
            <a href="/games/snake/" class="cta-btn">
                <span>▶</span> Play Snake Classic
            </a>
        </section>

    </div>
</main>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
