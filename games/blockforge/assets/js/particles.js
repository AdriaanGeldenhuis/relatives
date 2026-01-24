/* =============================================
   BLOCKFORGE - Particle Effects System
   ============================================= */

var BlockParticles = (function() {
    'use strict';

    var particles = [];
    var MAX_PARTICLES = 200;

    function create(x, y, color, count, opts) {
        opts = opts || {};
        var spread = opts.spread || 3;
        var life = opts.life || 1.0;
        var size = opts.size || 3;
        var speed = opts.speed || 2;
        var gravity = opts.gravity || 0.5;
        var type = opts.type || 'circle';

        for (var i = 0; i < count; i++) {
            if (particles.length >= MAX_PARTICLES) {
                particles.shift();
            }
            var angle = (Math.PI * 2 / count) * i + (Math.random() - 0.5) * spread;
            var vel = speed * (0.5 + Math.random() * 0.8);
            particles.push({
                x: x + (Math.random() - 0.5) * 4,
                y: y + (Math.random() - 0.5) * 4,
                vx: Math.cos(angle) * vel,
                vy: Math.sin(angle) * vel - (opts.upward ? 2 : 0),
                life: life,
                maxLife: life,
                color: color,
                size: size * (0.5 + Math.random()),
                gravity: gravity,
                type: type,
                rotation: Math.random() * Math.PI * 2,
                rotSpeed: (Math.random() - 0.5) * 0.2
            });
        }
    }

    function lineClearBurst(row, cols, cellSize, offsetX, offsetY, color) {
        for (var c = 0; c < cols; c++) {
            var px = offsetX + c * cellSize + cellSize / 2;
            var py = offsetY + row * cellSize + cellSize / 2;
            create(px, py, color || '#00f5ff', 4, {
                spread: 2,
                life: 0.8,
                size: 4,
                speed: 3,
                gravity: 0.8,
                type: 'spark'
            });
        }
    }

    function hardDropTrail(x, y, color) {
        create(x, y, color, 6, {
            spread: 1,
            life: 0.5,
            size: 2,
            speed: 1.5,
            gravity: -0.3,
            upward: true,
            type: 'circle'
        });
    }

    function lockFlash(x, y, w, h, color) {
        var count = Math.floor((w + h) / 8);
        for (var i = 0; i < count; i++) {
            var px = x + Math.random() * w;
            var py = y + Math.random() * h;
            create(px, py, color, 2, {
                spread: 4,
                life: 0.4,
                size: 2,
                speed: 0.8,
                gravity: 0.2,
                type: 'circle'
            });
        }
    }

    function levelUpBurst(centerX, centerY) {
        var colors = ['#00f5ff', '#ff00ff', '#b44aff', '#00ff88', '#ffee00'];
        for (var i = 0; i < 30; i++) {
            create(centerX, centerY, colors[i % colors.length], 1, {
                spread: 6.28,
                life: 1.2,
                size: 4,
                speed: 5,
                gravity: 0.3,
                type: 'spark'
            });
        }
    }

    function comboStar(x, y, level) {
        var color = level > 5 ? '#ffee00' : level > 3 ? '#ff00ff' : '#00f5ff';
        create(x, y, color, 8 + level * 2, {
            spread: 6.28,
            life: 0.6 + level * 0.1,
            size: 3 + level * 0.5,
            speed: 2 + level * 0.5,
            gravity: 0.4,
            type: 'star'
        });
    }

    function update(dt) {
        for (var i = particles.length - 1; i >= 0; i--) {
            var p = particles[i];
            p.life -= dt;
            if (p.life <= 0) {
                particles.splice(i, 1);
                continue;
            }
            p.x += p.vx;
            p.y += p.vy;
            p.vy += p.gravity * dt * 60;
            p.vx *= 0.98;
            p.rotation += p.rotSpeed;
        }
    }

    function render(ctx) {
        if (particles.length === 0) return;

        ctx.save();
        for (var i = 0; i < particles.length; i++) {
            var p = particles[i];
            var alpha = (p.life / p.maxLife);
            var size = p.size * (0.5 + alpha * 0.5);

            ctx.globalAlpha = alpha * 0.9;
            ctx.fillStyle = p.color;
            ctx.shadowColor = p.color;
            ctx.shadowBlur = size * 2;

            if (p.type === 'circle') {
                ctx.beginPath();
                ctx.arc(p.x, p.y, size, 0, Math.PI * 2);
                ctx.fill();
            } else if (p.type === 'spark') {
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rotation);
                ctx.fillRect(-size / 2, -size * 1.5, size, size * 3);
                ctx.restore();
            } else if (p.type === 'star') {
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rotation);
                drawStar(ctx, 0, 0, 5, size, size * 0.5);
                ctx.fill();
                ctx.restore();
            }
        }
        ctx.restore();
    }

    function drawStar(ctx, cx, cy, spikes, outerR, innerR) {
        var rot = Math.PI / 2 * 3;
        var step = Math.PI / spikes;
        ctx.beginPath();
        ctx.moveTo(cx, cy - outerR);
        for (var i = 0; i < spikes; i++) {
            ctx.lineTo(cx + Math.cos(rot) * outerR, cy + Math.sin(rot) * outerR);
            rot += step;
            ctx.lineTo(cx + Math.cos(rot) * innerR, cy + Math.sin(rot) * innerR);
            rot += step;
        }
        ctx.lineTo(cx, cy - outerR);
        ctx.closePath();
    }

    function clear() {
        particles.length = 0;
    }

    function count() {
        return particles.length;
    }

    return {
        create: create,
        lineClearBurst: lineClearBurst,
        hardDropTrail: hardDropTrail,
        lockFlash: lockFlash,
        levelUpBurst: levelUpBurst,
        comboStar: comboStar,
        update: update,
        render: render,
        clear: clear,
        count: count
    };
})();
