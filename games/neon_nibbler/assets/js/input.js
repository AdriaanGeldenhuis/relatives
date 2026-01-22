/**
 * NEON NIBBLER - Input Handler
 * Virtual joystick + Boost button + Keyboard + Swipe
 */
var NeonInput = (function() {
    'use strict';

    var queuedDir = null;
    var boostActive = false;
    var boostCooldown = false;
    var BOOST_DURATION = 2000;
    var BOOST_COOLDOWN = 5000;
    var boostTimer = null;
    var cooldownTimer = null;
    var callbacks = {};

    // Joystick state
    var joystickBase = null;
    var joystickKnob = null;
    var joystickActive = false;
    var joystickTouchId = null;
    var baseCenterX = 0;
    var baseCenterY = 0;
    var knobMaxDist = 0;
    var DEAD_ZONE = 12;

    function init() {
        setupJoystick();
        setupBoost();
        setupKeyboard();
        setupSwipe();
    }

    function setupJoystick() {
        joystickBase = document.querySelector('.joystick-base');
        joystickKnob = document.getElementById('joystick-knob');
        if (!joystickBase || !joystickKnob) return;

        joystickBase.addEventListener('touchstart', onJoystickStart, { passive: false });
        document.addEventListener('touchmove', onJoystickMove, { passive: false });
        document.addEventListener('touchend', onJoystickEnd, { passive: false });
        document.addEventListener('touchcancel', onJoystickEnd, { passive: false });
    }

    function onJoystickStart(e) {
        e.preventDefault();
        e.stopPropagation();
        if (joystickActive) return;

        var t = e.changedTouches[0];
        joystickTouchId = t.identifier;
        joystickActive = true;
        joystickKnob.classList.add('active');

        updateBaseRect();
        processJoystickTouch(t);
        NeonAudio.resume();
    }

    function onJoystickMove(e) {
        if (!joystickActive) return;
        for (var i = 0; i < e.changedTouches.length; i++) {
            if (e.changedTouches[i].identifier === joystickTouchId) {
                e.preventDefault();
                processJoystickTouch(e.changedTouches[i]);
                return;
            }
        }
    }

    function onJoystickEnd(e) {
        if (!joystickActive) return;
        for (var i = 0; i < e.changedTouches.length; i++) {
            if (e.changedTouches[i].identifier === joystickTouchId) {
                joystickActive = false;
                joystickTouchId = null;
                joystickKnob.classList.remove('active');
                joystickKnob.style.transform = 'translate(0px, 0px)';
                return;
            }
        }
    }

    function updateBaseRect() {
        var rect = joystickBase.getBoundingClientRect();
        baseCenterX = rect.left + rect.width / 2;
        baseCenterY = rect.top + rect.height / 2;
        knobMaxDist = rect.width / 2 - 20;
    }

    function processJoystickTouch(touch) {
        var dx = touch.clientX - baseCenterX;
        var dy = touch.clientY - baseCenterY;
        var dist = Math.sqrt(dx * dx + dy * dy);

        // Clamp to base radius
        if (dist > knobMaxDist) {
            dx = (dx / dist) * knobMaxDist;
            dy = (dy / dist) * knobMaxDist;
            dist = knobMaxDist;
        }

        // Move knob visually
        joystickKnob.style.transform = 'translate(' + dx + 'px, ' + dy + 'px)';

        // Determine direction if past dead zone
        if (dist > DEAD_ZONE) {
            var angle = Math.atan2(dy, dx);
            var dir;
            if (angle > -Math.PI * 0.25 && angle <= Math.PI * 0.25) {
                dir = 'right';
            } else if (angle > Math.PI * 0.25 && angle <= Math.PI * 0.75) {
                dir = 'down';
            } else if (angle > -Math.PI * 0.75 && angle <= -Math.PI * 0.25) {
                dir = 'up';
            } else {
                dir = 'left';
            }
            queuedDir = dir;
        }
    }

    function setupBoost() {
        var btn = document.getElementById('btn-boost');
        if (!btn) return;

        btn.addEventListener('touchstart', function(e) {
            e.preventDefault();
            e.stopPropagation();
            activateBoost();
            NeonAudio.resume();
        }, { passive: false });

        btn.addEventListener('mousedown', function(e) {
            e.preventDefault();
            activateBoost();
        });
    }

    function activateBoost() {
        if (boostActive || boostCooldown) return;

        boostActive = true;
        var btn = document.getElementById('btn-boost');
        if (btn) btn.classList.add('active');

        NeonAudio.power();

        boostTimer = setTimeout(function() {
            boostActive = false;
            boostCooldown = true;
            if (btn) {
                btn.classList.remove('active');
                btn.classList.add('cooldown');
            }

            cooldownTimer = setTimeout(function() {
                boostCooldown = false;
                if (btn) btn.classList.remove('cooldown');
            }, BOOST_COOLDOWN);
        }, BOOST_DURATION);
    }

    function setupKeyboard() {
        document.addEventListener('keydown', function(e) {
            var dir = null;
            switch (e.key) {
                case 'ArrowUp': case 'w': case 'W': dir = 'up'; break;
                case 'ArrowDown': case 's': case 'S': dir = 'down'; break;
                case 'ArrowLeft': case 'a': case 'A': dir = 'left'; break;
                case 'ArrowRight': case 'd': case 'D': dir = 'right'; break;
                case ' ': activateBoost(); e.preventDefault(); return;
                case 'Escape': case 'p': case 'P':
                    if (callbacks.pause) callbacks.pause();
                    return;
            }
            if (dir) {
                e.preventDefault();
                queuedDir = dir;
            }
        });
    }

    function setupSwipe() {
        var el = document.getElementById('game-canvas');
        if (!el) return;

        var startX = 0, startY = 0;
        el.addEventListener('touchstart', function(e) {
            if (joystickActive) return;
            var t = e.touches[0];
            startX = t.clientX;
            startY = t.clientY;
        }, { passive: true });

        el.addEventListener('touchend', function(e) {
            if (joystickActive) return;
            if (!e.changedTouches.length) return;
            var t = e.changedTouches[0];
            var dx = t.clientX - startX;
            var dy = t.clientY - startY;
            var dist = Math.sqrt(dx * dx + dy * dy);
            if (dist < 25) return;

            if (Math.abs(dx) > Math.abs(dy)) {
                queuedDir = dx > 0 ? 'right' : 'left';
            } else {
                queuedDir = dy > 0 ? 'down' : 'up';
            }
        }, { passive: true });
    }

    function consumeDirection() {
        var d = queuedDir;
        queuedDir = null;
        return d;
    }

    function peekDirection() {
        return queuedDir;
    }

    function isBoostActive() {
        return boostActive;
    }

    function setDpadVisible(visible) {
        var el = document.getElementById('game-controls');
        if (el) el.classList.toggle('hidden', !visible);
    }

    function onPause(fn) {
        callbacks.pause = fn;
    }

    function reset() {
        queuedDir = null;
        boostActive = false;
        boostCooldown = false;
        if (boostTimer) clearTimeout(boostTimer);
        if (cooldownTimer) clearTimeout(cooldownTimer);
        var btn = document.getElementById('btn-boost');
        if (btn) {
            btn.classList.remove('active', 'cooldown');
        }
    }

    return {
        init: init,
        consumeDirection: consumeDirection,
        peekDirection: peekDirection,
        isBoostActive: isBoostActive,
        setDpadVisible: setDpadVisible,
        onPause: onPause,
        reset: reset
    };
})();
