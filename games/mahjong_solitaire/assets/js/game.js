/* =============================================
   MAHJONG SOLITAIRE - Game Engine & Main
   ============================================= */

var MahjongGame = (function() {
    'use strict';

    // Game state
    var tiles = [];
    var tileIndex = {};  // Fast lookup by position
    var selected = null;
    var hovered = null;
    var hintedTiles = [];
    var moves = 0;
    var startTime = 0;
    var elapsedMs = 0;
    var timeLimit = 180000;  // Default 3 minutes
    var timeRemaining = 180000;
    var running = false;
    var paused = false;
    var gameOver = false;
    var currentLayout = 'simple';
    var hintsRemaining = Infinity;
    var shufflesRemaining = Infinity;

    // Settings
    var settings = {
        theme: 'jade',
        sound: true,
        showTimer: true,
        highlightFree: false
    };

    var animFrame = null;
    var lastTime = 0;

    // Initialize
    function boot() {
        loadSettings();
        applySettings();

        MahjongRenderer.init('game-canvas');
        MahjongAudio.init();

        setupMenuListeners();
        setupGameListeners();
        setupOverlayListeners();

        showScreen('menu');
    }

    function loadSettings() {
        try {
            var saved = localStorage.getItem('mahjong_settings');
            if (saved) {
                settings = Object.assign(settings, JSON.parse(saved));
            }
        } catch (e) {}
    }

    function saveSettings() {
        try {
            localStorage.setItem('mahjong_settings', JSON.stringify(settings));
        } catch (e) {}
    }

    function applySettings() {
        document.body.setAttribute('data-theme', settings.theme);
        MahjongRenderer.setTheme(settings.theme);
        MahjongAudio.setEnabled(settings.sound);

        var timerBox = document.getElementById('timer-box');
        if (timerBox) {
            timerBox.style.display = settings.showTimer ? '' : 'none';
        }
    }

    // ========== SCREENS ==========
    function showScreen(name) {
        document.querySelectorAll('.screen').forEach(function(s) {
            s.classList.toggle('active', s.id === 'screen-' + name);
        });

        if (name === 'game') {
            MahjongRenderer.resize();
        }
    }

    function showOverlay(name) {
        document.querySelectorAll('.overlay').forEach(function(o) {
            o.classList.toggle('active', o.id === 'overlay-' + name);
        });
    }

    function hideOverlay(name) {
        if (name) {
            var el = document.getElementById('overlay-' + name);
            if (el) el.classList.remove('active');
        } else {
            document.querySelectorAll('.overlay').forEach(function(o) {
                o.classList.remove('active');
            });
        }
    }

    // ========== MENU ==========
    function setupMenuListeners() {
        document.querySelectorAll('.mode-card').forEach(function(card) {
            card.addEventListener('click', function() {
                var layout = card.getAttribute('data-layout');
                MahjongAudio.play('select');
                startGame(layout);
            });
        });

        document.getElementById('btn-settings').addEventListener('click', function() {
            MahjongAudio.play('select');
            openSettings();
        });

        document.getElementById('btn-back-hub').addEventListener('click', function() {
            window.location.href = '/games/';
        });
    }

    function openSettings() {
        document.getElementById('setting-theme').value = settings.theme;
        document.getElementById('setting-sound').checked = settings.sound;
        document.getElementById('setting-timer').checked = settings.showTimer;
        document.getElementById('setting-highlight').checked = settings.highlightFree;
        showOverlay('settings');
    }

    // ========== GAME START ==========
    function startGame(layoutName) {
        currentLayout = layoutName || 'simple';
        var layout = MahjongLayouts.getLayout(currentLayout);

        hintsRemaining = layout.hints;
        shufflesRemaining = layout.shuffles;
        timeLimit = layout.timeLimit || 180000;
        timeRemaining = timeLimit;

        // Generate tiles from layout
        var positions = layout.generate();
        tiles = [];
        tileIndex = {};

        var id = 0;
        positions.forEach(function(pos) {
            tiles.push({
                id: id++,
                x: pos.x,
                y: pos.y,
                z: pos.z,
                symbol: null,
                removed: false,
                free: false
            });
        });

        // Assign symbols (pairs)
        assignSymbols();

        // Calculate free tiles
        updateFreeTiles();

        // Reset state
        selected = null;
        hovered = null;
        hintedTiles = [];
        moves = 0;
        startTime = Date.now();
        elapsedMs = 0;
        running = true;
        paused = false;
        gameOver = false;

        // Update UI
        updateHUD();
        showScreen('game');
        hideOverlay();

        // Start game loop
        startGameLoop();
    }

    function assignSymbols() {
        var activeTiles = tiles.filter(function(t) { return !t.removed; });
        var numPairs = activeTiles.length / 2;

        // Get symbols
        var symbols = MahjongLayouts.getSymbolsForCount(activeTiles.length);

        // Create pairs
        var symbolPairs = [];
        symbols.forEach(function(s) {
            symbolPairs.push(s);
            symbolPairs.push(s);
        });

        // Shuffle
        shuffleArray(symbolPairs);

        // Assign to tiles
        activeTiles.forEach(function(tile, i) {
            tile.symbol = symbolPairs[i];
        });
    }

    function shuffleArray(arr) {
        for (var i = arr.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var temp = arr[i];
            arr[i] = arr[j];
            arr[j] = temp;
        }
    }

    // ========== FREE TILE ALGORITHM ==========
    function buildTileIndex() {
        tileIndex = {};
        tiles.forEach(function(tile) {
            if (tile.removed) return;
            var key = tile.x + ',' + tile.y + ',' + tile.z;
            tileIndex[key] = tile;
        });
    }

    function getTileAt(x, y, z) {
        var key = x + ',' + y + ',' + z;
        return tileIndex[key] || null;
    }

    function isTileFree(tile) {
        if (tile.removed) return false;

        // Check if blocked from above
        // A tile at (x, y, z) is blocked if there's a tile at z+1 that overlaps
        if (isBlockedFromAbove(tile)) {
            return false;
        }

        // Check if blocked on both left AND right
        var blockedLeft = isBlockedOnLeft(tile);
        var blockedRight = isBlockedOnRight(tile);

        // Tile is free if at least one side is open
        return !(blockedLeft && blockedRight);
    }

    function isBlockedFromAbove(tile) {
        // Check z+1 layer for overlapping tiles
        // Tiles overlap if their x,y ranges intersect
        for (var i = 0; i < tiles.length; i++) {
            var other = tiles[i];
            if (other.removed || other.z !== tile.z + 1) continue;

            // Check overlap (tiles are 1x1 logical units, but can be at half positions)
            if (Math.abs(other.x - tile.x) < 1 && Math.abs(other.y - tile.y) < 1) {
                return true;
            }
        }
        return false;
    }

    function isBlockedOnLeft(tile) {
        // A tile is blocked on left if there's a tile at same z, immediately to the left
        for (var i = 0; i < tiles.length; i++) {
            var other = tiles[i];
            if (other.removed || other.id === tile.id) continue;
            if (other.z !== tile.z) continue;

            // Check if tile is directly to the left
            var dx = tile.x - other.x;
            var dy = Math.abs(tile.y - other.y);

            // Tile is to the left if dx is ~1 and y overlaps
            if (dx > 0.5 && dx <= 1.1 && dy < 1) {
                return true;
            }
        }
        return false;
    }

    function isBlockedOnRight(tile) {
        // A tile is blocked on right if there's a tile at same z, immediately to the right
        for (var i = 0; i < tiles.length; i++) {
            var other = tiles[i];
            if (other.removed || other.id === tile.id) continue;
            if (other.z !== tile.z) continue;

            // Check if tile is directly to the right
            var dx = other.x - tile.x;
            var dy = Math.abs(tile.y - other.y);

            // Tile is to the right if dx is ~1 and y overlaps
            if (dx > 0.5 && dx <= 1.1 && dy < 1) {
                return true;
            }
        }
        return false;
    }

    function updateFreeTiles() {
        buildTileIndex();
        tiles.forEach(function(tile) {
            tile.free = isTileFree(tile);
        });
    }

    // ========== MATCHING ==========
    function symbolsMatch(s1, s2) {
        if (!s1 || !s2) return false;
        return s1.type === s2.type && s1.value === s2.value;
    }

    function selectTile(tile) {
        if (!tile || tile.removed || !tile.free) {
            // Invalid selection - shake effect
            if (tile && !tile.free) {
                MahjongAudio.play('error');
                MahjongRenderer.addShakeAnimation(tile);
            }
            return;
        }

        if (!selected) {
            // First selection
            selected = tile;
            MahjongAudio.play('select');
        } else if (selected.id === tile.id) {
            // Deselect
            selected = null;
            MahjongAudio.play('select');
        } else {
            // Second selection - check match
            if (symbolsMatch(selected.symbol, tile.symbol)) {
                // Match!
                removePair(selected, tile);
            } else {
                // No match
                MahjongAudio.play('error');
                selected = null;
            }
        }

        hintedTiles = [];
    }

    function removePair(tile1, tile2) {
        tile1.removed = true;
        tile2.removed = true;
        selected = null;
        moves++;

        MahjongAudio.play('match');
        MahjongRenderer.addMatchParticles(tile1, tile2);
        MahjongRenderer.addRemoveAnimation(tile1);
        MahjongRenderer.addRemoveAnimation(tile2);

        updateFreeTiles();
        updateHUD();

        // Check win
        var remaining = tiles.filter(function(t) { return !t.removed; });
        if (remaining.length === 0) {
            setTimeout(function() { endGame(true); }, 500);
            return;
        }

        // Check for available moves
        if (!hasAvailableMoves()) {
            setTimeout(function() { showNoMoves(); }, 500);
        }
    }

    // ========== HINT ==========
    function findAvailableMatch() {
        var freeTiles = tiles.filter(function(t) { return !t.removed && t.free; });

        for (var i = 0; i < freeTiles.length; i++) {
            for (var j = i + 1; j < freeTiles.length; j++) {
                if (symbolsMatch(freeTiles[i].symbol, freeTiles[j].symbol)) {
                    return [freeTiles[i], freeTiles[j]];
                }
            }
        }
        return null;
    }

    function hasAvailableMoves() {
        return findAvailableMatch() !== null;
    }

    function showHint() {
        if (hintsRemaining <= 0) return;

        var match = findAvailableMatch();
        if (match) {
            hintedTiles = [match[0].id, match[1].id];
            hintsRemaining--;
            MahjongAudio.play('select');
        }
    }

    // ========== SHUFFLE ==========
    function shuffleRemaining() {
        if (shufflesRemaining <= 0) return;

        var activeTiles = tiles.filter(function(t) { return !t.removed; });

        // Collect all symbols
        var symbols = activeTiles.map(function(t) { return t.symbol; });
        shuffleArray(symbols);

        // Reassign
        activeTiles.forEach(function(t, i) {
            t.symbol = symbols[i];
        });

        shufflesRemaining--;
        hintedTiles = [];
        selected = null;
        updateFreeTiles();

        MahjongAudio.play('shuffle');
    }

    function showNoMoves() {
        if (shufflesRemaining > 0) {
            showOverlay('nomoves');
        } else {
            endGame(false);
        }
    }

    // ========== GAME LOOP ==========
    function startGameLoop() {
        lastTime = performance.now();

        function loop(time) {
            if (!running) return;

            var dt = (time - lastTime) / 1000;
            lastTime = time;

            if (!paused && !gameOver) {
                elapsedMs = Date.now() - startTime;
                timeRemaining = Math.max(0, timeLimit - elapsedMs);
                updateTimer();

                // Check if time ran out
                if (timeRemaining <= 0) {
                    endGame(false);
                    return;
                }
            }

            // Render
            MahjongRenderer.render({
                tiles: tiles,
                selected: selected,
                hovered: hovered,
                hintedTiles: hintedTiles,
                highlightFree: settings.highlightFree
            });

            animFrame = requestAnimationFrame(loop);
        }

        animFrame = requestAnimationFrame(loop);
    }

    function stopGameLoop() {
        if (animFrame) {
            cancelAnimationFrame(animFrame);
            animFrame = null;
        }
    }

    // ========== HUD ==========
    function updateHUD() {
        var remaining = tiles.filter(function(t) { return !t.removed; }).length;
        document.getElementById('hud-tiles').textContent = remaining;
        document.getElementById('hud-moves').textContent = moves;
    }

    function updateTimer() {
        if (!settings.showTimer) return;
        var el = document.getElementById('hud-timer');
        if (el) {
            el.textContent = formatTime(timeRemaining);
            // Make timer red when low
            if (timeRemaining < 30000) {
                el.style.color = '#ef4444';
            } else if (timeRemaining < 60000) {
                el.style.color = '#f59e0b';
            } else {
                el.style.color = '';
            }
        }
    }

    function formatTime(ms) {
        var s = Math.floor(ms / 1000);
        var m = Math.floor(s / 60);
        s = s % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    // ========== PAUSE ==========
    function pauseGame() {
        if (!running || gameOver) return;
        paused = true;

        document.getElementById('pause-tiles').textContent = tiles.filter(function(t) { return !t.removed; }).length;
        document.getElementById('pause-moves').textContent = moves;
        document.getElementById('pause-time').textContent = formatTime(elapsedMs);

        showOverlay('pause');
    }

    function resumeGame() {
        paused = false;
        startTime = Date.now() - elapsedMs;
        hideOverlay('pause');
    }

    // ========== END GAME ==========
    function endGame(won) {
        gameOver = true;
        running = false;
        stopGameLoop();

        document.getElementById('results-title').textContent = won ? 'You Win!' : 'Game Over';
        document.getElementById('results-time').textContent = formatTime(elapsedMs);
        document.getElementById('results-moves').textContent = moves;

        var message = '';
        if (won) {
            if (elapsedMs < 60000) message = 'Speed demon!';
            else if (elapsedMs < 120000) message = 'Great job!';
            else message = 'Well done!';
            MahjongAudio.play('win');
        } else {
            message = 'No more moves available.';
        }
        document.getElementById('results-message').textContent = message;

        showOverlay('results');
    }

    // ========== INPUT ==========
    function setupGameListeners() {
        var canvas = document.getElementById('game-canvas');

        canvas.addEventListener('click', function(e) {
            if (paused || gameOver) return;
            var rect = canvas.getBoundingClientRect();
            var x = e.clientX - rect.left;
            var y = e.clientY - rect.top;

            var tile = MahjongRenderer.screenToTile(x, y, tiles);
            selectTile(tile);
        });

        canvas.addEventListener('mousemove', function(e) {
            if (paused || gameOver) return;
            var rect = canvas.getBoundingClientRect();
            var x = e.clientX - rect.left;
            var y = e.clientY - rect.top;

            hovered = MahjongRenderer.screenToTile(x, y, tiles);
        });

        canvas.addEventListener('mouseleave', function() {
            hovered = null;
        });

        document.getElementById('btn-pause').addEventListener('click', function() {
            MahjongAudio.play('select');
            pauseGame();
        });

        document.getElementById('btn-hint').addEventListener('click', function() {
            if (!paused && !gameOver) {
                showHint();
            }
        });

        document.getElementById('btn-shuffle').addEventListener('click', function() {
            if (!paused && !gameOver && shufflesRemaining > 0) {
                shuffleRemaining();
            }
        });

        // Keyboard
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.querySelector('.overlay.active')) {
                    hideOverlay();
                    if (paused) resumeGame();
                } else if (running && !gameOver) {
                    pauseGame();
                }
            } else if (e.key === 'h' || e.key === 'H') {
                if (running && !paused && !gameOver) {
                    showHint();
                }
            }
        });
    }

    // ========== OVERLAY LISTENERS ==========
    function setupOverlayListeners() {
        // Pause
        document.getElementById('btn-resume').addEventListener('click', function() {
            MahjongAudio.play('select');
            resumeGame();
        });

        document.getElementById('btn-restart').addEventListener('click', function() {
            MahjongAudio.play('select');
            hideOverlay();
            startGame(currentLayout);
        });

        document.getElementById('btn-quit').addEventListener('click', function() {
            MahjongAudio.play('select');
            running = false;
            stopGameLoop();
            hideOverlay();
            showScreen('menu');
        });

        // Results
        document.getElementById('btn-play-again').addEventListener('click', function() {
            MahjongAudio.play('select');
            hideOverlay();
            startGame(currentLayout);
        });

        document.getElementById('btn-menu').addEventListener('click', function() {
            MahjongAudio.play('select');
            hideOverlay();
            showScreen('menu');
        });

        // No moves
        document.getElementById('btn-shuffle-confirm').addEventListener('click', function() {
            MahjongAudio.play('select');
            hideOverlay();
            shuffleRemaining();
        });

        document.getElementById('btn-give-up').addEventListener('click', function() {
            MahjongAudio.play('select');
            hideOverlay();
            endGame(false);
        });

        // Settings
        document.getElementById('btn-settings-close').addEventListener('click', function() {
            settings.theme = document.getElementById('setting-theme').value;
            settings.sound = document.getElementById('setting-sound').checked;
            settings.showTimer = document.getElementById('setting-timer').checked;
            settings.highlightFree = document.getElementById('setting-highlight').checked;

            saveSettings();
            applySettings();
            MahjongAudio.play('select');
            hideOverlay();
        });
    }

    // Boot on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // Handle resize
    window.addEventListener('resize', function() {
        if (running) {
            MahjongRenderer.resize();
        }
    });

    // Handle visibility
    document.addEventListener('visibilitychange', function() {
        if (document.hidden && running && !paused && !gameOver) {
            pauseGame();
        }
    });

    return {
        startGame: startGame,
        pauseGame: pauseGame,
        resumeGame: resumeGame
    };

})();
