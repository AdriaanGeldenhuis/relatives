/**
 * 30 Seconds Party - State Management Module
 * Handles all game state, localStorage persistence, and match logic
 */

(function() {
    'use strict';

    const STORAGE_KEYS = {
        DEVICE_ID: 'thirty_seconds_device_id',
        CURRENT_MATCH: 'thirty_seconds_current_match',
        MATCH_HISTORY: 'thirty_seconds_match_history',
        SETTINGS: 'thirty_seconds_settings',
        PROMPTS_CACHE: 'thirty_seconds_prompts'
    };

    const DEFAULT_SETTINGS = {
        theme: 'dark',
        strictMode: false,
        targetScore: 30,
        maxRounds: 10,
        turnDuration: 30,
        autoAdvanceOnStrike: true,
        experimentalGuesserMic: false
    };

    // Generate a unique device ID
    function generateDeviceId() {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        let result = '';
        for (let i = 0; i < 32; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    }

    // Seeded random number generator for deterministic deck shuffling
    function seededRandom(seed) {
        const x = Math.sin(seed++) * 10000;
        return x - Math.floor(x);
    }

    // Shuffle array with seed for reproducibility
    function shuffleWithSeed(array, seed) {
        const shuffled = [...array];
        for (let i = shuffled.length - 1; i > 0; i--) {
            const j = Math.floor(seededRandom(seed + i) * (i + 1));
            [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
        }
        return shuffled;
    }

    window.GameState = {
        // Current match state
        match: null,

        // Loaded prompts
        prompts: null,

        // Settings
        settings: { ...DEFAULT_SETTINGS },

        /**
         * Initialize the state module
         */
        init: async function() {
            this.loadSettings();
            this.loadDeviceId();
            await this.loadPrompts();
            this.loadCurrentMatch();
        },

        /**
         * Get or create device ID
         */
        loadDeviceId: function() {
            let deviceId = localStorage.getItem(STORAGE_KEYS.DEVICE_ID);
            if (!deviceId) {
                deviceId = generateDeviceId();
                localStorage.setItem(STORAGE_KEYS.DEVICE_ID, deviceId);
            }
            this.deviceId = deviceId;
            return deviceId;
        },

        /**
         * Load settings from localStorage
         */
        loadSettings: function() {
            try {
                const stored = localStorage.getItem(STORAGE_KEYS.SETTINGS);
                if (stored) {
                    this.settings = { ...DEFAULT_SETTINGS, ...JSON.parse(stored) };
                }
            } catch (e) {
                console.warn('Failed to load settings:', e);
                this.settings = { ...DEFAULT_SETTINGS };
            }
            this.applyTheme();
            return this.settings;
        },

        /**
         * Save settings to localStorage
         */
        saveSettings: function(newSettings = {}) {
            this.settings = { ...this.settings, ...newSettings };
            try {
                localStorage.setItem(STORAGE_KEYS.SETTINGS, JSON.stringify(this.settings));
            } catch (e) {
                console.warn('Failed to save settings:', e);
            }
            this.applyTheme();
        },

        /**
         * Apply current theme
         */
        applyTheme: function() {
            document.documentElement.setAttribute('data-theme', this.settings.theme);
        },

        /**
         * Toggle theme
         */
        toggleTheme: function() {
            const newTheme = this.settings.theme === 'dark' ? 'light' : 'dark';
            this.saveSettings({ theme: newTheme });
        },

        /**
         * Load prompts from JSON file or cache
         */
        loadPrompts: async function() {
            try {
                // Try to load from cache first
                const cached = localStorage.getItem(STORAGE_KEYS.PROMPTS_CACHE);
                if (cached) {
                    this.prompts = JSON.parse(cached);
                }

                // Fetch fresh prompts (will update cache)
                const response = await fetch('/games/30_seconds_offline/prompts/prompts.json');
                if (response.ok) {
                    this.prompts = await response.json();
                    try {
                        localStorage.setItem(STORAGE_KEYS.PROMPTS_CACHE, JSON.stringify(this.prompts));
                    } catch (e) {
                        // Cache quota exceeded, continue without caching
                    }
                }
            } catch (e) {
                console.warn('Failed to load prompts:', e);
                // Use cached version if available
                if (!this.prompts) {
                    throw new Error('No prompts available. Please ensure you are online for first load.');
                }
            }
            return this.prompts;
        },

        /**
         * Load current match from localStorage
         */
        loadCurrentMatch: function() {
            try {
                const stored = localStorage.getItem(STORAGE_KEYS.CURRENT_MATCH);
                if (stored) {
                    this.match = JSON.parse(stored);
                }
            } catch (e) {
                console.warn('Failed to load current match:', e);
                this.match = null;
            }
            return this.match;
        },

        /**
         * Save current match to localStorage
         */
        saveCurrentMatch: function() {
            if (this.match) {
                try {
                    localStorage.setItem(STORAGE_KEYS.CURRENT_MATCH, JSON.stringify(this.match));
                } catch (e) {
                    console.warn('Failed to save current match:', e);
                }
            }
        },

        /**
         * Create a new match
         */
        createMatch: function(teams, options = {}) {
            const seed = Date.now();
            const settings = { ...this.settings, ...options };

            // Shuffle prompts with seed for reproducibility
            const promptIds = this.prompts.prompts.map(p => p.id);
            const shuffledDeck = shuffleWithSeed(promptIds, seed);

            this.match = {
                id: generateDeviceId().substring(0, 8),
                seed: seed,
                createdAt: new Date().toISOString(),
                status: 'active', // active, paused, completed

                // Teams configuration
                teams: teams.map((team, index) => ({
                    id: index,
                    name: team.name || `Team ${index + 1}`,
                    players: [team.playerA, team.playerB],
                    score: 0,
                    turnsPlayed: 0,
                    correctItems: 0,
                    struckItems: 0
                })),

                // Game settings
                settings: {
                    targetScore: settings.targetScore,
                    maxRounds: settings.maxRounds,
                    turnDuration: settings.turnDuration,
                    strictMode: settings.strictMode,
                    endCondition: options.endCondition || 'score' // 'score' or 'rounds'
                },

                // Deck and turn tracking
                deck: shuffledDeck,
                deckIndex: 0,
                currentTurnIndex: 0,

                // Current turn state
                currentTurn: null,

                // History of all turns
                turnHistory: []
            };

            this.saveCurrentMatch();
            return this.match;
        },

        /**
         * Get current team for this turn
         */
        getCurrentTeam: function() {
            if (!this.match) return null;
            const teamIndex = this.match.currentTurnIndex % this.match.teams.length;
            return this.match.teams[teamIndex];
        },

        /**
         * Get current explainer for this turn
         */
        getCurrentExplainer: function() {
            const team = this.getCurrentTeam();
            if (!team) return null;
            // Alternate between players: even turns = player A, odd turns = player B
            const playerIndex = Math.floor(this.match.currentTurnIndex / this.match.teams.length) % 2;
            return team.players[playerIndex];
        },

        /**
         * Get current guesser for this turn
         */
        getCurrentGuesser: function() {
            const team = this.getCurrentTeam();
            if (!team) return null;
            const playerIndex = Math.floor(this.match.currentTurnIndex / this.match.teams.length) % 2;
            return team.players[1 - playerIndex]; // Opposite of explainer
        },

        /**
         * Get the next prompt card from the deck
         */
        getNextCard: function() {
            if (!this.match || !this.prompts) return null;

            // Check if deck is exhausted
            if (this.match.deckIndex >= this.match.deck.length) {
                // Reshuffle with new seed
                const newSeed = this.match.seed + this.match.deckIndex;
                const promptIds = this.prompts.prompts.map(p => p.id);
                this.match.deck = shuffleWithSeed(promptIds, newSeed);
                this.match.deckIndex = 0;
            }

            const promptId = this.match.deck[this.match.deckIndex];
            const prompt = this.prompts.prompts.find(p => p.id === promptId);
            this.match.deckIndex++;
            this.saveCurrentMatch();

            return prompt;
        },

        /**
         * Start a new turn
         */
        startTurn: function() {
            const card = this.getNextCard();
            if (!card) return null;

            const team = this.getCurrentTeam();
            const explainer = this.getCurrentExplainer();

            this.match.currentTurn = {
                teamId: team.id,
                explainer: explainer,
                guesser: this.getCurrentGuesser(),
                card: card,
                items: card.items.map((item, index) => ({
                    index: index,
                    text: item,
                    status: 'normal', // normal, struck, correct
                    timestamp: null
                })),
                focusedIndex: 0,
                startedAt: null,
                endedAt: null,
                timeRemaining: this.match.settings.turnDuration,
                actions: [] // Log of all actions
            };

            this.saveCurrentMatch();
            return this.match.currentTurn;
        },

        /**
         * Mark turn as actually started (timer running)
         */
        beginTurnTimer: function() {
            if (this.match && this.match.currentTurn) {
                this.match.currentTurn.startedAt = new Date().toISOString();
                this.saveCurrentMatch();
            }
        },

        /**
         * Set focused item
         */
        setFocusedItem: function(index) {
            if (!this.match || !this.match.currentTurn) return;
            if (index < 0 || index > 4) return;

            this.match.currentTurn.focusedIndex = index;
            this.match.currentTurn.actions.push({
                type: 'focus',
                index: index,
                timestamp: Date.now()
            });
            this.saveCurrentMatch();
        },

        /**
         * Mark an item as correct
         */
        markItemCorrect: function(index) {
            if (!this.match || !this.match.currentTurn) return false;
            const item = this.match.currentTurn.items[index];

            if (item.status !== 'normal') return false;

            item.status = 'correct';
            item.timestamp = Date.now();

            this.match.currentTurn.actions.push({
                type: 'correct',
                index: index,
                timestamp: Date.now()
            });

            this.saveCurrentMatch();
            return true;
        },

        /**
         * Strike through an item (forbidden word spoken)
         */
        strikeItem: function(index) {
            if (!this.match || !this.match.currentTurn) return false;
            const item = this.match.currentTurn.items[index];

            if (item.status !== 'normal') return false;

            item.status = 'struck';
            item.timestamp = Date.now();

            this.match.currentTurn.actions.push({
                type: 'strike',
                index: index,
                timestamp: Date.now()
            });

            this.saveCurrentMatch();
            return true;
        },

        /**
         * Undo last action
         */
        undoLastAction: function() {
            if (!this.match || !this.match.currentTurn) return false;

            const actions = this.match.currentTurn.actions;
            if (actions.length === 0) return false;

            // Find last correct or strike action
            for (let i = actions.length - 1; i >= 0; i--) {
                const action = actions[i];
                if (action.type === 'correct' || action.type === 'strike') {
                    const item = this.match.currentTurn.items[action.index];
                    item.status = 'normal';
                    item.timestamp = null;
                    actions.splice(i, 1);
                    this.saveCurrentMatch();
                    return true;
                }
            }

            return false;
        },

        /**
         * End the current turn and calculate score
         */
        endTurn: function() {
            if (!this.match || !this.match.currentTurn) return null;

            const turn = this.match.currentTurn;
            turn.endedAt = new Date().toISOString();

            // Calculate turn score
            let correct = 0;
            let struck = 0;
            turn.items.forEach(item => {
                if (item.status === 'correct') correct++;
                if (item.status === 'struck') struck++;
            });

            const turnResult = {
                turnIndex: this.match.currentTurnIndex,
                teamId: turn.teamId,
                teamName: this.match.teams[turn.teamId].name,
                explainer: turn.explainer,
                guesser: turn.guesser,
                cardId: turn.card.id,
                items: turn.items.map(i => ({ text: i.text, status: i.status })),
                correct: correct,
                struck: struck,
                startedAt: turn.startedAt,
                endedAt: turn.endedAt
            };

            // Update team score
            const team = this.match.teams[turn.teamId];
            team.score += correct;
            team.turnsPlayed++;
            team.correctItems += correct;
            team.struckItems += struck;

            // Add to history
            this.match.turnHistory.push(turnResult);

            // Clear current turn
            this.match.currentTurn = null;

            // Advance turn index
            this.match.currentTurnIndex++;

            this.saveCurrentMatch();
            return turnResult;
        },

        /**
         * Check if match is over
         */
        isMatchOver: function() {
            if (!this.match) return false;

            const settings = this.match.settings;

            // Check score target
            if (settings.endCondition === 'score') {
                return this.match.teams.some(t => t.score >= settings.targetScore);
            }

            // Check rounds
            const totalTurns = this.match.teams.length * settings.maxRounds;
            return this.match.currentTurnIndex >= totalTurns;
        },

        /**
         * Get match winner(s)
         */
        getWinners: function() {
            if (!this.match) return [];

            const maxScore = Math.max(...this.match.teams.map(t => t.score));
            return this.match.teams.filter(t => t.score === maxScore);
        },

        /**
         * Get MVP (player with most correct items as explainer)
         */
        getMVP: function() {
            if (!this.match) return null;

            const playerStats = {};

            this.match.turnHistory.forEach(turn => {
                const key = `${turn.teamId}-${turn.explainer}`;
                if (!playerStats[key]) {
                    playerStats[key] = {
                        teamId: turn.teamId,
                        name: turn.explainer,
                        correct: 0,
                        turns: 0
                    };
                }
                playerStats[key].correct += turn.correct;
                playerStats[key].turns++;
            });

            const stats = Object.values(playerStats);
            if (stats.length === 0) return null;

            stats.sort((a, b) => b.correct - a.correct);
            return stats[0];
        },

        /**
         * Complete the match
         */
        completeMatch: function() {
            if (!this.match) return;

            this.match.status = 'completed';
            this.match.completedAt = new Date().toISOString();
            this.match.winners = this.getWinners().map(t => t.name);
            this.match.mvp = this.getMVP();

            // Save to history
            this.addToMatchHistory(this.match);

            // Clear current match
            this.saveCurrentMatch();
        },

        /**
         * Add match to history
         */
        addToMatchHistory: function(match) {
            try {
                let history = [];
                const stored = localStorage.getItem(STORAGE_KEYS.MATCH_HISTORY);
                if (stored) {
                    history = JSON.parse(stored);
                }

                // Add summary to history
                history.unshift({
                    id: match.id,
                    date: match.completedAt,
                    teams: match.teams.map(t => ({ name: t.name, score: t.score })),
                    winners: match.winners,
                    mvp: match.mvp,
                    totalTurns: match.turnHistory.length
                });

                // Keep only last 20 matches
                history = history.slice(0, 20);

                localStorage.setItem(STORAGE_KEYS.MATCH_HISTORY, JSON.stringify(history));
            } catch (e) {
                console.warn('Failed to save match history:', e);
            }
        },

        /**
         * Get match history
         */
        getMatchHistory: function() {
            try {
                const stored = localStorage.getItem(STORAGE_KEYS.MATCH_HISTORY);
                if (stored) {
                    return JSON.parse(stored);
                }
            } catch (e) {
                console.warn('Failed to load match history:', e);
            }
            return [];
        },

        /**
         * Clear current match
         */
        clearCurrentMatch: function() {
            this.match = null;
            localStorage.removeItem(STORAGE_KEYS.CURRENT_MATCH);
        },

        /**
         * Generate state string for QR/viewer
         */
        generateStateString: function() {
            if (!this.match) return '';

            const state = {
                v: 1, // version
                id: this.match.id,
                t: this.match.teams.map(t => ({
                    n: t.name,
                    s: t.score
                })),
                ti: this.match.currentTurnIndex,
                ct: this.match.currentTurn ? {
                    tm: this.match.currentTurn.teamId,
                    ex: this.match.currentTurn.explainer,
                    it: this.match.currentTurn.items.map(i => i.status.charAt(0)) // n/s/c
                } : null
            };

            // Encode to base64 for URL safety
            return btoa(JSON.stringify(state));
        },

        /**
         * Parse state string from QR/viewer
         */
        parseStateString: function(stateString) {
            try {
                const decoded = atob(stateString);
                return JSON.parse(decoded);
            } catch (e) {
                console.error('Failed to parse state string:', e);
                return null;
            }
        },

        /**
         * Get sorted teams by score
         */
        getTeamsByScore: function() {
            if (!this.match) return [];
            return [...this.match.teams].sort((a, b) => b.score - a.score);
        }
    };
})();
