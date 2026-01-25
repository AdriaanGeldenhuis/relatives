/**
 * NEON NIBBLER - Game Engine
 * Core gameplay: movement, AI, collision, rendering
 */
var NeonEngine = (function() {
    'use strict';

    // Constants
    var TILE_SIZE = 0;
    var MOVE_SCALE = 480; // Fixed reference for movement speed (independent of screen size)
    var OFFSET_X = 0;
    var OFFSET_Y = 0;
    var MAX_LIVES = 3;
    var DOT_SCORE = 10;
    var POWER_SCORE = 50;
    var TAG_SCORES = [200, 400, 800, 1600];

    // Directions
    var DIRS = {
        up: { dr: -1, dc: 0 },
        down: { dr: 1, dc: 0 },
        left: { dr: 0, dc: -1 },
        right: { dr: 0, dc: 1 }
    };
    var OPPOSITE = { up: 'down', down: 'up', left: 'right', right: 'left' };

    // State
    var canvas, ctx;
    var maze = null;
    var config = null;
    var state = 'idle'; // idle, playing, paused, dead, levelComplete, gameOver
    var level = 1;
    var score = 0;
    var lives = MAX_LIVES;
    var dotsLeft = 0;
    var dotsCollected = 0;
    var startTime = 0;
    var totalDuration = 0;
    var frameId = null;

    // Player
    var player = {
        row: 10, col: 10,
        targetRow: 10, targetCol: 10,
        px: 0, py: 0,
        dir: null,
        nextDir: null,
        moving: false,
        moveProgress: 0,
        speed: 4
    };

    // Pulse mode
    var pulseMode = false;
    var pulseStart = 0;
    var pulseDuration = 8000;
    var tagCount = 0;

    // Sentinels
    var sentinels = [];
    var SENTINEL_COLORS = ['#ff4444', '#ff88ff', '#44ccff', '#ffaa00'];
    var SENTINEL_STATES = { PATROL: 0, CHASE: 1, SCATTER: 2, VULNERABLE: 3, RESPAWNING: 4 };

    // Timing
    var lastTime = 0;
    var accumulator = 0;
    var FIXED_DT = 1000 / 60;

    // Dots grid (separate from maze for rendering)
    var dots = null;

    // Callbacks
    var onGameOver = null;
    var onLevelComplete = null;

    // Precomputed glow layer
    var wallCanvas = null;

    function init(gameCanvas) {
        canvas = gameCanvas;
        ctx = canvas.getContext('2d');
        resize();
        window.addEventListener('resize', resize);
    }

    function resize() {
        var dpr = window.devicePixelRatio || 1;
        // Use offsetWidth/Height (ignores parent CSS transforms like rotation)
        var w = canvas.offsetWidth;
        var h = canvas.offsetHeight;
        if (w <= 0 || h <= 0) return;

        canvas.width = w * dpr;
        canvas.height = h * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        recalcLayout(w, h);
        if (maze) prerenderWalls();
    }

    function recalcLayout(w, h) {
        var rows = NeonLevels.ROWS;
        var cols = NeonLevels.COLS;
        // Canvas already excludes HUD (positioned via CSS)
        // Reserve space for D-pad buttons on sides
        var sideMargin = 70;
        var availW = w - sideMargin * 2;
        var availH = h;

        TILE_SIZE = Math.floor(Math.min(availW / cols, availH / rows));
        OFFSET_X = Math.floor((w - cols * TILE_SIZE) / 2);
        OFFSET_Y = Math.floor((h - rows * TILE_SIZE) / 2);
    }

    function startLevel(levelNum) {
        level = levelNum;
        maze = NeonLevels.getMaze(levelNum);
        config = NeonLevels.getLevelConfig(levelNum);

        // Build dots grid
        dots = [];
        for (var r = 0; r < maze.length; r++) {
            dots.push([]);
            for (var c = 0; c < maze[r].length; c++) {
                if (maze[r][c] === 1) dots[r].push(1); // dot
                else if (maze[r][c] === 2) dots[r].push(2); // power
                else dots[r].push(0);
            }
        }

        dotsLeft = NeonLevels.countDots(maze);
        dotsCollected = 0;

        // Setup player
        var pPos = NeonLevels.findPlayer(maze);
        player.row = pPos.row;
        player.col = pPos.col;
        player.targetRow = pPos.row;
        player.targetCol = pPos.col;
        player.px = pPos.col * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X;
        player.py = pPos.row * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y;
        player.dir = null;
        player.nextDir = null;
        player.moving = false;
        player.moveProgress = 0;
        player.speed = config.playerSpeed;

        // Mark player start as empty path
        maze[pPos.row][pPos.col] = 3;

        // Setup sentinels
        var sPositions = NeonLevels.findSentinels(maze);
        sentinels = [];
        for (var i = 0; i < sPositions.length; i++) {
            var sp = sPositions[i];
            maze[sp.row][sp.col] = 3;
            sentinels.push({
                row: sp.row,
                col: sp.col,
                homeRow: sp.row,
                homeCol: sp.col,
                targetRow: sp.row,
                targetCol: sp.col,
                px: sp.col * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X,
                py: sp.row * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y,
                dir: null,
                moving: false,
                moveProgress: 0,
                speed: config.sentinelSpeed,
                state: SENTINEL_STATES.PATROL,
                stateTimer: 0,
                color: SENTINEL_COLORS[i % SENTINEL_COLORS.length],
                chaseTimer: Math.random() * config.chaseInterval,
                released: i === 0, // First sentinel starts released
                releaseDelay: i * 1500
            });
        }

        // Pulse reset
        pulseMode = false;
        tagCount = 0;

        // Prerender walls
        recalcLayout(canvas.clientWidth, canvas.clientHeight);
        prerenderWalls();

        NeonUI.updateLevel(level);
        NeonUI.updateLives(lives, MAX_LIVES);
        NeonUI.showPulseTimer(false);
    }

    function prerenderWalls() {
        var w = canvas.offsetWidth;
        var h = canvas.offsetHeight;
        if (w <= 0 || h <= 0) return;
        var dpr = window.devicePixelRatio || 1;

        wallCanvas = document.createElement('canvas');
        wallCanvas.width = w * dpr;
        wallCanvas.height = h * dpr;
        var wctx = wallCanvas.getContext('2d');
        wctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        var isLight = document.documentElement.getAttribute('data-theme') === 'light';

        // Draw outer glow pass first (strong bloom effect)
        for (var r = 0; r < maze.length; r++) {
            for (var c = 0; c < maze[r].length; c++) {
                if (maze[r][c] !== 0) continue;
                if (!isEdgeWall(r, c)) continue;

                var x = c * TILE_SIZE + OFFSET_X;
                var y = r * TILE_SIZE + OFFSET_Y;
                var ts = TILE_SIZE;

                // Strong outer glow
                wctx.shadowColor = isLight ? 'rgba(100,100,255,0.8)' : 'rgba(0,200,255,0.9)';
                wctx.shadowBlur = 25;
                wctx.fillStyle = 'rgba(0,150,255,0.01)';
                wctx.fillRect(x + 2, y + 2, ts - 4, ts - 4);
            }
        }
        wctx.shadowBlur = 0;

        // Draw wall tiles with neon glass block style
        for (var r2 = 0; r2 < maze.length; r2++) {
            for (var c2 = 0; c2 < maze[r2].length; c2++) {
                if (maze[r2][c2] !== 0) continue;
                var x2 = c2 * TILE_SIZE + OFFSET_X;
                var y2 = r2 * TILE_SIZE + OFFSET_Y;
                var edge = isEdgeWall(r2, c2);
                var ts = TILE_SIZE;
                var gap = 2; // Gap between blocks
                var radius = 3; // Corner radius

                if (edge) {
                    // Main block fill - dark blue gradient
                    var blockGrad = wctx.createLinearGradient(x2, y2, x2 + ts, y2 + ts);
                    if (isLight) {
                        blockGrad.addColorStop(0, 'rgba(70,70,160,0.95)');
                        blockGrad.addColorStop(0.5, 'rgba(50,50,140,0.9)');
                        blockGrad.addColorStop(1, 'rgba(40,40,120,0.95)');
                    } else {
                        blockGrad.addColorStop(0, 'rgba(20,50,120,0.95)');
                        blockGrad.addColorStop(0.5, 'rgba(15,40,100,0.9)');
                        blockGrad.addColorStop(1, 'rgba(10,30,80,0.95)');
                    }

                    // Draw rounded rectangle
                    wctx.fillStyle = blockGrad;
                    roundRect(wctx, x2 + gap, y2 + gap, ts - gap * 2, ts - gap * 2, radius);
                    wctx.fill();

                    // Inner darker area (gives depth)
                    var innerGrad = wctx.createRadialGradient(
                        x2 + ts / 2, y2 + ts / 2, 0,
                        x2 + ts / 2, y2 + ts / 2, ts * 0.6
                    );
                    innerGrad.addColorStop(0, isLight ? 'rgba(40,40,100,0.4)' : 'rgba(5,20,60,0.5)');
                    innerGrad.addColorStop(1, 'rgba(0,0,0,0)');
                    wctx.fillStyle = innerGrad;
                    roundRect(wctx, x2 + gap + 4, y2 + gap + 4, ts - gap * 2 - 8, ts - gap * 2 - 8, radius);
                    wctx.fill();

                    // Glass highlight - top left corner reflection
                    wctx.globalAlpha = 0.35;
                    var glassGrad = wctx.createLinearGradient(x2 + gap, y2 + gap, x2 + ts * 0.6, y2 + ts * 0.6);
                    glassGrad.addColorStop(0, isLight ? 'rgba(180,180,255,0.9)' : 'rgba(100,180,255,0.8)');
                    glassGrad.addColorStop(0.3, isLight ? 'rgba(140,140,220,0.4)' : 'rgba(60,140,255,0.3)');
                    glassGrad.addColorStop(1, 'rgba(0,0,0,0)');
                    wctx.fillStyle = glassGrad;
                    wctx.beginPath();
                    wctx.moveTo(x2 + gap + radius, y2 + gap);
                    wctx.lineTo(x2 + ts * 0.65, y2 + gap);
                    wctx.lineTo(x2 + gap, y2 + ts * 0.65);
                    wctx.lineTo(x2 + gap, y2 + gap + radius);
                    wctx.quadraticCurveTo(x2 + gap, y2 + gap, x2 + gap + radius, y2 + gap);
                    wctx.fill();
                    wctx.globalAlpha = 1;

                    // Neon border glow - outer stroke
                    wctx.shadowColor = isLight ? 'rgba(100,150,255,0.9)' : 'rgba(0,220,255,1)';
                    wctx.shadowBlur = 8;
                    wctx.strokeStyle = isLight ? 'rgba(120,160,255,0.9)' : 'rgba(0,200,255,0.85)';
                    wctx.lineWidth = 2;
                    roundRect(wctx, x2 + gap, y2 + gap, ts - gap * 2, ts - gap * 2, radius);
                    wctx.stroke();
                    wctx.shadowBlur = 0;

                    // Inner bright edge line (top and left)
                    wctx.strokeStyle = isLight ? 'rgba(180,200,255,0.6)' : 'rgba(100,220,255,0.5)';
                    wctx.lineWidth = 1;
                    wctx.beginPath();
                    wctx.moveTo(x2 + gap + radius + 2, y2 + gap + 2);
                    wctx.lineTo(x2 + ts - gap - radius - 2, y2 + gap + 2);
                    wctx.stroke();
                    wctx.beginPath();
                    wctx.moveTo(x2 + gap + 2, y2 + gap + radius + 2);
                    wctx.lineTo(x2 + gap + 2, y2 + ts - gap - radius - 2);
                    wctx.stroke();

                } else {
                    // Interior walls: darker sunken fill
                    var innerBlockGrad = wctx.createLinearGradient(x2, y2, x2 + ts, y2 + ts);
                    if (isLight) {
                        innerBlockGrad.addColorStop(0, 'rgba(35,35,90,0.7)');
                        innerBlockGrad.addColorStop(1, 'rgba(25,25,70,0.8)');
                    } else {
                        innerBlockGrad.addColorStop(0, 'rgba(8,15,45,0.9)');
                        innerBlockGrad.addColorStop(1, 'rgba(5,10,35,0.95)');
                    }
                    wctx.fillStyle = innerBlockGrad;
                    wctx.fillRect(x2, y2, ts, ts);
                }
            }
        }
    }

    // Helper function to draw rounded rectangles
    function roundRect(ctx, x, y, width, height, radius) {
        ctx.beginPath();
        ctx.moveTo(x + radius, y);
        ctx.lineTo(x + width - radius, y);
        ctx.quadraticCurveTo(x + width, y, x + width, y + radius);
        ctx.lineTo(x + width, y + height - radius);
        ctx.quadraticCurveTo(x + width, y + height, x + width - radius, y + height);
        ctx.lineTo(x + radius, y + height);
        ctx.quadraticCurveTo(x, y + height, x, y + height - radius);
        ctx.lineTo(x, y + radius);
        ctx.quadraticCurveTo(x, y, x + radius, y);
        ctx.closePath();
    }

    function isEdgeWall(r, c) {
        // A wall is an edge if it's adjacent to a non-wall cell
        var dirs = [[-1,0],[1,0],[0,-1],[0,1]];
        for (var i = 0; i < dirs.length; i++) {
            var nr = r + dirs[i][0];
            var nc = c + dirs[i][1];
            if (nr < 0 || nr >= maze.length || nc < 0 || nc >= maze[0].length) continue;
            if (maze[nr][nc] !== 0) return true;
        }
        return false;
    }

    function start() {
        state = 'playing';
        startTime = Date.now();
        lastTime = performance.now();
        accumulator = 0;
        NeonInput.reset();
        // Ensure canvas is sized now that screen is visible
        resize();
        loop(performance.now());
    }

    function pause() {
        if (state !== 'playing') return;
        state = 'paused';
        if (frameId) cancelAnimationFrame(frameId);
        NeonUI.showScreen('pause');
    }

    function resume() {
        if (state !== 'paused') return;
        state = 'playing';
        NeonUI.hideOverlay('pause');
        lastTime = performance.now();
        accumulator = 0;
        loop(performance.now());
    }

    function loop(now) {
        if (state !== 'playing') return;

        var dt = now - lastTime;
        lastTime = now;
        if (dt > 100) dt = 100; // Prevent spiral

        accumulator += dt;
        while (accumulator >= FIXED_DT) {
            update(FIXED_DT);
            accumulator -= FIXED_DT;
        }

        render();
        frameId = requestAnimationFrame(loop);
    }

    function update(dt) {
        updatePlayer(dt);
        updateSentinels(dt);
        updatePulse(dt);
        NeonParticles.update();
        checkCollisions();
    }

    function isWalkable(row, col) {
        if (row < 0 || row >= maze.length || col < 0 || col >= maze[0].length) {
            // Wrap around (tunnel)
            return true;
        }
        return maze[row][col] !== 0;
    }

    function wrapCoord(row, col) {
        var rows = maze.length;
        var cols = maze[0].length;
        if (col < 0) col = cols - 1;
        if (col >= cols) col = 0;
        if (row < 0) row = rows - 1;
        if (row >= rows) row = 0;
        return { row: row, col: col };
    }

    function updatePlayer(dt) {
        var queued = NeonInput.peekDirection();

        if (!player.moving) {
            // Try queued direction first
            var tryDir = queued || player.dir;
            if (tryDir && canMove(player.row, player.col, tryDir)) {
                if (queued) {
                    NeonInput.consumeDirection();
                    player.dir = queued;
                }
                var d = DIRS[player.dir];
                var next = wrapCoord(player.row + d.dr, player.col + d.dc);
                player.targetRow = next.row;
                player.targetCol = next.col;
                player.moving = true;
                player.moveProgress = 0;
            } else if (queued && queued !== player.dir && canMove(player.row, player.col, queued)) {
                NeonInput.consumeDirection();
                player.dir = queued;
                var d2 = DIRS[player.dir];
                var next2 = wrapCoord(player.row + d2.dr, player.col + d2.dc);
                player.targetRow = next2.row;
                player.targetCol = next2.col;
                player.moving = true;
                player.moveProgress = 0;
            }
        }

        if (player.moving) {
            var boostMult = NeonInput.isBoostActive() ? 2.2 : 1.0;
            player.moveProgress += (player.speed * boostMult * dt) / MOVE_SCALE;
            if (player.moveProgress >= 1) {
                player.moveProgress = 1;
                player.row = player.targetRow;
                player.col = player.targetCol;
                player.moving = false;
                collectDot(player.row, player.col);
            }

            // Interpolate pixel position
            var startX = player.col * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X;
            var startY = player.row * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y;
            if (player.moving) {
                var fromX = (player.col - (player.targetCol - player.col) * (1 - 0)) ; // This needs proper calc
                // Simpler: lerp from current tile to target tile
                var sx = player.row * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X; // wrong, use col for x
                // Fix: source is current row/col, target is targetRow/targetCol
                var srcX = player.col * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X;
                var srcY = player.row * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y;

                // If wrapping, handle differently
                var dstX = player.targetCol * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X;
                var dstY = player.targetRow * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y;

                // For non-moving state (progress=1), snap to target
                var p = player.moveProgress;
                // Source is where we came from
                var d = DIRS[player.dir];
                var fromRow = player.targetRow - d.dr;
                var fromCol = player.targetCol - d.dc;
                var fX = fromCol * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X;
                var fY = fromRow * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y;

                player.px = fX + (dstX - fX) * p;
                player.py = fY + (dstY - fY) * p;
            }

            // Trail particles
            if (Math.random() < 0.4) {
                NeonParticles.emitTrail(player.px, player.py, pulseMode ? '#ff00ff' : '#00f5ff');
            }
        } else {
            player.px = player.col * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X;
            player.py = player.row * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y;
        }
    }

    function canMove(row, col, dir) {
        var d = DIRS[dir];
        if (!d) return false;
        var nr = row + d.dr;
        var nc = col + d.dc;
        var wrapped = wrapCoord(nr, nc);
        return isWalkable(wrapped.row, wrapped.col);
    }

    function collectDot(row, col) {
        if (row < 0 || row >= dots.length || col < 0 || col >= dots[0].length) return;
        var type = dots[row][col];
        if (type === 0) return;

        dots[row][col] = 0;
        dotsCollected++;
        dotsLeft--;

        if (type === 1) {
            score += DOT_SCORE;
            NeonAudio.dot();
            NeonParticles.emitCollect(player.px, player.py, '#ffff44');
        } else if (type === 2) {
            score += POWER_SCORE;
            NeonAudio.power();
            startPulseMode();
            NeonParticles.emitBurst(player.px, player.py, '#ff00ff', 12);
        }

        NeonUI.updateScore(score);

        if (dotsLeft <= 0) {
            levelComplete();
        }
    }

    function startPulseMode() {
        pulseMode = true;
        pulseStart = Date.now();
        pulseDuration = config.pulseDuration;
        tagCount = 0;
        NeonUI.showPulseTimer(true);

        for (var i = 0; i < sentinels.length; i++) {
            if (sentinels[i].state !== SENTINEL_STATES.RESPAWNING) {
                sentinels[i].state = SENTINEL_STATES.VULNERABLE;
                // Reverse direction
                if (sentinels[i].dir) {
                    sentinels[i].dir = OPPOSITE[sentinels[i].dir] || sentinels[i].dir;
                }
            }
        }
    }

    function updatePulse() {
        if (!pulseMode) return;
        var elapsed = Date.now() - pulseStart;
        var fraction = 1 - (elapsed / pulseDuration);
        NeonUI.updatePulseTimer(Math.max(0, fraction));

        if (elapsed >= pulseDuration) {
            endPulseMode();
        }
    }

    function endPulseMode() {
        pulseMode = false;
        NeonUI.showPulseTimer(false);
        for (var i = 0; i < sentinels.length; i++) {
            if (sentinels[i].state === SENTINEL_STATES.VULNERABLE) {
                sentinels[i].state = SENTINEL_STATES.PATROL;
            }
        }
    }

    function updateSentinels(dt) {
        var now = Date.now();
        for (var i = 0; i < sentinels.length; i++) {
            var s = sentinels[i];

            // Release delay
            if (!s.released) {
                if (now - startTime > s.releaseDelay) {
                    s.released = true;
                } else {
                    continue;
                }
            }

            // Respawning
            if (s.state === SENTINEL_STATES.RESPAWNING) {
                if (now - s.stateTimer > 3000) {
                    s.row = s.homeRow;
                    s.col = s.homeCol;
                    s.px = s.col * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X;
                    s.py = s.row * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y;
                    s.state = SENTINEL_STATES.PATROL;
                    s.moving = false;
                }
                continue;
            }

            // State transitions for non-vulnerable
            if (s.state !== SENTINEL_STATES.VULNERABLE) {
                s.chaseTimer -= dt;
                if (s.chaseTimer <= 0) {
                    if (s.state === SENTINEL_STATES.CHASE) {
                        s.state = SENTINEL_STATES.SCATTER;
                        s.chaseTimer = config.scatterDuration;
                    } else {
                        s.state = SENTINEL_STATES.CHASE;
                        s.chaseTimer = config.chaseInterval;
                    }
                }
            }

            // Movement
            if (!s.moving) {
                var nextDir = chooseSentinelDir(s, i);
                if (nextDir && canMove(s.row, s.col, nextDir)) {
                    s.dir = nextDir;
                    var d = DIRS[s.dir];
                    var next = wrapCoord(s.row + d.dr, s.col + d.dc);
                    s.targetRow = next.row;
                    s.targetCol = next.col;
                    s.moving = true;
                    s.moveProgress = 0;
                }
            }

            if (s.moving) {
                var spd = s.state === SENTINEL_STATES.VULNERABLE ? s.speed * 0.5 : s.speed;
                s.moveProgress += (spd * dt) / MOVE_SCALE;
                if (s.moveProgress >= 1) {
                    s.moveProgress = 1;
                    s.row = s.targetRow;
                    s.col = s.targetCol;
                    s.moving = false;
                }

                // Interpolate
                var d2 = DIRS[s.dir];
                if (d2) {
                    var fromRow = s.targetRow - d2.dr;
                    var fromCol = s.targetCol - d2.dc;
                    var fX = fromCol * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X;
                    var fY = fromRow * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y;
                    var tX = s.targetCol * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X;
                    var tY = s.targetRow * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y;
                    s.px = fX + (tX - fX) * s.moveProgress;
                    s.py = fY + (tY - fY) * s.moveProgress;
                }
            } else {
                s.px = s.col * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X;
                s.py = s.row * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y;
            }
        }
    }

    function chooseSentinelDir(s, idx) {
        var dirs = ['up', 'down', 'left', 'right'];
        var validDirs = [];

        for (var i = 0; i < dirs.length; i++) {
            if (s.dir && dirs[i] === OPPOSITE[s.dir]) continue; // No instant reverse
            if (canMove(s.row, s.col, dirs[i])) {
                validDirs.push(dirs[i]);
            }
        }

        if (validDirs.length === 0) {
            // Dead end - allow reverse
            if (s.dir && canMove(s.row, s.col, OPPOSITE[s.dir])) {
                return OPPOSITE[s.dir];
            }
            return null;
        }

        if (s.state === SENTINEL_STATES.CHASE) {
            // BFS toward player
            return bfsDirection(s.row, s.col, player.row, player.col, validDirs);
        } else if (s.state === SENTINEL_STATES.SCATTER) {
            // Go to assigned corner
            var corners = [
                { row: 1, col: 1 },
                { row: 1, col: maze[0].length - 2 },
                { row: maze.length - 2, col: 1 },
                { row: maze.length - 2, col: maze[0].length - 2 }
            ];
            var corner = corners[idx % 4];
            return bfsDirection(s.row, s.col, corner.row, corner.col, validDirs);
        } else if (s.state === SENTINEL_STATES.VULNERABLE) {
            // Run away from player
            var bestDir = null;
            var bestDist = -1;
            for (var j = 0; j < validDirs.length; j++) {
                var d = DIRS[validDirs[j]];
                var nr = s.row + d.dr;
                var nc = s.col + d.dc;
                var dist = Math.abs(nr - player.row) + Math.abs(nc - player.col);
                if (dist > bestDist) {
                    bestDist = dist;
                    bestDir = validDirs[j];
                }
            }
            return bestDir;
        } else {
            // Patrol: random with bias
            return validDirs[Math.floor(Math.random() * validDirs.length)];
        }
    }

    function bfsDirection(fromRow, fromCol, toRow, toCol, validDirs) {
        // Simple BFS, limited to 100 nodes to avoid jank
        var queue = [{ row: fromRow, col: fromCol, firstDir: null }];
        var visited = {};
        visited[fromRow + ',' + fromCol] = true;
        var limit = 100;

        while (queue.length > 0 && limit-- > 0) {
            var node = queue.shift();
            if (node.row === toRow && node.col === toCol) {
                if (node.firstDir && validDirs.indexOf(node.firstDir) >= 0) {
                    return node.firstDir;
                }
                break;
            }

            var dirs = ['up', 'down', 'left', 'right'];
            for (var i = 0; i < dirs.length; i++) {
                var d = DIRS[dirs[i]];
                var nr = node.row + d.dr;
                var nc = node.col + d.dc;
                var key = nr + ',' + nc;

                if (visited[key]) continue;
                if (!isWalkable(nr, nc)) continue;
                if (nr < 0 || nr >= maze.length || nc < 0 || nc >= maze[0].length) continue;

                visited[key] = true;
                queue.push({
                    row: nr,
                    col: nc,
                    firstDir: node.firstDir || dirs[i]
                });
            }
        }

        // Fallback to closest direction
        var bestDir = validDirs[0];
        var bestDist = Infinity;
        for (var j = 0; j < validDirs.length; j++) {
            var dd = DIRS[validDirs[j]];
            var nr2 = fromRow + dd.dr;
            var nc2 = fromCol + dd.dc;
            var dist = Math.abs(nr2 - toRow) + Math.abs(nc2 - toCol);
            if (dist < bestDist) {
                bestDist = dist;
                bestDir = validDirs[j];
            }
        }
        return bestDir;
    }

    function checkCollisions() {
        for (var i = 0; i < sentinels.length; i++) {
            var s = sentinels[i];
            if (s.state === SENTINEL_STATES.RESPAWNING) continue;
            if (!s.released) continue;

            var dx = Math.abs(player.px - s.px);
            var dy = Math.abs(player.py - s.py);
            var dist = Math.sqrt(dx * dx + dy * dy);

            if (dist < TILE_SIZE * 0.6) {
                if (s.state === SENTINEL_STATES.VULNERABLE) {
                    tagSentinel(s, i);
                } else if (pulseMode) {
                    // Still safe during pulse even if sentinel recovered
                } else {
                    playerHit();
                    return;
                }
            }
        }
    }

    function tagSentinel(s, idx) {
        var tagScore = TAG_SCORES[Math.min(tagCount, TAG_SCORES.length - 1)];
        tagCount++;
        score += tagScore;
        NeonUI.updateScore(score);
        NeonUI.showCombo(tagCount);
        NeonUI.showScorePopup(s.px, s.py - 10, '+' + tagScore, '#ff00ff');
        NeonAudio.tag();
        NeonParticles.emitBurst(s.px, s.py, s.color, 10);

        s.state = SENTINEL_STATES.RESPAWNING;
        s.stateTimer = Date.now();
        s.moving = false;
    }

    function playerHit() {
        lives--;
        NeonUI.updateLives(lives, MAX_LIVES);
        NeonAudio.death();
        NeonParticles.emitBurst(player.px, player.py, '#ff0000', 15);

        if (lives <= 0) {
            gameOver();
        } else {
            // Reset positions
            state = 'dead';
            if (frameId) cancelAnimationFrame(frameId);
            setTimeout(function() {
                resetPositions();
                state = 'playing';
                lastTime = performance.now();
                accumulator = 0;
                loop(performance.now());
            }, 1000);
        }
    }

    function resetPositions() {
        var pPos = NeonLevels.findPlayer(NeonLevels.getMaze(level));
        player.row = pPos.row;
        player.col = pPos.col;
        player.targetRow = pPos.row;
        player.targetCol = pPos.col;
        player.px = pPos.col * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X;
        player.py = pPos.row * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y;
        player.dir = null;
        player.moving = false;

        for (var i = 0; i < sentinels.length; i++) {
            var s = sentinels[i];
            s.row = s.homeRow;
            s.col = s.homeCol;
            s.px = s.col * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X;
            s.py = s.row * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y;
            s.dir = null;
            s.moving = false;
            s.state = SENTINEL_STATES.PATROL;
            s.released = i === 0;
            s.releaseDelay = i * 1500;
        }

        if (pulseMode) endPulseMode();
        NeonInput.reset();
        startTime = Date.now();
    }

    function levelComplete() {
        state = 'levelComplete';
        if (frameId) cancelAnimationFrame(frameId);
        NeonAudio.levelUp();

        var elapsed = Date.now() - startTime;
        var timeBonus = Math.min(Math.floor((60000 - elapsed) / 1000) * config.timeBonusPerSec, config.timeBonusMax);
        if (timeBonus < 0) timeBonus = 0;

        score += config.levelBonus + timeBonus;
        NeonUI.updateScore(score);
        NeonUI.showLevelComplete(config.levelBonus, timeBonus, level + 1);

        setTimeout(function() {
            NeonUI.hideOverlay('levelComplete');
            level++;
            startLevel(level);
            start();
        }, 1800);
    }

    function gameOver() {
        state = 'gameOver';
        if (frameId) cancelAnimationFrame(frameId);
        totalDuration = Date.now() - startTime;

        var newBest = NeonStorage.saveBest(score, level);

        // Submit score
        NeonAPI.submitScore({
            score: score,
            level: level,
            dots: dotsCollected,
            duration: totalDuration
        }, function(err, result) {
            NeonUI.showResults({
                title: 'GAME OVER',
                score: score,
                level: level,
                dots: dotsCollected,
                duration: totalDuration,
                newBest: newBest,
                synced: result ? result.synced : false
            });
        });

        if (onGameOver) onGameOver({ score: score, level: level });
    }

    function render() {
        var w = canvas.offsetWidth;
        var h = canvas.offsetHeight;
        if (w <= 0 || h <= 0) return;

        // Clear with gradient background
        var isLight = document.documentElement.getAttribute('data-theme') === 'light';
        if (isLight) {
            ctx.fillStyle = '#e0e0f0';
        } else {
            var bgGrad = ctx.createRadialGradient(w / 2, h / 2, 0, w / 2, h / 2, w * 0.7);
            bgGrad.addColorStop(0, '#0a0a2a');
            bgGrad.addColorStop(0.5, '#060618');
            bgGrad.addColorStop(1, '#030308');
            ctx.fillStyle = bgGrad;
        }
        ctx.fillRect(0, 0, w, h);

        // Subtle grid underlay
        if (!isLight) {
            ctx.strokeStyle = 'rgba(0,80,180,0.04)';
            ctx.lineWidth = 0.5;
            for (var gx = OFFSET_X % TILE_SIZE; gx < w; gx += TILE_SIZE) {
                ctx.beginPath();
                ctx.moveTo(gx, 0);
                ctx.lineTo(gx, h);
                ctx.stroke();
            }
            for (var gy = OFFSET_Y % TILE_SIZE; gy < h; gy += TILE_SIZE) {
                ctx.beginPath();
                ctx.moveTo(0, gy);
                ctx.lineTo(w, gy);
                ctx.stroke();
            }
        }

        // Draw walls (prerendered)
        if (wallCanvas && wallCanvas.width > 0 && wallCanvas.height > 0) {
            ctx.drawImage(wallCanvas, 0, 0, w, h);
        }

        // Draw dots
        renderDots();

        // Draw sentinels
        renderSentinels();

        // Draw particles (behind player)
        NeonParticles.draw(ctx);

        // Draw player
        renderPlayer();
    }

    function renderDots() {
        if (!dots) return;
        var time = Date.now();

        for (var r = 0; r < dots.length; r++) {
            for (var c = 0; c < dots[r].length; c++) {
                var type = dots[r][c];
                if (type === 0) continue;

                var x = c * TILE_SIZE + TILE_SIZE / 2 + OFFSET_X;
                var y = r * TILE_SIZE + TILE_SIZE / 2 + OFFSET_Y;

                if (type === 1) {
                    // 3D Spark Dot - golden sphere
                    var dotRadius = TILE_SIZE * 0.14;

                    // Outer glow
                    ctx.shadowColor = '#ffcc00';
                    ctx.shadowBlur = 8;

                    // 3D sphere gradient (light from top-left)
                    var dotGrad = ctx.createRadialGradient(
                        x - dotRadius * 0.3, y - dotRadius * 0.3, 0,
                        x, y, dotRadius
                    );
                    dotGrad.addColorStop(0, '#ffffff');
                    dotGrad.addColorStop(0.2, '#ffffcc');
                    dotGrad.addColorStop(0.5, '#ffdd44');
                    dotGrad.addColorStop(0.8, '#cc9900');
                    dotGrad.addColorStop(1, '#886600');
                    ctx.fillStyle = dotGrad;
                    ctx.beginPath();
                    ctx.arc(x, y, dotRadius, 0, Math.PI * 2);
                    ctx.fill();

                    // Specular highlight
                    ctx.shadowBlur = 0;
                    ctx.fillStyle = 'rgba(255,255,255,0.8)';
                    ctx.beginPath();
                    ctx.arc(x - dotRadius * 0.3, y - dotRadius * 0.3, dotRadius * 0.25, 0, Math.PI * 2);
                    ctx.fill();

                } else if (type === 2) {
                    // 3D Pulse Orb - glowing magenta sphere
                    var pulse = 0.85 + Math.sin(time * 0.005) * 0.15;
                    var orbRadius = TILE_SIZE * 0.28 * pulse;

                    // Outer bloom glow
                    ctx.shadowColor = '#ff00ff';
                    ctx.shadowBlur = 20 * pulse;
                    ctx.globalAlpha = 0.3;
                    ctx.fillStyle = '#ff44ff';
                    ctx.beginPath();
                    ctx.arc(x, y, orbRadius * 1.8, 0, Math.PI * 2);
                    ctx.fill();

                    // Mid glow ring
                    ctx.globalAlpha = 0.5;
                    ctx.beginPath();
                    ctx.arc(x, y, orbRadius * 1.3, 0, Math.PI * 2);
                    ctx.fill();

                    // Core 3D sphere
                    ctx.globalAlpha = 1;
                    ctx.shadowBlur = 15;
                    var orbGrad = ctx.createRadialGradient(
                        x - orbRadius * 0.25, y - orbRadius * 0.25, 0,
                        x, y, orbRadius
                    );
                    orbGrad.addColorStop(0, '#ffffff');
                    orbGrad.addColorStop(0.15, '#ffccff');
                    orbGrad.addColorStop(0.4, '#ff66ff');
                    orbGrad.addColorStop(0.7, '#dd00dd');
                    orbGrad.addColorStop(1, '#880088');
                    ctx.fillStyle = orbGrad;
                    ctx.beginPath();
                    ctx.arc(x, y, orbRadius, 0, Math.PI * 2);
                    ctx.fill();

                    // Inner energy core
                    ctx.shadowBlur = 0;
                    var coreGrad = ctx.createRadialGradient(x, y, 0, x, y, orbRadius * 0.5);
                    coreGrad.addColorStop(0, 'rgba(255,255,255,0.9)');
                    coreGrad.addColorStop(0.5, 'rgba(255,150,255,0.4)');
                    coreGrad.addColorStop(1, 'rgba(255,0,255,0)');
                    ctx.fillStyle = coreGrad;
                    ctx.beginPath();
                    ctx.arc(x, y, orbRadius * 0.6, 0, Math.PI * 2);
                    ctx.fill();

                    // Specular highlight
                    ctx.fillStyle = 'rgba(255,255,255,0.85)';
                    ctx.beginPath();
                    ctx.arc(x - orbRadius * 0.3, y - orbRadius * 0.3, orbRadius * 0.2, 0, Math.PI * 2);
                    ctx.fill();
                }
            }
        }
        ctx.shadowBlur = 0;
        ctx.globalAlpha = 1;
    }

    function renderPlayer() {
        var time = Date.now();
        var pulse = 0.92 + Math.sin(time * 0.006) * 0.08;
        var radius = TILE_SIZE * 0.38 * pulse;
        var px = player.px;
        var py = player.py;

        // Colors based on mode
        var primaryColor = pulseMode ? '#ff00ff' : '#00f5ff';
        var lightColor = pulseMode ? '#ffaaff' : '#aaffff';
        var darkColor = pulseMode ? '#880088' : '#006688';

        // Outer bloom layer 1 (largest, softest)
        ctx.shadowColor = primaryColor;
        ctx.shadowBlur = 35;
        ctx.globalAlpha = 0.12;
        ctx.fillStyle = primaryColor;
        ctx.beginPath();
        ctx.arc(px, py, radius * 2.5, 0, Math.PI * 2);
        ctx.fill();

        // Outer bloom layer 2
        ctx.shadowBlur = 25;
        ctx.globalAlpha = 0.2;
        ctx.beginPath();
        ctx.arc(px, py, radius * 1.8, 0, Math.PI * 2);
        ctx.fill();

        // Mid glow layer
        ctx.shadowBlur = 18;
        ctx.globalAlpha = 0.35;
        ctx.beginPath();
        ctx.arc(px, py, radius * 1.4, 0, Math.PI * 2);
        ctx.fill();

        // Ground shadow (subtle)
        ctx.shadowBlur = 0;
        ctx.globalAlpha = 0.2;
        ctx.fillStyle = 'rgba(0,0,0,0.5)';
        ctx.beginPath();
        ctx.ellipse(px + 2, py + radius * 0.9, radius * 0.7, radius * 0.25, 0, 0, Math.PI * 2);
        ctx.fill();

        // Main 3D orb with complex gradient
        ctx.globalAlpha = 1;
        ctx.shadowColor = primaryColor;
        ctx.shadowBlur = 15;

        var orbGrad = ctx.createRadialGradient(
            px - radius * 0.3, py - radius * 0.3, 0,
            px, py, radius
        );
        orbGrad.addColorStop(0, '#ffffff');
        orbGrad.addColorStop(0.15, lightColor);
        orbGrad.addColorStop(0.4, primaryColor);
        orbGrad.addColorStop(0.75, darkColor);
        orbGrad.addColorStop(1, 'rgba(0,0,40,0.8)');
        ctx.fillStyle = orbGrad;
        ctx.beginPath();
        ctx.arc(px, py, radius, 0, Math.PI * 2);
        ctx.fill();

        // Inner energy core
        ctx.shadowBlur = 0;
        var coreGrad = ctx.createRadialGradient(px, py, 0, px, py, radius * 0.5);
        coreGrad.addColorStop(0, 'rgba(255,255,255,0.9)');
        coreGrad.addColorStop(0.4, pulseMode ? 'rgba(255,200,255,0.5)' : 'rgba(200,255,255,0.5)');
        coreGrad.addColorStop(1, 'rgba(255,255,255,0)');
        ctx.fillStyle = coreGrad;
        ctx.beginPath();
        ctx.arc(px, py, radius * 0.55, 0, Math.PI * 2);
        ctx.fill();

        // Rim light (edge highlight - 3D effect)
        ctx.globalAlpha = 0.4;
        ctx.strokeStyle = lightColor;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(px, py, radius - 1, Math.PI * 0.8, Math.PI * 1.4);
        ctx.stroke();

        // Primary specular highlight
        ctx.globalAlpha = 0.9;
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.ellipse(px - radius * 0.28, py - radius * 0.28, radius * 0.22, radius * 0.15, -Math.PI / 4, 0, Math.PI * 2);
        ctx.fill();

        // Secondary smaller highlight
        ctx.globalAlpha = 0.5;
        ctx.beginPath();
        ctx.arc(px - radius * 0.1, py - radius * 0.4, radius * 0.08, 0, Math.PI * 2);
        ctx.fill();

        // Animated energy ring
        var ringPhase = (time * 0.003) % (Math.PI * 2);
        ctx.globalAlpha = 0.3 + Math.sin(time * 0.008) * 0.15;
        ctx.strokeStyle = primaryColor;
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.arc(px, py, radius * 1.15, ringPhase, ringPhase + Math.PI * 1.2);
        ctx.stroke();

        ctx.globalAlpha = 1;
        ctx.shadowBlur = 0;
    }

    function renderSentinels() {
        var time = Date.now();

        for (var i = 0; i < sentinels.length; i++) {
            var s = sentinels[i];
            if (s.state === SENTINEL_STATES.RESPAWNING) continue;
            if (!s.released) continue;

            var color = s.color;
            var radius = TILE_SIZE * 0.38;

            if (s.state === SENTINEL_STATES.VULNERABLE) {
                var flash = Math.sin(time * 0.01) > 0;
                var elapsed = Date.now() - pulseStart;
                if (elapsed > pulseDuration * 0.7) {
                    flash = Math.sin(time * 0.02) > 0;
                }
                color = flash ? '#4444ff' : '#ffffff';
            }

            var shimmer = 0.9 + Math.sin(time * 0.006 + i * 2) * 0.1;
            var sx = s.px;
            var sy = s.py;

            // Outer glow/shadow
            ctx.shadowColor = color;
            ctx.shadowBlur = 15;
            ctx.globalAlpha = 0.4;
            ctx.fillStyle = color;
            ctx.beginPath();
            ctx.arc(sx, sy, radius * 1.1, 0, Math.PI * 2);
            ctx.fill();

            // Draw sentinel body path for clipping
            ctx.globalAlpha = shimmer;
            ctx.shadowBlur = 10;

            // Create body path
            ctx.beginPath();
            ctx.moveTo(sx, sy - radius);
            ctx.quadraticCurveTo(sx + radius, sy - radius * 0.3, sx + radius, sy + radius * 0.3);
            ctx.lineTo(sx + radius * 0.7, sy + radius);
            ctx.lineTo(sx + radius * 0.3, sy + radius * 0.6);
            ctx.lineTo(sx, sy + radius);
            ctx.lineTo(sx - radius * 0.3, sy + radius * 0.6);
            ctx.lineTo(sx - radius * 0.7, sy + radius);
            ctx.lineTo(sx - radius, sy + radius * 0.3);
            ctx.quadraticCurveTo(sx - radius, sy - radius * 0.3, sx, sy - radius);
            ctx.closePath();

            // 3D body gradient (light from top-left)
            var bodyGrad = ctx.createLinearGradient(
                sx - radius, sy - radius,
                sx + radius, sy + radius
            );
            // Parse color and create lighter/darker versions
            var baseColor = color;
            bodyGrad.addColorStop(0, lightenColor(baseColor, 40));
            bodyGrad.addColorStop(0.3, baseColor);
            bodyGrad.addColorStop(0.7, darkenColor(baseColor, 30));
            bodyGrad.addColorStop(1, darkenColor(baseColor, 50));
            ctx.fillStyle = bodyGrad;
            ctx.fill();

            // Top highlight arc
            ctx.globalAlpha = 0.4;
            ctx.shadowBlur = 0;
            var highlightGrad = ctx.createLinearGradient(sx, sy - radius, sx, sy);
            highlightGrad.addColorStop(0, 'rgba(255,255,255,0.8)');
            highlightGrad.addColorStop(1, 'rgba(255,255,255,0)');
            ctx.fillStyle = highlightGrad;
            ctx.beginPath();
            ctx.ellipse(sx, sy - radius * 0.3, radius * 0.6, radius * 0.4, 0, 0, Math.PI * 2);
            ctx.fill();

            // Bottom tentacle shadows
            ctx.globalAlpha = 0.3;
            ctx.fillStyle = 'rgba(0,0,0,0.4)';
            ctx.beginPath();
            ctx.ellipse(sx, sy + radius * 0.8, radius * 0.7, radius * 0.25, 0, 0, Math.PI * 2);
            ctx.fill();

            // 3D Eyes with depth
            ctx.globalAlpha = 1;
            var eyeRadius = radius * 0.2;
            var eyeY = sy - radius * 0.1;

            // Eye sockets (shadow)
            ctx.fillStyle = 'rgba(0,0,0,0.3)';
            ctx.beginPath();
            ctx.arc(sx - radius * 0.28, eyeY + 2, eyeRadius * 1.1, 0, Math.PI * 2);
            ctx.arc(sx + radius * 0.28, eyeY + 2, eyeRadius * 1.1, 0, Math.PI * 2);
            ctx.fill();

            // Eyeballs with 3D gradient
            var eyeGrad = ctx.createRadialGradient(
                sx - radius * 0.28 - eyeRadius * 0.2, eyeY - eyeRadius * 0.2, 0,
                sx - radius * 0.28, eyeY, eyeRadius
            );
            eyeGrad.addColorStop(0, '#ffffff');
            eyeGrad.addColorStop(0.7, '#eeeeff');
            eyeGrad.addColorStop(1, '#ccccdd');
            ctx.fillStyle = eyeGrad;
            ctx.beginPath();
            ctx.arc(sx - radius * 0.28, eyeY, eyeRadius, 0, Math.PI * 2);
            ctx.fill();

            var eyeGrad2 = ctx.createRadialGradient(
                sx + radius * 0.28 - eyeRadius * 0.2, eyeY - eyeRadius * 0.2, 0,
                sx + radius * 0.28, eyeY, eyeRadius
            );
            eyeGrad2.addColorStop(0, '#ffffff');
            eyeGrad2.addColorStop(0.7, '#eeeeff');
            eyeGrad2.addColorStop(1, '#ccccdd');
            ctx.fillStyle = eyeGrad2;
            ctx.beginPath();
            ctx.arc(sx + radius * 0.28, eyeY, eyeRadius, 0, Math.PI * 2);
            ctx.fill();

            // Pupils with highlight
            var pupilColor = s.state === SENTINEL_STATES.VULNERABLE ? '#cc0000' : '#111133';
            var pupilRadius = eyeRadius * 0.45;

            ctx.fillStyle = pupilColor;
            ctx.beginPath();
            ctx.arc(sx - radius * 0.28, eyeY + 1, pupilRadius, 0, Math.PI * 2);
            ctx.arc(sx + radius * 0.28, eyeY + 1, pupilRadius, 0, Math.PI * 2);
            ctx.fill();

            // Pupil highlights
            ctx.fillStyle = 'rgba(255,255,255,0.7)';
            ctx.beginPath();
            ctx.arc(sx - radius * 0.28 - pupilRadius * 0.3, eyeY - pupilRadius * 0.2, pupilRadius * 0.35, 0, Math.PI * 2);
            ctx.arc(sx + radius * 0.28 - pupilRadius * 0.3, eyeY - pupilRadius * 0.2, pupilRadius * 0.35, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.globalAlpha = 1;
        ctx.shadowBlur = 0;
    }

    // Helper functions for 3D color manipulation
    function lightenColor(hex, percent) {
        var r = parseInt(hex.slice(1, 3), 16);
        var g = parseInt(hex.slice(3, 5), 16);
        var b = parseInt(hex.slice(5, 7), 16);
        r = Math.min(255, r + (255 - r) * percent / 100);
        g = Math.min(255, g + (255 - g) * percent / 100);
        b = Math.min(255, b + (255 - b) * percent / 100);
        return 'rgb(' + Math.round(r) + ',' + Math.round(g) + ',' + Math.round(b) + ')';
    }

    function darkenColor(hex, percent) {
        var r = parseInt(hex.slice(1, 3), 16);
        var g = parseInt(hex.slice(3, 5), 16);
        var b = parseInt(hex.slice(5, 7), 16);
        r = Math.max(0, r * (100 - percent) / 100);
        g = Math.max(0, g * (100 - percent) / 100);
        b = Math.max(0, b * (100 - percent) / 100);
        return 'rgb(' + Math.round(r) + ',' + Math.round(g) + ',' + Math.round(b) + ')';
    }

    function getState() { return state; }
    function getScore() { return score; }
    function getLevel() { return level; }

    function setCallbacks(cbs) {
        onGameOver = cbs.onGameOver || null;
        onLevelComplete = cbs.onLevelComplete || null;
    }

    function reset() {
        if (frameId) cancelAnimationFrame(frameId);
        state = 'idle';
        score = 0;
        lives = MAX_LIVES;
        level = 1;
        dotsCollected = 0;
        totalDuration = 0;
        pulseMode = false;
        NeonParticles.clear();
        NeonUI.updateScore(0);
    }

    return {
        init: init,
        resize: resize,
        startLevel: startLevel,
        start: start,
        pause: pause,
        resume: resume,
        reset: reset,
        getState: getState,
        getScore: getScore,
        getLevel: getLevel,
        setCallbacks: setCallbacks,
        render: render
    };
})();
