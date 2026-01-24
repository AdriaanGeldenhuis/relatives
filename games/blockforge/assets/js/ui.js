/* =============================================
   BLOCKFORGE - UI Manager (Overlays, HUD, Screens)
   ============================================= */

var BlockUI = (function() {
    'use strict';

    var screens = {};
    var overlays = {};
    var hudElements = {};
    var currentScreen = 'menu';

    function init() {
        // Cache screen elements
        screens.menu = document.getElementById('screen-menu');
        screens.game = document.getElementById('screen-game');
        screens.family = document.getElementById('screen-family');

        // Cache overlays
        overlays.pause = document.getElementById('overlay-pause');
        overlays.results = document.getElementById('overlay-results');
        overlays.settings = document.getElementById('overlay-settings');
        overlays.leaderboard = document.getElementById('overlay-leaderboard');

        // Cache HUD elements
        hudElements.score = document.getElementById('hud-score');
        hudElements.level = document.getElementById('hud-level');
        hudElements.lines = document.getElementById('hud-lines');
        hudElements.combo = document.getElementById('hud-combo');
        hudElements.timer = document.getElementById('hud-timer');
        hudElements.timerBox = document.getElementById('timer-box');

        // Combo popup
        hudElements.comboPopup = document.getElementById('combo-popup');
    }

    function showScreen(name) {
        for (var key in screens) {
            if (screens[key]) {
                screens[key].classList.toggle('active', key === name);
            }
        }
        currentScreen = name;
    }

    function showOverlay(name) {
        for (var key in overlays) {
            if (overlays[key]) {
                overlays[key].classList.toggle('active', key === name);
            }
        }
    }

    function hideOverlay(name) {
        if (name) {
            if (overlays[name]) overlays[name].classList.remove('active');
        } else {
            for (var key in overlays) {
                if (overlays[key]) overlays[key].classList.remove('active');
            }
        }
    }

    function hideAllOverlays() {
        hideOverlay();
    }

    // HUD Updates
    function updateHUD(state) {
        if (hudElements.score) hudElements.score.textContent = formatNumber(state.score);
        if (hudElements.level) hudElements.level.textContent = state.level;
        if (hudElements.lines) hudElements.lines.textContent = state.lines;
        if (hudElements.combo) hudElements.combo.textContent = state.combo > 0 ? state.combo : '0';

        // Timer for daily/family mode
        if (state.mode === 'daily') {
            if (hudElements.timerBox) hudElements.timerBox.style.display = '';
            if (hudElements.timer) hudElements.timer.textContent = formatTime(state.dailyTimer);
        } else if (state.mode === 'family') {
            if (hudElements.timerBox) hudElements.timerBox.style.display = '';
            var familyRemaining = 30000 - state.elapsedMs;
            if (hudElements.timer) hudElements.timer.textContent = formatTime(Math.max(0, familyRemaining));
        } else {
            if (hudElements.timerBox) hudElements.timerBox.style.display = 'none';
        }

        // Combo highlight
        if (state.combo > 0) {
            hudElements.combo.parentElement.classList.add('active-combo');
        } else {
            hudElements.combo.parentElement.classList.remove('active-combo');
        }
    }

    // Combo popup animation
    function showComboPopup(combo, score) {
        var el = hudElements.comboPopup;
        if (!el) return;

        var text = '';
        if (combo >= 8) text = 'INSANE x' + combo;
        else if (combo >= 5) text = 'AMAZING x' + combo;
        else if (combo >= 3) text = 'COMBO x' + combo;
        else text = combo + 'x';

        if (score) text += '\n+' + formatNumber(score);

        el.textContent = text;
        el.classList.remove('show');
        void el.offsetWidth; // force reflow
        el.classList.add('show');

        setTimeout(function() {
            el.classList.remove('show');
        }, 800);
    }

    // Pause overlay
    function showPause(state) {
        document.getElementById('pause-score').textContent = formatNumber(state.score);
        document.getElementById('pause-level').textContent = state.level;
        document.getElementById('pause-lines').textContent = state.lines;
        showOverlay('pause');
    }

    // Results overlay
    function showResults(result) {
        document.getElementById('results-title').textContent =
            result.mode === 'family' ? 'Turn Complete!' : 'Game Over';

        document.getElementById('results-score').textContent = formatNumber(result.score);
        document.getElementById('results-lines').textContent = result.lines;
        document.getElementById('results-level').textContent = result.level;
        document.getElementById('results-combo').textContent = result.maxCombo;
        document.getElementById('results-duration').textContent = formatDuration(result.duration);

        // Badges
        var badgesEl = document.getElementById('results-badges');
        badgesEl.innerHTML = '';
        var badges = generateBadges(result);
        badges.forEach(function(badge) {
            var span = document.createElement('span');
            span.className = 'badge-item';
            span.textContent = badge;
            badgesEl.appendChild(span);
        });

        // Rank (if available from API response)
        var rankEl = document.getElementById('results-rank');
        rankEl.style.display = 'none';

        showOverlay('results');
    }

    function updateResultsRank(ranks) {
        if (!ranks) return;
        var rankEl = document.getElementById('results-rank');
        var rankVal = document.getElementById('results-rank-value');
        if (ranks.solo || ranks.global) {
            rankEl.style.display = '';
            rankVal.textContent = '#' + (ranks.solo || ranks.global || '?');
        }
    }

    // Settings overlay
    function showSettings(settings) {
        document.getElementById('setting-theme').value = settings.theme;
        document.getElementById('setting-sound').checked = settings.sound;
        document.getElementById('setting-haptics').checked = settings.haptics;
        document.getElementById('setting-controls').checked = settings.controls;
        document.getElementById('setting-ghost').checked = settings.ghost;
        showOverlay('settings');
    }

    function getSettingsValues() {
        return {
            theme: document.getElementById('setting-theme').value,
            sound: document.getElementById('setting-sound').checked,
            haptics: document.getElementById('setting-haptics').checked,
            controls: document.getElementById('setting-controls').checked,
            ghost: document.getElementById('setting-ghost').checked
        };
    }

    // Leaderboard overlay
    function showLeaderboard() {
        showOverlay('leaderboard');
    }

    function renderLeaderboard(data) {
        var list = document.getElementById('lb-list');
        if (!data || !data.entries || data.entries.length === 0) {
            list.innerHTML = '<div class="lb-empty">No scores yet. Be the first!</div>';
            return;
        }

        var html = '';
        data.entries.forEach(function(entry, idx) {
            var rankClass = idx < 3 ? ' r' + (idx + 1) : '';
            html += '<div class="lb-entry">';
            html += '<span class="lb-rank' + rankClass + '">' + (idx + 1) + '</span>';
            html += '<span class="lb-name">' + escapeHtml(entry.display_name || 'Player') + '</span>';
            html += '<span class="lb-score-val">' + formatNumber(entry.score) + '</span>';
            html += '</div>';
        });
        list.innerHTML = html;
    }

    // Family screen
    function updateFamilyScreen(data) {
        document.getElementById('family-date').textContent = data.date || 'Today';
        document.getElementById('family-lines').textContent = data.family_lines || 0;
        document.getElementById('family-members').textContent =
            (data.members_played || 0) + '/' + (data.total_members || 0);

        var turnStatus = document.getElementById('family-turn-status');
        var playBtn = document.getElementById('btn-family-play');

        if (data.your_turn_used || BlockStorage.isFamilyTurnUsed()) {
            turnStatus.textContent = 'Used Today';
            turnStatus.style.color = 'var(--neon-red)';
            playBtn.disabled = true;
            playBtn.textContent = 'Turn Used';
            playBtn.style.opacity = '0.5';
        } else {
            turnStatus.textContent = 'Available';
            turnStatus.style.color = 'var(--neon-green)';
            playBtn.disabled = false;
            playBtn.textContent = 'Take Your Turn';
            playBtn.style.opacity = '1';
        }
    }

    // Online/Sync status badges
    function updateStatusBadges(online, synced) {
        var onlineBadge = document.getElementById('online-badge');
        var syncBadge = document.getElementById('sync-badge');

        if (onlineBadge) {
            onlineBadge.className = 'status-badge ' + (online ? 'online' : 'offline');
            onlineBadge.textContent = online ? 'Online' : 'Offline';
        }

        if (syncBadge) {
            if (synced) {
                syncBadge.className = 'status-badge synced';
                syncBadge.textContent = 'Synced';
            } else {
                syncBadge.className = 'status-badge pending';
                syncBadge.textContent = 'Saved Locally';
            }
        }
    }

    // Generate achievement badges
    function generateBadges(result) {
        var badges = [];
        if (result.score >= 10000) badges.push('10K Club');
        if (result.score >= 50000) badges.push('50K Legend');
        if (result.lines >= 40) badges.push('Line Master');
        if (result.lines >= 100) badges.push('Century');
        if (result.maxCombo >= 5) badges.push('Combo King');
        if (result.maxCombo >= 10) badges.push('Unstoppable');
        if (result.level >= 10) badges.push('Level 10');
        if (result.level >= 20) badges.push('Speed Demon');

        var streak = BlockStorage.getStreak();
        if (streak.count >= 3) badges.push(streak.count + ' Day Streak');
        if (streak.count >= 7) badges.push('Week Warrior');

        var isNewBest = BlockStorage.saveBestScore(result.mode, result.score);
        if (isNewBest) badges.push('New Best!');

        return badges;
    }

    // Helpers
    function formatNumber(n) {
        if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
        if (n >= 10000) return (n / 1000).toFixed(1) + 'K';
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function formatTime(ms) {
        var s = Math.ceil(ms / 1000);
        var m = Math.floor(s / 60);
        s = s % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function formatDuration(ms) {
        var s = Math.floor(ms / 1000);
        var m = Math.floor(s / 60);
        s = s % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    return {
        init: init,
        showScreen: showScreen,
        showOverlay: showOverlay,
        hideOverlay: hideOverlay,
        hideAllOverlays: hideAllOverlays,
        updateHUD: updateHUD,
        showComboPopup: showComboPopup,
        showPause: showPause,
        showResults: showResults,
        updateResultsRank: updateResultsRank,
        showSettings: showSettings,
        getSettingsValues: getSettingsValues,
        showLeaderboard: showLeaderboard,
        renderLeaderboard: renderLeaderboard,
        updateFamilyScreen: updateFamilyScreen,
        updateStatusBadges: updateStatusBadges
    };
})();
