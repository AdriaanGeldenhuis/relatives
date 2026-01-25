<?php
declare(strict_types=1);

/**
 * ============================================
 * FLASH CHALLENGE - Daily 30-Second Game
 * Premium Mobile-First UI v1.0
 * ============================================
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
    error_log('Flash Challenge page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

$pageTitle = 'Flash Challenge';
$cacheVersion = '1.0.2';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0f0c29">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Relatives</title>

    <!-- Preload critical assets -->
    <link rel="preload" href="assets/css/flash.css?v=<?php echo $cacheVersion; ?>" as="style">

    <!-- Manifest & Icons -->
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚡</text></svg>">

    <!-- Styles -->
    <link rel="stylesheet" href="assets/css/flash.css?v=<?php echo $cacheVersion; ?>">
</head>
<body>
    <!-- App Container -->
    <div id="app" class="app-container">

        <!-- Top Navigation Bar -->
        <header class="top-bar">
            <a href="/games/" class="back-btn" aria-label="Back to Games">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
            </a>

            <div class="top-bar-title">
                <span class="title-icon">⚡</span>
                <span>Flash Challenge</span>
            </div>

            <div class="top-bar-actions">
                <!-- Online/Offline Status -->
                <div class="status-pill" id="onlineStatus">
                    <span class="status-dot online"></span>
                    <span class="status-text">Online</span>
                </div>

                <!-- Sync Status -->
                <div class="sync-pill" id="syncStatus" style="display: none;">
                    <svg class="sync-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12a9 9 0 11-9-9"/>
                    </svg>
                    <span class="sync-text">Syncing</span>
                </div>

                <!-- Theme Toggle -->
                <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <svg class="sun-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="5"/>
                        <path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
                    </svg>
                    <svg class="moon-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>
                    </svg>
                </button>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="main-content">

            <!-- Loading State -->
            <div id="loadingState" class="state-container">
                <div class="loading-spinner">
                    <div class="spinner-ring"></div>
                    <span class="spinner-icon">⚡</span>
                </div>
                <p class="loading-text">Loading today's challenge...</p>
            </div>

            <!-- Error State -->
            <div id="errorState" class="state-container" style="display: none;">
                <div class="error-icon">⚠️</div>
                <h2 class="error-title">Something went wrong</h2>
                <p class="error-message" id="errorMessage">Unable to load the challenge.</p>
                <button class="btn btn-primary" id="retryBtn">Try Again</button>
            </div>

            <!-- Pre-Game State (Ready to Play) -->
            <div id="preGameState" class="state-container" style="display: none;">
                <!-- Streak Banner -->
                <div class="streak-banner" id="streakBanner" style="display: none;">
                    <span class="streak-flame">🔥</span>
                    <span class="streak-count" id="streakCount">0</span>
                    <span class="streak-label">day streak!</span>
                </div>

                <!-- Hero Card -->
                <div class="hero-card">
                    <div class="challenge-meta">
                        <span class="category-chip" id="categoryChip">General</span>
                        <div class="difficulty-dots" id="difficultyDots">
                            <span class="dot filled"></span>
                            <span class="dot filled"></span>
                            <span class="dot"></span>
                            <span class="dot"></span>
                            <span class="dot"></span>
                        </div>
                    </div>

                    <h1 class="challenge-question" id="questionText">Today's Challenge</h1>

                    <p class="format-hint" id="formatHint">Get ready!</p>

                    <!-- Timer Ring (Pre-game) -->
                    <div class="timer-container">
                        <svg class="timer-ring" viewBox="0 0 120 120">
                            <circle class="timer-bg" cx="60" cy="60" r="54"/>
                            <circle class="timer-progress" cx="60" cy="60" r="54" id="timerProgress"/>
                        </svg>
                        <div class="timer-center">
                            <span class="timer-value" id="timerValue">30</span>
                            <span class="timer-label">seconds</span>
                        </div>
                    </div>

                    <!-- Start Button -->
                    <button class="btn btn-start" id="startBtn">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M8 5v14l11-7z"/>
                        </svg>
                        <span>Start Challenge</span>
                    </button>
                </div>

                <!-- Family Participation -->
                <div class="family-participation" id="familyParticipation" style="display: none;">
                    <div class="participation-icon">👨‍👩‍👧‍👦</div>
                    <div class="participation-info">
                        <span class="participation-label">Family Participation</span>
                        <div class="participation-bar">
                            <div class="participation-fill" id="participationFill" style="width: 0%"></div>
                        </div>
                        <span class="participation-percent" id="participationPercent">0% played today</span>
                    </div>
                </div>
            </div>

            <!-- Active Game State -->
            <div id="gameState" class="state-container" style="display: none;">
                <!-- Question Display -->
                <div class="game-question-card">
                    <div class="challenge-meta">
                        <span class="category-chip" id="gameCategoryChip">General</span>
                        <div class="difficulty-dots" id="gameDifficultyDots">
                            <span class="dot filled"></span>
                            <span class="dot filled"></span>
                            <span class="dot"></span>
                            <span class="dot"></span>
                            <span class="dot"></span>
                        </div>
                    </div>
                    <h1 class="game-question" id="gameQuestionText">Question goes here</h1>
                    <p class="format-hint" id="gameFormatHint">Answer format hint</p>
                </div>

                <!-- Active Timer -->
                <div class="active-timer-container">
                    <svg class="timer-ring large" viewBox="0 0 120 120">
                        <circle class="timer-bg" cx="60" cy="60" r="54"/>
                        <circle class="timer-progress active" cx="60" cy="60" r="54" id="activeTimerProgress"/>
                    </svg>
                    <div class="timer-center">
                        <span class="timer-value large" id="activeTimerValue">30</span>
                        <span class="timer-label">seconds left</span>
                    </div>
                </div>

                <!-- Voice Input Card -->
                <div class="voice-card" id="voiceCard">
                    <button class="mic-btn" id="micBtn" aria-label="Start voice input">
                        <svg class="mic-icon" width="32" height="32" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/>
                            <path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
                        </svg>
                        <div class="mic-pulse"></div>
                    </button>

                    <div class="voice-status" id="voiceStatus">
                        <span class="voice-status-text">Tap to speak or type below</span>
                    </div>

                    <div class="transcript-area" id="transcriptArea">
                        <span class="transcript-text" id="transcriptText"></span>
                        <span class="transcript-interim" id="transcriptInterim"></span>
                    </div>
                </div>

                <!-- Text Input Fallback -->
                <div class="text-input-card">
                    <div class="input-wrapper">
                        <input
                            type="text"
                            id="answerInput"
                            class="answer-input"
                            placeholder="Type your answer..."
                            maxlength="200"
                            autocomplete="off"
                            autocapitalize="off"
                            spellcheck="false"
                        >
                        <button class="submit-btn" id="submitBtn">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
                            </svg>
                        </button>
                    </div>
                    <p class="input-hint">Press Enter or tap send to submit</p>
                </div>
            </div>

            <!-- Results State -->
            <div id="resultsState" class="state-container" style="display: none;">
                <!-- Verdict Banner -->
                <div class="verdict-banner" id="verdictBanner">
                    <div class="verdict-icon" id="verdictIcon">✓</div>
                    <h1 class="verdict-text" id="verdictText">Correct!</h1>
                    <p class="verdict-reason" id="verdictReason">Exact match</p>
                </div>

                <!-- Score Breakdown -->
                <div class="score-card">
                    <div class="score-main">
                        <span class="score-value" id="scoreValue">0</span>
                        <span class="score-label">points</span>
                    </div>
                    <div class="score-breakdown">
                        <div class="breakdown-item">
                            <span class="breakdown-label">Base</span>
                            <span class="breakdown-value" id="baseScoreValue">0</span>
                        </div>
                        <div class="breakdown-divider">+</div>
                        <div class="breakdown-item">
                            <span class="breakdown-label">Speed Bonus</span>
                            <span class="breakdown-value" id="speedBonusValue">0</span>
                        </div>
                    </div>
                </div>

                <!-- Answer Comparison -->
                <div class="answer-card">
                    <div class="answer-section">
                        <span class="answer-label">Your Answer</span>
                        <span class="answer-value user" id="userAnswerValue">—</span>
                    </div>
                    <div class="answer-divider"></div>
                    <div class="answer-section">
                        <span class="answer-label">Correct Answer(s)</span>
                        <span class="answer-value correct" id="correctAnswerValue">—</span>
                    </div>
                </div>

                <!-- Confidence Bar (for partial matches) -->
                <div class="confidence-card" id="confidenceCard" style="display: none;">
                    <span class="confidence-label">Match Confidence</span>
                    <div class="confidence-bar">
                        <div class="confidence-fill" id="confidenceFill" style="width: 0%"></div>
                    </div>
                    <span class="confidence-percent" id="confidencePercent">0%</span>
                </div>

                <!-- Streak Display -->
                <div class="results-streak" id="resultsStreak">
                    <div class="streak-item">
                        <span class="streak-flame">🔥</span>
                        <span class="streak-value" id="resultsStreakValue">0</span>
                        <span class="streak-label">Day Streak</span>
                    </div>
                    <div class="streak-divider"></div>
                    <div class="streak-item">
                        <span class="streak-icon">👨‍👩‍👧‍👦</span>
                        <span class="streak-value" id="familyParticipationValue">0%</span>
                        <span class="streak-label">Family Playing</span>
                    </div>
                </div>

                <!-- Achievement Badges -->
                <div class="achievements" id="achievements" style="display: none;">
                    <div class="achievement-badge personal-best" id="personalBestBadge" style="display: none;">
                        <span class="badge-icon">🏆</span>
                        <span class="badge-text">New Personal Best!</span>
                    </div>
                    <div class="achievement-badge family-winner" id="familyWinnerBadge" style="display: none;">
                        <span class="badge-icon">👑</span>
                        <span class="badge-text">Family Leader!</span>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="results-actions">
                    <button class="btn btn-secondary" id="shareBtn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="18" cy="5" r="3"/>
                            <circle cx="6" cy="12" r="3"/>
                            <circle cx="18" cy="19" r="3"/>
                            <path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98"/>
                        </svg>
                        <span>Share Result</span>
                    </button>
                    <button class="btn btn-primary" id="viewLeaderboardBtn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 20V10M18 20V4M6 20v-4"/>
                        </svg>
                        <span>Leaderboards</span>
                    </button>
                </div>
            </div>

            <!-- Already Played State -->
            <div id="playedState" class="state-container" style="display: none;">
                <div class="played-badge">
                    <span class="played-icon">✓</span>
                    <span class="played-text">Played Today</span>
                </div>

                <!-- Today's Result Summary -->
                <div class="played-summary-card">
                    <div class="summary-question" id="playedQuestion">Question</div>

                    <div class="summary-verdict" id="playedVerdict">
                        <span class="verdict-badge correct">Correct</span>
                    </div>

                    <div class="summary-score">
                        <span class="score-big" id="playedScore">0</span>
                        <span class="score-label">points</span>
                    </div>

                    <div class="summary-answers">
                        <div class="summary-row">
                            <span class="row-label">Your Answer</span>
                            <span class="row-value" id="playedUserAnswer">—</span>
                        </div>
                        <div class="summary-row">
                            <span class="row-label">Correct</span>
                            <span class="row-value correct" id="playedCorrectAnswer">—</span>
                        </div>
                    </div>
                </div>

                <!-- Streak Display -->
                <div class="results-streak">
                    <div class="streak-item">
                        <span class="streak-flame">🔥</span>
                        <span class="streak-value" id="playedStreakValue">0</span>
                        <span class="streak-label">Day Streak</span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="played-actions">
                    <button class="btn btn-primary btn-lg" id="playedLeaderboardBtn">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 20V10M18 20V4M6 20v-4"/>
                        </svg>
                        <span>View Leaderboards</span>
                    </button>
                    <button class="btn btn-secondary" id="playedHistoryBtn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                        <span>View History</span>
                    </button>
                </div>

                <!-- Come Back Tomorrow -->
                <div class="tomorrow-card">
                    <span class="tomorrow-icon">🌅</span>
                    <p class="tomorrow-text">New challenge available tomorrow!</p>
                </div>
            </div>

        </main>

        <!-- Leaderboard Modal -->
        <div class="modal" id="leaderboardModal">
            <div class="modal-backdrop"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">Leaderboards</h2>
                    <button class="modal-close" id="closeLeaderboardModal" aria-label="Close">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6L6 18M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Tab Navigation -->
                <div class="leaderboard-tabs">
                    <button class="tab-btn active" data-tab="solo">
                        <span class="tab-icon">👤</span>
                        <span>Solo</span>
                    </button>
                    <button class="tab-btn" data-tab="family">
                        <span class="tab-icon">👨‍👩‍👧‍👦</span>
                        <span>Family</span>
                    </button>
                    <button class="tab-btn" data-tab="global">
                        <span class="tab-icon">🌍</span>
                        <span>Global</span>
                    </button>
                </div>

                <!-- Tab Content -->
                <div class="leaderboard-content">
                    <!-- Solo Tab -->
                    <div class="tab-panel active" id="soloPanel">
                        <div class="solo-stats">
                            <div class="stat-card">
                                <span class="stat-label">Today</span>
                                <span class="stat-value" id="soloTodayScore">—</span>
                            </div>
                            <div class="stat-card highlight">
                                <span class="stat-label">Personal Best</span>
                                <span class="stat-value" id="soloPersonalBest">—</span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-label">Current Streak</span>
                                <span class="stat-value" id="soloStreak">—</span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-label">Accuracy</span>
                                <span class="stat-value" id="soloAccuracy">—</span>
                            </div>
                        </div>
                    </div>

                    <!-- Family Tab -->
                    <div class="tab-panel" id="familyPanel">
                        <div class="family-winner-card" id="familyWinnerCard" style="display: none;">
                            <span class="winner-crown">👑</span>
                            <div class="winner-info">
                                <span class="winner-label">Today's Winner</span>
                                <span class="winner-name" id="familyWinnerName">—</span>
                                <span class="winner-score" id="familyWinnerScore">0 pts</span>
                            </div>
                        </div>

                        <div class="leaderboard-list" id="familyLeaderboardList">
                            <div class="empty-state">
                                <span class="empty-icon">👨‍👩‍👧‍👦</span>
                                <p>No family members have played today yet.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Global Tab -->
                    <div class="tab-panel" id="globalPanel">
                        <div class="global-stats">
                            <span class="global-players" id="globalPlayersCount">0 players today</span>
                        </div>

                        <div class="leaderboard-list" id="globalLeaderboardList">
                            <div class="empty-state">
                                <span class="empty-icon">🌍</span>
                                <p>No one has played today yet. Be the first!</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- History Modal -->
        <div class="modal" id="historyModal">
            <div class="modal-backdrop"></div>
            <div class="modal-content large">
                <div class="modal-header">
                    <h2 class="modal-title">Your History</h2>
                    <button class="modal-close" id="closeHistoryModal" aria-label="Close">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6L6 18M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Streak Calendar -->
                <div class="streak-calendar" id="streakCalendar">
                    <!-- Populated by JS -->
                </div>

                <!-- History Stats -->
                <div class="history-stats" id="historyStats">
                    <!-- Populated by JS -->
                </div>

                <!-- History List -->
                <div class="history-list" id="historyList">
                    <!-- Populated by JS -->
                </div>
            </div>
        </div>

        <!-- Share Modal -->
        <div class="modal" id="shareModal">
            <div class="modal-backdrop"></div>
            <div class="modal-content small">
                <div class="modal-header">
                    <h2 class="modal-title">Share Result</h2>
                    <button class="modal-close" id="closeShareModal" aria-label="Close">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6L6 18M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Share Card Preview -->
                <div class="share-preview" id="sharePreview">
                    <canvas id="shareCanvas" width="400" height="300"></canvas>
                </div>

                <div class="share-actions">
                    <button class="btn btn-secondary" id="copyShareText">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                            <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                        </svg>
                        <span>Copy Text</span>
                    </button>
                    <button class="btn btn-primary" id="downloadShareImage">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>
                        </svg>
                        <span>Save Image</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Confetti Canvas -->
        <canvas id="confettiCanvas" class="confetti-canvas"></canvas>

        <!-- Toast Container -->
        <div id="toastContainer" class="toast-container"></div>

    </div>

    <!-- User Data (for JS) -->
    <script>
        window.FlashConfig = {
            userId: <?php echo (int) $user['id']; ?>,
            familyId: <?php echo (int) ($user['family_id'] ?? 0); ?>,
            displayName: <?php echo json_encode($user['full_name'] ?? 'Player'); ?>,
            apiBase: '/api/games/flash',
            cacheVersion: '<?php echo $cacheVersion; ?>'
        };
    </script>

    <!-- JavaScript Modules -->
    <script src="assets/js/storage.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="assets/js/api.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="assets/js/voice.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="assets/js/animations.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="assets/js/ui.js?v=<?php echo $cacheVersion; ?>"></script>
    <script src="assets/js/flash.js?v=<?php echo $cacheVersion; ?>"></script>

    <!-- Service Worker Registration -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js?v=<?php echo $cacheVersion; ?>')
                    .then(reg => console.log('Flash SW registered'))
                    .catch(err => console.warn('Flash SW registration failed:', err));
            });
        }
    </script>
</body>
</html>
