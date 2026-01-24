/* =============================================
   BLOCKFORGE - WebAudio Oscillator Sounds
   ============================================= */

var BlockAudio = (function() {
    'use strict';

    var ctx = null;
    var enabled = true;
    var masterGain = null;

    function init() {
        if (ctx) return;
        try {
            ctx = new (window.AudioContext || window.webkitAudioContext)();
            masterGain = ctx.createGain();
            masterGain.gain.value = 0.3;
            masterGain.connect(ctx.destination);
        } catch(e) {
            ctx = null;
        }
    }

    function resume() {
        if (ctx && ctx.state === 'suspended') {
            ctx.resume();
        }
    }

    function setEnabled(val) {
        enabled = val;
    }

    function playTone(freq, duration, type, volume, detune) {
        if (!ctx || !enabled) return;
        resume();

        var osc = ctx.createOscillator();
        var gain = ctx.createGain();

        osc.type = type || 'sine';
        osc.frequency.value = freq;
        if (detune) osc.detune.value = detune;

        gain.gain.setValueAtTime((volume || 0.3) * 0.5, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + (duration || 0.1));

        osc.connect(gain);
        gain.connect(masterGain);

        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + (duration || 0.1) + 0.05);
    }

    function playChord(freqs, duration, type, volume) {
        freqs.forEach(function(f) {
            playTone(f, duration, type, (volume || 0.2) / freqs.length);
        });
    }

    // Game sounds
    function rotate() {
        playTone(880, 0.05, 'sine', 0.2);
        playTone(1100, 0.04, 'sine', 0.15);
    }

    function move() {
        playTone(440, 0.03, 'sine', 0.1);
    }

    function softDrop() {
        playTone(220, 0.04, 'triangle', 0.1);
    }

    function hardDrop() {
        playTone(110, 0.12, 'sawtooth', 0.25);
        playTone(80, 0.15, 'square', 0.15);
    }

    function lock() {
        playTone(200, 0.08, 'triangle', 0.15);
    }

    function lineClear(count) {
        var baseFreq = 600;
        var duration = 0.15 + count * 0.05;
        for (var i = 0; i < count; i++) {
            setTimeout(function(idx) {
                playTone(baseFreq + idx * 200, 0.1, 'sine', 0.2);
                playTone((baseFreq + idx * 200) * 1.5, 0.08, 'sine', 0.1);
            }.bind(null, i), i * 60);
        }
        setTimeout(function() {
            playChord([800, 1000, 1200], duration, 'sine', 0.15);
        }, count * 60);
    }

    function combo(level) {
        var freq = 500 + level * 100;
        playTone(freq, 0.1, 'sine', 0.2);
        setTimeout(function() {
            playTone(freq * 1.25, 0.08, 'sine', 0.15);
        }, 50);
        setTimeout(function() {
            playTone(freq * 1.5, 0.12, 'sine', 0.2);
        }, 100);
    }

    function levelUp() {
        var notes = [523, 659, 784, 1047];
        notes.forEach(function(n, i) {
            setTimeout(function() {
                playTone(n, 0.15, 'sine', 0.25);
                playTone(n * 0.5, 0.15, 'triangle', 0.1);
            }, i * 100);
        });
    }

    function hold() {
        playTone(660, 0.06, 'sine', 0.15);
        playTone(550, 0.06, 'sine', 0.1);
    }

    function gameOver() {
        var notes = [400, 350, 300, 250, 200];
        notes.forEach(function(n, i) {
            setTimeout(function() {
                playTone(n, 0.2, 'sawtooth', 0.15);
            }, i * 150);
        });
    }

    function achievement() {
        playChord([523, 659, 784], 0.3, 'sine', 0.25);
        setTimeout(function() {
            playChord([659, 784, 1047], 0.4, 'sine', 0.25);
        }, 200);
    }

    function menuSelect() {
        playTone(800, 0.05, 'sine', 0.15);
    }

    function countdown() {
        playTone(600, 0.1, 'square', 0.15);
    }

    function countdownGo() {
        playChord([800, 1000, 1200], 0.2, 'sine', 0.25);
    }

    return {
        init: init,
        resume: resume,
        setEnabled: setEnabled,
        rotate: rotate,
        move: move,
        softDrop: softDrop,
        hardDrop: hardDrop,
        lock: lock,
        lineClear: lineClear,
        combo: combo,
        levelUp: levelUp,
        hold: hold,
        gameOver: gameOver,
        achievement: achievement,
        menuSelect: menuSelect,
        countdown: countdown,
        countdownGo: countdownGo
    };
})();
