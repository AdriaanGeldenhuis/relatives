<?php
declare(strict_types=1);

/**
 * PAC-MAN Game
 * Family Games Hub
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: /login.php', true, 302);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();
    if (!$user) {
        header('Location: /login.php?session_expired=1', true, 302);
        exit;
    }
} catch (Exception $e) {
    error_log('Pacman page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

$pageTitle = 'Pac-Man';
$activePage = 'games';
$cacheVersion = '1.0.0';
$pageCSS = [];

require_once __DIR__ . '/../../shared/components/header.php';
?>

<style>
    .bg-animation {
        position: fixed;
        inset: 0;
        z-index: -1;
        pointer-events: none;
        background: linear-gradient(180deg, #0f0c29 0%, #1a1a2e 50%, #16213e 100%);
    }
</style>

<link rel="stylesheet" href="/games/pacman/assets/css/pacman.css?v=<?php echo $cacheVersion; ?>">

<div class="bg-animation"></div>

<main class="main-content">
    <div class="pacman-container">
        <a href="/games/" class="back-link">&#8592; Back to Games</a>

        <!-- Score Header -->
        <div class="game-header">
            <div class="game-stat">
                <div class="game-stat-label">Score</div>
                <div class="game-stat-value" id="score-value">0</div>
            </div>
            <div class="game-stat">
                <div class="game-stat-label">Level</div>
                <div class="game-stat-value" id="level-value">1</div>
            </div>
            <div class="game-stat">
                <div class="game-stat-label">Lives</div>
                <div class="game-stat-value" id="lives-value">3</div>
            </div>
        </div>

        <!-- Game Canvas -->
        <div id="game-container">
            <canvas id="pacman-canvas" width="532" height="588"></canvas>
        </div>

        <!-- Controls -->
        <div class="game-controls">
            <button id="btn-start" class="game-btn primary">Start</button>
            <button id="btn-pause" class="game-btn secondary" style="display:none;">Pause</button>
        </div>

        <!-- D-Pad for mobile -->
        <div class="dpad-container">
            <div class="dpad">
                <button id="dpad-up" class="dpad-btn">&#9650;</button>
                <button id="dpad-left" class="dpad-btn">&#9664;</button>
                <button class="dpad-btn empty"></button>
                <button id="dpad-right" class="dpad-btn">&#9654;</button>
                <button id="dpad-down" class="dpad-btn">&#9660;</button>
            </div>
        </div>

        <!-- Info -->
        <div class="game-info">
            <h3>How to Play</h3>
            <p>Eat all the dots to advance. Avoid ghosts! Power pellets let you eat ghosts.</p>
            <div class="keys">
                <span class="key">Arrow Keys / WASD</span>
                <span class="key">SPACE - Start/Pause</span>
                <span class="key">Swipe on mobile</span>
            </div>
        </div>
    </div>
</main>

<script src="/games/pacman/assets/js/pacman.js?v=<?php echo $cacheVersion; ?>"></script>

<?php require_once __DIR__ . '/../../shared/components/footer.php'; ?>
