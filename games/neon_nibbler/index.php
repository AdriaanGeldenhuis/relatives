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
    error_log('Neon Nibbler error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

// Get user's best Neon Nibbler score
$bestScore = 0;
$bestLevel = 0;
try {
    $stmt = $db->prepare("SELECT MAX(score) as best_score, MAX(level_reached) as best_level FROM neon_scores WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $bestScore = (int)($row['best_score'] ?? 0);
        $bestLevel = (int)($row['best_level'] ?? 0);
    }
} catch (Exception $e) {
    // Table may not exist yet
}

$pageTitle = 'Neon Nibbler';
$activePage = 'games';
$cacheVersion = '1.0.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="screen-orientation" content="landscape">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#0a0a1a">
    <title>Neon Nibbler</title>
    <link rel="manifest" href="/games/neon_nibbler/manifest.json">
    <link rel="stylesheet" href="/games/neon_nibbler/assets/css/game.css?v=<?php echo $cacheVersion; ?>">
</head>
<body>
    <div id="game-wrapper">
        <!-- Start Screen -->
        <div id="screen-start" class="screen active">
            <a href="/games/" class="back-btn" id="btn-back">&#8592; Back</a>
            <div class="start-content">
                <div class="game-logo">
                    <div class="logo-orb"></div>
                    <h1>NEON<br>NIBBLER</h1>
                </div>
                <div class="start-stats">
                    <div class="start-stat">
                        <span class="start-stat-label">Best Score</span>
                        <span class="start-stat-value" id="start-best-score"><?php echo number_format($bestScore); ?></span>
                    </div>
                    <div class="start-stat">
                        <span class="start-stat-label">Best Level</span>
                        <span class="start-stat-value" id="start-best-level"><?php echo $bestLevel; ?></span>
                    </div>
                </div>
                <button id="btn-play" class="neon-btn primary">
                    <span class="btn-icon">&#9654;</span> PLAY
                </button>
                <div class="start-controls-hint">
                    Joystick to move, Boost for speed
                </div>
                <div class="start-options">
                    <button id="btn-theme-toggle" class="neon-btn small">Theme: Dark</button>
                    <button id="btn-sound-toggle" class="neon-btn small">Sound: On</button>
                </div>
            </div>
        </div>

        <!-- Game Screen -->
        <div id="screen-game" class="screen">
            <!-- HUD -->
            <div id="hud">
                <div class="hud-left">
                    <div class="hud-score">
                        <span class="hud-label">SCORE</span>
                        <span id="hud-score-value" class="hud-value">0</span>
                    </div>
                    <div class="hud-combo" id="hud-combo" style="display:none;">
                        <span id="hud-combo-value">x2</span>
                    </div>
                </div>
                <div class="hud-center">
                    <span class="hud-label">LEVEL</span>
                    <span id="hud-level-value" class="hud-value">1</span>
                </div>
                <div class="hud-right">
                    <div class="hud-lives" id="hud-lives"></div>
                    <button id="btn-pause" class="hud-btn">&#10074;&#10074;</button>
                </div>
            </div>

            <!-- Pulse Mode Timer -->
            <div id="pulse-timer" class="pulse-timer" style="display:none;">
                <div id="pulse-timer-bar" class="pulse-timer-bar"></div>
            </div>

            <!-- Canvas -->
            <canvas id="game-canvas"></canvas>

            <!-- Controls: Boost left, Joystick right -->
            <div id="game-controls" class="game-controls">
                <div class="ctrl-left">
                    <button id="btn-boost" class="boost-btn">BOOST</button>
                </div>
                <div class="ctrl-right">
                    <div id="joystick" class="joystick-zone">
                        <div class="joystick-base">
                            <div id="joystick-knob" class="joystick-knob"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pause Overlay -->
        <div id="screen-pause" class="screen overlay">
            <div class="overlay-box">
                <h2>PAUSED</h2>
                <button id="btn-resume" class="neon-btn primary">Resume</button>
                <button id="btn-quit" class="neon-btn secondary">Quit</button>
            </div>
        </div>

        <!-- Game Over / Results -->
        <div id="screen-results" class="screen overlay">
            <div class="overlay-box results-box">
                <h2 id="results-title">GAME OVER</h2>
                <div class="results-grid">
                    <div class="result-item">
                        <span class="result-label">Score</span>
                        <span id="result-score" class="result-value">0</span>
                    </div>
                    <div class="result-item">
                        <span class="result-label">Level</span>
                        <span id="result-level" class="result-value">1</span>
                    </div>
                    <div class="result-item">
                        <span class="result-label">Dots</span>
                        <span id="result-dots" class="result-value">0</span>
                    </div>
                    <div class="result-item">
                        <span class="result-label">Time</span>
                        <span id="result-time" class="result-value">0:00</span>
                    </div>
                </div>
                <div id="result-new-best" class="result-new-best" style="display:none;">NEW BEST!</div>
                <div id="result-sync-status" class="result-sync"></div>
                <div class="results-actions">
                    <button id="btn-retry" class="neon-btn primary">Play Again</button>
                    <button id="btn-home" class="neon-btn secondary">Home</button>
                </div>
            </div>
        </div>

        <!-- Level Complete -->
        <div id="screen-level-complete" class="screen overlay">
            <div class="overlay-box">
                <h2>LEVEL COMPLETE!</h2>
                <div class="level-bonus">
                    <span>Level Bonus: </span>
                    <span id="level-bonus-value">+500</span>
                </div>
                <div class="level-bonus">
                    <span>Time Bonus: </span>
                    <span id="time-bonus-value">+0</span>
                </div>
                <p id="next-level-text">Get ready for Level 2...</p>
            </div>
        </div>

        <!-- Vignette -->
        <div class="vignette"></div>

        <!-- Rotate Phone Overlay (portrait only) -->
        <div id="rotate-overlay" class="rotate-overlay">
            <div class="rotate-icon">&#x1F4F1;&#x21BB;</div>
            <p>Draai jou foon landscape</p>
        </div>
    </div>

    <!-- User data for JS -->
    <script>
        window.NEON_USER = {
            id: <?php echo (int)$user['id']; ?>,
            name: <?php echo json_encode($user['full_name'] ?? 'Player'); ?>,
            familyId: <?php echo (int)($user['family_id'] ?? 0); ?>,
            bestScore: <?php echo $bestScore; ?>,
            bestLevel: <?php echo $bestLevel; ?>
        };
    </script>
    <script src="/games/neon_nibbler/assets/js/levels.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/neon_nibbler/assets/js/particles.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/neon_nibbler/assets/js/audio.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/neon_nibbler/assets/js/input.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/neon_nibbler/assets/js/storage.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/neon_nibbler/assets/js/api.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/neon_nibbler/assets/js/ui.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/neon_nibbler/assets/js/engine.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/neon_nibbler/assets/js/game.js?v=<?php echo $cacheVersion; ?>"></script>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/games/neon_nibbler/sw.js');
        }
        // Force landscape orientation
        (function() {
            try {
                if (screen.orientation && screen.orientation.lock) {
                    screen.orientation.lock('landscape').catch(function(){});
                }
            } catch(e) {}
        })();
    </script>
</body>
</html>
