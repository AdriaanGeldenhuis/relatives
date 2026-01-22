<?php
/**
 * 30 Seconds Party - Turn Page
 * Main gameplay with timer, word items, and speech recognition
 */

session_start();
require_once __DIR__ . '/../../includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#0f0c29">

    <title>Your Turn - 30 Seconds Party</title>

    <link rel="manifest" href="/games/30_seconds_offline/manifest.json">
    <link rel="stylesheet" href="/games/30_seconds_offline/assets/css/offline.css">

    <style>
        .turn-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--spacing-md);
        }

        .turn-info {
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        .turn-info strong {
            color: var(--text-primary);
        }

        .timer-section {
            margin: var(--spacing-lg) 0;
        }

        .control-bar {
            display: flex;
            gap: var(--spacing-sm);
            margin-top: var(--spacing-lg);
        }

        .control-bar .btn {
            flex: 1;
        }

        .gate-handoff {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-lg);
            padding: var(--spacing-lg);
            text-align: center;
            margin: var(--spacing-lg) 0;
        }

        .gate-handoff-icon {
            font-size: 3rem;
            margin-bottom: var(--spacing-md);
        }

        .gate-handoff-text {
            color: var(--text-muted);
            margin-bottom: var(--spacing-md);
        }

        .gate-explainer {
            font-size: 1.5rem;
            font-weight: 700;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Theme Toggle -->
        <div class="theme-toggle">
            <button class="theme-toggle-btn" aria-label="Toggle theme">🌙</button>
        </div>

        <!-- Gate Screen: Hand Phone to Explainer -->
        <div id="gate-screen" class="screen">
            <div class="gate-screen">
                <div class="gate-icon">📱</div>
                <h1 class="gate-title">Hand the Phone</h1>
                <p class="gate-subtitle">It's <span id="gate-team-name">Team Name</span>'s turn</p>

                <div class="gate-handoff">
                    <div class="gate-handoff-icon">🎤</div>
                    <div class="gate-handoff-text">Give the phone to:</div>
                    <div class="gate-explainer" id="gate-explainer-name">Player Name</div>
                </div>

                <button id="reveal-card-btn" class="btn btn-primary btn-lg btn-block">
                    I'm Ready - Show My Card!
                </button>

                <p style="text-align: center; margin-top: var(--spacing-lg); color: var(--text-muted); font-size: 0.875rem;">
                    Don't let the guesser see the screen!
                </p>
            </div>
        </div>

        <!-- Gameplay Screen -->
        <div id="gameplay-screen" class="screen">
            <!-- Header -->
            <div class="turn-header">
                <div class="turn-info">
                    Team: <strong id="team-name">Team Name</strong>
                </div>
                <div class="turn-info">
                    Explainer: <strong id="explainer-name">Player</strong>
                </div>
            </div>

            <!-- Speech Status -->
            <div id="speech-status" class="speech-status">
                <span class="mic-indicator"></span>
                <span>Mic Ready</span>
            </div>

            <!-- Timer -->
            <div class="timer-section">
                <div id="timer-container" class="timer-container">
                    <!-- Timer SVG rendered by JS -->
                </div>
            </div>

            <!-- Start Button (before timer starts) -->
            <button id="start-timer-btn" class="btn btn-primary btn-lg btn-block mb-lg">
                Start 30 Seconds!
            </button>

            <!-- Transcript Display -->
            <div class="transcript-container">
                <div class="transcript-header">
                    <span class="mic-indicator"></span>
                    <span>Live Transcript</span>
                </div>
                <div id="transcript-text" class="transcript-text">
                    Say "Number 1" to start...
                </div>
            </div>

            <!-- Items List -->
            <div id="items-list" class="items-list">
                <!-- Items rendered by JS -->
            </div>

            <!-- Control Bar -->
            <div class="control-bar">
                <button id="undo-btn" class="btn btn-secondary">
                    ↩ Undo
                </button>
                <button id="end-turn-btn" class="btn btn-danger">
                    End Turn
                </button>
            </div>
        </div>
    </div>

    <!-- Confetti Canvas -->
    <canvas id="confetti-canvas"></canvas>

    <!-- Scripts -->
    <script src="/games/30_seconds_offline/assets/js/state.js"></script>
    <script src="/games/30_seconds_offline/assets/js/timer.js"></script>
    <script src="/games/30_seconds_offline/assets/js/matcher.js"></script>
    <script src="/games/30_seconds_offline/assets/js/speech.js"></script>
    <script src="/games/30_seconds_offline/assets/js/ui.js"></script>
    <script src="/games/30_seconds_offline/assets/js/app.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            ThirtySecondsApp.init('turn');
        });
    </script>
</body>
</html>
