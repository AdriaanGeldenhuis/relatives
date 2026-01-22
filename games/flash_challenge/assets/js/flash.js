/**
 * ============================================
 * FLASH CHALLENGE - Main Controller
 * Game logic and state management
 * ============================================
 */

(function() {
    'use strict';

    const GAME_DURATION = 30; // 30 seconds
    const AUTO_SUBMIT_THRESHOLD = 3; // Auto-submit at 3 seconds remaining

    /**
     * FlashGame - Main game controller
     */
    window.FlashGame = {
        /**
         * Current game state
         */
        challengeData: null,
        gameStarted: false,
        gameEnded: false,
        startTime: null,
        answeredTime: null,
        endTime: null,
        timerInterval: null,
        remainingTime: GAME_DURATION,
        lastResult: null,

        /**
         * Initialize the game
         */
        init: async function() {
            console.log('Flash Challenge initializing...');

            // Initialize UI
            FlashUI.init();

            // Initialize animations
            FlashAnimations.initConfetti();

            // Setup event listeners
            this.setupEventListeners();

            // Setup API callbacks
            this.setupAPICallbacks();

            // Setup voice callbacks
            this.setupVoiceCallbacks();

            // Start auto-sync
            FlashAPI.startAutoSync();

            // Load challenge
            await this.loadChallenge();
        },

        /**
         * Setup event listeners
         */
        setupEventListeners: function() {
            // Start button
            const startBtn = document.getElementById('startBtn');
            if (startBtn) {
                startBtn.addEventListener('click', () => this.startGame());
            }

            // Microphone button
            const micBtn = document.getElementById('micBtn');
            if (micBtn) {
                micBtn.addEventListener('click', () => this.toggleVoice());
            }

            // Text input
            const answerInput = document.getElementById('answerInput');
            if (answerInput) {
                answerInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        this.submitAnswer();
                    }
                });

                answerInput.addEventListener('input', () => {
                    // Clear voice transcript when typing
                    FlashUI.setTranscript('', '');
                });
            }

            // Submit button
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.addEventListener('click', () => this.submitAnswer());
            }

            // Results buttons
            const shareBtn = document.getElementById('shareBtn');
            if (shareBtn) {
                shareBtn.addEventListener('click', () => this.openShareModal());
            }

            const viewLeaderboardBtn = document.getElementById('viewLeaderboardBtn');
            if (viewLeaderboardBtn) {
                viewLeaderboardBtn.addEventListener('click', () => this.openLeaderboard());
            }

            // Played state buttons
            const playedLeaderboardBtn = document.getElementById('playedLeaderboardBtn');
            if (playedLeaderboardBtn) {
                playedLeaderboardBtn.addEventListener('click', () => this.openLeaderboard());
            }

            const playedHistoryBtn = document.getElementById('playedHistoryBtn');
            if (playedHistoryBtn) {
                playedHistoryBtn.addEventListener('click', () => this.openHistory());
            }

            // Retry button
            const retryBtn = document.getElementById('retryBtn');
            if (retryBtn) {
                retryBtn.addEventListener('click', () => this.loadChallenge());
            }

            // Share modal buttons
            const copyShareText = document.getElementById('copyShareText');
            if (copyShareText) {
                copyShareText.addEventListener('click', () => this.copyShareText());
            }

            const downloadShareImage = document.getElementById('downloadShareImage');
            if (downloadShareImage) {
                downloadShareImage.addEventListener('click', () => this.downloadShareImage());
            }
        },

        /**
         * Setup API callbacks
         */
        setupAPICallbacks: function() {
            FlashAPI.onOnlineChange = (isOnline) => {
                FlashUI.updateOnlineStatus(isOnline);

                if (isOnline) {
                    FlashUI.showToast('Back online', 'success');
                } else {
                    FlashUI.showToast('You are offline', 'warning');
                }
            };

            FlashAPI.onSyncStart = () => {
                FlashUI.updateSyncStatus('syncing');
            };

            FlashAPI.onSyncComplete = (result) => {
                if (result.synced > 0) {
                    FlashUI.updateSyncStatus('synced');
                    FlashUI.showToast(`Synced ${result.synced} attempt(s)`, 'success');
                } else {
                    FlashUI.updateSyncStatus('hidden');
                }
            };

            // Initial online status
            FlashUI.updateOnlineStatus(FlashAPI.isOnline());
        },

        /**
         * Setup voice callbacks
         */
        setupVoiceCallbacks: function() {
            if (!FlashVoice.isSupported()) {
                // Hide voice UI elements or show fallback message
                const voiceCard = document.getElementById('voiceCard');
                if (voiceCard) {
                    voiceCard.innerHTML = `
                        <div class="voice-unsupported">
                            <p>Voice input not supported on this device.</p>
                            <p>Use the text input below.</p>
                        </div>
                    `;
                }
                return;
            }

            FlashVoice.onStart = () => {
                FlashUI.setVoiceStatus('listening', 'Listening...');
                FlashVoice.hapticFeedback('light');
            };

            FlashVoice.onResult = (transcript) => {
                FlashUI.setTranscript(transcript, '');

                // Update text input
                const answerInput = document.getElementById('answerInput');
                if (answerInput) {
                    answerInput.value = transcript;
                }
            };

            FlashVoice.onInterim = (interim) => {
                FlashUI.setTranscript(FlashVoice.getTranscript(), interim);
            };

            FlashVoice.onEnd = (transcript) => {
                FlashUI.setVoiceStatus('idle', 'Tap to speak or type below');

                // Auto-submit if we have a transcript and game is active
                if (transcript && this.gameStarted && !this.gameEnded) {
                    // Update input
                    const answerInput = document.getElementById('answerInput');
                    if (answerInput) {
                        answerInput.value = transcript;
                    }

                    // Don't auto-submit, let user confirm
                    FlashVoice.hapticFeedback('medium');
                }
            };

            FlashVoice.onError = (message, code) => {
                FlashUI.setVoiceStatus('error', message);
                FlashUI.showToast(message, 'error');
                FlashVoice.hapticFeedback('error');
            };
        },

        /**
         * Load today's challenge
         */
        loadChallenge: async function() {
            FlashUI.showState('loading');

            try {
                const result = await FlashAPI.getDailyChallenge();

                if (!result.success) {
                    FlashUI.showError(result.error || 'Failed to load challenge');
                    return;
                }

                this.challengeData = result;

                // Set challenge data in UI
                FlashUI.setChallengeData(result.challenge);
                FlashUI.setUserStatus(result.user_status || {});

                if (result.family_status) {
                    FlashUI.setFamilyParticipation(result.family_status);
                }

                // Check if already played
                if (result.user_status?.has_played_today && result.attempt) {
                    this.lastResult = result.attempt;
                    FlashUI.showPlayedState(result.challenge, result.attempt, result.user_status);
                    FlashUI.showState('played');
                } else {
                    // Reset timer display
                    FlashAnimations.resetTimerRing();
                    FlashUI.showState('preGame');
                }

            } catch (error) {
                console.error('Failed to load challenge:', error);
                FlashUI.showError('Failed to load challenge. Please try again.');
            }
        },

        /**
         * Start the game
         */
        startGame: function() {
            if (this.gameStarted) return;

            console.log('Starting game...');

            this.gameStarted = true;
            this.gameEnded = false;
            this.remainingTime = GAME_DURATION;
            this.startTime = new Date();
            this.answeredTime = null;
            this.endTime = null;

            // Clear any previous input
            const answerInput = document.getElementById('answerInput');
            if (answerInput) {
                answerInput.value = '';
            }
            FlashUI.setTranscript('', '');

            // Reset timer
            FlashAnimations.resetTimerRing();

            // Show game state
            FlashUI.showState('game');

            // Start timer
            this.startTimer();

            // Haptic feedback
            FlashVoice.hapticFeedback('medium');

            // Focus input
            setTimeout(() => {
                answerInput?.focus();
            }, 300);
        },

        /**
         * Start countdown timer
         */
        startTimer: function() {
            const startTimestamp = Date.now();

            this.timerInterval = setInterval(() => {
                const elapsed = (Date.now() - startTimestamp) / 1000;
                this.remainingTime = Math.max(0, GAME_DURATION - elapsed);

                // Update timer display
                const progress = this.remainingTime / GAME_DURATION;
                FlashAnimations.animateTimerRing(progress, this.remainingTime);

                // Auto-submit at threshold if we have an answer
                if (this.remainingTime <= AUTO_SUBMIT_THRESHOLD && !this.gameEnded) {
                    const answerInput = document.getElementById('answerInput');
                    const answer = answerInput?.value?.trim();

                    if (answer) {
                        this.submitAnswer();
                    }
                }

                // Time's up
                if (this.remainingTime <= 0) {
                    this.endGame(true);
                }

                // Warning haptic at 10 seconds
                if (Math.ceil(this.remainingTime) === 10) {
                    FlashVoice.hapticFeedback('light');
                }

                // Danger haptic at 5 seconds
                if (Math.ceil(this.remainingTime) === 5) {
                    FlashVoice.hapticFeedback('medium');
                }

            }, 100);
        },

        /**
         * Toggle voice input
         */
        toggleVoice: function() {
            if (!this.gameStarted || this.gameEnded) return;

            if (FlashVoice.isListening()) {
                FlashVoice.stop();
            } else {
                FlashVoice.start();
            }
        },

        /**
         * Submit answer
         */
        submitAnswer: async function() {
            if (this.gameEnded) return;

            const answerInput = document.getElementById('answerInput');
            const answer = answerInput?.value?.trim();

            if (!answer) {
                FlashUI.showToast('Please enter an answer', 'warning');
                FlashAnimations.shake(answerInput);
                return;
            }

            // Record answer time
            this.answeredTime = new Date();

            // Stop voice if active
            if (FlashVoice.isListening()) {
                FlashVoice.stop();
            }

            // End game
            this.endGame(false);

            // Submit to API
            await this.submitToAPI(answer);
        },

        /**
         * End the game
         */
        endGame: function(timedOut) {
            if (this.gameEnded) return;

            this.gameEnded = true;
            this.endTime = new Date();

            // Stop timer
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }

            // Stop voice
            FlashVoice.stop();

            // If timed out without answer, submit empty
            if (timedOut) {
                const answerInput = document.getElementById('answerInput');
                const answer = answerInput?.value?.trim() || '';

                if (!this.answeredTime) {
                    this.answeredTime = new Date();
                }

                this.submitToAPI(answer);
            }
        },

        /**
         * Submit answer to API
         */
        submitToAPI: async function(answer) {
            // Show loading state
            FlashUI.showState('loading');

            const attemptData = {
                challenge_date: this.challengeData?.challenge?.challenge_date,
                answer_text: answer,
                started_at: this.startTime?.toISOString(),
                answered_at: this.answeredTime?.toISOString(),
                ended_at: this.endTime?.toISOString()
            };

            try {
                const result = await FlashAPI.submitAttempt(attemptData);

                if (result.queued) {
                    // Queued for later sync
                    FlashUI.updateSyncStatus('pending');
                    FlashUI.showToast('Saved offline. Will sync when online.', 'info');

                    // Show a placeholder result
                    this.showOfflineResult(answer);
                    return;
                }

                if (!result.success && result.status === 409) {
                    // Already submitted - show existing result
                    this.lastResult = result.attempt;
                    FlashUI.showResults(result.attempt);
                    FlashUI.showState('results');
                    return;
                }

                if (!result.success) {
                    FlashUI.showError(result.error || 'Failed to submit answer');
                    return;
                }

                // Success - show results
                this.lastResult = result;
                FlashUI.showResults(result);
                FlashUI.showState('results');

                // Haptic feedback based on result
                if (result.verdict === 'correct') {
                    FlashVoice.hapticFeedback('success');
                } else if (result.verdict === 'partial') {
                    FlashVoice.hapticFeedback('medium');
                } else {
                    FlashVoice.hapticFeedback('error');
                }

            } catch (error) {
                console.error('Submit error:', error);
                FlashUI.showError('Failed to submit answer. Please try again.');
            }
        },

        /**
         * Show offline placeholder result
         */
        showOfflineResult: function(answer) {
            const placeholderResult = {
                verdict: 'pending',
                confidence: 0,
                reason: 'Your answer has been saved and will be scored when you\'re back online.',
                score: 0,
                base_score: 0,
                speed_bonus: 0,
                normalized_answer: answer,
                correct_answers: ['Answers will be revealed when synced'],
                streak: { user_streak: 0, family_participation_percent: 0 },
                achievements: {}
            };

            // Custom display for offline
            FlashUI.showResults(placeholderResult);

            // Override verdict banner
            const verdictBanner = document.getElementById('verdictBanner');
            if (verdictBanner) {
                verdictBanner.className = 'verdict-banner';
                verdictBanner.style.background = 'var(--glass-medium)';
                verdictBanner.style.borderColor = 'var(--glass-border)';
            }

            const verdictIcon = document.getElementById('verdictIcon');
            if (verdictIcon) {
                verdictIcon.textContent = '⏳';
            }

            const verdictText = document.getElementById('verdictText');
            if (verdictText) {
                verdictText.textContent = 'Saved Offline';
                verdictText.style.color = 'var(--text-primary)';
            }

            FlashUI.showState('results');
        },

        /**
         * Open leaderboard modal
         */
        openLeaderboard: async function() {
            FlashUI.openModal('leaderboard');

            // Load data
            const result = await FlashAPI.getLeaderboard();

            if (result.success) {
                FlashUI.populateLeaderboard(result);
            } else {
                FlashUI.showToast('Failed to load leaderboard', 'error');
            }
        },

        /**
         * Open history modal
         */
        openHistory: async function() {
            FlashUI.openModal('history');

            // Load data
            const result = await FlashAPI.getHistory(14);

            if (result.success) {
                FlashUI.populateHistory(result);
            } else {
                FlashUI.showToast('Failed to load history', 'error');
            }
        },

        /**
         * Open share modal
         */
        openShareModal: function() {
            if (!this.lastResult) return;

            // Generate share card
            FlashAnimations.createShareCard({
                score: this.lastResult.score || 0,
                verdict: this.lastResult.verdict || 'pending',
                streak: this.lastResult.streak?.user_streak || 0
            });

            FlashUI.openModal('share');
        },

        /**
         * Copy share text
         */
        copyShareText: async function() {
            if (!this.lastResult) return;

            const success = await FlashAnimations.copyShareText({
                score: this.lastResult.score || 0,
                verdict: this.lastResult.verdict || 'pending',
                streak: this.lastResult.streak?.user_streak || 0
            });

            if (success) {
                FlashUI.showToast('Copied to clipboard!', 'success');
            } else {
                FlashUI.showToast('Failed to copy', 'error');
            }
        },

        /**
         * Download share image
         */
        downloadShareImage: function() {
            if (!this.lastResult) return;

            FlashAnimations.downloadShareImage({
                score: this.lastResult.score || 0,
                verdict: this.lastResult.verdict || 'pending',
                streak: this.lastResult.streak?.user_streak || 0
            });

            FlashUI.showToast('Image downloaded!', 'success');
        },

        /**
         * Reset game state
         */
        reset: function() {
            this.gameStarted = false;
            this.gameEnded = false;
            this.startTime = null;
            this.answeredTime = null;
            this.endTime = null;
            this.remainingTime = GAME_DURATION;

            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }

            FlashVoice.reset();
            FlashAnimations.resetTimerRing();
            FlashAnimations.clearConfetti();
        }
    };

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => FlashGame.init());
    } else {
        FlashGame.init();
    }

})();
