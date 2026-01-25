<?php
declare(strict_types=1);

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
    header('Location: /login.php?error=1', true, 302);
    exit;
}

$userName = htmlspecialchars($user['full_name'] ?? 'Player');
$cacheVersion = '1.0.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#0a0a1a">
    <title>BlockForge</title>
    <link rel="manifest" href="/games/blockforge/manifest.json">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/games/blockforge/assets/css/game.css?v=<?php echo $cacheVersion; ?>">
</head>
<body>
    <!-- Main Menu Screen -->
    <div id="screen-menu" class="screen active">
        <div class="menu-bg">
            <canvas id="menu-bg-canvas"></canvas>
        </div>
        <div class="menu-content">
            <div class="menu-logo">
                <div class="logo-icon">
                    <div class="logo-block b1"></div>
                    <div class="logo-block b2"></div>
                    <div class="logo-block b3"></div>
                    <div class="logo-block b4"></div>
                </div>
                <h1 class="logo-text">BlockForge</h1>
                <p class="logo-tagline">Premium Block Puzzle</p>
            </div>

            <div class="menu-modes">
                <button class="mode-card" data-mode="solo">
                    <div class="mode-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="7" height="7" rx="1"/>
                            <rect x="14" y="3" width="7" height="7" rx="1"/>
                            <rect x="3" y="14" width="7" height="7" rx="1"/>
                            <rect x="14" y="14" width="7" height="7" rx="1"/>
                        </svg>
                    </div>
                    <div class="mode-info">
                        <h3>Solo Endless</h3>
                        <p>Classic block puzzle. Beat your best!</p>
                    </div>
                    <div class="mode-arrow">&rsaquo;</div>
                </button>

                <button class="mode-card" data-mode="daily">
                    <div class="mode-icon daily-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                            <path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>
                        </svg>
                    </div>
                    <div class="mode-info">
                        <h3>Daily Challenge</h3>
                        <p>Same seed for everyone. Compete globally!</p>
                    </div>
                    <span class="mode-badge">DAILY</span>
                    <div class="mode-arrow">&rsaquo;</div>
                </button>

                <button class="mode-card" data-mode="family">
                    <div class="mode-icon family-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                    </div>
                    <div class="mode-info">
                        <h3>Family Board</h3>
                        <p>Take turns. Build together. Clear lines as a team!</p>
                    </div>
                    <div class="mode-arrow">&rsaquo;</div>
                </button>
            </div>

            <div class="menu-bottom">
                <button class="menu-btn" id="btn-leaderboard">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/>
                        <path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/>
                        <path d="M4 22h16"/>
                        <path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/>
                        <path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/>
                        <path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>
                    </svg>
                    <span>Leaderboard</span>
                </button>
                <button class="menu-btn" id="btn-settings">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
                    </svg>
                    <span>Settings</span>
                </button>
                <button class="menu-btn" id="btn-back-hub">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M19 12H5M12 19l-7-7 7-7"/>
                    </svg>
                    <span>Back</span>
                </button>
            </div>

            <div class="menu-status">
                <span id="online-badge" class="status-badge online">Online</span>
                <span id="sync-badge" class="status-badge synced">Synced</span>
            </div>
        </div>
    </div>

    <!-- Game Screen -->
    <div id="screen-game" class="screen">
        <div class="game-wrapper">
            <!-- HUD Top -->
            <div class="hud-top">
                <div class="hud-item">
                    <span class="hud-label">SCORE</span>
                    <span class="hud-value" id="hud-score">0</span>
                </div>
                <div class="hud-item">
                    <span class="hud-label">LEVEL</span>
                    <span class="hud-value" id="hud-level">1</span>
                </div>
                <div class="hud-item">
                    <span class="hud-label">LINES</span>
                    <span class="hud-value" id="hud-lines">0</span>
                </div>
                <div class="hud-item combo-item">
                    <span class="hud-label">COMBO</span>
                    <span class="hud-value" id="hud-combo">0</span>
                </div>
            </div>

            <!-- Game Area -->
            <div class="game-area">
                <!-- Hold Piece -->
                <div class="side-panel left-panel">
                    <div class="panel-box hold-box">
                        <span class="panel-label">HOLD</span>
                        <canvas id="hold-canvas" width="100" height="100"></canvas>
                    </div>
                    <div class="panel-box timer-box" id="timer-box" style="display:none;">
                        <span class="panel-label">TIME</span>
                        <span class="timer-value" id="hud-timer">2:00</span>
                    </div>
                </div>

                <!-- Main Board -->
                <div class="board-container">
                    <canvas id="game-canvas"></canvas>
                    <div class="combo-popup" id="combo-popup"></div>
                </div>

                <!-- Next Pieces -->
                <div class="side-panel right-panel">
                    <div class="panel-box next-box">
                        <span class="panel-label">NEXT</span>
                        <canvas id="next-canvas-1" width="100" height="100"></canvas>
                        <canvas id="next-canvas-2" width="100" height="80"></canvas>
                        <canvas id="next-canvas-3" width="100" height="80"></canvas>
                    </div>
                </div>
            </div>

            <!-- Controls -->
            <div class="controls-area" id="touch-controls">
                <div class="ctrl-row">
                    <button class="ctrl-btn" id="ctrl-left" aria-label="Move Left">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                    </button>
                    <button class="ctrl-btn ctrl-down" id="ctrl-down" aria-label="Soft Drop">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
                    </button>
                    <button class="ctrl-btn" id="ctrl-right" aria-label="Move Right">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
                    </button>
                </div>
                <div class="ctrl-row ctrl-row-actions">
                    <button class="ctrl-btn ctrl-rotate" id="ctrl-rotate" aria-label="Rotate">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.5 8c-2.65 0-5.05.99-6.9 2.6L2 7v9h9l-3.62-3.62c1.39-1.16 3.16-1.88 5.12-1.88 3.54 0 6.55 2.31 7.6 5.5l2.37-.78C21.08 11.03 17.15 8 12.5 8z"/></svg>
                    </button>
                    <button class="ctrl-btn ctrl-hold" id="ctrl-hold" aria-label="Hold">
                        <span>H</span>
                    </button>
                    <button class="ctrl-btn ctrl-drop" id="ctrl-drop" aria-label="Hard Drop">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6z"/><path d="M7.41 14.59L12 19.17l4.59-4.58L18 16l-6 6-6-6z"/></svg>
                    </button>
                </div>
                <button class="ctrl-btn ctrl-pause" id="ctrl-pause" aria-label="Pause">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Pause Overlay -->
    <div id="overlay-pause" class="overlay">
        <div class="overlay-card">
            <h2>Paused</h2>
            <div class="overlay-stats">
                <div class="os-row"><span>Score</span><span id="pause-score">0</span></div>
                <div class="os-row"><span>Level</span><span id="pause-level">1</span></div>
                <div class="os-row"><span>Lines</span><span id="pause-lines">0</span></div>
            </div>
            <div class="overlay-buttons">
                <button class="ov-btn primary" id="btn-resume">Resume</button>
                <button class="ov-btn" id="btn-restart">Restart</button>
                <button class="ov-btn danger" id="btn-quit">Quit</button>
            </div>
        </div>
    </div>

    <!-- Game Over / Results Overlay -->
    <div id="overlay-results" class="overlay">
        <div class="overlay-card results-card">
            <h2 id="results-title">Game Over</h2>
            <div class="results-score">
                <span class="rs-label">Final Score</span>
                <span class="rs-value" id="results-score">0</span>
            </div>
            <div class="results-breakdown">
                <div class="rb-row"><span>Lines Cleared</span><span id="results-lines">0</span></div>
                <div class="rb-row"><span>Level Reached</span><span id="results-level">1</span></div>
                <div class="rb-row"><span>Max Combo</span><span id="results-combo">0</span></div>
                <div class="rb-row"><span>Duration</span><span id="results-duration">0:00</span></div>
            </div>
            <div class="results-badges" id="results-badges"></div>
            <div class="results-rank" id="results-rank" style="display:none;">
                <span class="rank-label">Rank</span>
                <span class="rank-value" id="results-rank-value">#1</span>
            </div>
            <div class="overlay-buttons">
                <button class="ov-btn primary" id="btn-share">Share Result</button>
                <button class="ov-btn" id="btn-play-again">Play Again</button>
                <button class="ov-btn danger" id="btn-menu">Menu</button>
            </div>
        </div>
    </div>

    <!-- Settings Overlay -->
    <div id="overlay-settings" class="overlay">
        <div class="overlay-card settings-card">
            <h2>Settings</h2>
            <div class="setting-row">
                <span>Theme</span>
                <select id="setting-theme">
                    <option value="neon-dark">Neon Dark</option>
                    <option value="neon-light">Neon Light</option>
                    <option value="synthwave">Synthwave</option>
                </select>
            </div>
            <div class="setting-row">
                <span>Sound</span>
                <label class="toggle">
                    <input type="checkbox" id="setting-sound" checked>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="setting-row">
                <span>Haptics</span>
                <label class="toggle">
                    <input type="checkbox" id="setting-haptics" checked>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="setting-row">
                <span>Touch Controls</span>
                <label class="toggle">
                    <input type="checkbox" id="setting-controls" checked>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="setting-row">
                <span>Ghost Piece</span>
                <label class="toggle">
                    <input type="checkbox" id="setting-ghost" checked>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="overlay-buttons">
                <button class="ov-btn primary" id="btn-settings-close">Done</button>
            </div>
        </div>
    </div>

    <!-- Leaderboard Overlay -->
    <div id="overlay-leaderboard" class="overlay">
        <div class="overlay-card leaderboard-card">
            <h2>Leaderboard</h2>
            <div class="lb-tabs">
                <button class="lb-tab active" data-tab="solo">Solo</button>
                <button class="lb-tab" data-tab="daily">Daily</button>
                <button class="lb-tab" data-tab="family">Family</button>
            </div>
            <div class="lb-range">
                <button class="lb-range-btn active" data-range="today">Today</button>
                <button class="lb-range-btn" data-range="week">Week</button>
                <button class="lb-range-btn" data-range="all">All Time</button>
            </div>
            <div class="lb-list" id="lb-list">
                <div class="lb-empty">Loading...</div>
            </div>
            <div class="overlay-buttons">
                <button class="ov-btn primary" id="btn-lb-close">Close</button>
            </div>
        </div>
    </div>

    <!-- Family Board Screen -->
    <div id="screen-family" class="screen">
        <div class="family-header">
            <button class="back-btn" id="btn-family-back">&larr;</button>
            <h2>Family Board</h2>
            <span class="family-date" id="family-date">Today</span>
        </div>
        <div class="family-board-area">
            <canvas id="family-canvas"></canvas>
        </div>
        <div class="family-info">
            <div class="fi-row"><span>Family Lines</span><span id="family-lines">0</span></div>
            <div class="fi-row"><span>Your Turn</span><span id="family-turn-status">Available</span></div>
            <div class="fi-row"><span>Members Played</span><span id="family-members">0/0</span></div>
        </div>
        <div class="family-actions">
            <button class="ov-btn primary" id="btn-family-play">Take Your Turn</button>
        </div>
    </div>

    <!-- Share Card Hidden Canvas -->
    <canvas id="share-canvas" width="600" height="800" style="display:none;"></canvas>

    <!-- Scripts -->
    <script src="/games/blockforge/assets/js/pieces.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/blockforge/assets/js/storage.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/blockforge/assets/js/audio.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/blockforge/assets/js/particles.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/blockforge/assets/js/renderer.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/blockforge/assets/js/engine.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/blockforge/assets/js/input.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/blockforge/assets/js/api.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/blockforge/assets/js/ui.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/blockforge/assets/js/sharecard.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/blockforge/assets/js/game.js?v=<?php echo $cacheVersion; ?>"></script>

    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/games/blockforge/sw.js').catch(function() {});
        }
    </script>
</body>
</html>
