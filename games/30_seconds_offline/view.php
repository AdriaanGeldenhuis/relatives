<?php
/**
 * 30 Seconds Party - Spectator View Page
 * Read-only view of game state from QR code/state string
 */

session_start();
// No auth required for spectator view
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#0f0c29">

    <title>Spectator View - 30 Seconds Party</title>

    <link rel="manifest" href="/games/30_seconds_offline/manifest.json">
    <link rel="stylesheet" href="/games/30_seconds_offline/assets/css/offline.css">

    <style>
        .viewer-header {
            text-align: center;
            margin-bottom: var(--spacing-lg);
        }

        .viewer-icon {
            font-size: 3rem;
            margin-bottom: var(--spacing-md);
        }

        .manual-entry {
            margin-top: var(--spacing-xl);
        }

        .manual-entry textarea {
            width: 100%;
            min-height: 100px;
            padding: var(--spacing-md);
            font-family: monospace;
            font-size: 0.875rem;
            color: var(--text-primary);
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-md);
            resize: vertical;
        }

        .refresh-hint {
            text-align: center;
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-top: var(--spacing-xl);
            padding: var(--spacing-md);
            background: var(--glass-bg);
            border-radius: var(--radius-md);
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
            <div class="viewer-header">
                <div class="viewer-icon">👁️</div>
                <h1 class="page-title">Spectator View</h1>
                <p class="page-subtitle">Read-only game snapshot</p>
            </div>

            <!-- Viewer Content -->
            <div id="viewer-content">
                <div class="loading-container">
                    <div class="loading-spinner"></div>
                    <div class="loading-text">Loading game state...</div>
                </div>
            </div>

            <!-- Manual Entry (if no state in URL) -->
            <div id="manual-entry" class="manual-entry hidden">
                <div class="form-group">
                    <label class="form-label">Paste State Code</label>
                    <textarea id="state-input" placeholder="Paste the state code from host phone..."></textarea>
                </div>
                <button id="load-state-btn" class="btn btn-primary btn-block mt-md">
                    Load State
                </button>
            </div>

            <!-- Refresh Hint -->
            <div class="refresh-hint">
                <strong>📴 Snapshot Mode</strong><br>
                This is a static snapshot. Scan a new QR code to get the latest state.
            </div>

            <!-- Back Link -->
            <a href="index.php" style="display: block; text-align: center; margin-top: var(--spacing-lg); color: var(--text-muted); text-decoration: none;">
                ← Back to 30 Seconds
            </a>
        </div>
    </div>

    <!-- Scripts -->
    <script src="/games/30_seconds_offline/assets/js/state.js"></script>
    <script src="/games/30_seconds_offline/assets/js/ui.js"></script>
    <script src="/games/30_seconds_offline/assets/js/app.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Check for state in URL
            const params = new URLSearchParams(window.location.search);
            const stateString = params.get('state');

            if (stateString) {
                ThirtySecondsApp.init('view');
            } else {
                // Show manual entry
                document.getElementById('viewer-content').innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">📲</div>
                        <div class="empty-state-title">No State Found</div>
                        <div class="empty-state-text">Scan a QR code from the host phone, or paste the state code below.</div>
                    </div>
                `;
                document.getElementById('manual-entry').classList.remove('hidden');

                document.getElementById('load-state-btn').addEventListener('click', () => {
                    const input = document.getElementById('state-input').value.trim();
                    if (input) {
                        window.location.href = `view.php?state=${encodeURIComponent(input)}`;
                    }
                });
            }

            // Initialize UI for theme
            GameUI.init();
        });
    </script>
</body>
</html>
