<?php
/**
 * Snake Classic Game - Main Entry Point
 *
 * Nokia 3310 style snake game with offline-first architecture.
 * Supports Solo, Family, and Global leaderboards.
 * Multiple visual themes available.
 */

session_start();

// Check authentication - redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$userId = $_SESSION['user_id'];
$displayName = $_SESSION['display_name'] ?? 'Player';
$familyId = $_SESSION['family_id'] ?? null;
?>
<!DOCTYPE html>
<html lang="en" data-theme="neon">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#1a1a2e">
    <title>Snake Classic</title>
    <link rel="manifest" href="/games/snake/manifest.json">
    <link rel="stylesheet" href="/games/snake/assets/css/snake.css?v=2.0">
</head>
<body>
    <div id="app">
        <!-- Header -->
        <header class="game-header">
            <div class="header-left">
                <a href="/games/" class="back-btn" aria-label="Back to games">&larr;</a>
            </div>
            <h1>Snake Classic</h1>
            <div class="header-right">
                <button id="theme-btn" class="theme-btn" aria-label="Change theme" title="Change Theme">
                    <span class="theme-icon">🎨</span>
                </button>
                <span id="sync-indicator" class="sync-indicator" title="Sync status">
                    <span class="sync-icon"></span>
                </span>
            </div>
        </header>

        <!-- Score Display -->
        <div class="score-panel">
            <div class="score-item">
                <span class="score-label">Score</span>
                <span id="current-score" class="score-value">0</span>
            </div>
            <div class="score-item">
                <span class="score-label">Best</span>
                <span id="best-score" class="score-value">0</span>
            </div>
            <div class="score-item">
                <span class="score-label">Family</span>
                <span id="family-best" class="score-value">-</span>
            </div>
            <div class="score-item">
                <span class="score-label">Global</span>
                <span id="global-best" class="score-value">-</span>
            </div>
        </div>

        <!-- Game Canvas Container -->
        <div class="canvas-container">
            <canvas id="game-canvas"></canvas>

            <!-- Overlay Screens -->
            <div id="start-screen" class="overlay-screen">
                <div class="overlay-content">
                    <h2>Snake Classic</h2>
                    <p>Swipe or use D-pad to control</p>
                    <button id="start-btn" class="game-btn primary">Start Game</button>
                </div>
            </div>

            <div id="pause-screen" class="overlay-screen hidden">
                <div class="overlay-content">
                    <h2>Paused</h2>
                    <button id="resume-btn" class="game-btn primary">Resume</button>
                    <button id="restart-btn" class="game-btn secondary">Restart</button>
                </div>
            </div>

            <div id="gameover-screen" class="overlay-screen hidden">
                <div class="overlay-content">
                    <h2>Game Over</h2>
                    <p class="final-score">Score: <span id="final-score-value">0</span></p>
                    <p id="score-status" class="score-status">Saving...</p>
                    <button id="playagain-btn" class="game-btn primary">Play Again</button>
                    <button id="leaderboard-btn" class="game-btn secondary">Leaderboards</button>
                </div>
            </div>

            <div id="leaderboard-screen" class="overlay-screen hidden">
                <div class="overlay-content leaderboard-content">
                    <h2>Leaderboards</h2>
                    <div class="leaderboard-tabs">
                        <button class="tab-btn active" data-tab="today">Today</button>
                        <button class="tab-btn" data-tab="week">This Week</button>
                    </div>
                    <div class="leaderboard-sections">
                        <div class="leaderboard-section">
                            <h3>Family</h3>
                            <ol id="family-leaderboard" class="leaderboard-list">
                                <li class="loading">Loading...</li>
                            </ol>
                        </div>
                        <div class="leaderboard-section">
                            <h3>Global</h3>
                            <ol id="global-leaderboard" class="leaderboard-list">
                                <li class="loading">Loading...</li>
                            </ol>
                        </div>
                    </div>
                    <button id="close-leaderboard-btn" class="game-btn secondary">Close</button>
                </div>
            </div>

            <!-- Theme Selector Modal -->
            <div id="theme-screen" class="overlay-screen hidden">
                <div class="overlay-content theme-content">
                    <h2>Choose Theme</h2>
                    <div class="theme-grid">
                        <button class="theme-option" data-theme="neon">
                            <div class="theme-preview neon-preview">
                                <div class="preview-snake"></div>
                                <div class="preview-food"></div>
                            </div>
                            <span class="theme-name">Neon Retro</span>
                            <span class="theme-desc">Glowing cyberpunk vibes</span>
                        </button>
                        <button class="theme-option" data-theme="realistic">
                            <div class="theme-preview realistic-preview">
                                <div class="preview-snake"></div>
                                <div class="preview-food"></div>
                            </div>
                            <span class="theme-name">Realistic</span>
                            <span class="theme-desc">Natural & lifelike</span>
                        </button>
                        <button class="theme-option" data-theme="classic">
                            <div class="theme-preview classic-preview">
                                <div class="preview-snake"></div>
                                <div class="preview-food"></div>
                            </div>
                            <span class="theme-name">Nokia Classic</span>
                            <span class="theme-desc">Old school LCD</span>
                        </button>
                    </div>
                    <button id="close-theme-btn" class="game-btn secondary">Close</button>
                </div>
            </div>
        </div>

        <!-- D-Pad Controls -->
        <div class="controls-container">
            <div class="dpad">
                <button id="btn-up" class="dpad-btn up" aria-label="Move up">
                    <span class="arrow">&#9650;</span>
                </button>
                <button id="btn-left" class="dpad-btn left" aria-label="Move left">
                    <span class="arrow">&#9664;</span>
                </button>
                <button id="btn-right" class="dpad-btn right" aria-label="Move right">
                    <span class="arrow">&#9654;</span>
                </button>
                <button id="btn-down" class="dpad-btn down" aria-label="Move down">
                    <span class="arrow">&#9660;</span>
                </button>
                <div class="dpad-center"></div>
            </div>
            <button id="pause-btn" class="control-btn pause-btn" aria-label="Pause game">
                <span class="pause-icon">&#10074;&#10074;</span>
            </button>
        </div>
    </div>

    <!-- User data for JS -->
    <script>
        window.SNAKE_CONFIG = {
            userId: <?php echo json_encode($userId); ?>,
            displayName: <?php echo json_encode($displayName); ?>,
            familyId: <?php echo json_encode($familyId); ?>,
            apiBase: '/api/games/snake'
        };
    </script>

    <!-- Scripts -->
    <script src="/games/snake/assets/js/storage.js?v=2.0"></script>
    <script src="/games/snake/assets/js/api.js?v=2.0"></script>
    <script src="/games/snake/assets/js/snake.js?v=2.0"></script>

    <!-- Register Service Worker -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/games/snake/sw.js')
                    .then(function(registration) {
                        console.log('SW registered:', registration.scope);
                    })
                    .catch(function(error) {
                        console.log('SW registration failed:', error);
                    });
            });
        }
    </script>
</body>
</html>
