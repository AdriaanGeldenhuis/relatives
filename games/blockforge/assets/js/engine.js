/* =============================================
   BLOCKFORGE - Core Game Engine
   ============================================= */

var BlockEngine = (function() {
    'use strict';

    var COLS = 10;
    var ROWS = 20;
    var HIDDEN_ROWS = 4;

    // Game state
    var grid = [];
    var currentPiece = null;
    var ghostPiece = null;
    var bag = null;
    var score = 0;
    var level = 1;
    var lines = 0;
    var combo = -1;
    var maxCombo = 0;
    var backToBack = false;
    var running = false;
    var paused = false;
    var gameOver = false;
    var mode = 'solo';
    var dropTimer = 0;
    var lockTimer = 0;
    var lockDelay = 500;
    var lockMoves = 0;
    var maxLockMoves = 15;
    var startTime = 0;
    var elapsedMs = 0;
    var piecesPlaced = 0;
    var seed = null;
    var rng = null;

    // Daily mode limits
    var dailyTimeLimit = 120000; // 2 minutes
    var dailyPiecesLimit = 200;
    var dailyTimer = 0;

    // Family mode limits
    var familyPiecesLimit = 3;
    var familyTimeLimit = 30000;
    var familyPiecesUsed = 0;
    var familyActions = [];

    // Scoring
    var LINE_SCORES = [0, 100, 300, 500, 800];
    var COMBO_BONUS = 0.1; // +10% per combo

    // Callbacks
    var onLineClear = null;
    var onLevelUp = null;
    var onCombo = null;
    var onGameOver = null;
    var onHardDrop = null;
    var onLock = null;
    var onPieceSpawn = null;

    function getDropInterval() {
        // Speed curve: starts at 750ms, decreases with level
        var base = 750;
        var interval = base * Math.pow(0.85, level - 1);
        return Math.max(interval, 50);
    }

    function createGrid() {
        grid = [];
        for (var r = 0; r < ROWS + HIDDEN_ROWS; r++) {
            grid.push(new Array(COLS).fill(null));
        }
    }

    function init(options) {
        options = options || {};
        mode = options.mode || 'solo';
        seed = options.seed || Date.now().toString();

        createGrid();
        score = 0;
        level = 1;
        lines = 0;
        combo = -1;
        maxCombo = 0;
        backToBack = false;
        running = false;
        paused = false;
        gameOver = false;
        dropTimer = 0;
        lockTimer = 0;
        lockMoves = 0;
        piecesPlaced = 0;
        elapsedMs = 0;
        dailyTimer = dailyTimeLimit;
        familyPiecesUsed = 0;
        familyActions = [];
        currentPiece = null;
        ghostPiece = null;

        rng = BlockPieces.createRNG(BlockPieces.hashSeed(seed));
        bag = BlockPieces.createBag(rng);

        if (options.dailyRules) {
            if (options.dailyRules.time_limit_ms) dailyTimeLimit = options.dailyRules.time_limit_ms;
            if (options.dailyRules.pieces_limit) dailyPiecesLimit = options.dailyRules.pieces_limit;
            dailyTimer = dailyTimeLimit;
        }

        if (options.grid) {
            // Family mode: load existing grid
            for (var r = 0; r < ROWS + HIDDEN_ROWS; r++) {
                if (options.grid[r]) {
                    grid[r] = options.grid[r].slice();
                }
            }
        }
    }

    function start() {
        running = true;
        paused = false;
        gameOver = false;
        startTime = Date.now();
        spawnPiece();
    }

    function pause() {
        paused = true;
    }

    function resume() {
        paused = false;
    }

    function spawnPiece() {
        var name = bag.next();
        currentPiece = BlockPieces.createPiece(name);
        var shape = BlockPieces.getShape(name, 0);

        // Center piece
        currentPiece.x = Math.floor((COLS - shape[0].length) / 2);
        currentPiece.y = HIDDEN_ROWS - shape.length;

        // Check if spawn position is blocked = game over
        if (checkCollision(currentPiece.x, currentPiece.y, currentPiece.rotation)) {
            gameOver = true;
            running = false;
            if (onGameOver) onGameOver(getResult());
            return;
        }

        lockTimer = 0;
        lockMoves = 0;
        dropTimer = 0;
        updateGhost();

        if (onPieceSpawn) onPieceSpawn(currentPiece);
    }

    function checkCollision(x, y, rotation) {
        var shape = BlockPieces.getShape(currentPiece.name, rotation);
        for (var r = 0; r < shape.length; r++) {
            for (var c = 0; c < shape[r].length; c++) {
                if (shape[r][c]) {
                    var nx = x + c;
                    var ny = y + r;
                    if (nx < 0 || nx >= COLS || ny >= ROWS + HIDDEN_ROWS) return true;
                    if (ny >= 0 && grid[ny][nx]) return true;
                }
            }
        }
        return false;
    }

    function updateGhost() {
        if (!currentPiece) { ghostPiece = null; return; }
        ghostPiece = {
            x: currentPiece.x,
            y: currentPiece.y,
            rotation: currentPiece.rotation,
            name: currentPiece.name
        };
        while (!checkCollision(ghostPiece.x, ghostPiece.y + 1, ghostPiece.rotation)) {
            ghostPiece.y++;
        }
    }

    function moveLeft() {
        if (!running || paused || !currentPiece || gameOver) return false;
        if (!checkCollision(currentPiece.x - 1, currentPiece.y, currentPiece.rotation)) {
            currentPiece.x--;
            lockMoves++;
            if (lockTimer > 0 && lockMoves < maxLockMoves) lockTimer = 0;
            updateGhost();
            return true;
        }
        return false;
    }

    function moveRight() {
        if (!running || paused || !currentPiece || gameOver) return false;
        if (!checkCollision(currentPiece.x + 1, currentPiece.y, currentPiece.rotation)) {
            currentPiece.x++;
            lockMoves++;
            if (lockTimer > 0 && lockMoves < maxLockMoves) lockTimer = 0;
            updateGhost();
            return true;
        }
        return false;
    }

    function moveDown() {
        if (!running || paused || !currentPiece || gameOver) return false;
        if (!checkCollision(currentPiece.x, currentPiece.y + 1, currentPiece.rotation)) {
            currentPiece.y++;
            dropTimer = 0;
            score += 1; // soft drop bonus
            return true;
        }
        return false;
    }

    function hardDrop() {
        if (!running || paused || !currentPiece || gameOver) return 0;
        var startY = currentPiece.y;
        var dropped = 0;
        while (!checkCollision(currentPiece.x, currentPiece.y + 1, currentPiece.rotation)) {
            currentPiece.y++;
            dropped++;
        }
        score += dropped * 2; // hard drop bonus

        if (onHardDrop) onHardDrop(currentPiece, startY, dropped);
        lockPiece();
        return dropped;
    }

    function rotate() {
        if (!running || paused || !currentPiece || gameOver) return false;
        var newRot = (currentPiece.rotation + 1) % 4;
        var kicks = BlockPieces.getKicks(currentPiece.name, currentPiece.rotation, newRot);

        for (var i = 0; i < kicks.length; i++) {
            var kx = kicks[i][0];
            var ky = -kicks[i][1]; // SRS uses inverted y
            if (!checkCollision(currentPiece.x + kx, currentPiece.y + ky, newRot)) {
                currentPiece.x += kx;
                currentPiece.y += ky;
                currentPiece.rotation = newRot;
                lockMoves++;
                if (lockTimer > 0 && lockMoves < maxLockMoves) lockTimer = 0;
                updateGhost();
                return true;
            }
        }
        return false;
    }

    function lockPiece() {
        if (!currentPiece) return;
        var shape = BlockPieces.getShape(currentPiece.name, currentPiece.rotation);
        var color = BlockPieces.getColor(currentPiece.name);
        var glowColor = BlockPieces.getGlowColor(currentPiece.name);
        var shadowColor = BlockPieces.getShadowColor(currentPiece.name);

        for (var r = 0; r < shape.length; r++) {
            for (var c = 0; c < shape[r].length; c++) {
                if (shape[r][c]) {
                    var gx = currentPiece.x + c;
                    var gy = currentPiece.y + r;
                    if (gy >= 0 && gy < ROWS + HIDDEN_ROWS && gx >= 0 && gx < COLS) {
                        grid[gy][gx] = { color: color, glowColor: glowColor, shadowColor: shadowColor };
                    }
                }
            }
        }

        if (onLock) onLock(currentPiece);

        // Record family action
        if (mode === 'family') {
            familyActions.push({
                piece: currentPiece.name,
                x: currentPiece.x,
                y: currentPiece.y,
                rotation: currentPiece.rotation
            });
            familyPiecesUsed++;
        }

        piecesPlaced++;

        // Check lines
        var clearedRows = checkLines();
        if (clearedRows.length > 0) {
            processLineClear(clearedRows);
        } else {
            combo = -1;
        }

        // Check game end conditions
        if (mode === 'daily' && piecesPlaced >= dailyPiecesLimit) {
            endGame();
            return;
        }
        if (mode === 'family' && familyPiecesUsed >= familyPiecesLimit) {
            endGame();
            return;
        }

        // Next piece
        currentPiece = null;
        ghostPiece = null;
        spawnPiece();
    }

    function checkLines() {
        var cleared = [];
        for (var r = HIDDEN_ROWS; r < ROWS + HIDDEN_ROWS; r++) {
            var full = true;
            for (var c = 0; c < COLS; c++) {
                if (!grid[r][c]) { full = false; break; }
            }
            if (full) cleared.push(r);
        }
        return cleared;
    }

    function processLineClear(rows) {
        var count = rows.length;
        combo++;
        if (combo > maxCombo) maxCombo = combo;

        // Score calculation
        var baseScore = LINE_SCORES[Math.min(count, 4)];
        var comboMultiplier = 1 + (combo * COMBO_BONUS);
        var b2bMultiplier = 1;

        // Back-to-back for quads
        if (count >= 4) {
            if (backToBack) b2bMultiplier = 1.5;
            backToBack = true;
        } else {
            backToBack = false;
        }

        var earnedScore = Math.floor(baseScore * level * comboMultiplier * b2bMultiplier);
        score += earnedScore;
        lines += count;

        // Level up every 10 lines
        var newLevel = Math.floor(lines / 10) + 1;
        if (newLevel > level) {
            level = newLevel;
            if (onLevelUp) onLevelUp(level);
        }

        if (onLineClear) onLineClear(rows, count, combo, earnedScore);
        if (combo > 0 && onCombo) onCombo(combo, earnedScore);

        // Remove lines from grid
        for (var i = rows.length - 1; i >= 0; i--) {
            grid.splice(rows[i], 1);
            grid.unshift(new Array(COLS).fill(null));
        }
    }

    function endGame() {
        gameOver = true;
        running = false;
        elapsedMs = Date.now() - startTime;
        if (onGameOver) onGameOver(getResult());
    }

    function update(dt) {
        if (!running || paused || gameOver || !currentPiece) return;

        elapsedMs = Date.now() - startTime;

        // Daily timer
        if (mode === 'daily') {
            dailyTimer -= dt * 1000;
            if (dailyTimer <= 0) {
                dailyTimer = 0;
                endGame();
                return;
            }
        }

        // Family timer
        if (mode === 'family') {
            var familyElapsed = Date.now() - startTime;
            if (familyElapsed >= familyTimeLimit) {
                endGame();
                return;
            }
        }

        // Gravity drop
        dropTimer += dt * 1000;
        var interval = getDropInterval();
        if (dropTimer >= interval) {
            dropTimer = 0;
            if (!checkCollision(currentPiece.x, currentPiece.y + 1, currentPiece.rotation)) {
                currentPiece.y++;
            } else {
                // Start lock delay
                lockTimer += interval;
                if (lockTimer >= lockDelay || lockMoves >= maxLockMoves) {
                    lockPiece();
                }
            }
        }

        // Lock delay while on ground
        if (currentPiece && checkCollision(currentPiece.x, currentPiece.y + 1, currentPiece.rotation)) {
            lockTimer += dt * 1000;
            if (lockTimer >= lockDelay || lockMoves >= maxLockMoves) {
                lockPiece();
            }
        }
    }

    function getState() {
        return {
            grid: grid.slice(HIDDEN_ROWS),
            currentPiece: currentPiece ? {
                name: currentPiece.name,
                x: currentPiece.x,
                y: currentPiece.y - HIDDEN_ROWS,
                rotation: currentPiece.rotation
            } : null,
            ghostPiece: ghostPiece ? {
                name: ghostPiece.name,
                x: ghostPiece.x,
                y: ghostPiece.y - HIDDEN_ROWS,
                rotation: ghostPiece.rotation
            } : null,
            nextPieces: bag ? bag.peek(1) : [],
            score: score,
            level: level,
            lines: lines,
            combo: Math.max(0, combo),
            showGhost: false,
            running: running,
            paused: paused,
            gameOver: gameOver,
            mode: mode,
            dailyTimer: dailyTimer,
            familyPiecesUsed: familyPiecesUsed,
            familyPiecesLimit: familyPiecesLimit,
            elapsedMs: elapsedMs
        };
    }

    function getResult() {
        return {
            mode: mode,
            score: score,
            lines: lines,
            level: level,
            maxCombo: maxCombo,
            duration: elapsedMs || (Date.now() - startTime),
            piecesPlaced: piecesPlaced,
            seed: seed,
            familyActions: mode === 'family' ? familyActions : null,
            familyScoreDelta: mode === 'family' ? score : null,
            familyLinesDelta: mode === 'family' ? lines : null
        };
    }

    function getGrid() {
        return grid;
    }

    function isRunning() { return running; }
    function isPaused() { return paused; }
    function isGameOver() { return gameOver; }

    return {
        init: init,
        start: start,
        pause: pause,
        resume: resume,
        update: update,
        moveLeft: moveLeft,
        moveRight: moveRight,
        moveDown: moveDown,
        hardDrop: hardDrop,
        rotate: rotate,
        getState: getState,
        getResult: getResult,
        getGrid: getGrid,
        isRunning: isRunning,
        isPaused: isPaused,
        isGameOver: isGameOver,
        set onLineClear(fn) { onLineClear = fn; },
        set onLevelUp(fn) { onLevelUp = fn; },
        set onCombo(fn) { onCombo = fn; },
        set onGameOver(fn) { onGameOver = fn; },
        set onHardDrop(fn) { onHardDrop = fn; },
        set onLock(fn) { onLock = fn; },
        set onPieceSpawn(fn) { onPieceSpawn = fn; },
        COLS: COLS,
        ROWS: ROWS
    };
})();
