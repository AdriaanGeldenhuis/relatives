/**
 * NEON NIBBLER - UI Manager
 * Screen transitions, HUD updates, score popups
 */
var NeonUI = (function() {
    'use strict';

    var screens = {};
    var scoreEl, levelEl, livesEl, comboEl, pulseTimer, pulseBar;

    function init() {
        screens.start = document.getElementById('screen-start');
        screens.game = document.getElementById('screen-game');
        screens.pause = document.getElementById('screen-pause');
        screens.results = document.getElementById('screen-results');
        screens.levelComplete = document.getElementById('screen-level-complete');

        scoreEl = document.getElementById('hud-score-value');
        levelEl = document.getElementById('hud-level-value');
        livesEl = document.getElementById('hud-lives');
        comboEl = document.getElementById('hud-combo');
        pulseTimer = document.getElementById('pulse-timer');
        pulseBar = document.getElementById('pulse-timer-bar');
    }

    function showScreen(name) {
        for (var key in screens) {
            if (screens[key]) {
                screens[key].classList.remove('active');
            }
        }
        if (screens[name]) {
            screens[name].classList.add('active');
        }
        // Game screen always stays active behind overlays
        if (name === 'pause' || name === 'results' || name === 'levelComplete') {
            screens.game.classList.add('active');
        }
    }

    function hideOverlay(name) {
        if (screens[name]) {
            screens[name].classList.remove('active');
        }
    }

    function updateScore(score) {
        if (scoreEl) {
            scoreEl.textContent = score.toLocaleString();
            scoreEl.classList.add('bump');
            setTimeout(function() { scoreEl.classList.remove('bump'); }, 100);
        }
    }

    function updateLevel(level) {
        if (levelEl) levelEl.textContent = level;
    }

    function updateLives(lives, maxLives) {
        if (!livesEl) return;
        var html = '';
        for (var i = 0; i < maxLives; i++) {
            html += '<div class="hud-life' + (i >= lives ? ' lost' : '') + '"></div>';
        }
        livesEl.innerHTML = html;
    }

    function showCombo(multiplier) {
        if (!comboEl) return;
        document.getElementById('hud-combo-value').textContent = 'x' + multiplier;
        comboEl.style.display = 'block';
        setTimeout(function() { comboEl.style.display = 'none'; }, 1500);
    }

    function showPulseTimer(show) {
        if (pulseTimer) pulseTimer.style.display = show ? 'block' : 'none';
        var wrapper = document.getElementById('game-wrapper');
        if (wrapper) wrapper.classList.toggle('pulse-mode', show);
    }

    function updatePulseTimer(fraction) {
        if (pulseBar) pulseBar.style.width = (fraction * 100) + '%';
    }

    function showScorePopup(x, y, text, color) {
        var popup = document.createElement('div');
        popup.className = 'score-popup';
        popup.textContent = text;
        popup.style.left = x + 'px';
        popup.style.top = y + 'px';
        if (color) popup.style.color = color;
        document.getElementById('game-wrapper').appendChild(popup);
        setTimeout(function() { popup.remove(); }, 800);
    }

    function showResults(data) {
        document.getElementById('results-title').textContent = data.title || 'GAME OVER';
        document.getElementById('result-score').textContent = data.score.toLocaleString();
        document.getElementById('result-level').textContent = data.level;
        document.getElementById('result-dots').textContent = data.dots;

        var secs = Math.floor(data.duration / 1000);
        var mins = Math.floor(secs / 60);
        secs = secs % 60;
        document.getElementById('result-time').textContent = mins + ':' + (secs < 10 ? '0' : '') + secs;

        var bestEl = document.getElementById('result-new-best');
        bestEl.style.display = data.newBest ? 'block' : 'none';

        var syncEl = document.getElementById('result-sync-status');
        syncEl.textContent = data.synced ? 'Score synced' : 'Saved locally';

        showScreen('results');
    }

    function showLevelComplete(levelBonus, timeBonus, nextLevel) {
        document.getElementById('level-bonus-value').textContent = '+' + levelBonus;
        document.getElementById('time-bonus-value').textContent = '+' + timeBonus;
        document.getElementById('next-level-text').textContent = 'Get ready for Level ' + nextLevel + '...';
        showScreen('levelComplete');
    }

    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        var btn = document.getElementById('btn-theme-toggle');
        if (btn) btn.textContent = 'Theme: ' + (theme === 'dark' ? 'Dark' : 'Light');
    }

    return {
        init: init,
        showScreen: showScreen,
        hideOverlay: hideOverlay,
        updateScore: updateScore,
        updateLevel: updateLevel,
        updateLives: updateLives,
        showCombo: showCombo,
        showPulseTimer: showPulseTimer,
        updatePulseTimer: updatePulseTimer,
        showScorePopup: showScorePopup,
        showResults: showResults,
        showLevelComplete: showLevelComplete,
        setTheme: setTheme
    };
})();
