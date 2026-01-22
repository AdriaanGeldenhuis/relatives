/**
 * NEON NIBBLER - Main Game Orchestrator
 * Initializes all modules and handles UI flow
 */
(function() {
    'use strict';

    var prefs;

    function init() {
        prefs = NeonStorage.getPrefs();

        // Initialize modules
        NeonUI.init();
        NeonAudio.init();
        NeonAudio.setEnabled(prefs.sound);
        NeonInput.init();

        var canvas = document.getElementById('game-canvas');
        NeonEngine.init(canvas);

        // Apply preferences
        NeonUI.setTheme(prefs.theme);
        NeonInput.setDpadVisible(prefs.dpad);
        updateToggleButtons();

        // Button handlers
        setupButtons();

        // Input pause callback
        NeonInput.onPause(function() {
            if (NeonEngine.getState() === 'playing') {
                NeonEngine.pause();
            }
        });

        // Sync any queued scores
        NeonAPI.syncQueue();

        // Show start screen
        NeonUI.showScreen('start');

        // Update best scores from storage (may be higher than server)
        var best = NeonStorage.getBest();
        if (best.score > window.NEON_USER.bestScore) {
            document.getElementById('start-best-score').textContent = best.score.toLocaleString();
        }
        if (best.level > window.NEON_USER.bestLevel) {
            document.getElementById('start-best-level').textContent = best.level;
        }
    }

    function setupButtons() {
        // Play
        document.getElementById('btn-play').addEventListener('click', function() {
            NeonAudio.init();
            NeonAudio.resume();
            NeonAudio.menuSelect();
            startGame();
        });

        // Pause
        document.getElementById('btn-pause').addEventListener('click', function() {
            NeonEngine.pause();
        });

        // Resume
        document.getElementById('btn-resume').addEventListener('click', function() {
            NeonAudio.menuSelect();
            NeonEngine.resume();
        });

        // Quit
        document.getElementById('btn-quit').addEventListener('click', function() {
            NeonAudio.menuSelect();
            NeonEngine.reset();
            NeonUI.showScreen('start');
        });

        // Retry
        document.getElementById('btn-retry').addEventListener('click', function() {
            NeonAudio.menuSelect();
            startGame();
        });

        // Home
        document.getElementById('btn-home').addEventListener('click', function() {
            NeonAudio.menuSelect();
            NeonEngine.reset();
            NeonUI.showScreen('start');
            updateStartStats();
        });

        // Theme toggle
        document.getElementById('btn-theme-toggle').addEventListener('click', function() {
            prefs.theme = prefs.theme === 'dark' ? 'light' : 'dark';
            NeonStorage.savePrefs(prefs);
            NeonUI.setTheme(prefs.theme);
            updateToggleButtons();
            NeonAudio.menuSelect();
        });

        // Sound toggle
        document.getElementById('btn-sound-toggle').addEventListener('click', function() {
            prefs.sound = !prefs.sound;
            NeonStorage.savePrefs(prefs);
            NeonAudio.setEnabled(prefs.sound);
            updateToggleButtons();
            if (prefs.sound) {
                NeonAudio.init();
                NeonAudio.menuSelect();
            }
        });

        // D-Pad toggle
        document.getElementById('btn-dpad-toggle').addEventListener('click', function() {
            prefs.dpad = !prefs.dpad;
            NeonStorage.savePrefs(prefs);
            NeonInput.setDpadVisible(prefs.dpad);
            updateToggleButtons();
            NeonAudio.menuSelect();
        });
    }

    function startGame() {
        NeonEngine.reset();
        NeonUI.showScreen('game');
        NeonEngine.startLevel(1);
        NeonInput.setDpadVisible(prefs.dpad);

        // Small delay before starting movement
        setTimeout(function() {
            NeonEngine.start();
        }, 500);
    }

    function updateToggleButtons() {
        var themeBtn = document.getElementById('btn-theme-toggle');
        var soundBtn = document.getElementById('btn-sound-toggle');
        var dpadBtn = document.getElementById('btn-dpad-toggle');

        if (themeBtn) themeBtn.textContent = 'Theme: ' + (prefs.theme === 'dark' ? 'Dark' : 'Light');
        if (soundBtn) soundBtn.textContent = 'Sound: ' + (prefs.sound ? 'On' : 'Off');
        if (dpadBtn) dpadBtn.textContent = 'D-Pad: ' + (prefs.dpad ? 'On' : 'Off');
    }

    function updateStartStats() {
        var best = NeonStorage.getBest();
        document.getElementById('start-best-score').textContent = best.score.toLocaleString();
        document.getElementById('start-best-level').textContent = best.level || 0;
    }

    // Visibility change - auto pause
    document.addEventListener('visibilitychange', function() {
        if (document.hidden && NeonEngine.getState() === 'playing') {
            NeonEngine.pause();
        }
    });

    // Init when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
