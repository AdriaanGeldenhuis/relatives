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

        // Draw outer glow pass first (bloom effect)
        for (var r = 0; r < maze.length; r++) {
            for (var c = 0; c < maze[r].length; c++) {
                if (maze[r][c] !== 0) continue;
                // Only glow edges (walls adjacent to paths)
                if (!isEdgeWall(r, c)) continue;

                var x = c * TILE_SIZE + OFFSET_X;
                var y = r * TILE_SIZE + OFFSET_Y;
                var cx = x + TILE_SIZE / 2;
                var cy = y + TILE_SIZE / 2;

                wctx.shadowColor = isLight ? 'rgba(80,80,200,0.4)' : 'rgba(0,150,255,0.5)';
                wctx.shadowBlur = 12;
                wctx.fillStyle = 'transparent';
                wctx.beginPath();
                wctx.arc(cx, cy, TILE_SIZE * 0.35, 0, Math.PI * 2);
                wctx.fill();
            }
        }
        wctx.shadowBlur = 0;

        // Draw wall tiles with edge-aware rendering
        for (var r2 = 0; r2 < maze.length; r2++) {
            for (var c2 = 0; c2 < maze[r2].length; c2++) {
                if (maze[r2][c2] !== 0) continue;
                var x2 = c2 * TILE_SIZE + OFFSET_X;
                var y2 = r2 * TILE_SIZE + OFFSET_Y;
                var edge = isEdgeWall(r2, c2);

                if (edge) {
                    // Edge walls: bright neon border
                    var grad = wctx.createLinearGradient(x2, y2, x2 + TILE_SIZE, y2 + TILE_SIZE);
                    if (isLight) {
                        grad.addColorStop(0, 'rgba(60,60,160,0.9)');
                        grad.addColorStop(1, 'rgba(80,80,200,0.7)');
                    } else {
                        grad.addColorStop(0, 'rgba(0,80,200,0.9)');
                        grad.addColorStop(1, 'rgba(20,40,150,0.7)');
                    }
                    wctx.fillStyle = grad;
                    wctx.fillRect(x2 + 1, y2 + 1, TILE_SIZE - 2, TILE_SIZE - 2);

                    // Bright edge line
                    wctx.strokeStyle = isLight ? 'rgba(100,100,220,0.8)' : 'rgba(0,180,255,0.6)';
                    wctx.lineWidth = 1.5;
                    wctx.strokeRect(x2 + 1.5, y2 + 1.5, TILE_SIZE - 3, TILE_SIZE - 3);
                } else {
                    // Interior walls: darker fill
                    wctx.fillStyle = isLight ? 'rgba(50,50,120,0.5)' : 'rgba(10,15,60,0.8)';
                    wctx.fillRect(x2, y2, TILE_SIZE, TILE_SIZE);
                }
            }
        }
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
                    // Spark Dot
                    ctx.fillStyle = '#ffff88';
                    ctx.shadowColor = '#ffff00';
                    ctx.shadowBlur = 4;
                    ctx.beginPath();
                    ctx.arc(x, y, TILE_SIZE * 0.12, 0, Math.PI * 2);
                    ctx.fill();
                } else if (type === 2) {
                    // Pulse Orb
                    var pulse = 0.8 + Math.sin(time * 0.005) * 0.3;
                    ctx.fillStyle = '#ff66ff';
                    ctx.shadowColor = '#ff00ff';
                    ctx.shadowBlur = 10 * pulse;
                    ctx.beginPath();
                    ctx.arc(x, y, TILE_SIZE * 0.25 * pulse, 0, Math.PI * 2);
                    ctx.fill();
                }
            }
        }
        ctx.shadowBlur = 0;
    }

    function renderPlayer() {
        var time = Date.now();
        var pulse = 0.9 + Math.sin(time * 0.006) * 0.1;
        var radius = TILE_SIZE * 0.35 * pulse;
        var color = pulseMode ? '#ff00ff' : '#00f5ff';
        var color2 = pulseMode ? '#ff88ff' : '#88ffff';

        // Outer bloom (large, soft)
        ctx.shadowColor = color;
        ctx.shadowBlur = 25;
        ctx.globalAlpha = 0.15;
        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.arc(player.px, player.py, radius * 2.2, 0, Math.PI * 2);
        ctx.fill();

        // Mid glow
        ctx.shadowBlur = 15;
        ctx.globalAlpha = 0.35;
        ctx.beginPath();
        ctx.arc(player.px, player.py, radius * 1.5, 0, Math.PI * 2);
        ctx.fill();

        // Core orb with gradient
        ctx.globalAlpha = 1;
        ctx.shadowBlur = 12;
        var grad = ctx.createRadialGradient(
            player.px - radius * 0.2, player.py - radius * 0.2, 0,
            player.px, player.py, radius
        );
        grad.addColorStop(0, '#ffffff');
        grad.addColorStop(0.3, color2);
        grad.addColorStop(0.7, color);
        grad.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.arc(player.px, player.py, radius, 0, Math.PI * 2);
        ctx.fill();

        // Specular highlight
        ctx.shadowBlur = 0;
        ctx.globalAlpha = 0.6;
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.arc(player.px - radius * 0.25, player.py - radius * 0.25, radius * 0.2, 0, Math.PI * 2);
        ctx.fill();

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
            var radius = TILE_SIZE * 0.35;

            if (s.state === SENTINEL_STATES.VULNERABLE) {
                // Flashing blue when vulnerable
                var flash = Math.sin(time * 0.01) > 0;
                // Blink faster near end
                var elapsed = Date.now() - pulseStart;
                if (elapsed > pulseDuration * 0.7) {
                    flash = Math.sin(time * 0.02) > 0;
                }
                color = flash ? '#4444ff' : '#ffffff';
            }

            // Shimmer
            var shimmer = 0.85 + Math.sin(time * 0.006 + i * 2) * 0.15;

            // Body (diamond/rounded shape)
            ctx.shadowColor = color;
            ctx.shadowBlur = 8;
            ctx.fillStyle = color;
            ctx.globalAlpha = shimmer;

            ctx.beginPath();
            // Draw a rounded sentinel shape
            var sx = s.px;
            var sy = s.py;
            ctx.moveTo(sx, sy - radius);
            ctx.quadraticCurveTo(sx + radius, sy - radius * 0.3, sx + radius, sy + radius * 0.3);
            ctx.lineTo(sx + radius * 0.7, sy + radius);
            ctx.lineTo(sx + radius * 0.3, sy + radius * 0.6);
            ctx.lineTo(sx, sy + radius);
            ctx.lineTo(sx - radius * 0.3, sy + radius * 0.6);
            ctx.lineTo(sx - radius * 0.7, sy + radius);
            ctx.lineTo(sx - radius, sy + radius * 0.3);
            ctx.quadraticCurveTo(sx - radius, sy - radius * 0.3, sx, sy - radius);
            ctx.fill();

            // Eyes
            ctx.globalAlpha = 1;
            ctx.shadowBlur = 0;
            ctx.fillStyle = '#fff';
            ctx.beginPath();
            ctx.arc(sx - radius * 0.25, sy - radius * 0.1, radius * 0.18, 0, Math.PI * 2);
            ctx.arc(sx + radius * 0.25, sy - radius * 0.1, radius * 0.18, 0, Math.PI * 2);
            ctx.fill();

            // Pupils
            ctx.fillStyle = s.state === SENTINEL_STATES.VULNERABLE ? '#ff0000' : '#111';
            ctx.beginPath();
            ctx.arc(sx - radius * 0.25, sy - radius * 0.05, radius * 0.08, 0, Math.PI * 2);
            ctx.arc(sx + radius * 0.25, sy - radius * 0.05, radius * 0.08, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.globalAlpha = 1;
        ctx.shadowBlur = 0;
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
