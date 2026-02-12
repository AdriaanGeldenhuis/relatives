<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_name('RELATIVES_SESSION');
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
    header('Location: /login.php?error=1', true, 302);
    exit;
}

$pageTitle = 'Memory Match';
$cacheVersion = '1.0.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="description" content="Classic tile-matching puzzle game with beautiful 3D visuals">
    <title><?php echo $pageTitle; ?> - Relatives</title>
    <link rel="manifest" href="manifest.json?v=2">
    <meta name="theme-color" content="#1a1a2e">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/game.css?v=<?php echo $cacheVersion; ?>">
</head>
<body>
    <!-- Menu Screen -->
    <div id="screen-menu" class="screen active">
        <div class="menu-container">
            <div class="menu-header">
                <h1 class="game-title">Memory</h1>
                <p class="game-subtitle">Match</p>
            </div>

            <div class="menu-cards">
                <div class="mode-card" data-layout="simple">
                    <div class="card-icon">
                        <svg viewBox="0 0 48 48" fill="none">
                            <rect x="8" y="8" width="32" height="32" rx="4" stroke="currentColor" stroke-width="2"/>
                            <rect x="14" y="14" width="20" height="20" rx="2" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </div>
                    <div class="card-info">
                        <h3>Easy</h3>
                        <p>36 tiles</p>
                    </div>
                </div>

                <div class="mode-card" data-layout="medium">
                    <div class="card-icon">
                        <svg viewBox="0 0 48 48" fill="none">
                            <rect x="6" y="12" width="36" height="28" rx="4" stroke="currentColor" stroke-width="2"/>
                            <rect x="12" y="8" width="24" height="20" rx="2" stroke="currentColor" stroke-width="2"/>
                            <rect x="18" y="4" width="12" height="12" rx="2" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </div>
                    <div class="card-info">
                        <h3>Medium</h3>
                        <p>72 tiles</p>
                    </div>
                </div>

                <div class="mode-card" data-layout="turtle">
                    <div class="card-icon">
                        <svg viewBox="0 0 48 48" fill="none">
                            <ellipse cx="24" cy="28" rx="18" ry="12" stroke="currentColor" stroke-width="2"/>
                            <ellipse cx="24" cy="24" rx="14" ry="8" stroke="currentColor" stroke-width="2"/>
                            <ellipse cx="24" cy="20" rx="10" ry="5" stroke="currentColor" stroke-width="2"/>
                            <ellipse cx="24" cy="17" rx="5" ry="3" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </div>
                    <div class="card-info">
                        <h3>Classic</h3>
                        <p>144 tiles</p>
                    </div>
                </div>
            </div>

            <div class="menu-footer">
                <button id="btn-settings" class="btn-icon" aria-label="Settings">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/>
                    </svg>
                </button>
                <button id="btn-back-hub" class="btn-secondary">Back to Games</button>
            </div>
        </div>
        <canvas id="menu-bg-canvas"></canvas>
    </div>

    <!-- Game Screen -->
    <div id="screen-game" class="screen">
        <div class="game-hud">
            <div class="hud-left">
                <button id="btn-pause" class="btn-icon" aria-label="Pause">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <rect x="6" y="4" width="4" height="16" rx="1"/>
                        <rect x="14" y="4" width="4" height="16" rx="1"/>
                    </svg>
                </button>
            </div>
            <div class="hud-center">
                <div class="hud-stat">
                    <span class="stat-label">Tiles</span>
                    <span id="hud-tiles" class="stat-value">144</span>
                </div>
                <div class="hud-stat">
                    <span class="stat-label">Moves</span>
                    <span id="hud-moves" class="stat-value">0</span>
                </div>
                <div class="hud-stat" id="timer-box">
                    <span class="stat-label">Time</span>
                    <span id="hud-timer" class="stat-value">0:00</span>
                </div>
            </div>
            <div class="hud-right">
                <button id="btn-hint" class="btn-icon" aria-label="Hint">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/>
                        <circle cx="12" cy="17" r="0.5" fill="currentColor"/>
                    </svg>
                </button>
                <button id="btn-shuffle" class="btn-icon" aria-label="Shuffle">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="game-board-container">
            <canvas id="game-canvas"></canvas>
        </div>
    </div>

    <!-- Pause Overlay -->
    <div id="overlay-pause" class="overlay">
        <div class="overlay-panel">
            <h2>Paused</h2>
            <div class="pause-stats">
                <div class="stat-row">
                    <span>Tiles Left</span>
                    <span id="pause-tiles">0</span>
                </div>
                <div class="stat-row">
                    <span>Moves</span>
                    <span id="pause-moves">0</span>
                </div>
                <div class="stat-row">
                    <span>Time</span>
                    <span id="pause-time">0:00</span>
                </div>
            </div>
            <div class="overlay-buttons">
                <button id="btn-resume" class="btn-primary">Resume</button>
                <button id="btn-restart" class="btn-secondary">Restart</button>
                <button id="btn-quit" class="btn-secondary">Quit</button>
            </div>
        </div>
    </div>

    <!-- Results Overlay -->
    <div id="overlay-results" class="overlay">
        <div class="overlay-panel results-panel">
            <h2 id="results-title">You Win!</h2>
            <div class="results-stats">
                <div class="stat-big">
                    <span class="stat-label">Time</span>
                    <span id="results-time" class="stat-value">0:00</span>
                </div>
                <div class="stat-big">
                    <span class="stat-label">Moves</span>
                    <span id="results-moves" class="stat-value">0</span>
                </div>
            </div>
            <div id="results-message" class="results-message"></div>
            <div class="overlay-buttons">
                <button id="btn-next-level" class="btn-primary">Next Level</button>
                <button id="btn-play-again" class="btn-secondary">Play Again</button>
                <button id="btn-menu" class="btn-secondary">Menu</button>
            </div>
        </div>
    </div>

    <!-- No Moves Overlay -->
    <div id="overlay-nomoves" class="overlay">
        <div class="overlay-panel">
            <h2>No Moves Left</h2>
            <p class="overlay-text">Would you like to shuffle the remaining tiles?</p>
            <div class="overlay-buttons">
                <button id="btn-shuffle-confirm" class="btn-primary">Shuffle</button>
                <button id="btn-give-up" class="btn-secondary">Give Up</button>
            </div>
        </div>
    </div>

    <!-- Settings Overlay -->
    <div id="overlay-settings" class="overlay">
        <div class="overlay-panel">
            <h2>Settings</h2>
            <div class="settings-list">
                <div class="setting-row">
                    <label for="setting-theme">Theme</label>
                    <select id="setting-theme">
                        <option value="jade">Jade</option>
                        <option value="bamboo">Bamboo</option>
                        <option value="night">Night</option>
                    </select>
                </div>
                <div class="setting-row">
                    <label for="setting-sound">Sound</label>
                    <input type="checkbox" id="setting-sound" checked>
                </div>
                <div class="setting-row">
                    <label for="setting-timer">Show Timer</label>
                    <input type="checkbox" id="setting-timer" checked>
                </div>
                <div class="setting-row">
                    <label for="setting-highlight">Highlight Free</label>
                    <input type="checkbox" id="setting-highlight">
                </div>
            </div>
            <div class="overlay-buttons">
                <button id="btn-settings-close" class="btn-primary">Done</button>
            </div>
        </div>
    </div>

    <script src="assets/js/layouts.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="assets/js/audio.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="assets/js/renderer.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="assets/js/game.js?v=<?php echo $cacheVersion; ?>"></script>
</body>
</html>
