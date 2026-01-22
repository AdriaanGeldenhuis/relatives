/**
 * NEON NIBBLER - Input Handler
 * Touch swipe + D-pad + Keyboard support
 */
var NeonInput = (function() {
    'use strict';

    var currentDir = null;
    var queuedDir = null;
    var touchStartX = 0;
    var touchStartY = 0;
    var touchStartTime = 0;
    var SWIPE_THRESHOLD = 20;
    var dpadEnabled = true;
    var callbacks = {};

    function init() {
        setupTouch();
        setupKeyboard();
        setupDpad();
    }

    function setupTouch() {
        var el = document.getElementById('game-canvas');
        if (!el) return;

        el.addEventListener('touchstart', function(e) {
            e.preventDefault();
            var t = e.touches[0];
            touchStartX = t.clientX;
            touchStartY = t.clientY;
            touchStartTime = Date.now();
        }, { passive: false });

        el.addEventListener('touchmove', function(e) {
            e.preventDefault();
        }, { passive: false });

        el.addEventListener('touchend', function(e) {
            e.preventDefault();
            if (e.changedTouches.length === 0) return;
            var t = e.changedTouches[0];
            var dx = t.clientX - touchStartX;
            var dy = t.clientY - touchStartY;
            var dist = Math.sqrt(dx * dx + dy * dy);

            if (dist < SWIPE_THRESHOLD) return;

            var dir;
            if (Math.abs(dx) > Math.abs(dy)) {
                dir = dx > 0 ? 'right' : 'left';
            } else {
                dir = dy > 0 ? 'down' : 'up';
            }
            setDirection(dir);
        }, { passive: false });
    }

    function setupKeyboard() {
        document.addEventListener('keydown', function(e) {
            var dir = null;
            switch (e.key) {
                case 'ArrowUp': case 'w': case 'W': dir = 'up'; break;
                case 'ArrowDown': case 's': case 'S': dir = 'down'; break;
                case 'ArrowLeft': case 'a': case 'A': dir = 'left'; break;
                case 'ArrowRight': case 'd': case 'D': dir = 'right'; break;
                case 'Escape': case 'p': case 'P':
                    if (callbacks.pause) callbacks.pause();
                    return;
            }
            if (dir) {
                e.preventDefault();
                setDirection(dir);
            }
        });
    }

    function setupDpad() {
        var btns = document.querySelectorAll('.dpad-btn');
        for (var i = 0; i < btns.length; i++) {
            (function(btn) {
                btn.addEventListener('touchstart', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    setDirection(btn.getAttribute('data-dir'));
                }, { passive: false });
                btn.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    setDirection(btn.getAttribute('data-dir'));
                });
            })(btns[i]);
        }
    }

    function setDirection(dir) {
        queuedDir = dir;
        NeonAudio.resume();
    }

    function consumeDirection() {
        var d = queuedDir;
        queuedDir = null;
        return d;
    }

    function peekDirection() {
        return queuedDir;
    }

    function getCurrentDir() {
        return currentDir;
    }

    function setCurrentDir(dir) {
        currentDir = dir;
    }

    function setDpadVisible(visible) {
        var el = document.getElementById('dpad');
        if (el) {
            el.classList.toggle('hidden', !visible);
        }
        dpadEnabled = visible;
    }

    function onPause(fn) {
        callbacks.pause = fn;
    }

    function reset() {
        currentDir = null;
        queuedDir = null;
    }

    return {
        init: init,
        consumeDirection: consumeDirection,
        peekDirection: peekDirection,
        getCurrentDir: getCurrentDir,
        setCurrentDir: setCurrentDir,
        setDirection: setDirection,
        setDpadVisible: setDpadVisible,
        onPause: onPause,
        reset: reset
    };
})();
