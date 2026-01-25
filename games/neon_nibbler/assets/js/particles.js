/**
 * NEON NIBBLER - Particle System
 * Lightweight particle pool for trails and effects
 */
var NeonParticles = (function() {
    'use strict';

    var MAX_PARTICLES = 200;
    var pool = [];
    var active = [];

    function Particle() {
        this.x = 0;
        this.y = 0;
        this.vx = 0;
        this.vy = 0;
        this.life = 0;
        this.maxLife = 0;
        this.size = 2;
        this.color = '#00f5ff';
        this.alpha = 1;
        this.decay = 0.02;
        this.type = 'trail'; // trail, burst, collect
    }

    // Pre-allocate pool
    for (var i = 0; i < MAX_PARTICLES; i++) {
        pool.push(new Particle());
    }

    function spawn(x, y, opts) {
        if (pool.length === 0) return;
        var p = pool.pop();
        p.x = x;
        p.y = y;
        p.vx = opts.vx || 0;
        p.vy = opts.vy || 0;
        p.life = opts.life || 30;
        p.maxLife = p.life;
        p.size = opts.size || 2;
        p.color = opts.color || '#00f5ff';
        p.alpha = 1;
        p.type = opts.type || 'trail';
        active.push(p);
    }

    function emitTrail(x, y, color) {
        spawn(x, y, {
            vx: (Math.random() - 0.5) * 0.5,
            vy: (Math.random() - 0.5) * 0.5,
            life: 15 + Math.random() * 10,
            size: 1.5 + Math.random() * 1.5,
            color: color || '#00f5ff',
            type: 'trail'
        });
    }

    function emitBurst(x, y, color, count) {
        count = count || 8;
        for (var i = 0; i < count; i++) {
            var angle = (Math.PI * 2 / count) * i + Math.random() * 0.3;
            var speed = 1.5 + Math.random() * 2;
            spawn(x, y, {
                vx: Math.cos(angle) * speed,
                vy: Math.sin(angle) * speed,
                life: 20 + Math.random() * 15,
                size: 2 + Math.random() * 2,
                color: color || '#00f5ff',
                type: 'burst'
            });
        }
    }

    function emitCollect(x, y, color) {
        for (var i = 0; i < 5; i++) {
            spawn(x, y, {
                vx: (Math.random() - 0.5) * 3,
                vy: (Math.random() - 0.5) * 3 - 1,
                life: 15 + Math.random() * 10,
                size: 1 + Math.random() * 2,
                color: color || '#ffff00',
                type: 'collect'
            });
        }
    }

    function update() {
        for (var i = active.length - 1; i >= 0; i--) {
            var p = active[i];
            p.x += p.vx;
            p.y += p.vy;
            p.life--;
            p.alpha = p.life / p.maxLife;

            if (p.type === 'burst') {
                p.vx *= 0.95;
                p.vy *= 0.95;
            }

            if (p.life <= 0) {
                active.splice(i, 1);
                pool.push(p);
            }
        }
    }

    function draw(ctx) {
        for (var i = 0; i < active.length; i++) {
            var p = active[i];
            ctx.globalAlpha = p.alpha * 0.8;
            ctx.fillStyle = p.color;
            ctx.shadowColor = p.color;
            ctx.shadowBlur = p.size * 2;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size * p.alpha, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.globalAlpha = 1;
        ctx.shadowBlur = 0;
    }

    function clear() {
        while (active.length > 0) {
            pool.push(active.pop());
        }
    }

    return {
        emitTrail: emitTrail,
        emitBurst: emitBurst,
        emitCollect: emitCollect,
        update: update,
        draw: draw,
        clear: clear
    };
})();
