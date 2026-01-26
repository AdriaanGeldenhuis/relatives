/* =============================================
   MAHJONG SOLITAIRE - Canvas 2D Renderer (4K)
   ============================================= */

var MahjongRenderer = (function() {
    'use strict';

    var canvas, ctx;
    var dpr = 1;
    var width = 0, height = 0;

    // Tile dimensions (logical pixels)
    var TILE = {
        width: 52,
        height: 68,
        depth: 10,
        radius: 6,
        stackOffsetX: -6,
        stackOffsetY: -8,
        gap: 2
    };

    // Theme colors
    var theme = {
        tileFace: '#f5f0e6',
        tileFaceGrad1: '#fffef8',
        tileFaceGrad2: '#e8e0d0',
        tileSide: '#c4b8a0',
        tileSideLight: '#d8cbb8',
        tileSideDark: '#a89880',
        tileOutline: '#8a8070',
        tileShadow: 'rgba(0, 0, 0, 0.35)',
        tileHighlight: 'rgba(255, 255, 255, 0.6)',
        tileGlow: '#4ade80',
        tileSelected: '#fbbf24',
        tileHover: 'rgba(74, 222, 128, 0.3)',
        tileBlocked: 'rgba(100, 100, 100, 0.2)',
        symbolPrimary: '#1a5f3c',
        symbolRed: '#c53030',
        symbolBlue: '#2563eb',
        symbolGreen: '#16a34a',
        background: '#0d1f17'
    };

    // Animation state
    var animations = [];
    var particles = [];

    // Board offset for centering
    var boardOffsetX = 0;
    var boardOffsetY = 0;
    var boardScale = 1;

    function init(canvasId) {
        canvas = document.getElementById(canvasId);
        ctx = canvas.getContext('2d');
        dpr = window.devicePixelRatio || 1;
        resize();
        window.addEventListener('resize', resize);
    }

    function resize() {
        var container = canvas.parentElement;
        var containerW = container.clientWidth;
        var containerH = container.clientHeight;

        dpr = window.devicePixelRatio || 1;

        canvas.width = containerW * dpr;
        canvas.height = containerH * dpr;
        canvas.style.width = containerW + 'px';
        canvas.style.height = containerH + 'px';

        width = containerW;
        height = containerH;

        ctx.scale(dpr, dpr);
    }

    function setTheme(themeName) {
        if (themeName === 'bamboo') {
            theme.tileGlow = '#d4a853';
            theme.tileSelected = '#d4a853';
            theme.symbolPrimary = '#6b4423';
            theme.background = '#1a1408';
        } else if (themeName === 'night') {
            theme.tileGlow = '#818cf8';
            theme.tileSelected = '#818cf8';
            theme.symbolPrimary = '#3730a3';
            theme.background = '#0f0f1a';
        } else {
            // jade default
            theme.tileGlow = '#4ade80';
            theme.tileSelected = '#fbbf24';
            theme.symbolPrimary = '#1a5f3c';
            theme.background = '#0d1f17';
        }
    }

    function calculateBoardBounds(tiles) {
        if (!tiles || tiles.length === 0) return { minX: 0, maxX: 0, minY: 0, maxY: 0, maxZ: 0 };

        var minX = Infinity, maxX = -Infinity;
        var minY = Infinity, maxY = -Infinity;
        var maxZ = 0;

        tiles.forEach(function(t) {
            if (t.x < minX) minX = t.x;
            if (t.x > maxX) maxX = t.x;
            if (t.y < minY) minY = t.y;
            if (t.y > maxY) maxY = t.y;
            if (t.z > maxZ) maxZ = t.z;
        });

        return { minX: minX, maxX: maxX, minY: minY, maxY: maxY, maxZ: maxZ };
    }

    function calculateBoardLayout(tiles) {
        var bounds = calculateBoardBounds(tiles);

        var boardW = (bounds.maxX - bounds.minX + 1) * (TILE.width + TILE.gap) + Math.abs(TILE.stackOffsetX) * bounds.maxZ;
        var boardH = (bounds.maxY - bounds.minY + 1) * (TILE.height + TILE.gap) + Math.abs(TILE.stackOffsetY) * bounds.maxZ + TILE.depth;

        var scaleX = (width - 40) / boardW;
        var scaleY = (height - 40) / boardH;
        boardScale = Math.min(scaleX, scaleY, 1.2);

        boardOffsetX = (width - boardW * boardScale) / 2 - bounds.minX * (TILE.width + TILE.gap) * boardScale;
        boardOffsetY = (height - boardH * boardScale) / 2 - bounds.minY * (TILE.height + TILE.gap) * boardScale + 20;
    }

    function tileToScreen(tile) {
        var x = tile.x * (TILE.width + TILE.gap) + tile.z * TILE.stackOffsetX;
        var y = tile.y * (TILE.height + TILE.gap) + tile.z * TILE.stackOffsetY;
        return {
            x: x * boardScale + boardOffsetX,
            y: y * boardScale + boardOffsetY,
            w: TILE.width * boardScale,
            h: TILE.height * boardScale,
            d: TILE.depth * boardScale
        };
    }

    function screenToTile(screenX, screenY, tiles) {
        // Check tiles from top layer down
        var sortedTiles = tiles.slice().sort(function(a, b) {
            return b.z - a.z || b.y - a.y || b.x - a.x;
        });

        for (var i = 0; i < sortedTiles.length; i++) {
            var tile = sortedTiles[i];
            if (tile.removed) continue;

            var pos = tileToScreen(tile);

            if (screenX >= pos.x && screenX <= pos.x + pos.w &&
                screenY >= pos.y && screenY <= pos.y + pos.h) {
                return tile;
            }
        }
        return null;
    }

    function render(state) {
        ctx.clearRect(0, 0, width, height);

        if (!state || !state.tiles) return;

        calculateBoardLayout(state.tiles);

        // Sort tiles for correct draw order (back to front, bottom to top)
        var sortedTiles = state.tiles.filter(function(t) { return !t.removed; }).sort(function(a, b) {
            if (a.z !== b.z) return a.z - b.z;
            if (a.y !== b.y) return a.y - b.y;
            return a.x - b.x;
        });

        // Draw shadows first
        sortedTiles.forEach(function(tile) {
            drawTileShadow(tile);
        });

        // Draw tiles
        sortedTiles.forEach(function(tile) {
            var isSelected = state.selected && state.selected.id === tile.id;
            var isHovered = state.hovered && state.hovered.id === tile.id;
            var isFree = tile.free;
            var isHinted = state.hintedTiles && state.hintedTiles.indexOf(tile.id) !== -1;

            drawTile(tile, {
                selected: isSelected,
                hovered: isHovered,
                free: isFree,
                hinted: isHinted,
                highlightFree: state.highlightFree
            });
        });

        // Draw particles
        renderParticles();

        // Draw animations
        renderAnimations();
    }

    function drawTileShadow(tile) {
        var pos = tileToScreen(tile);
        var shadowOffset = (tile.z + 1) * 3 * boardScale;

        ctx.save();
        ctx.fillStyle = theme.tileShadow;
        ctx.beginPath();
        roundRect(ctx, pos.x + shadowOffset, pos.y + pos.d + shadowOffset, pos.w, pos.h, TILE.radius * boardScale);
        ctx.fill();
        ctx.restore();
    }

    function drawTile(tile, options) {
        var pos = tileToScreen(tile);
        var r = TILE.radius * boardScale;
        var d = pos.d;

        ctx.save();

        // Glow effect for selected/hinted
        if (options.selected || options.hinted) {
            ctx.shadowColor = options.selected ? theme.tileSelected : theme.tileGlow;
            ctx.shadowBlur = 20 * boardScale;
            ctx.shadowOffsetX = 0;
            ctx.shadowOffsetY = 0;
        }

        // Draw right side (depth)
        ctx.fillStyle = theme.tileSideDark;
        ctx.beginPath();
        ctx.moveTo(pos.x + pos.w - r, pos.y + pos.h);
        ctx.lineTo(pos.x + pos.w - r + d, pos.y + pos.h + d);
        ctx.lineTo(pos.x + pos.w + d, pos.y + pos.h + d - r);
        ctx.lineTo(pos.x + pos.w + d, pos.y + r + d);
        ctx.lineTo(pos.x + pos.w, pos.y + r);
        ctx.lineTo(pos.x + pos.w, pos.y + pos.h - r);
        ctx.closePath();
        ctx.fill();

        // Draw bottom side (depth)
        ctx.fillStyle = theme.tileSide;
        ctx.beginPath();
        ctx.moveTo(pos.x + r, pos.y + pos.h);
        ctx.lineTo(pos.x + r + d, pos.y + pos.h + d);
        ctx.lineTo(pos.x + pos.w - r + d, pos.y + pos.h + d);
        ctx.lineTo(pos.x + pos.w - r, pos.y + pos.h);
        ctx.closePath();
        ctx.fill();

        // Draw top face with gradient
        var faceGrad = ctx.createLinearGradient(pos.x, pos.y, pos.x, pos.y + pos.h);
        faceGrad.addColorStop(0, theme.tileFaceGrad1);
        faceGrad.addColorStop(1, theme.tileFaceGrad2);

        ctx.fillStyle = faceGrad;
        ctx.beginPath();
        roundRect(ctx, pos.x, pos.y, pos.w, pos.h, r);
        ctx.fill();

        // Inner bevel highlight (top-left)
        ctx.strokeStyle = theme.tileHighlight;
        ctx.lineWidth = 1.5 * boardScale;
        ctx.beginPath();
        ctx.moveTo(pos.x + r, pos.y + 2 * boardScale);
        ctx.lineTo(pos.x + pos.w - r, pos.y + 2 * boardScale);
        ctx.stroke();

        // Outline
        ctx.strokeStyle = theme.tileOutline;
        ctx.lineWidth = 1 * boardScale;
        ctx.beginPath();
        roundRect(ctx, pos.x, pos.y, pos.w, pos.h, r);
        ctx.stroke();

        ctx.shadowColor = 'transparent';
        ctx.shadowBlur = 0;

        // Draw symbol
        drawSymbol(tile.symbol, pos.x, pos.y, pos.w, pos.h);

        // Hover overlay
        if (options.hovered && options.free) {
            ctx.fillStyle = theme.tileHover;
            ctx.beginPath();
            roundRect(ctx, pos.x, pos.y, pos.w, pos.h, r);
            ctx.fill();
        }

        // Selection border
        if (options.selected) {
            ctx.strokeStyle = theme.tileSelected;
            ctx.lineWidth = 3 * boardScale;
            ctx.beginPath();
            roundRect(ctx, pos.x - 2, pos.y - 2, pos.w + 4, pos.h + 4, r + 2);
            ctx.stroke();
        }

        // Hint pulse
        if (options.hinted && !options.selected) {
            ctx.strokeStyle = theme.tileGlow;
            ctx.lineWidth = 2 * boardScale;
            ctx.beginPath();
            roundRect(ctx, pos.x - 1, pos.y - 1, pos.w + 2, pos.h + 2, r + 1);
            ctx.stroke();
        }

        // Blocked indicator (subtle)
        if (!options.free && options.highlightFree) {
            ctx.fillStyle = theme.tileBlocked;
            ctx.beginPath();
            roundRect(ctx, pos.x, pos.y, pos.w, pos.h, r);
            ctx.fill();
        }

        ctx.restore();
    }

    function drawSymbol(symbol, x, y, w, h) {
        if (!symbol) return;

        var cx = x + w / 2;
        var cy = y + h / 2;
        var s = Math.min(w, h) * 0.35;

        ctx.save();
        ctx.translate(cx, cy);
        ctx.fillStyle = symbol.color || '#333';
        ctx.strokeStyle = symbol.color || '#333';
        ctx.lineWidth = 2 * boardScale;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        switch(symbol.value) {
            // Shapes
            case 'circle': drawCircle(s); break;
            case 'square': drawSquare(s); break;
            case 'triangle': drawTriangle(s); break;
            case 'diamond': drawDiamond(s); break;
            case 'star': drawStar(s); break;
            case 'heart': drawHeart(s); break;
            case 'hexagon': drawHexagon(s); break;
            case 'cross': drawCross(s); break;
            case 'crescent': drawCrescent(s); break;
            // Nature
            case 'sun': drawSun(s); break;
            case 'moon': drawMoon(s); break;
            case 'leaf': drawLeaf(s); break;
            case 'flower': drawFlower(s); break;
            case 'tree': drawTree(s); break;
            case 'cloud': drawCloud(s); break;
            case 'drop': drawDrop(s); break;
            case 'flame': drawFlame(s); break;
            case 'snowflake': drawSnowflake(s); break;
            // Objects
            case 'crown': drawCrown(s); break;
            case 'bell': drawBell(s); break;
            case 'gem': drawGem(s); break;
            case 'key': drawKey(s); break;
            case 'bolt': drawBolt(s); break;
            case 'apple': drawApple(s); break;
            case 'cherry': drawCherry(s); break;
            case 'grape': drawGrape(s); break;
            case 'lemon': drawLemon(s); break;
            // Symbols
            case 'plus': drawPlus(s); break;
            case 'minus': drawMinus(s); break;
            case 'multiply': drawMultiply(s); break;
            case 'spiral': drawSpiral(s); break;
            case 'wave': drawWave(s); break;
            case 'infinity': drawInfinity(s); break;
            case 'target': drawTarget(s); break;
            case 'eye': drawEye(s); break;
        }

        ctx.restore();
    }

    // Shape drawings
    function drawCircle(s) {
        ctx.beginPath();
        ctx.arc(0, 0, s * 0.8, 0, Math.PI * 2);
        ctx.fill();
    }

    function drawSquare(s) {
        ctx.fillRect(-s * 0.7, -s * 0.7, s * 1.4, s * 1.4);
    }

    function drawTriangle(s) {
        ctx.beginPath();
        ctx.moveTo(0, -s * 0.9);
        ctx.lineTo(s * 0.85, s * 0.7);
        ctx.lineTo(-s * 0.85, s * 0.7);
        ctx.closePath();
        ctx.fill();
    }

    function drawDiamond(s) {
        ctx.beginPath();
        ctx.moveTo(0, -s);
        ctx.lineTo(s * 0.7, 0);
        ctx.lineTo(0, s);
        ctx.lineTo(-s * 0.7, 0);
        ctx.closePath();
        ctx.fill();
    }

    function drawStar(s) {
        ctx.beginPath();
        for (var i = 0; i < 5; i++) {
            var angle = (i * 144 - 90) * Math.PI / 180;
            var r = s * 0.9;
            if (i === 0) ctx.moveTo(Math.cos(angle) * r, Math.sin(angle) * r);
            else ctx.lineTo(Math.cos(angle) * r, Math.sin(angle) * r);
        }
        ctx.closePath();
        ctx.fill();
    }

    function drawHeart(s) {
        ctx.beginPath();
        ctx.moveTo(0, s * 0.8);
        ctx.bezierCurveTo(-s, s * 0.2, -s, -s * 0.5, 0, -s * 0.2);
        ctx.bezierCurveTo(s, -s * 0.5, s, s * 0.2, 0, s * 0.8);
        ctx.fill();
    }

    function drawHexagon(s) {
        ctx.beginPath();
        for (var i = 0; i < 6; i++) {
            var angle = (i * 60 - 90) * Math.PI / 180;
            var x = Math.cos(angle) * s * 0.85;
            var y = Math.sin(angle) * s * 0.85;
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        }
        ctx.closePath();
        ctx.fill();
    }

    function drawCross(s) {
        ctx.fillRect(-s * 0.25, -s * 0.85, s * 0.5, s * 1.7);
        ctx.fillRect(-s * 0.85, -s * 0.25, s * 1.7, s * 0.5);
    }

    function drawCrescent(s) {
        ctx.beginPath();
        ctx.arc(0, 0, s * 0.85, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#f5f0e6';
        ctx.beginPath();
        ctx.arc(s * 0.35, -s * 0.1, s * 0.65, 0, Math.PI * 2);
        ctx.fill();
    }

    // Nature drawings
    function drawSun(s) {
        ctx.beginPath();
        ctx.arc(0, 0, s * 0.5, 0, Math.PI * 2);
        ctx.fill();
        for (var i = 0; i < 8; i++) {
            var angle = i * 45 * Math.PI / 180;
            ctx.beginPath();
            ctx.moveTo(Math.cos(angle) * s * 0.6, Math.sin(angle) * s * 0.6);
            ctx.lineTo(Math.cos(angle) * s * 0.95, Math.sin(angle) * s * 0.95);
            ctx.lineWidth = 3 * boardScale;
            ctx.stroke();
        }
    }

    function drawMoon(s) {
        ctx.beginPath();
        ctx.arc(0, 0, s * 0.8, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#f5f0e6';
        ctx.beginPath();
        ctx.arc(s * 0.35, -s * 0.2, s * 0.6, 0, Math.PI * 2);
        ctx.fill();
    }

    function drawLeaf(s) {
        ctx.beginPath();
        ctx.moveTo(0, -s * 0.9);
        ctx.quadraticCurveTo(s * 0.9, -s * 0.3, s * 0.3, s * 0.9);
        ctx.quadraticCurveTo(0, s * 0.5, -s * 0.3, s * 0.9);
        ctx.quadraticCurveTo(-s * 0.9, -s * 0.3, 0, -s * 0.9);
        ctx.fill();
    }

    function drawFlower(s) {
        for (var i = 0; i < 5; i++) {
            var angle = (i * 72 - 90) * Math.PI / 180;
            ctx.beginPath();
            ctx.ellipse(Math.cos(angle) * s * 0.4, Math.sin(angle) * s * 0.4, s * 0.45, s * 0.3, angle, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.fillStyle = '#fbbf24';
        ctx.beginPath();
        ctx.arc(0, 0, s * 0.25, 0, Math.PI * 2);
        ctx.fill();
    }

    function drawTree(s) {
        ctx.fillRect(-s * 0.15, s * 0.3, s * 0.3, s * 0.6);
        ctx.beginPath();
        ctx.moveTo(0, -s * 0.9);
        ctx.lineTo(s * 0.7, s * 0.4);
        ctx.lineTo(-s * 0.7, s * 0.4);
        ctx.closePath();
        ctx.fill();
    }

    function drawCloud(s) {
        ctx.beginPath();
        ctx.arc(-s * 0.3, s * 0.1, s * 0.45, 0, Math.PI * 2);
        ctx.arc(s * 0.25, s * 0.1, s * 0.5, 0, Math.PI * 2);
        ctx.arc(0, -s * 0.25, s * 0.4, 0, Math.PI * 2);
        ctx.fill();
    }

    function drawDrop(s) {
        ctx.beginPath();
        ctx.moveTo(0, -s * 0.9);
        ctx.quadraticCurveTo(s * 0.8, s * 0.2, 0, s * 0.9);
        ctx.quadraticCurveTo(-s * 0.8, s * 0.2, 0, -s * 0.9);
        ctx.fill();
    }

    function drawFlame(s) {
        ctx.beginPath();
        ctx.moveTo(0, -s * 0.9);
        ctx.quadraticCurveTo(s * 0.5, -s * 0.3, s * 0.4, s * 0.3);
        ctx.quadraticCurveTo(s * 0.2, s * 0.1, 0, s * 0.9);
        ctx.quadraticCurveTo(-s * 0.2, s * 0.1, -s * 0.4, s * 0.3);
        ctx.quadraticCurveTo(-s * 0.5, -s * 0.3, 0, -s * 0.9);
        ctx.fill();
    }

    function drawSnowflake(s) {
        ctx.lineWidth = 2.5 * boardScale;
        for (var i = 0; i < 6; i++) {
            var angle = i * 60 * Math.PI / 180;
            ctx.save();
            ctx.rotate(angle);
            ctx.beginPath();
            ctx.moveTo(0, 0);
            ctx.lineTo(0, -s * 0.85);
            ctx.moveTo(0, -s * 0.5);
            ctx.lineTo(-s * 0.2, -s * 0.7);
            ctx.moveTo(0, -s * 0.5);
            ctx.lineTo(s * 0.2, -s * 0.7);
            ctx.stroke();
            ctx.restore();
        }
    }

    // Object drawings
    function drawCrown(s) {
        ctx.beginPath();
        ctx.moveTo(-s * 0.8, s * 0.5);
        ctx.lineTo(-s * 0.8, -s * 0.1);
        ctx.lineTo(-s * 0.4, s * 0.2);
        ctx.lineTo(0, -s * 0.7);
        ctx.lineTo(s * 0.4, s * 0.2);
        ctx.lineTo(s * 0.8, -s * 0.1);
        ctx.lineTo(s * 0.8, s * 0.5);
        ctx.closePath();
        ctx.fill();
    }

    function drawBell(s) {
        ctx.beginPath();
        ctx.moveTo(-s * 0.6, s * 0.5);
        ctx.quadraticCurveTo(-s * 0.6, -s * 0.2, 0, -s * 0.7);
        ctx.quadraticCurveTo(s * 0.6, -s * 0.2, s * 0.6, s * 0.5);
        ctx.closePath();
        ctx.fill();
        ctx.beginPath();
        ctx.arc(0, s * 0.7, s * 0.2, 0, Math.PI * 2);
        ctx.fill();
    }

    function drawGem(s) {
        ctx.beginPath();
        ctx.moveTo(0, -s * 0.9);
        ctx.lineTo(s * 0.7, -s * 0.3);
        ctx.lineTo(s * 0.5, s * 0.9);
        ctx.lineTo(-s * 0.5, s * 0.9);
        ctx.lineTo(-s * 0.7, -s * 0.3);
        ctx.closePath();
        ctx.fill();
    }

    function drawKey(s) {
        ctx.beginPath();
        ctx.arc(0, -s * 0.5, s * 0.4, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#f5f0e6';
        ctx.beginPath();
        ctx.arc(0, -s * 0.5, s * 0.2, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = ctx.strokeStyle;
        ctx.fillRect(-s * 0.12, -s * 0.2, s * 0.24, s * 1.1);
        ctx.fillRect(s * 0.12, s * 0.5, s * 0.25, s * 0.15);
        ctx.fillRect(s * 0.12, s * 0.75, s * 0.18, s * 0.12);
    }

    function drawBolt(s) {
        ctx.beginPath();
        ctx.moveTo(s * 0.2, -s * 0.9);
        ctx.lineTo(-s * 0.3, s * 0.1);
        ctx.lineTo(s * 0.1, s * 0.1);
        ctx.lineTo(-s * 0.2, s * 0.9);
        ctx.lineTo(s * 0.3, -s * 0.1);
        ctx.lineTo(-s * 0.1, -s * 0.1);
        ctx.closePath();
        ctx.fill();
    }

    function drawApple(s) {
        ctx.beginPath();
        ctx.arc(-s * 0.25, s * 0.15, s * 0.55, 0, Math.PI * 2);
        ctx.arc(s * 0.25, s * 0.15, s * 0.55, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#22c55e';
        ctx.fillRect(-s * 0.08, -s * 0.9, s * 0.16, s * 0.4);
    }

    function drawCherry(s) {
        ctx.strokeStyle = '#22c55e';
        ctx.lineWidth = 2 * boardScale;
        ctx.beginPath();
        ctx.moveTo(0, -s * 0.7);
        ctx.quadraticCurveTo(-s * 0.3, -s * 0.3, -s * 0.4, s * 0.2);
        ctx.moveTo(0, -s * 0.7);
        ctx.quadraticCurveTo(s * 0.3, -s * 0.3, s * 0.4, s * 0.2);
        ctx.stroke();
        ctx.fillStyle = ctx.fillStyle;
        ctx.beginPath();
        ctx.arc(-s * 0.4, s * 0.45, s * 0.4, 0, Math.PI * 2);
        ctx.arc(s * 0.4, s * 0.45, s * 0.4, 0, Math.PI * 2);
        ctx.fill();
    }

    function drawGrape(s) {
        var positions = [
            {x: 0, y: -s * 0.5}, {x: -s * 0.35, y: -s * 0.15}, {x: s * 0.35, y: -s * 0.15},
            {x: 0, y: -s * 0.15}, {x: -s * 0.18, y: s * 0.25}, {x: s * 0.18, y: s * 0.25}, {x: 0, y: s * 0.6}
        ];
        positions.forEach(function(p) {
            ctx.beginPath();
            ctx.arc(p.x, p.y, s * 0.28, 0, Math.PI * 2);
            ctx.fill();
        });
    }

    function drawLemon(s) {
        ctx.beginPath();
        ctx.ellipse(0, 0, s * 0.9, s * 0.6, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.beginPath();
        ctx.moveTo(s * 0.75, 0);
        ctx.lineTo(s * 1, -s * 0.15);
        ctx.lineTo(s * 0.85, 0);
        ctx.closePath();
        ctx.fill();
    }

    // Symbol drawings
    function drawPlus(s) {
        ctx.fillRect(-s * 0.2, -s * 0.8, s * 0.4, s * 1.6);
        ctx.fillRect(-s * 0.8, -s * 0.2, s * 1.6, s * 0.4);
    }

    function drawMinus(s) {
        ctx.fillRect(-s * 0.8, -s * 0.2, s * 1.6, s * 0.4);
    }

    function drawMultiply(s) {
        ctx.save();
        ctx.rotate(Math.PI / 4);
        ctx.fillRect(-s * 0.18, -s * 0.8, s * 0.36, s * 1.6);
        ctx.fillRect(-s * 0.8, -s * 0.18, s * 1.6, s * 0.36);
        ctx.restore();
    }

    function drawSpiral(s) {
        ctx.lineWidth = 3 * boardScale;
        ctx.beginPath();
        for (var i = 0; i < 720; i += 10) {
            var angle = i * Math.PI / 180;
            var r = s * 0.1 + (i / 720) * s * 0.7;
            var x = Math.cos(angle) * r;
            var y = Math.sin(angle) * r;
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        }
        ctx.stroke();
    }

    function drawWave(s) {
        ctx.lineWidth = 4 * boardScale;
        ctx.beginPath();
        ctx.moveTo(-s * 0.9, 0);
        ctx.quadraticCurveTo(-s * 0.45, -s * 0.6, 0, 0);
        ctx.quadraticCurveTo(s * 0.45, s * 0.6, s * 0.9, 0);
        ctx.stroke();
    }

    function drawInfinity(s) {
        ctx.lineWidth = 4 * boardScale;
        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.bezierCurveTo(s * 0.5, -s * 0.6, s * 0.9, -s * 0.3, s * 0.9, 0);
        ctx.bezierCurveTo(s * 0.9, s * 0.3, s * 0.5, s * 0.6, 0, 0);
        ctx.bezierCurveTo(-s * 0.5, -s * 0.6, -s * 0.9, -s * 0.3, -s * 0.9, 0);
        ctx.bezierCurveTo(-s * 0.9, s * 0.3, -s * 0.5, s * 0.6, 0, 0);
        ctx.stroke();
    }

    function drawTarget(s) {
        ctx.beginPath();
        ctx.arc(0, 0, s * 0.85, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#f5f0e6';
        ctx.beginPath();
        ctx.arc(0, 0, s * 0.6, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = ctx.strokeStyle;
        ctx.beginPath();
        ctx.arc(0, 0, s * 0.35, 0, Math.PI * 2);
        ctx.fill();
    }

    function drawEye(s) {
        ctx.beginPath();
        ctx.moveTo(-s * 0.9, 0);
        ctx.quadraticCurveTo(0, -s * 0.7, s * 0.9, 0);
        ctx.quadraticCurveTo(0, s * 0.7, -s * 0.9, 0);
        ctx.fill();
        ctx.fillStyle = '#fff';
        ctx.beginPath();
        ctx.arc(0, 0, s * 0.35, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#1e293b';
        ctx.beginPath();
        ctx.arc(0, 0, s * 0.2, 0, Math.PI * 2);
        ctx.fill();
    }

    function roundRect(ctx, x, y, w, h, r) {
        ctx.moveTo(x + r, y);
        ctx.lineTo(x + w - r, y);
        ctx.quadraticCurveTo(x + w, y, x + w, y + r);
        ctx.lineTo(x + w, y + h - r);
        ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
        ctx.lineTo(x + r, y + h);
        ctx.quadraticCurveTo(x, y + h, x, y + h - r);
        ctx.lineTo(x, y + r);
        ctx.quadraticCurveTo(x, y, x + r, y);
        ctx.closePath();
    }

    // Particles
    function addMatchParticles(tile1, tile2) {
        var pos1 = tileToScreen(tile1);
        var pos2 = tileToScreen(tile2);

        var cx1 = pos1.x + pos1.w / 2;
        var cy1 = pos1.y + pos1.h / 2;
        var cx2 = pos2.x + pos2.w / 2;
        var cy2 = pos2.y + pos2.h / 2;

        for (var i = 0; i < 12; i++) {
            particles.push(createParticle(cx1, cy1));
            particles.push(createParticle(cx2, cy2));
        }
    }

    function createParticle(x, y) {
        var angle = Math.random() * Math.PI * 2;
        var speed = 2 + Math.random() * 4;
        return {
            x: x,
            y: y,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed,
            life: 1,
            decay: 0.02 + Math.random() * 0.02,
            size: 3 + Math.random() * 4,
            color: theme.tileGlow
        };
    }

    function renderParticles() {
        for (var i = particles.length - 1; i >= 0; i--) {
            var p = particles[i];

            ctx.save();
            ctx.globalAlpha = p.life;
            ctx.fillStyle = p.color;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size * p.life, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();

            p.x += p.vx;
            p.y += p.vy;
            p.vy += 0.1; // gravity
            p.life -= p.decay;

            if (p.life <= 0) {
                particles.splice(i, 1);
            }
        }
    }

    // Animations
    function addRemoveAnimation(tile) {
        var pos = tileToScreen(tile);
        animations.push({
            type: 'remove',
            x: pos.x,
            y: pos.y,
            w: pos.w,
            h: pos.h,
            progress: 0,
            speed: 0.08
        });
    }

    function addShakeAnimation(tile) {
        animations.push({
            type: 'shake',
            tile: tile,
            progress: 0,
            speed: 0.15
        });
    }

    function renderAnimations() {
        for (var i = animations.length - 1; i >= 0; i--) {
            var anim = animations[i];

            if (anim.type === 'remove') {
                var scale = 1 + anim.progress * 0.3;
                var alpha = 1 - anim.progress;

                ctx.save();
                ctx.globalAlpha = alpha;
                ctx.translate(anim.x + anim.w / 2, anim.y + anim.h / 2);
                ctx.scale(scale, scale);

                ctx.fillStyle = theme.tileGlow;
                ctx.beginPath();
                roundRect(ctx, -anim.w / 2, -anim.h / 2, anim.w, anim.h, TILE.radius * boardScale);
                ctx.fill();

                ctx.restore();
            }

            anim.progress += anim.speed;
            if (anim.progress >= 1) {
                animations.splice(i, 1);
            }
        }
    }

    function getCanvasInfo() {
        return { width: width, height: height, canvas: canvas };
    }

    return {
        init: init,
        resize: resize,
        setTheme: setTheme,
        render: render,
        screenToTile: screenToTile,
        tileToScreen: tileToScreen,
        addMatchParticles: addMatchParticles,
        addRemoveAnimation: addRemoveAnimation,
        addShakeAnimation: addShakeAnimation,
        getCanvasInfo: getCanvasInfo
    };

})();
