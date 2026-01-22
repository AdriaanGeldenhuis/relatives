/**
 * 30 Seconds Party - Main Application Controller
 * Coordinates all modules and handles page-specific logic
 */

(function() {
    'use strict';

    window.ThirtySecondsApp = {
        // Current page
        currentPage: null,

        // Turn state
        turnActive: false,

        /**
         * Initialize the application
         */
        init: async function(page) {
            this.currentPage = page;

            // Initialize core modules
            await GameState.init();
            GameUI.init();

            // Apply saved theme
            GameState.applyTheme();

            // Initialize page-specific logic
            switch (page) {
                case 'index':
                    this.initIndexPage();
                    break;
                case 'lobby':
                    this.initLobbyPage();
                    break;
                case 'turn':
                    this.initTurnPage();
                    break;
                case 'scoreboard':
                    this.initScoreboardPage();
                    break;
                case 'results':
                    this.initResultsPage();
                    break;
                case 'view':
                    this.initViewPage();
                    break;
            }

            console.log(`30 Seconds Party initialized on page: ${page}`);
        },

        // ==========================================
        // INDEX PAGE
        // ==========================================

        initIndexPage: function() {
            const startBtn = document.getElementById('start-match-btn');
            const continueBtn = document.getElementById('continue-match-btn');
            const historyBtn = document.getElementById('history-btn');

            if (startBtn) {
                startBtn.addEventListener('click', () => {
                    window.location.href = 'lobby.php';
                });
            }

            // Show continue button if there's an active match
            if (GameState.match && GameState.match.status === 'active') {
                if (continueBtn) {
                    continueBtn.classList.remove('hidden');
                    continueBtn.addEventListener('click', () => {
                        if (GameState.match.currentTurn) {
                            window.location.href = 'turn.php';
                        } else {
                            window.location.href = 'scoreboard.php';
                        }
                    });
                }
            }

            // History button
            if (historyBtn) {
                historyBtn.addEventListener('click', () => {
                    this.showMatchHistory();
                });
            }

            // Show offline status
            this.updateOfflineStatus();
        },

        updateOfflineStatus: function() {
            const statusEl = document.getElementById('offline-status');
            if (statusEl) {
                statusEl.textContent = navigator.onLine ? 'Online' : 'Offline Ready';
                statusEl.className = navigator.onLine ? 'status-online' : 'status-offline';
            }
        },

        showMatchHistory: function() {
            const history = GameState.getMatchHistory();
            const container = document.getElementById('history-container');

            if (!container) return;

            if (history.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">📝</div>
                        <div class="empty-state-title">No Match History</div>
                        <div class="empty-state-text">Complete a match to see it here!</div>
                    </div>
                `;
            } else {
                container.innerHTML = history.map(match => `
                    <div class="history-item">
                        <div>
                            <div class="history-round">${match.winners?.join(' & ') || 'Unknown'}</div>
                            <div class="history-team">${GameUI.formatDate(match.date)}</div>
                        </div>
                        <div class="history-score">${match.teams[0]?.score || 0}</div>
                    </div>
                `).join('');
            }

            GameUI.showScreen('history-screen');
        },

        // ==========================================
        // LOBBY PAGE
        // ==========================================

        initLobbyPage: function() {
            this.teams = [];
            this.teamCounter = 0;

            // Add initial teams
            this.addTeam();
            this.addTeam();

            // Setup buttons
            document.getElementById('add-team-btn')?.addEventListener('click', () => {
                this.addTeam();
            });

            document.getElementById('start-game-btn')?.addEventListener('click', () => {
                this.startGame();
            });

            // Setup settings
            this.initLobbySettings();
        },

        addTeam: function() {
            this.teamCounter++;
            const teamId = this.teamCounter;

            const teamCard = document.createElement('div');
            teamCard.className = 'team-card';
            teamCard.dataset.teamId = teamId;
            teamCard.innerHTML = `
                <div class="team-card-header">
                    <span class="team-card-number">Team ${teamId}</span>
                    <button class="team-card-remove" onclick="ThirtySecondsApp.removeTeam(${teamId})">×</button>
                </div>
                <div class="form-group">
                    <input type="text" class="form-input team-name-input" placeholder="Team name (optional)">
                </div>
                <div class="team-players">
                    <div class="player-input-group">
                        <label class="player-label">Player A</label>
                        <input type="text" class="player-input player-a-input" placeholder="Name" required>
                    </div>
                    <div class="player-input-group">
                        <label class="player-label">Player B</label>
                        <input type="text" class="player-input player-b-input" placeholder="Name" required>
                    </div>
                </div>
            `;

            document.getElementById('team-list')?.appendChild(teamCard);
            this.teams.push({ id: teamId });
        },

        removeTeam: function(teamId) {
            if (this.teams.length <= 2) {
                GameUI.showToast('Minimum 2 teams required', 'error');
                return;
            }

            const card = document.querySelector(`.team-card[data-team-id="${teamId}"]`);
            if (card) {
                card.remove();
                this.teams = this.teams.filter(t => t.id !== teamId);
            }
        },

        initLobbySettings: function() {
            // Target score stepper
            const scoreValue = document.getElementById('target-score-value');
            document.getElementById('score-minus')?.addEventListener('click', () => {
                let val = parseInt(scoreValue.textContent) || 30;
                val = Math.max(10, val - 5);
                scoreValue.textContent = val;
            });
            document.getElementById('score-plus')?.addEventListener('click', () => {
                let val = parseInt(scoreValue.textContent) || 30;
                val = Math.min(100, val + 5);
                scoreValue.textContent = val;
            });

            // Max rounds stepper
            const roundsValue = document.getElementById('max-rounds-value');
            document.getElementById('rounds-minus')?.addEventListener('click', () => {
                let val = parseInt(roundsValue.textContent) || 10;
                val = Math.max(3, val - 1);
                roundsValue.textContent = val;
            });
            document.getElementById('rounds-plus')?.addEventListener('click', () => {
                let val = parseInt(roundsValue.textContent) || 10;
                val = Math.min(30, val + 1);
                roundsValue.textContent = val;
            });

            // Strict mode toggle
            document.getElementById('strict-mode-toggle')?.addEventListener('change', (e) => {
                GameState.saveSettings({ strictMode: e.target.checked });
            });

            // End condition radio
            document.querySelectorAll('input[name="end-condition"]').forEach(radio => {
                radio.addEventListener('change', (e) => {
                    const isScore = e.target.value === 'score';
                    document.getElementById('score-setting').classList.toggle('hidden', !isScore);
                    document.getElementById('rounds-setting').classList.toggle('hidden', isScore);
                });
            });
        },

        startGame: function() {
            // Collect team data
            const teamCards = document.querySelectorAll('.team-card');
            const teams = [];

            let valid = true;
            teamCards.forEach(card => {
                const teamName = card.querySelector('.team-name-input')?.value.trim();
                const playerA = card.querySelector('.player-a-input')?.value.trim();
                const playerB = card.querySelector('.player-b-input')?.value.trim();

                if (!playerA || !playerB) {
                    valid = false;
                    return;
                }

                teams.push({
                    name: teamName || `${playerA} & ${playerB}`,
                    playerA: playerA,
                    playerB: playerB
                });
            });

            if (!valid || teams.length < 2) {
                GameUI.showToast('Please fill in all player names', 'error');
                return;
            }

            // Get settings
            const endCondition = document.querySelector('input[name="end-condition"]:checked')?.value || 'score';
            const targetScore = parseInt(document.getElementById('target-score-value')?.textContent) || 30;
            const maxRounds = parseInt(document.getElementById('max-rounds-value')?.textContent) || 10;

            // Create match
            GameState.createMatch(teams, {
                endCondition: endCondition,
                targetScore: targetScore,
                maxRounds: maxRounds
            });

            // Navigate to turn
            window.location.href = 'turn.php';
        },

        // ==========================================
        // TURN PAGE
        // ==========================================

        initTurnPage: function() {
            if (!GameState.match || GameState.match.status !== 'active') {
                window.location.href = 'index.php';
                return;
            }

            // Initialize modules
            WordMatcher.init({ strictMode: GameState.settings.strictMode });

            // Setup speech recognition
            const speechSupported = SpeechRecognition.init({
                onResult: (result) => this.handleSpeechResult(result),
                onForbiddenWord: (data) => this.handleForbiddenWord(data),
                onNumberDetected: (num) => this.handleNumberDetected(num),
                onStateChange: (state) => this.updateSpeechStatus(state),
                onError: (error) => this.handleSpeechError(error)
            });

            // Update speech status display
            this.updateSpeechSupport(speechSupported);

            // Check if turn is in progress
            if (GameState.match.currentTurn && GameState.match.currentTurn.startedAt) {
                // Resume turn
                this.resumeTurn();
            } else {
                // Start new turn
                this.showGateScreen();
            }

            // Setup event listeners
            this.setupTurnEventListeners();
        },

        showGateScreen: function() {
            GameUI.showScreen('gate-screen');

            const team = GameState.getCurrentTeam();
            const explainer = GameState.getCurrentExplainer();

            document.getElementById('gate-team-name').textContent = team.name;
            document.getElementById('gate-explainer-name').textContent = explainer;

            document.getElementById('reveal-card-btn')?.addEventListener('click', () => {
                this.startNewTurn();
            }, { once: true });
        },

        startNewTurn: function() {
            // Create turn state
            GameState.startTurn();

            // Show gameplay screen
            GameUI.showScreen('gameplay-screen');

            // Render items
            this.renderItems();

            // Initialize timer
            GameTimer.init({
                duration: GameState.match.settings.turnDuration,
                onTick: (remaining) => this.handleTimerTick(remaining),
                onWarning: () => GameUI.haptic('warning'),
                onDanger: () => GameUI.haptic('warning'),
                onComplete: () => this.endTurn()
            });

            // Show start button
            document.getElementById('start-timer-btn')?.classList.remove('hidden');
        },

        resumeTurn: function() {
            GameUI.showScreen('gameplay-screen');
            this.renderItems();

            // Calculate remaining time
            const turn = GameState.match.currentTurn;
            const elapsed = (Date.now() - new Date(turn.startedAt).getTime()) / 1000;
            const remaining = Math.max(0, GameState.match.settings.turnDuration - elapsed);

            if (remaining <= 0) {
                this.endTurn();
                return;
            }

            // Initialize timer with remaining time
            GameTimer.init({
                duration: remaining,
                onTick: (remaining) => this.handleTimerTick(remaining),
                onComplete: () => this.endTurn()
            });

            // Auto-start since we're resuming
            this.startTimer();
        },

        startTimer: function() {
            GameState.beginTurnTimer();
            GameTimer.start();
            this.turnActive = true;

            document.getElementById('start-timer-btn')?.classList.add('hidden');

            // Start speech recognition
            const items = GameState.match.currentTurn.items;
            const focusedIndex = GameState.match.currentTurn.focusedIndex;
            SpeechRecognition.start(items, focusedIndex);

            GameUI.haptic('medium');
        },

        renderItems: function() {
            const container = document.getElementById('items-list');
            const turn = GameState.match.currentTurn;

            if (!container || !turn) return;

            container.innerHTML = turn.items.map((item, index) => `
                <div class="item-card ${item.status !== 'normal' ? item.status : ''} ${index === turn.focusedIndex ? 'focused' : ''}"
                     data-index="${index}"
                     onclick="ThirtySecondsApp.handleItemClick(${index})">
                    <div class="item-number">${index + 1}</div>
                    <div class="item-content">
                        <div class="item-text">${item.text}</div>
                        <div class="item-forbidden">${WordMatcher.getForbiddenDisplay(item.text)}</div>
                    </div>
                </div>
            `).join('');
        },

        handleItemClick: function(index) {
            const turn = GameState.match.currentTurn;
            if (!turn || !this.turnActive) return;

            const item = turn.items[index];

            if (item.status === 'normal') {
                // If clicking focused item, mark correct
                if (index === turn.focusedIndex) {
                    this.markCorrect(index);
                } else {
                    // Switch focus
                    this.setFocus(index);
                }
            }
        },

        setFocus: function(index) {
            GameState.setFocusedItem(index);
            SpeechRecognition.updateContext(GameState.match.currentTurn.items, index);
            this.renderItems();
            GameUI.haptic('light');
        },

        markCorrect: function(index) {
            if (GameState.markItemCorrect(index)) {
                GameTimer.triggerHaptic('correct');
                this.renderItems();
                this.advanceToNextItem();
            }
        },

        strikeItem: function(index) {
            if (GameState.strikeItem(index)) {
                GameTimer.triggerHaptic('strike');
                this.renderItems();

                if (GameState.settings.autoAdvanceOnStrike) {
                    this.advanceToNextItem();
                }
            }
        },

        advanceToNextItem: function() {
            const turn = GameState.match.currentTurn;
            // Find next normal item
            for (let i = 0; i < 5; i++) {
                const nextIndex = (turn.focusedIndex + 1 + i) % 5;
                if (turn.items[nextIndex].status === 'normal') {
                    this.setFocus(nextIndex);
                    return;
                }
            }
        },

        handleTimerTick: function(remaining) {
            // Update any additional UI if needed
        },

        handleSpeechResult: function(result) {
            const transcriptEl = document.getElementById('transcript-text');
            if (transcriptEl) {
                transcriptEl.textContent = result.full || 'Listening...';
                transcriptEl.className = result.interim ? 'transcript-text interim' : 'transcript-text';
            }
        },

        handleForbiddenWord: function(data) {
            console.log('Forbidden word detected:', data);
            this.strikeItem(data.index);
            GameUI.showToast(`"${data.matchedWords[0]}" is forbidden!`, 'error', 2000);
        },

        handleNumberDetected: function(num) {
            if (num >= 1 && num <= 5) {
                this.setFocus(num - 1);
            }
        },

        updateSpeechSupport: function(supported) {
            const statusEl = document.getElementById('speech-status');
            if (statusEl) {
                if (!supported) {
                    statusEl.innerHTML = `<span class="mic-indicator"></span> Manual mode (speech not supported)`;
                    statusEl.classList.add('error');
                }
            }
        },

        updateSpeechStatus: function(state) {
            const statusEl = document.getElementById('speech-status');
            const indicator = statusEl?.querySelector('.mic-indicator');

            if (indicator) {
                indicator.classList.toggle('listening', state.isListening);
            }

            if (statusEl) {
                statusEl.classList.toggle('active', state.isListening);
                statusEl.classList.toggle('error', state.state === 'error');
            }
        },

        handleSpeechError: function(error) {
            console.warn('Speech error:', error);
            // Don't show toast for common transient errors
            if (error.error !== 'no-speech' && error.error !== 'aborted') {
                GameUI.showToast(error.message, 'error', 3000);
            }
        },

        setupTurnEventListeners: function() {
            document.getElementById('start-timer-btn')?.addEventListener('click', () => {
                this.startTimer();
            });

            document.getElementById('undo-btn')?.addEventListener('click', () => {
                if (GameState.undoLastAction()) {
                    this.renderItems();
                    GameUI.showToast('Undone', 'info', 1000);
                }
            });

            document.getElementById('end-turn-btn')?.addEventListener('click', () => {
                this.endTurn();
            });
        },

        endTurn: function() {
            this.turnActive = false;
            GameTimer.stop();
            SpeechRecognition.stop();

            const result = GameState.endTurn();

            // Check if match is over
            if (GameState.isMatchOver()) {
                GameState.completeMatch();
                window.location.href = 'results.php';
            } else {
                window.location.href = 'scoreboard.php';
            }
        },

        // ==========================================
        // SCOREBOARD PAGE
        // ==========================================

        initScoreboardPage: function() {
            if (!GameState.match) {
                window.location.href = 'index.php';
                return;
            }

            this.renderScoreboard();
            this.renderNextTurnInfo();
            this.renderLastTurnSummary();

            document.getElementById('next-turn-btn')?.addEventListener('click', () => {
                window.location.href = 'turn.php';
            });

            document.getElementById('show-qr-btn')?.addEventListener('click', () => {
                this.showViewerQR();
            });
        },

        renderScoreboard: function() {
            const container = document.getElementById('scoreboard');
            const teams = GameState.getTeamsByScore();
            const targetScore = GameState.match.settings.targetScore;

            container.innerHTML = teams.map((team, index) => {
                const isLeader = index === 0;
                const rankClass = index === 0 ? 'gold' : index === 1 ? 'silver' : index === 2 ? 'bronze' : '';

                return `
                    <div class="score-row ${isLeader ? 'active' : ''}">
                        <div class="score-team">
                            <div class="score-rank ${rankClass}">${index + 1}</div>
                            <span class="score-name">${team.name}</span>
                        </div>
                        <div class="score-value">${team.score}</div>
                    </div>
                `;
            }).join('');
        },

        renderNextTurnInfo: function() {
            const team = GameState.getCurrentTeam();
            const explainer = GameState.getCurrentExplainer();
            const guesser = GameState.getCurrentGuesser();

            document.getElementById('next-team-name').textContent = team.name;
            document.getElementById('next-explainer').textContent = explainer;
            document.getElementById('next-guesser').textContent = guesser;
        },

        renderLastTurnSummary: function() {
            const history = GameState.match.turnHistory;
            if (history.length === 0) return;

            const lastTurn = history[history.length - 1];
            const container = document.getElementById('last-turn-summary');

            if (container) {
                container.innerHTML = `
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">Last Turn: ${lastTurn.teamName}</div>
                            <div class="card-subtitle">${lastTurn.explainer} explained, ${lastTurn.guesser} guessed</div>
                        </div>
                        <div class="history-list">
                            ${lastTurn.items.map(item => `
                                <div class="history-item">
                                    <span class="history-round ${item.status}">${item.text}</span>
                                    <span class="history-score ${item.status}">${
                                        item.status === 'correct' ? '✓' :
                                        item.status === 'struck' ? '✗' : '○'
                                    }</span>
                                </div>
                            `).join('')}
                        </div>
                        <div class="mt-md text-center">
                            <strong>+${lastTurn.correct} points</strong>
                        </div>
                    </div>
                `;
            }
        },

        showViewerQR: function() {
            const stateString = GameState.generateStateString();
            const viewerUrl = `${window.location.origin}/games/30_seconds_offline/view.php?state=${stateString}`;

            const modal = GameUI.showModal({
                title: 'Spectator View',
                content: `
                    <div id="qr-container" class="qr-container"></div>
                    <div class="text-center mt-md">
                        <small style="color: var(--text-muted);">Scan to view current game state</small>
                    </div>
                `,
                buttons: [{ label: 'Close' }]
            });

            setTimeout(() => {
                const qrContainer = document.getElementById('qr-container');
                if (qrContainer) {
                    GameUI.createQRCode(qrContainer, viewerUrl);
                }
            }, 100);
        },

        // ==========================================
        // RESULTS PAGE
        // ==========================================

        initResultsPage: function() {
            if (!GameState.match || GameState.match.status !== 'completed') {
                window.location.href = 'index.php';
                return;
            }

            // Fire confetti
            setTimeout(() => GameUI.fireConfetti(), 300);

            this.renderWinner();
            this.renderMVP();
            this.renderFinalScoreboard();

            document.getElementById('share-btn')?.addEventListener('click', () => {
                this.shareResults();
            });

            document.getElementById('new-match-btn')?.addEventListener('click', () => {
                GameState.clearCurrentMatch();
                window.location.href = 'lobby.php';
            });

            document.getElementById('home-btn')?.addEventListener('click', () => {
                GameState.clearCurrentMatch();
                window.location.href = 'index.php';
            });
        },

        renderWinner: function() {
            const winners = GameState.getWinners();
            const maxScore = Math.max(...GameState.match.teams.map(t => t.score));

            document.getElementById('winner-name').textContent = winners.map(w => w.name).join(' & ');
            document.getElementById('winner-score').textContent = maxScore;
        },

        renderMVP: function() {
            const mvp = GameState.getMVP();
            const container = document.getElementById('mvp-container');

            if (mvp && container) {
                container.innerHTML = `
                    <div class="mvp-card">
                        <div class="mvp-icon">⭐</div>
                        <div class="mvp-info">
                            <div class="mvp-label">MVP - Best Explainer</div>
                            <div class="mvp-name">${mvp.name}</div>
                            <div class="mvp-stat">${mvp.correct} correct in ${mvp.turns} turns</div>
                        </div>
                    </div>
                `;
            }
        },

        renderFinalScoreboard: function() {
            const container = document.getElementById('final-scoreboard');
            const teams = GameState.getTeamsByScore();
            const maxScore = Math.max(...teams.map(t => t.score));

            container.innerHTML = teams.map((team, index) => {
                const isWinner = team.score === maxScore;
                const rankClass = index === 0 ? 'gold' : index === 1 ? 'silver' : index === 2 ? 'bronze' : '';

                return `
                    <div class="score-row ${isWinner ? 'winner' : ''}">
                        <div class="score-team">
                            <div class="score-rank ${rankClass}">${index + 1}</div>
                            <span class="score-name">${team.name}</span>
                        </div>
                        <div class="score-value">${team.score}</div>
                    </div>
                `;
            }).join('');
        },

        shareResults: async function() {
            ShareCard.generateResultCard(GameState.match);
            const shared = await ShareCard.share('30 Seconds Party Results');

            if (!shared) {
                GameUI.showToast('Image saved to downloads', 'success');
            }
        },

        // ==========================================
        // VIEW PAGE (Spectator)
        // ==========================================

        initViewPage: function() {
            const params = new URLSearchParams(window.location.search);
            const stateString = params.get('state');

            if (!stateString) {
                this.showViewerError('No state provided');
                return;
            }

            const state = GameState.parseStateString(stateString);
            if (!state) {
                this.showViewerError('Invalid state data');
                return;
            }

            this.renderViewerState(state);
        },

        showViewerError: function(message) {
            document.getElementById('viewer-content').innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">❌</div>
                    <div class="empty-state-title">Error</div>
                    <div class="empty-state-text">${message}</div>
                </div>
            `;
        },

        renderViewerState: function(state) {
            const container = document.getElementById('viewer-content');

            // Render teams
            let teamsHtml = state.t.map((team, index) => `
                <div class="score-row">
                    <div class="score-team">
                        <div class="score-rank">${index + 1}</div>
                        <span class="score-name">${team.n}</span>
                    </div>
                    <div class="score-value">${team.s}</div>
                </div>
            `).join('');

            // Render current turn if active
            let turnHtml = '';
            if (state.ct) {
                const statusMap = { 'n': 'normal', 's': 'struck', 'c': 'correct' };
                turnHtml = `
                    <div class="card mt-lg">
                        <div class="card-header">
                            <div class="card-title">Current Turn</div>
                            <div class="card-subtitle">${state.ct.ex} is explaining</div>
                        </div>
                        <div class="items-list">
                            ${state.ct.it.map((status, index) => `
                                <div class="item-card ${statusMap[status]}">
                                    <div class="item-number">${index + 1}</div>
                                    <div class="item-text">${statusMap[status] === 'correct' ? '✓' : statusMap[status] === 'struck' ? '✗' : '?'}</div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }

            container.innerHTML = `
                <div class="spectator-banner">
                    <div class="spectator-banner-title">🔇 KEEP QUIET</div>
                    <div class="spectator-banner-text">You're viewing as a spectator. This is a snapshot, not live.</div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Scoreboard</div>
                        <div class="card-subtitle">Match #${state.id}</div>
                    </div>
                    <div class="scoreboard">
                        ${teamsHtml}
                    </div>
                </div>
                ${turnHtml}
                <div class="mt-lg text-center">
                    <small style="color: var(--text-muted);">Turn ${state.ti + 1}</small>
                </div>
            `;
        }
    };
})();
