/* =============================================
   BLOCKFORGE - Input Handler (Touch + Keyboard)
   ============================================= */

var BlockInput = (function() {
    'use strict';

    var callbacks = {};
    var touchStartX = 0, touchStartY = 0;
    var touchStartTime = 0;
    var isSwiping = false;
    var swipeThreshold = 30;
    var tapThreshold = 10;
    var repeatTimers = {};
    var DAS = 150; // delayed auto shift
    var ARR = 50;  // auto repeat rate
    var softDropInterval = null;
    var enabled = true;
    var useOnScreenControls = true;

    function init(options) {
        options = options || {};
        useOnScreenControls = options.controls !== false;

        setupKeyboard();
        setupTouch();
        setupOnScreenControls();
    }

    function setCallbacks(cbs) {
        callbacks = cbs;
    }

    function setEnabled(val) {
        enabled = val;
    }

    function emit(action) {
        if (!enabled) return;
        if (callbacks[action]) callbacks[action]();
    }

    // Keyboard
    function setupKeyboard() {
        document.addEventListener('keydown', function(e) {
            if (!enabled) return;
            switch (e.code) {
                case 'ArrowLeft':
                case 'KeyA':
                    e.preventDefault();
                    emit('moveLeft');
                    startRepeat('left', function() { emit('moveLeft'); });
                    break;
                case 'ArrowRight':
                case 'KeyD':
                    e.preventDefault();
                    emit('moveRight');
                    startRepeat('right', function() { emit('moveRight'); });
                    break;
                case 'ArrowDown':
                case 'KeyS':
                    e.preventDefault();
                    emit('softDrop');
                    startRepeat('down', function() { emit('softDrop'); });
                    break;
                case 'ArrowUp':
                case 'KeyW':
                case 'KeyX':
                    e.preventDefault();
                    emit('rotate');
                    break;
                case 'Space':
                    e.preventDefault();
                    emit('hardDrop');
                    break;
                case 'KeyC':
                case 'ShiftLeft':
                case 'ShiftRight':
                    e.preventDefault();
                    emit('hold');
                    break;
                case 'KeyP':
                case 'Escape':
                    e.preventDefault();
                    emit('pause');
                    break;
            }
        });

        document.addEventListener('keyup', function(e) {
            switch (e.code) {
                case 'ArrowLeft':
                case 'KeyA':
                    stopRepeat('left');
                    break;
                case 'ArrowRight':
                case 'KeyD':
                    stopRepeat('right');
                    break;
                case 'ArrowDown':
                case 'KeyS':
                    stopRepeat('down');
                    break;
            }
        });
    }

    function startRepeat(key, fn) {
        stopRepeat(key);
        repeatTimers[key] = {
            timeout: setTimeout(function() {
                repeatTimers[key].interval = setInterval(fn, ARR);
            }, DAS)
        };
    }

    function stopRepeat(key) {
        if (repeatTimers[key]) {
            clearTimeout(repeatTimers[key].timeout);
            clearInterval(repeatTimers[key].interval);
            delete repeatTimers[key];
        }
    }

    // Touch gestures on game canvas
    function setupTouch() {
        var gameCanvas = document.getElementById('game-canvas');
        if (!gameCanvas) return;

        gameCanvas.addEventListener('touchstart', function(e) {
            if (!enabled) return;
            e.preventDefault();
            var touch = e.touches[0];
            touchStartX = touch.clientX;
            touchStartY = touch.clientY;
            touchStartTime = Date.now();
            isSwiping = false;
        }, { passive: false });

        gameCanvas.addEventListener('touchmove', function(e) {
            if (!enabled) return;
            e.preventDefault();
            var touch = e.touches[0];
            var dx = touch.clientX - touchStartX;
            var dy = touch.clientY - touchStartY;

            if (!isSwiping && (Math.abs(dx) > swipeThreshold || Math.abs(dy) > swipeThreshold)) {
                isSwiping = true;
                if (Math.abs(dx) > Math.abs(dy)) {
                    // Horizontal swipe
                    if (dx > 0) emit('moveRight');
                    else emit('moveLeft');
                    touchStartX = touch.clientX;
                } else {
                    // Vertical swipe
                    if (dy > 0) {
                        emit('softDrop');
                    } else {
                        emit('hardDrop');
                    }
                    touchStartY = touch.clientY;
                }
            } else if (isSwiping) {
                // Continue movement for horizontal
                if (Math.abs(dx) > swipeThreshold) {
                    if (dx > 0) emit('moveRight');
                    else emit('moveLeft');
                    touchStartX = touch.clientX;
                }
                if (dy > swipeThreshold) {
                    emit('softDrop');
                    touchStartY = touch.clientY;
                }
            }
        }, { passive: false });

        gameCanvas.addEventListener('touchend', function(e) {
            if (!enabled) return;
            e.preventDefault();
            var elapsed = Date.now() - touchStartTime;
            if (!isSwiping && elapsed < 300) {
                // Tap = rotate
                emit('rotate');
            }
            isSwiping = false;
        }, { passive: false });
    }

    // On-screen button controls
    function setupOnScreenControls() {
        bindBtn('ctrl-left', 'moveLeft', true);
        bindBtn('ctrl-right', 'moveRight', true);
        bindBtn('ctrl-down', 'softDrop', true);
        bindBtn('ctrl-rotate', 'rotate', false);
        bindBtn('ctrl-hold', 'hold', false);
        bindBtn('ctrl-drop', 'hardDrop', false);
        bindBtn('ctrl-pause', 'pause', false);
    }

    function bindBtn(id, action, repeatable) {
        var btn = document.getElementById(id);
        if (!btn) return;

        var repeatKey = 'btn_' + id;

        btn.addEventListener('touchstart', function(e) {
            e.preventDefault();
            if (!enabled) return;
            emit(action);
            if (repeatable) {
                startRepeat(repeatKey, function() { emit(action); });
            }
        }, { passive: false });

        btn.addEventListener('touchend', function(e) {
            e.preventDefault();
            if (repeatable) stopRepeat(repeatKey);
        }, { passive: false });

        btn.addEventListener('touchcancel', function(e) {
            if (repeatable) stopRepeat(repeatKey);
        });

        // Mouse fallback for desktop
        btn.addEventListener('mousedown', function(e) {
            e.preventDefault();
            if (!enabled) return;
            emit(action);
            if (repeatable) {
                startRepeat(repeatKey, function() { emit(action); });
            }
        });

        btn.addEventListener('mouseup', function() {
            if (repeatable) stopRepeat(repeatKey);
        });

        btn.addEventListener('mouseleave', function() {
            if (repeatable) stopRepeat(repeatKey);
        });
    }

    function showControls(show) {
        var controls = document.getElementById('touch-controls');
        if (controls) {
            controls.style.display = show ? '' : 'none';
        }
    }

    function destroy() {
        for (var key in repeatTimers) {
            stopRepeat(key);
        }
    }

    // Haptic feedback
    function vibrate(pattern) {
        try {
            if (navigator.vibrate) {
                navigator.vibrate(pattern);
            }
        } catch(e) {}
    }

    return {
        init: init,
        setCallbacks: setCallbacks,
        setEnabled: setEnabled,
        showControls: showControls,
        vibrate: vibrate,
        destroy: destroy
    };
})();
