<?php
/**
 * 30 Seconds Party - Scoreboard Page
 * Shows current standings and next turn info
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
$cacheVersion = '1.0.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#0f0c29">

    <title>Scoreboard - 30 Seconds Party</title>

    <link rel="manifest" href="/games/30_seconds_offline/manifest.json">
    <link rel="stylesheet" href="/games/30_seconds_offline/assets/css/offline.css?v=<?php echo $cacheVersion; ?>">

    <style>
        .scoreboard-header {
            text-align: center;
            margin-bottom: var(--spacing-xl);
        }

        .next-turn-card {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.15), rgba(118, 75, 162, 0.15));
            border: 1px solid var(--accent-primary);
            border-radius: var(--radius-lg);
            padding: var(--spacing-xl);
            text-align: center;
            margin-bottom: var(--spacing-xl);
        }

        .next-turn-label {
            font-size: 0.875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: var(--spacing-sm);
        }

        .next-turn-team {
            font-size: 1.5rem;
            font-weight: 800;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: var(--spacing-md);
        }

        .next-turn-players {
            display: flex;
            justify-content: center;
            gap: var(--spacing-xl);
        }

        .next-turn-player {
            text-align: center;
        }

        .next-turn-player-role {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .next-turn-player-name {
            font-weight: 600;
            margin-top: var(--spacing-xs);
        }

        .qr-btn {
            display: inline-flex;
            align-items: center;
            gap: var(--spacing-sm);
            font-size: 0.875rem;
            color: var(--text-muted);
            background: none;
            border: 1px solid var(--glass-border);
            padding: var(--spacing-sm) var(--spacing-md);
            border-radius: var(--radius-full);
            cursor: pointer;
            margin-top: var(--spacing-lg);
        }

        .qr-btn:hover {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
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
            <div class="scoreboard-header">
                <h1 class="page-title">Scoreboard</h1>
                <p class="page-subtitle">Current standings</p>
            </div>

            <!-- Scoreboard -->
            <div id="scoreboard" class="scoreboard">
                <!-- Populated by JS -->
            </div>

            <!-- Next Turn Info -->
            <div class="next-turn-card">
                <div class="next-turn-label">Up Next</div>
                <div class="next-turn-team" id="next-team-name">Team Name</div>

                <div class="next-turn-players">
                    <div class="next-turn-player">
                        <div class="next-turn-player-role">🎤 Explainer</div>
                        <div class="next-turn-player-name" id="next-explainer">Player A</div>
                    </div>
                    <div class="next-turn-player">
                        <div class="next-turn-player-role">🤔 Guesser</div>
                        <div class="next-turn-player-name" id="next-guesser">Player B</div>
                    </div>
                </div>

                <button id="show-qr-btn" class="qr-btn">
                    📱 Show Spectator QR
                </button>
            </div>

            <!-- Last Turn Summary -->
            <div id="last-turn-summary">
                <!-- Populated by JS -->
            </div>

            <!-- Actions -->
            <div class="mt-xl">
                <button id="next-turn-btn" class="btn btn-primary btn-lg btn-block">
                    Start Next Turn
                </button>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="/games/30_seconds_offline/assets/js/state.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/30_seconds_offline/assets/js/ui.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="/games/30_seconds_offline/assets/js/app.js?v=<?php echo $cacheVersion; ?>"></script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            ThirtySecondsApp.init('scoreboard');
        });
    </script>
</body>
</html>
