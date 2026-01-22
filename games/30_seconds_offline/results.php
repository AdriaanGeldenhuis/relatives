<?php
/**
 * 30 Seconds Party - Results Page
 * Final results, winner, MVP, and sharing
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentication check
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: /login.php', true, 302);
    exit;
}

// Load bootstrap
require_once __DIR__ . '/../../core/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#0f0c29">

    <title>Results - 30 Seconds Party</title>

    <link rel="manifest" href="/games/30_seconds_offline/manifest.json">
    <link rel="stylesheet" href="/games/30_seconds_offline/assets/css/offline.css">

    <style>
        .results-header {
            text-align: center;
            padding: var(--spacing-xl) 0;
        }

        .winner-section {
            text-align: center;
            margin-bottom: var(--spacing-xl);
        }

        .winner-label {
            font-size: 1rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: var(--spacing-md);
        }

        .winner-name {
            font-size: 1.75rem;
            font-weight: 800;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: var(--spacing-md);
        }

        .winner-score {
            font-size: 4rem;
            font-weight: 800;
            color: var(--success);
            line-height: 1;
        }

        .winner-score-label {
            font-size: 0.875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .section-title {
            font-size: 0.875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: var(--spacing-xl) 0 var(--spacing-md);
        }

        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-md);
            margin-top: var(--spacing-xl);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Theme Toggle -->
        <div class="theme-toggle">
            <button class="theme-toggle-btn" aria-label="Toggle theme">🌙</button>
        </div>

        <div class="screen active">
            <div class="results-header">
                <div class="results-trophy">🏆</div>
                <h1 class="page-title">Game Over!</h1>
            </div>

            <!-- Winner Section -->
            <div class="winner-section">
                <div class="winner-label">Winner</div>
                <div id="winner-name" class="winner-name">Team Name</div>
                <div id="winner-score" class="winner-score">0</div>
                <div class="winner-score-label">Points</div>
            </div>

            <!-- MVP -->
            <div id="mvp-container">
                <!-- Populated by JS -->
            </div>

            <!-- Final Scoreboard -->
            <div class="section-title">Final Standings</div>
            <div id="final-scoreboard" class="scoreboard">
                <!-- Populated by JS -->
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <button id="share-btn" class="btn btn-primary btn-lg btn-block">
                    📤 Share Results
                </button>
                <button id="new-match-btn" class="btn btn-secondary btn-block">
                    🔄 New Match
                </button>
                <button id="home-btn" class="btn btn-secondary btn-block">
                    🏠 Back to Home
                </button>
            </div>
        </div>
    </div>

    <!-- Confetti Canvas -->
    <canvas id="confetti-canvas"></canvas>

    <!-- Scripts -->
    <script src="/games/30_seconds_offline/assets/js/state.js"></script>
    <script src="/games/30_seconds_offline/assets/js/ui.js"></script>
    <script src="/games/30_seconds_offline/assets/js/sharecard.js"></script>
    <script src="/games/30_seconds_offline/assets/js/app.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            ThirtySecondsApp.init('results');
        });
    </script>
</body>
</html>
