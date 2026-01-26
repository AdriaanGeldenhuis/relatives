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
        var scale = boardScale * 0.7;

        ctx.save();
        ctx.translate(cx, cy);

        if (symbol.type === 'dots') {
            drawDots(symbol.value, scale);
        } else if (symbol.type === 'bamboo') {
            drawBamboo(symbol.value, scale);
        } else if (symbol.type === 'character') {
            drawCharacter(symbol.value, scale);
        } else if (symbol.type === 'wind') {
            drawWind(symbol.value, scale);
        } else if (symbol.type === 'dragon') {
            drawDragon(symbol.value, scale);
        }

        ctx.restore();
    }

    function drawDots(value, scale) {
        var s = 8 * scale;
        var positions = getDotPositions(value);

        ctx.fillStyle = theme.symbolRed;

        positions.forEach(function(p) {
            ctx.beginPath();
            ctx.arc(p.x * s, p.y * s, s * 0.4, 0, Math.PI * 2);
            ctx.fill();

            // Inner highlight
            ctx.fillStyle = '#ff6b6b';
            ctx.beginPath();
            ctx.arc(p.x * s - s * 0.1, p.y * s - s * 0.1, s * 0.15, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = theme.symbolRed;
        });
    }

    function getDotPositions(value) {
        var patterns = {
            1: [{ x: 0, y: 0 }],
            2: [{ x: 0, y: -1 }, { x: 0, y: 1 }],
            3: [{ x: 0, y: -1.2 }, { x: 0, y: 0 }, { x: 0, y: 1.2 }],
            4: [{ x: -0.8, y: -0.8 }, { x: 0.8, y: -0.8 }, { x: -0.8, y: 0.8 }, { x: 0.8, y: 0.8 }],
            5: [{ x: -0.8, y: -0.8 }, { x: 0.8, y: -0.8 }, { x: 0, y: 0 }, { x: -0.8, y: 0.8 }, { x: 0.8, y: 0.8 }],
            6: [{ x: -0.8, y: -1 }, { x: 0.8, y: -1 }, { x: -0.8, y: 0 }, { x: 0.8, y: 0 }, { x: -0.8, y: 1 }, { x: 0.8, y: 1 }],
            7: [{ x: -0.8, y: -1.2 }, { x: 0.8, y: -1.2 }, { x: -0.8, y: 0 }, { x: 0, y: 0 }, { x: 0.8, y: 0 }, { x: -0.8, y: 1.2 }, { x: 0.8, y: 1.2 }],
            8: [{ x: -0.8, y: -1.2 }, { x: 0, y: -1.2 }, { x: 0.8, y: -1.2 }, { x: -0.8, y: 0 }, { x: 0.8, y: 0 }, { x: -0.8, y: 1.2 }, { x: 0, y: 1.2 }, { x: 0.8, y: 1.2 }],
            9: [{ x: -0.8, y: -1.2 }, { x: 0, y: -1.2 }, { x: 0.8, y: -1.2 }, { x: -0.8, y: 0 }, { x: 0, y: 0 }, { x: 0.8, y: 0 }, { x: -0.8, y: 1.2 }, { x: 0, y: 1.2 }, { x: 0.8, y: 1.2 }]
        };
        return patterns[value] || [];
    }

    function drawBamboo(value, scale) {
        var s = 6 * scale;
        ctx.strokeStyle = theme.symbolGreen;
        ctx.fillStyle = theme.symbolGreen;
        ctx.lineWidth = 2 * scale;
        ctx.lineCap = 'round';

        if (value === 1) {
            // Special: bird/peacock for 1 bamboo
            ctx.fillStyle = theme.symbolGreen;
            ctx.beginPath();
            ctx.arc(0, 0, s * 1.5, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = theme.tileFace;
            ctx.beginPath();
            ctx.arc(-s * 0.4, -s * 0.3, s * 0.3, 0, Math.PI * 2);
            ctx.fill();
            return;
        }

        var positions = getBambooPositions(value);
        positions.forEach(function(p) {
            ctx.beginPath();
            ctx.moveTo(p.x * s, p.y * s - s * 1.5);
            ctx.lineTo(p.x * s, p.y * s + s * 1.5);
            ctx.stroke();

            // Bamboo segments
            for (var i = -1; i <= 1; i++) {
                ctx.beginPath();
                ctx.moveTo(p.x * s - s * 0.3, p.y * s + i * s);
                ctx.lineTo(p.x * s + s * 0.3, p.y * s + i * s);
                ctx.stroke();
            }
        });
    }

    function getBambooPositions(value) {
        var patterns = {
            2: [{ x: -0.6, y: 0 }, { x: 0.6, y: 0 }],
            3: [{ x: 0, y: -1 }, { x: -0.6, y: 0.8 }, { x: 0.6, y: 0.8 }],
            4: [{ x: -0.6, y: -0.8 }, { x: 0.6, y: -0.8 }, { x: -0.6, y: 0.8 }, { x: 0.6, y: 0.8 }],
            5: [{ x: 0, y: -1 }, { x: -0.8, y: 0 }, { x: 0.8, y: 0 }, { x: -0.4, y: 1 }, { x: 0.4, y: 1 }],
            6: [{ x: -0.6, y: -1 }, { x: 0.6, y: -1 }, { x: -0.6, y: 0 }, { x: 0.6, y: 0 }, { x: -0.6, y: 1 }, { x: 0.6, y: 1 }],
            7: [{ x: 0, y: -1.2 }, { x: -0.7, y: -0.2 }, { x: 0.7, y: -0.2 }, { x: -0.7, y: 0.8 }, { x: 0, y: 0.8 }, { x: 0.7, y: 0.8 }, { x: 0, y: 1.8 }],
            8: [{ x: -0.6, y: -1.2 }, { x: 0.6, y: -1.2 }, { x: -0.6, y: -0.2 }, { x: 0.6, y: -0.2 }, { x: -0.6, y: 0.8 }, { x: 0.6, y: 0.8 }, { x: -0.6, y: 1.8 }, { x: 0.6, y: 1.8 }],
            9: [{ x: -0.7, y: -1.2 }, { x: 0, y: -1.2 }, { x: 0.7, y: -1.2 }, { x: -0.7, y: 0 }, { x: 0, y: 0 }, { x: 0.7, y: 0 }, { x: -0.7, y: 1.2 }, { x: 0, y: 1.2 }, { x: 0.7, y: 1.2 }]
        };
        return patterns[value] || [];
    }

    function drawCharacter(value, scale) {
        var s = 14 * scale;
        ctx.font = 'bold ' + s + 'px serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = theme.symbolPrimary;

        // Chinese numeral characters
        var chars = ['', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
        ctx.fillText(chars[value] || value, 0, -s * 0.3);

        // "wan" character below
        ctx.font = s * 0.7 + 'px serif';
        ctx.fillStyle = theme.symbolRed;
        ctx.fillText('萬', 0, s * 0.5);
    }

    function drawWind(value, scale) {
        var s = 16 * scale;
        ctx.font = 'bold ' + s + 'px serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        var chars = { E: '東', S: '南', W: '西', N: '北' };
        var colors = { E: theme.symbolGreen, S: theme.symbolRed, W: theme.symbolBlue, N: theme.symbolPrimary };

        ctx.fillStyle = colors[value] || theme.symbolPrimary;
        ctx.fillText(chars[value] || value, 0, 0);
    }

    function drawDragon(value, scale) {
        var s = 16 * scale;
        ctx.font = 'bold ' + s + 'px serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        if (value === 'R') {
            ctx.fillStyle = theme.symbolRed;
            ctx.fillText('中', 0, 0);
        } else if (value === 'G') {
            ctx.fillStyle = theme.symbolGreen;
            ctx.fillText('發', 0, 0);
        } else {
            // White dragon - frame only
            ctx.strokeStyle = theme.symbolBlue;
            ctx.lineWidth = 2 * scale;
            ctx.strokeRect(-s * 0.5, -s * 0.6, s, s * 1.2);
        }
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
