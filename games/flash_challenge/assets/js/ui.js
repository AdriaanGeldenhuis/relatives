/**
 * ============================================
 * FLASH CHALLENGE - UI Module
 * DOM manipulation and state management
 * ============================================
 */

(function() {
    'use strict';

    /**
     * FlashUI - Handles all UI operations
     */
    window.FlashUI = {
        /**
         * Current state
         */
        currentState: 'loading',

        /**
         * DOM Element cache
         */
        elements: {},

        /**
         * Initialize UI elements
         */
        init: function() {
            // Cache all important elements
            this.elements = {
                // States
                loadingState: document.getElementById('loadingState'),
                errorState: document.getElementById('errorState'),
                preGameState: document.getElementById('preGameState'),
                gameState: document.getElementById('gameState'),
                resultsState: document.getElementById('resultsState'),
                playedState: document.getElementById('playedState'),

                // Top bar
                onlineStatus: document.getElementById('onlineStatus'),
                syncStatus: document.getElementById('syncStatus'),
                themeToggle: document.getElementById('themeToggle'),

                // Pre-game
                streakBanner: document.getElementById('streakBanner'),
                streakCount: document.getElementById('streakCount'),
                categoryChip: document.getElementById('categoryChip'),
                difficultyDots: document.getElementById('difficultyDots'),
                questionText: document.getElementById('questionText'),
                formatHint: document.getElementById('formatHint'),
                timerProgress: document.getElementById('timerProgress'),
                timerValue: document.getElementById('timerValue'),
                startBtn: document.getElementById('startBtn'),
                familyParticipation: document.getElementById('familyParticipation'),
                participationFill: document.getElementById('participationFill'),
                participationPercent: document.getElementById('participationPercent'),

                // Game state
                gameCategoryChip: document.getElementById('gameCategoryChip'),
                gameDifficultyDots: document.getElementById('gameDifficultyDots'),
                gameQuestionText: document.getElementById('gameQuestionText'),
                gameFormatHint: document.getElementById('gameFormatHint'),
                activeTimerProgress: document.getElementById('activeTimerProgress'),
                activeTimerValue: document.getElementById('activeTimerValue'),
                micBtn: document.getElementById('micBtn'),
                voiceStatus: document.getElementById('voiceStatus'),
                transcriptArea: document.getElementById('transcriptArea'),
                transcriptText: document.getElementById('transcriptText'),
                transcriptInterim: document.getElementById('transcriptInterim'),
                answerInput: document.getElementById('answerInput'),
                submitBtn: document.getElementById('submitBtn'),

                // Results state
                verdictBanner: document.getElementById('verdictBanner'),
                verdictIcon: document.getElementById('verdictIcon'),
                verdictText: document.getElementById('verdictText'),
                verdictReason: document.getElementById('verdictReason'),
                scoreValue: document.getElementById('scoreValue'),
                baseScoreValue: document.getElementById('baseScoreValue'),
                speedBonusValue: document.getElementById('speedBonusValue'),
                userAnswerValue: document.getElementById('userAnswerValue'),
                correctAnswerValue: document.getElementById('correctAnswerValue'),
                confidenceCard: document.getElementById('confidenceCard'),
                confidenceFill: document.getElementById('confidenceFill'),
                confidencePercent: document.getElementById('confidencePercent'),
                resultsStreakValue: document.getElementById('resultsStreakValue'),
                familyParticipationValue: document.getElementById('familyParticipationValue'),
                achievements: document.getElementById('achievements'),
                personalBestBadge: document.getElementById('personalBestBadge'),
                familyWinnerBadge: document.getElementById('familyWinnerBadge'),
                shareBtn: document.getElementById('shareBtn'),
                viewLeaderboardBtn: document.getElementById('viewLeaderboardBtn'),

                // Played state
                playedQuestion: document.getElementById('playedQuestion'),
                playedVerdict: document.getElementById('playedVerdict'),
                playedScore: document.getElementById('playedScore'),
                playedUserAnswer: document.getElementById('playedUserAnswer'),
                playedCorrectAnswer: document.getElementById('playedCorrectAnswer'),
                playedStreakValue: document.getElementById('playedStreakValue'),
                playedLeaderboardBtn: document.getElementById('playedLeaderboardBtn'),
                playedHistoryBtn: document.getElementById('playedHistoryBtn'),

                // Modals
                leaderboardModal: document.getElementById('leaderboardModal'),
                historyModal: document.getElementById('historyModal'),
                shareModal: document.getElementById('shareModal'),

                // Error state
                errorMessage: document.getElementById('errorMessage'),
                retryBtn: document.getElementById('retryBtn'),

                // Toast
                toastContainer: document.getElementById('toastContainer')
            };

            // Initialize theme
            this.initTheme();

            // Initialize modals
            this.initModals();
        },

        /**
         * Show a specific state
         */
        showState: function(stateName) {
            const states = ['loading', 'error', 'preGame', 'game', 'results', 'played'];

            states.forEach(state => {
                const el = this.elements[state + 'State'];
                if (el) {
                    el.style.display = state === stateName ? 'flex' : 'none';
                }
            });

            this.currentState = stateName;
        },

        /**
         * Update online status indicator
         */
        updateOnlineStatus: function(isOnline) {
            const statusEl = this.elements.onlineStatus;
            if (!statusEl) return;

            const dot = statusEl.querySelector('.status-dot');
            const text = statusEl.querySelector('.status-text');

            if (dot) {
                dot.classList.toggle('online', isOnline);
            }
            if (text) {
                text.textContent = isOnline ? 'Online' : 'Offline';
            }
        },

        /**
         * Update sync status indicator
         */
        updateSyncStatus: function(status) {
            const syncEl = this.elements.syncStatus;
            if (!syncEl) return;

            const text = syncEl.querySelector('.sync-text');

            switch (status) {
                case 'syncing':
                    syncEl.style.display = 'flex';
                    if (text) text.textContent = 'Syncing';
                    break;
                case 'synced':
                    syncEl.style.display = 'flex';
                    if (text) text.textContent = 'Synced';
                    setTimeout(() => {
                        syncEl.style.display = 'none';
                    }, 2000);
                    break;
                case 'pending':
                    syncEl.style.display = 'flex';
                    if (text) text.textContent = 'Saved locally';
                    break;
                case 'hidden':
                default:
                    syncEl.style.display = 'none';
                    break;
            }
        },

        /**
         * Set challenge data in pre-game UI
         */
        setChallengeData: function(challenge) {
            if (this.elements.categoryChip) {
                this.elements.categoryChip.textContent = challenge.category || 'General';
            }

            if (this.elements.gameCategoryChip) {
                this.elements.gameCategoryChip.textContent = challenge.category || 'General';
            }

            // Set difficulty dots
            const difficulty = challenge.difficulty || 3;
            this.setDifficultyDots(this.elements.difficultyDots, difficulty);
            this.setDifficultyDots(this.elements.gameDifficultyDots, difficulty);

            if (this.elements.questionText) {
                this.elements.questionText.textContent = challenge.question;
            }

            if (this.elements.gameQuestionText) {
                this.elements.gameQuestionText.textContent = challenge.question;
            }

            if (this.elements.formatHint) {
                this.elements.formatHint.textContent = challenge.format_hint || 'Answer when ready';
            }

            if (this.elements.gameFormatHint) {
                this.elements.gameFormatHint.textContent = challenge.format_hint || '';
            }
        },

        /**
         * Set difficulty dots
         */
        setDifficultyDots: function(container, level) {
            if (!container) return;

            const dots = container.querySelectorAll('.dot');
            dots.forEach((dot, index) => {
                dot.classList.toggle('filled', index < level);
            });
        },

        /**
         * Set user status (streak, participation)
         */
        setUserStatus: function(status) {
            // Streak banner
            if (status.user_streak > 0) {
                if (this.elements.streakBanner) {
                    this.elements.streakBanner.style.display = 'flex';
                }
                if (this.elements.streakCount) {
                    this.elements.streakCount.textContent = status.user_streak;
                }
            } else {
                if (this.elements.streakBanner) {
                    this.elements.streakBanner.style.display = 'none';
                }
            }
        },

        /**
         * Set family participation
         */
        setFamilyParticipation: function(familyStatus) {
            if (!familyStatus) {
                if (this.elements.familyParticipation) {
                    this.elements.familyParticipation.style.display = 'none';
                }
                return;
            }

            if (this.elements.familyParticipation) {
                this.elements.familyParticipation.style.display = 'flex';
            }

            if (this.elements.participationFill) {
                this.elements.participationFill.style.width = familyStatus.participation_percent + '%';
            }

            if (this.elements.participationPercent) {
                this.elements.participationPercent.textContent =
                    familyStatus.members_played + ' of ' + familyStatus.total_members + ' played today';
            }
        },

        /**
         * Update voice status
         */
        setVoiceStatus: function(status, message) {
            const statusEl = this.elements.voiceStatus;
            const textEl = statusEl?.querySelector('.voice-status-text');

            if (textEl) {
                textEl.textContent = message || 'Tap to speak or type below';
                textEl.classList.toggle('listening', status === 'listening');
            }

            if (this.elements.micBtn) {
                this.elements.micBtn.classList.toggle('listening', status === 'listening');
            }
        },

        /**
         * Update transcript display
         */
        setTranscript: function(final, interim) {
            if (this.elements.transcriptText) {
                this.elements.transcriptText.textContent = final || '';
            }

            if (this.elements.transcriptInterim) {
                this.elements.transcriptInterim.textContent = interim || '';
            }

            // Also update text input
            if (this.elements.answerInput && final) {
                this.elements.answerInput.value = final;
            }
        },

        /**
         * Display results
         */
        showResults: function(result) {
            // Verdict banner
            if (this.elements.verdictBanner) {
                this.elements.verdictBanner.className = 'verdict-banner ' + result.verdict;
            }

            const verdictIcons = {
                correct: '✓',
                partial: '≈',
                incorrect: '✗'
            };

            const verdictTexts = {
                correct: 'Correct!',
                partial: 'Close!',
                incorrect: 'Not quite'
            };

            if (this.elements.verdictIcon) {
                this.elements.verdictIcon.textContent = verdictIcons[result.verdict] || '?';
            }

            if (this.elements.verdictText) {
                this.elements.verdictText.textContent = verdictTexts[result.verdict] || 'Result';
            }

            if (this.elements.verdictReason) {
                this.elements.verdictReason.textContent = result.reason || '';
            }

            // Score with animation
            if (this.elements.scoreValue) {
                FlashAnimations.animateNumber(this.elements.scoreValue, 0, result.score, 800);
            }

            if (this.elements.baseScoreValue) {
                this.elements.baseScoreValue.textContent = result.base_score;
            }

            if (this.elements.speedBonusValue) {
                this.elements.speedBonusValue.textContent = result.speed_bonus;
            }

            // Answers
            if (this.elements.userAnswerValue) {
                this.elements.userAnswerValue.textContent = result.normalized_answer || '—';
            }

            if (this.elements.correctAnswerValue) {
                const answers = result.correct_answers || [];
                this.elements.correctAnswerValue.textContent =
                    answers.slice(0, 3).join(', ') + (answers.length > 3 ? '...' : '');
            }

            // Confidence (for partial matches)
            if (result.verdict === 'partial' && result.confidence < 100) {
                if (this.elements.confidenceCard) {
                    this.elements.confidenceCard.style.display = 'block';
                }
                if (this.elements.confidenceFill) {
                    setTimeout(() => {
                        this.elements.confidenceFill.style.width = result.confidence + '%';
                    }, 300);
                }
                if (this.elements.confidencePercent) {
                    this.elements.confidencePercent.textContent = result.confidence + '%';
                }
            } else {
                if (this.elements.confidenceCard) {
                    this.elements.confidenceCard.style.display = 'none';
                }
            }

            // Streak
            if (this.elements.resultsStreakValue) {
                this.elements.resultsStreakValue.textContent = result.streak?.user_streak || 0;
            }

            if (this.elements.familyParticipationValue) {
                this.elements.familyParticipationValue.textContent =
                    Math.round(result.streak?.family_participation_percent || 0) + '%';
            }

            // Achievements
            const hasAchievements = result.achievements?.is_personal_best || result.achievements?.is_family_winner;

            if (this.elements.achievements) {
                this.elements.achievements.style.display = hasAchievements ? 'flex' : 'none';
            }

            if (this.elements.personalBestBadge) {
                this.elements.personalBestBadge.style.display =
                    result.achievements?.is_personal_best ? 'flex' : 'none';
            }

            if (this.elements.familyWinnerBadge) {
                this.elements.familyWinnerBadge.style.display =
                    result.achievements?.is_family_winner ? 'flex' : 'none';
            }

            // Trigger confetti for achievements
            if (result.achievements?.is_personal_best || result.achievements?.is_family_winner) {
                setTimeout(() => {
                    FlashAnimations.celebrateConfetti();
                }, 500);
            }
        },

        /**
         * Show already played state
         */
        showPlayedState: function(challenge, attempt, userStatus) {
            if (this.elements.playedQuestion) {
                this.elements.playedQuestion.textContent = challenge.question;
            }

            if (this.elements.playedVerdict) {
                const badge = this.elements.playedVerdict.querySelector('.verdict-badge');
                if (badge) {
                    badge.className = 'verdict-badge ' + attempt.verdict;
                    badge.textContent = attempt.verdict.charAt(0).toUpperCase() + attempt.verdict.slice(1);
                }
            }

            if (this.elements.playedScore) {
                this.elements.playedScore.textContent = attempt.score;
            }

            if (this.elements.playedUserAnswer) {
                this.elements.playedUserAnswer.textContent = attempt.answer_text || '—';
            }

            if (this.elements.playedCorrectAnswer) {
                const answers = attempt.correct_answers || [];
                this.elements.playedCorrectAnswer.textContent =
                    answers.slice(0, 3).join(', ') + (answers.length > 3 ? '...' : '');
            }

            if (this.elements.playedStreakValue) {
                this.elements.playedStreakValue.textContent = userStatus?.user_streak || 0;
            }
        },

        /**
         * Show error state
         */
        showError: function(message) {
            if (this.elements.errorMessage) {
                this.elements.errorMessage.textContent = message;
            }
            this.showState('error');
        },

        /**
         * Show toast notification
         */
        showToast: function(message, type = 'info', duration = 3000) {
            const container = this.elements.toastContainer;
            if (!container) return;

            const icons = {
                success: '✓',
                error: '✗',
                warning: '⚠',
                info: 'ℹ'
            };

            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            toast.innerHTML = `
                <span class="toast-icon">${icons[type] || icons.info}</span>
                <span class="toast-message">${message}</span>
            `;

            container.appendChild(toast);

            // Auto remove
            setTimeout(() => {
                toast.classList.add('removing');
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, duration);
        },

        /**
         * Initialize theme system
         */
        initTheme: function() {
            const savedTheme = FlashStorage.getTheme();

            if (savedTheme === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            } else if (savedTheme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
            // 'auto' relies on prefers-color-scheme

            // Theme toggle handler
            if (this.elements.themeToggle) {
                this.elements.themeToggle.addEventListener('click', () => {
                    this.toggleTheme();
                });
            }
        },

        /**
         * Toggle theme
         */
        toggleTheme: function() {
            const current = document.documentElement.getAttribute('data-theme');

            if (current === 'light') {
                document.documentElement.setAttribute('data-theme', 'dark');
                FlashStorage.setTheme('dark');
            } else {
                document.documentElement.setAttribute('data-theme', 'light');
                FlashStorage.setTheme('light');
            }
        },

        /**
         * Initialize modals
         */
        initModals: function() {
            // Close buttons
            document.getElementById('closeLeaderboardModal')?.addEventListener('click', () => {
                this.closeModal('leaderboard');
            });

            document.getElementById('closeHistoryModal')?.addEventListener('click', () => {
                this.closeModal('history');
            });

            document.getElementById('closeShareModal')?.addEventListener('click', () => {
                this.closeModal('share');
            });

            // Backdrop clicks
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
                backdrop.addEventListener('click', () => {
                    this.closeAllModals();
                });
            });

            // Tab switching
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const tab = e.currentTarget.dataset.tab;
                    this.switchTab(tab);
                });
            });
        },

        /**
         * Open modal
         */
        openModal: function(modalName) {
            const modal = this.elements[modalName + 'Modal'];
            if (modal) {
                modal.classList.add('open');
                document.body.style.overflow = 'hidden';
            }
        },

        /**
         * Close modal
         */
        closeModal: function(modalName) {
            const modal = this.elements[modalName + 'Modal'];
            if (modal) {
                modal.classList.remove('open');
                document.body.style.overflow = '';
            }
        },

        /**
         * Close all modals
         */
        closeAllModals: function() {
            document.querySelectorAll('.modal.open').forEach(modal => {
                modal.classList.remove('open');
            });
            document.body.style.overflow = '';
        },

        /**
         * Switch tab
         */
        switchTab: function(tabName) {
            // Update buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.tab === tabName);
            });

            // Update panels
            document.querySelectorAll('.tab-panel').forEach(panel => {
                panel.classList.toggle('active', panel.id === tabName + 'Panel');
            });
        },

        /**
         * Populate leaderboard modal
         */
        populateLeaderboard: function(data) {
            // Solo stats
            if (data.solo) {
                const todayScore = document.getElementById('soloTodayScore');
                const personalBest = document.getElementById('soloPersonalBest');
                const streak = document.getElementById('soloStreak');
                const accuracy = document.getElementById('soloAccuracy');

                if (todayScore) {
                    todayScore.textContent = data.solo.today?.score ?? '—';
                }
                if (personalBest) {
                    personalBest.textContent = data.solo.personal_best?.score ?? '—';
                }
                if (streak) {
                    streak.textContent = data.solo.personal_best?.streak ?? '—';
                }
                if (accuracy) {
                    accuracy.textContent = data.solo.personal_best?.accuracy
                        ? data.solo.personal_best.accuracy + '%'
                        : '—';
                }
            }

            // Family leaderboard
            const familyList = document.getElementById('familyLeaderboardList');
            const familyWinnerCard = document.getElementById('familyWinnerCard');

            if (familyList && data.family) {
                if (data.family.today_top && data.family.today_top.length > 0) {
                    familyList.innerHTML = data.family.today_top.map(entry =>
                        this.createLeaderboardItem(entry)
                    ).join('');

                    // Show winner card
                    if (familyWinnerCard && data.family.winner) {
                        familyWinnerCard.style.display = 'flex';
                        document.getElementById('familyWinnerName').textContent = data.family.winner.display_name;
                        document.getElementById('familyWinnerScore').textContent = data.family.winner.score + ' pts';
                    }
                } else {
                    familyList.innerHTML = `
                        <div class="empty-state">
                            <span class="empty-icon">👨‍👩‍👧‍👦</span>
                            <p>No family members have played today yet.</p>
                        </div>
                    `;
                    if (familyWinnerCard) {
                        familyWinnerCard.style.display = 'none';
                    }
                }
            }

            // Global leaderboard
            const globalList = document.getElementById('globalLeaderboardList');
            const globalCount = document.getElementById('globalPlayersCount');

            if (globalList && data.global) {
                if (globalCount) {
                    globalCount.textContent = data.global.total_players_today + ' players today';
                }

                if (data.global.today_top && data.global.today_top.length > 0) {
                    globalList.innerHTML = data.global.today_top.map(entry =>
                        this.createLeaderboardItem(entry)
                    ).join('');
                } else {
                    globalList.innerHTML = `
                        <div class="empty-state">
                            <span class="empty-icon">🌍</span>
                            <p>No one has played today yet. Be the first!</p>
                        </div>
                    `;
                }
            }
        },

        /**
         * Create leaderboard item HTML
         */
        createLeaderboardItem: function(entry) {
            const rankClasses = {
                1: 'gold',
                2: 'silver',
                3: 'bronze'
            };

            return `
                <div class="leaderboard-item ${entry.is_current_user ? 'highlight' : ''}">
                    <span class="leaderboard-rank ${rankClasses[entry.rank] || 'default'}">${entry.rank}</span>
                    <div class="leaderboard-avatar" style="background: ${entry.avatar_color || '#667eea'}">
                        ${entry.initials || '?'}
                    </div>
                    <div class="leaderboard-info">
                        <div class="leaderboard-name">${entry.display_name}</div>
                        <div class="leaderboard-verdict">${entry.verdict}</div>
                    </div>
                    <div class="leaderboard-score">${entry.score}</div>
                </div>
            `;
        },

        /**
         * Populate history modal
         */
        populateHistory: function(data) {
            // Streak calendar
            const calendar = document.getElementById('streakCalendar');
            if (calendar && data.streak_calendar) {
                calendar.innerHTML = data.streak_calendar.map(day => `
                    <div class="calendar-day ${day.played ? 'played' : ''} ${day.date === new Date().toISOString().split('T')[0] ? 'today' : ''}">
                        <span class="calendar-day-name">${day.day_name}</span>
                        <span class="calendar-day-num">${day.day_num}</span>
                    </div>
                `).join('');
            }

            // Stats
            const stats = document.getElementById('historyStats');
            if (stats && data.stats) {
                stats.innerHTML = `
                    <div class="history-stat">
                        <span class="history-stat-value">${data.stats.days_played}</span>
                        <span class="history-stat-label">Days Played</span>
                    </div>
                    <div class="history-stat">
                        <span class="history-stat-value">${Math.round(data.stats.avg_score)}</span>
                        <span class="history-stat-label">Avg Score</span>
                    </div>
                    <div class="history-stat">
                        <span class="history-stat-value">${data.stats.current_streak}</span>
                        <span class="history-stat-label">Streak</span>
                    </div>
                `;
            }

            // History list
            const list = document.getElementById('historyList');
            if (list && data.user_results) {
                if (data.user_results.length > 0) {
                    list.innerHTML = data.user_results.map(result => {
                        const date = new Date(result.date);
                        return `
                            <div class="history-item">
                                <div class="history-date">
                                    <div class="history-day">${date.toLocaleDateString('en-US', { weekday: 'short' })}</div>
                                    <div class="history-date-num">${date.getDate()}</div>
                                </div>
                                <div class="history-info">
                                    <div class="history-category">${result.category || 'General'}</div>
                                    <div class="history-verdict ${result.verdict}">${result.verdict}</div>
                                </div>
                                <div class="history-score">${result.score}</div>
                            </div>
                        `;
                    }).join('');
                } else {
                    list.innerHTML = `
                        <div class="empty-state">
                            <span class="empty-icon">📊</span>
                            <p>No history yet. Play your first challenge!</p>
                        </div>
                    `;
                }
            }
        }
    };

})();
