<?php
/**
 * 30 Seconds Party - Lobby Page
 * Team setup and game configuration
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

    <title>Game Setup - 30 Seconds Party</title>

    <link rel="manifest" href="/games/30_seconds_offline/manifest.json">
    <link rel="stylesheet" href="/games/30_seconds_offline/assets/css/offline.css">

    <style>
        .lobby-header {
            text-align: center;
            margin-bottom: var(--spacing-xl);
        }

        .section-title {
            font-size: 0.875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: var(--spacing-md);
        }

        .end-condition-toggle {
            display: flex;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-md);
            padding: 4px;
            margin-bottom: var(--spacing-md);
        }

        .end-condition-toggle label {
            flex: 1;
            text-align: center;
            padding: var(--spacing-sm) var(--spacing-md);
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 500;
            transition: all var(--transition-fast);
        }

        .end-condition-toggle input {
            display: none;
        }

        .end-condition-toggle input:checked + label {
            background: var(--accent-gradient);
            color: white;
        }

        .add-team-btn {
            border: 2px dashed var(--glass-border);
            background: transparent;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--spacing-sm);
            padding: var(--spacing-lg);
            width: 100%;
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .add-team-btn:hover {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }

        .bottom-actions {
            position: sticky;
            bottom: var(--spacing-md);
            padding-top: var(--spacing-md);
            background: linear-gradient(to top, var(--bg-gradient-3), transparent);
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
            <div class="lobby-header">
                <h1 class="page-title">Game Setup</h1>
                <p class="page-subtitle">Add teams and configure the match</p>
            </div>

            <!-- Teams Section -->
            <div class="section-title">Teams</div>
            <div id="team-list" class="team-list">
                <!-- Teams populated by JS -->
            </div>

            <button id="add-team-btn" class="add-team-btn mt-md">
                <span>+</span> Add Team
            </button>

            <!-- Game Settings -->
            <div class="section-title mt-xl">Game Settings</div>

            <div class="settings-panel">
                <!-- End Condition Toggle -->
                <div class="setting-row">
                    <span class="setting-label">Win Condition</span>
                </div>
                <div class="end-condition-toggle">
                    <input type="radio" name="end-condition" id="end-score" value="score" checked>
                    <label for="end-score">Target Score</label>
                    <input type="radio" name="end-condition" id="end-rounds" value="rounds">
                    <label for="end-rounds">Fixed Rounds</label>
                </div>

                <!-- Target Score -->
                <div id="score-setting" class="setting-row">
                    <span class="setting-label">Target Score</span>
                    <div class="setting-value">
                        <div class="stepper">
                            <button id="score-minus" class="stepper-btn">−</button>
                            <span id="target-score-value" class="stepper-value">30</span>
                            <button id="score-plus" class="stepper-btn">+</button>
                        </div>
                    </div>
                </div>

                <!-- Max Rounds -->
                <div id="rounds-setting" class="setting-row hidden">
                    <span class="setting-label">Number of Rounds</span>
                    <div class="setting-value">
                        <div class="stepper">
                            <button id="rounds-minus" class="stepper-btn">−</button>
                            <span id="max-rounds-value" class="stepper-value">10</span>
                            <button id="rounds-plus" class="stepper-btn">+</button>
                        </div>
                    </div>
                </div>

                <!-- Strict Mode -->
                <div class="setting-row">
                    <span class="setting-label">
                        Strict Mode
                        <br>
                        <small style="color: var(--text-muted); font-weight: normal;">
                            Forbid smaller word parts (more false positives)
                        </small>
                    </span>
                    <div class="setting-value">
                        <label class="toggle">
                            <input type="checkbox" id="strict-mode-toggle">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Start Button -->
            <div class="bottom-actions">
                <button id="start-game-btn" class="btn btn-primary btn-lg btn-block">
                    Start Match
                </button>
                <a href="index.php" class="back-link" style="display: block; text-align: center; margin-top: var(--spacing-md); color: var(--text-muted); text-decoration: none;">
                    ← Cancel
                </a>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="/games/30_seconds_offline/assets/js/state.js"></script>
    <script src="/games/30_seconds_offline/assets/js/ui.js"></script>
    <script src="/games/30_seconds_offline/assets/js/app.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            ThirtySecondsApp.init('lobby');
        });
    </script>
</body>
</html>
