/* =============================================
   BLOCKFORGE - Canvas 2D Renderer with Neon Glow
   ============================================= */

var BlockRenderer = (function() {
    'use strict';

    var canvas, ctx;
    var nextCanvas, nextCtx;
    var cellSize = 28;
    var boardCols = 10;
    var boardRows = 20;
    var offsetX = 0, offsetY = 0;
    var animTime = 0;
    var theme = 'neon-dark';
    var lineClearRows = [];
    var lineClearAnim = 0;
    var squashPieces = [];
    var dropTrails = [];

    // Theme colors
    var themes = {
        'neon-dark': {
            bg: '#0a0a1a',
            gridLine: 'rgba(255, 255, 255, 0.03)',
            gridDot: 'rgba(255, 255, 255, 0.06)',
            border: 'rgba(0, 245, 255, 0.15)',
            ghostAlpha: 0.2
        },
        'neon-light': {
            bg: '#e8e8f0',
            gridLine: 'rgba(0, 0, 0, 0.05)',
            gridDot: 'rgba(0, 0, 0, 0.08)',
            border: 'rgba(0, 100, 200, 0.2)',
            ghostAlpha: 0.15
        },
        'synthwave': {
            bg: '#1a0533',
            gridLine: 'rgba(255, 100, 200, 0.04)',
            gridDot: 'rgba(255, 100, 200, 0.08)',
            border: 'rgba(255, 100, 200, 0.2)',
            ghostAlpha: 0.2
        }
    };

    function init(canvasId) {
        canvas = document.getElementById(canvasId);
        ctx = canvas.getContext('2d');

        nextCanvas = document.getElementById('next-canvas-1');
        nextCtx = nextCanvas.getContext('2d');

        resize();
        return { canvas: canvas, ctx: ctx };
    }

    function resize() {
        var wrapper = canvas.parentElement;
        var maxWidth = wrapper.clientWidth;
        var maxHeight = wrapper.clientHeight || (window.innerHeight * 0.55);

        // Calculate cell size to fit
        var csFromWidth = Math.floor(maxWidth / boardCols);
        var csFromHeight = Math.floor(maxHeight / boardRows);
        cellSize = Math.min(csFromWidth, csFromHeight, 32);
        cellSize = Math.max(cellSize, 16);

        canvas.width = boardCols * cellSize;
        canvas.height = boardRows * cellSize;

        offsetX = 0;
        offsetY = 0;

        // Size next canvas
        var previewCell = Math.floor(cellSize * 0.7);
        nextCanvas.width = previewCell * 4 + 8;
        nextCanvas.height = previewCell * 4 + 8;
    }

    function setTheme(t) {
        theme = t;
    }

    function getTheme() {
        return themes[theme] || themes['neon-dark'];
    }

    function getCellSize() {
        return cellSize;
    }

    function getOffset() {
        return { x: offsetX, y: offsetY };
    }

    function getCanvasSize() {
        return { width: canvas.width, height: canvas.height };
    }

    // Draw a single block cell with neon glow
    function drawCell(cx, x, y, cs, color, glowColor, shadowColor, alpha) {
        var px = x * cs;
        var py = y * cs;
        var a = alpha || 1;

        cx.globalAlpha = a;

        // Outer glow
        cx.shadowColor = glowColor || color;
        cx.shadowBlur = cs * 0.4;

        // Main block with gradient
        var grad = cx.createLinearGradient(px, py, px + cs, py + cs);
        grad.addColorStop(0, lightenColor(color, 30));
        grad.addColorStop(0.5, color);
        grad.addColorStop(1, darkenColor(color, 30));

        cx.fillStyle = grad;
        cx.beginPath();
        cx.roundRect(px + 1, py + 1, cs - 2, cs - 2, cs * 0.15);
        cx.fill();

        cx.shadowBlur = 0;

        // Inner shine (top-left highlight)
        cx.globalAlpha = a * 0.4;
        var shineGrad = cx.createLinearGradient(px, py, px + cs * 0.6, py + cs * 0.6);
        shineGrad.addColorStop(0, 'rgba(255, 255, 255, 0.5)');
        shineGrad.addColorStop(1, 'rgba(255, 255, 255, 0)');
        cx.fillStyle = shineGrad;
        cx.beginPath();
        cx.roundRect(px + 2, py + 2, cs - 4, cs - 4, cs * 0.12);
        cx.fill();

        // Edge glow (neon border)
        cx.globalAlpha = a * 0.7;
        cx.strokeStyle = glowColor || color;
        cx.lineWidth = 1.5;
        cx.shadowColor = glowColor || color;
        cx.shadowBlur = cs * 0.25;
        cx.beginPath();
        cx.roundRect(px + 1.5, py + 1.5, cs - 3, cs - 3, cs * 0.15);
        cx.stroke();

        cx.shadowBlur = 0;
        cx.globalAlpha = 1;
    }

    // Draw ghost piece
    function drawGhostCell(cx, x, y, cs, color) {
        var px = x * cs;
        var py = y * cs;
        var t = getTheme();

        cx.globalAlpha = t.ghostAlpha;
        cx.strokeStyle = color;
        cx.lineWidth = 1.5;
        cx.setLineDash([3, 3]);
        cx.beginPath();
        cx.roundRect(px + 2, py + 2, cs - 4, cs - 4, cs * 0.12);
        cx.stroke();
        cx.setLineDash([]);
        cx.globalAlpha = 1;
    }

    // Render the board grid
    function renderGrid() {
        var t = getTheme();

        // Background
        ctx.fillStyle = t.bg;
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // Grid dots at intersections
        ctx.fillStyle = t.gridDot;
        for (var r = 0; r <= boardRows; r++) {
            for (var c = 0; c <= boardCols; c++) {
                ctx.beginPath();
                ctx.arc(c * cellSize, r * cellSize, 1, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        // Subtle grid lines
        ctx.strokeStyle = t.gridLine;
        ctx.lineWidth = 0.5;
        for (var r2 = 0; r2 <= boardRows; r2++) {
            ctx.beginPath();
            ctx.moveTo(0, r2 * cellSize);
            ctx.lineTo(canvas.width, r2 * cellSize);
            ctx.stroke();
        }
        for (var c2 = 0; c2 <= boardCols; c2++) {
            ctx.beginPath();
            ctx.moveTo(c2 * cellSize, 0);
            ctx.lineTo(c2 * cellSize, canvas.height);
            ctx.stroke();
        }
    }

    // Render placed blocks on board
    function renderBoard(grid) {
        for (var r = 0; r < boardRows; r++) {
            for (var c = 0; c < boardCols; c++) {
                var cell = grid[r] && grid[r][c];
                if (cell) {
                    var inClear = lineClearRows.indexOf(r) !== -1;
                    if (inClear) {
                        var flash = Math.sin(lineClearAnim * Math.PI * 4) * 0.5 + 0.5;
                        drawCell(ctx, c, r, cellSize, '#ffffff', '#ffffff', '#ffffff', flash);
                    } else {
                        drawCell(ctx, c, r, cellSize, cell.color, cell.glowColor, cell.shadowColor, 1);
                    }
                }
            }
        }
    }

    // Render active piece
    function renderPiece(piece, ghost) {
        if (!piece) return;
        var shape = BlockPieces.getShape(piece.name, piece.rotation);
        var color = BlockPieces.getColor(piece.name);
        var glowColor = BlockPieces.getGlowColor(piece.name);
        var shadowColor = BlockPieces.getShadowColor(piece.name);

        // Draw ghost first
        if (ghost && ghost.y !== piece.y) {
            for (var gr = 0; gr < shape.length; gr++) {
                for (var gc = 0; gc < shape[gr].length; gc++) {
                    if (shape[gr][gc]) {
                        var gx = ghost.x + gc;
                        var gy = ghost.y + gr;
                        if (gy >= 0) {
                            drawGhostCell(ctx, gx, gy, cellSize, color);
                        }
                    }
                }
            }
        }

        // Draw piece
        for (var r = 0; r < shape.length; r++) {
            for (var c = 0; c < shape[r].length; c++) {
                if (shape[r][c]) {
                    var px = piece.x + c;
                    var py = piece.y + r;
                    if (py >= 0) {
                        // Check for squash animation
                        var squash = getSquash(px, py);
                        if (squash) {
                            ctx.save();
                            var cx2 = px * cellSize + cellSize / 2;
                            var cy2 = py * cellSize + cellSize / 2;
                            ctx.translate(cx2, cy2);
                            ctx.scale(1 + squash.sx, 1 + squash.sy);
                            ctx.translate(-cx2, -cy2);
                            drawCell(ctx, px, py, cellSize, color, glowColor, shadowColor, 1);
                            ctx.restore();
                        } else {
                            drawCell(ctx, px, py, cellSize, color, glowColor, shadowColor, 1);
                        }
                    }
                }
            }
        }
    }

    // Render next piece in side panel (only 1)
    function renderNext(pieceNames) {
        var previewCell = Math.floor(cellSize * 0.7);
        nextCtx.clearRect(0, 0, nextCanvas.width, nextCanvas.height);

        if (!pieceNames || !pieceNames[0]) return;
        var name = pieceNames[0];
        var shape = BlockPieces.getShape(name, 0);
        var color = BlockPieces.getColor(name);
        var glowColor = BlockPieces.getGlowColor(name);
        var shadowColor = BlockPieces.getShadowColor(name);

        var ox = Math.floor((nextCanvas.width - shape[0].length * previewCell) / 2);
        var oy = Math.floor((nextCanvas.height - shape.length * previewCell) / 2);

        nextCtx.save();
        nextCtx.translate(ox, oy);
        for (var r = 0; r < shape.length; r++) {
            for (var c = 0; c < shape[r].length; c++) {
                if (shape[r][c]) {
                    drawCell(nextCtx, c, r, previewCell, color, glowColor, shadowColor, 0.9);
                }
            }
        }
        nextCtx.restore();
    }

    // Line clear animation
    function startLineClear(rows) {
        lineClearRows = rows.slice();
        lineClearAnim = 0;
    }

    function updateLineClear(dt) {
        if (lineClearRows.length === 0) return false;
        lineClearAnim += dt * 3;
        if (lineClearAnim >= 1) {
            lineClearRows = [];
            lineClearAnim = 0;
            return true; // animation done
        }
        return false;
    }

    // Squash effect on drop
    function addSquash(x, y) {
        squashPieces.push({ x: x, y: y, t: 0 });
    }

    function getSquash(x, y) {
        for (var i = 0; i < squashPieces.length; i++) {
            if (squashPieces[i].x === x && squashPieces[i].y === y) {
                var t = squashPieces[i].t;
                var sy = -0.3 * Math.sin(t * Math.PI);
                var sx = 0.15 * Math.sin(t * Math.PI);
                return { sx: sx, sy: sy };
            }
        }
        return null;
    }

    function updateSquash(dt) {
        for (var i = squashPieces.length - 1; i >= 0; i--) {
            squashPieces[i].t += dt * 4;
            if (squashPieces[i].t >= 1) {
                squashPieces.splice(i, 1);
            }
        }
    }

    // Drop trail effect
    function addDropTrail(x, fromY, toY, color) {
        dropTrails.push({ x: x, fromY: fromY, toY: toY, color: color, t: 0 });
    }

    function updateDropTrails(dt) {
        for (var i = dropTrails.length - 1; i >= 0; i--) {
            dropTrails[i].t += dt * 5;
            if (dropTrails[i].t >= 1) {
                dropTrails.splice(i, 1);
            }
        }
    }

    function renderDropTrails() {
        for (var i = 0; i < dropTrails.length; i++) {
            var trail = dropTrails[i];
            var alpha = 1 - trail.t;
            var px = trail.x * cellSize + cellSize / 2;
            var fromPy = trail.fromY * cellSize;
            var toPy = trail.toY * cellSize + cellSize;

            ctx.globalAlpha = alpha * 0.3;
            var grad = ctx.createLinearGradient(px, fromPy, px, toPy);
            grad.addColorStop(0, 'transparent');
            grad.addColorStop(0.5, trail.color);
            grad.addColorStop(1, 'transparent');
            ctx.strokeStyle = grad;
            ctx.lineWidth = cellSize * 0.3;
            ctx.beginPath();
            ctx.moveTo(px, fromPy);
            ctx.lineTo(px, toPy);
            ctx.stroke();
            ctx.globalAlpha = 1;
        }
    }

    // Full frame render
    function render(state) {
        var dt = 1 / 60;
        animTime += dt;

        // Update animations
        updateSquash(dt);
        updateDropTrails(dt);
        var clearDone = updateLineClear(dt);

        // Clear and draw
        renderGrid();
        renderDropTrails();
        renderBoard(state.grid);
        if (state.showGhost) {
            renderPiece(state.currentPiece, state.ghostPiece);
        } else {
            renderPiece(state.currentPiece, null);
        }

        // Particles on top
        BlockParticles.update(dt);
        BlockParticles.render(ctx);

        // Next piece panel
        renderNext(state.nextPieces || []);

        return clearDone;
    }

    // Render family board (static view)
    function renderFamilyBoard(familyCanvas, grid, cols, rows) {
        var fCtx = familyCanvas.getContext('2d');
        var fCell = Math.floor(Math.min(familyCanvas.width / cols, familyCanvas.height / rows));
        familyCanvas.width = cols * fCell;
        familyCanvas.height = rows * fCell;

        var t = getTheme();
        fCtx.fillStyle = t.bg;
        fCtx.fillRect(0, 0, familyCanvas.width, familyCanvas.height);

        // Grid
        fCtx.strokeStyle = t.gridLine;
        fCtx.lineWidth = 0.5;
        for (var r = 0; r <= rows; r++) {
            fCtx.beginPath();
            fCtx.moveTo(0, r * fCell);
            fCtx.lineTo(familyCanvas.width, r * fCell);
            fCtx.stroke();
        }
        for (var c = 0; c <= cols; c++) {
            fCtx.beginPath();
            fCtx.moveTo(c * fCell, 0);
            fCtx.lineTo(c * fCell, familyCanvas.height);
            fCtx.stroke();
        }

        // Blocks
        if (grid) {
            for (var r2 = 0; r2 < rows; r2++) {
                for (var c2 = 0; c2 < cols; c2++) {
                    var cell = grid[r2] && grid[r2][c2];
                    if (cell) {
                        drawCell(fCtx, c2, r2, fCell, cell.color || '#00f5ff', cell.glowColor || 'rgba(0,245,255,0.6)', null, 0.85);
                    }
                }
            }
        }
    }

    // Helper color functions
    function lightenColor(hex, percent) {
        var num = parseInt(hex.replace('#', ''), 16);
        var r = Math.min(255, (num >> 16) + percent);
        var g = Math.min(255, ((num >> 8) & 0x00FF) + percent);
        var b = Math.min(255, (num & 0x0000FF) + percent);
        return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
    }

    function darkenColor(hex, percent) {
        var num = parseInt(hex.replace('#', ''), 16);
        var r = Math.max(0, (num >> 16) - percent);
        var g = Math.max(0, ((num >> 8) & 0x00FF) - percent);
        var b = Math.max(0, (num & 0x0000FF) - percent);
        return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
    }

    // Menu background animation
    function renderMenuBg(menuCanvas) {
        var mCtx = menuCanvas.getContext('2d');
        menuCanvas.width = menuCanvas.parentElement.clientWidth;
        menuCanvas.height = menuCanvas.parentElement.clientHeight;

        var w = menuCanvas.width;
        var h = menuCanvas.height;
        var t = animTime * 0.5;

        // Dark gradient bg
        var bgGrad = mCtx.createRadialGradient(w / 2, h / 2, 0, w / 2, h / 2, w * 0.8);
        bgGrad.addColorStop(0, '#0f0f2a');
        bgGrad.addColorStop(1, '#0a0a1a');
        mCtx.fillStyle = bgGrad;
        mCtx.fillRect(0, 0, w, h);

        // Floating blocks
        var colors = ['#00f5ff', '#ff00ff', '#b44aff', '#00ff88', '#ffee00', '#4a6aff', '#ff6600'];
        for (var i = 0; i < 12; i++) {
            var bx = (w * 0.1 + (i * 97) % w) + Math.sin(t + i * 1.3) * 20;
            var by = (h * 0.1 + (i * 137) % h) + Math.cos(t + i * 0.9) * 15;
            var bs = 12 + (i * 7) % 20;
            var alpha = 0.08 + Math.sin(t * 0.7 + i) * 0.04;

            mCtx.globalAlpha = alpha;
            mCtx.fillStyle = colors[i % colors.length];
            mCtx.shadowColor = colors[i % colors.length];
            mCtx.shadowBlur = bs;
            mCtx.beginPath();
            mCtx.roundRect(bx, by, bs, bs, 4);
            mCtx.fill();
        }

        mCtx.globalAlpha = 1;
        mCtx.shadowBlur = 0;
    }

    return {
        init: init,
        resize: resize,
        setTheme: setTheme,
        getCellSize: getCellSize,
        getOffset: getOffset,
        getCanvasSize: getCanvasSize,
        render: render,
        renderMenuBg: renderMenuBg,
        renderFamilyBoard: renderFamilyBoard,
        startLineClear: startLineClear,
        addSquash: addSquash,
        addDropTrail: addDropTrail
    };
})();
