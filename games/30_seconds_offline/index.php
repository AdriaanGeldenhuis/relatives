<?php
/**
 * 30 Seconds Party - Offline Mode
 * Entry point / Home page
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

// Validate session
try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();

    if (!$user) {
        header('Location: /login.php?session_expired=1', true, 302);
        exit;
    }
} catch (Exception $e) {
    error_log('30 Seconds page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

$displayName = htmlspecialchars($user['full_name'] ?? 'Player');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0f0c29">

    <title>30 Seconds Party - Offline Mode</title>

    <link rel="manifest" href="/games/30_seconds_offline/manifest.json">
    <link rel="stylesheet" href="/games/30_seconds_offline/assets/css/offline.css">

    <style>
        .hero {
            text-align: center;
            padding: var(--spacing-2xl) 0;
        }

        .hero-icon {
            font-size: 5rem;
            margin-bottom: var(--spacing-lg);
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .hero-badge {
            display: inline-block;
            padding: var(--spacing-xs) var(--spacing-md);
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid var(--success);
            border-radius: var(--radius-full);
            color: var(--success);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: var(--spacing-md);
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: var(--spacing-md);
            margin: var(--spacing-xl) 0;
        }

        .feature-card {
            text-align: center;
            padding: var(--spacing-lg);
        }

        .feature-icon {
            font-size: 2rem;
            margin-bottom: var(--spacing-sm);
        }

        .feature-title {
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: var(--spacing-xs);
        }

        .feature-text {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .actions {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-md);
            margin-top: var(--spacing-xl);
        }

        .back-link {
            display: block;
            text-align: center;
            color: var(--text-muted);
            text-decoration: none;
            margin-top: var(--spacing-lg);
            font-size: 0.875rem;
        }

        .back-link:hover {
            color: var(--text-primary);
        }

        /* Mobile responsive */
        @media (max-width: 400px) {
            .hero-icon {
                font-size: 4rem;
            }

            .feature-grid {
                grid-template-columns: 1fr;
                gap: var(--spacing-sm);
            }

            .feature-card {
                padding: var(--spacing-md);
            }

            .feature-icon {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Theme Toggle -->
        <div class="theme-toggle">
            <button class="theme-toggle-btn" aria-label="Toggle theme">🌙</button>
        </div>

        <!-- Main Screen -->
        <div id="main-screen" class="screen active">
            <div class="hero">
                <div class="hero-badge">Works Offline</div>
                <div class="hero-icon">⏱️</div>
                <h1 class="page-title">30 Seconds Party</h1>
                <p class="page-subtitle">Teams of 2 • Explain words • Beat the clock!</p>
            </div>

            <div class="feature-grid">
                <div class="feature-card card">
                    <div class="feature-icon">👥</div>
                    <div class="feature-title">Teams of 2</div>
                    <div class="feature-text">One explains, one guesses</div>
                </div>
                <div class="feature-card card">
                    <div class="feature-icon">⏰</div>
                    <div class="feature-title">30 Seconds</div>
                    <div class="feature-text">Race against time</div>
                </div>
                <div class="feature-card card">
                    <div class="feature-icon">🎤</div>
                    <div class="feature-title">Voice Detection</div>
                    <div class="feature-text">Auto-catches forbidden words</div>
                </div>
                <div class="feature-card card">
                    <div class="feature-icon">📴</div>
                    <div class="feature-title">100% Offline</div>
                    <div class="feature-text">No internet needed</div>
                </div>
            </div>

            <div class="rules-section">
                <div class="rules-title">📜 Quick Rules</div>
                <ul class="rules-list">
                    <li>The explainer describes 5 words in 30 seconds</li>
                    <li>You cannot say the word itself or parts of it!</li>
                    <li>Say "Number 2" (or tap) to switch items</li>
                    <li>First team to reach target score wins!</li>
                </ul>
            </div>

            <div class="actions">
                <button id="start-match-btn" class="btn btn-primary btn-lg btn-block">
                    Start New Match
                </button>
                <button id="continue-match-btn" class="btn btn-secondary btn-lg btn-block hidden">
                    Continue Match
                </button>
                <button id="history-btn" class="btn btn-secondary btn-block">
                    Match History
                </button>
            </div>

            <a href="/games/" class="back-link">← Back to Games</a>
        </div>

        <!-- History Screen -->
        <div id="history-screen" class="screen">
            <h2 class="page-title">Match History</h2>
            <p class="page-subtitle">Your last 20 matches</p>

            <div id="history-container" class="history-list">
                <!-- Populated by JS -->
            </div>

            <button class="btn btn-secondary btn-block mt-lg" onclick="GameUI.showScreen('main-screen')">
                ← Back
            </button>
        </div>
    </div>

    <!-- Scripts -->
    <script src="/games/30_seconds_offline/assets/js/state.js"></script>
    <script src="/games/30_seconds_offline/assets/js/ui.js"></script>
    <script src="/games/30_seconds_offline/assets/js/app.js"></script>

    <script>
        // Register service worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/games/30_seconds_offline/sw.js')
                .then(reg => console.log('SW registered:', reg.scope))
                .catch(err => console.warn('SW registration failed:', err));
        }

        // Initialize app
        document.addEventListener('DOMContentLoaded', () => {
            ThirtySecondsApp.init('index');
        });
    </script>
</body>
</html>
