/**
 * NEON NIBBLER - Audio Engine
 * WebAudio API oscillator-based sound effects
 */
var NeonAudio = (function() {
    'use strict';

    var ctx = null;
    var enabled = true;
    var initialized = false;

    function init() {
        if (initialized) return;
        try {
            ctx = new (window.AudioContext || window.webkitAudioContext)();
            initialized = true;
        } catch (e) {
            enabled = false;
        }
    }

    function resume() {
        if (ctx && ctx.state === 'suspended') {
            ctx.resume();
        }
    }

    function playTone(freq, duration, type, volume, ramp) {
        if (!enabled || !ctx) return;
        resume();
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.type = type || 'sine';
        osc.frequency.setValueAtTime(freq, ctx.currentTime);
        if (ramp) {
            osc.frequency.linearRampToValueAtTime(ramp, ctx.currentTime + duration);
        }
        gain.gain.setValueAtTime(volume || 0.15, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + duration);
    }

    function dot() {
        playTone(880, 0.05, 'sine', 0.08);
    }

    function power() {
        playTone(220, 0.3, 'sawtooth', 0.12, 880);
    }

    function tag() {
        playTone(523, 0.1, 'square', 0.1);
        setTimeout(function() { playTone(784, 0.1, 'square', 0.1); }, 80);
        setTimeout(function() { playTone(1047, 0.15, 'square', 0.1); }, 160);
    }

    function death() {
        playTone(440, 0.15, 'sawtooth', 0.15, 110);
        setTimeout(function() { playTone(330, 0.2, 'sawtooth', 0.12, 55); }, 150);
    }

    function levelUp() {
        playTone(523, 0.1, 'sine', 0.12);
        setTimeout(function() { playTone(659, 0.1, 'sine', 0.12); }, 100);
        setTimeout(function() { playTone(784, 0.1, 'sine', 0.12); }, 200);
        setTimeout(function() { playTone(1047, 0.2, 'sine', 0.15); }, 300);
    }

    function menuSelect() {
        playTone(660, 0.08, 'sine', 0.06);
    }

    function setEnabled(val) {
        enabled = val;
    }

    function isEnabled() {
        return enabled;
    }

    return {
        init: init,
        resume: resume,
        dot: dot,
        power: power,
        tag: tag,
        death: death,
        levelUp: levelUp,
        menuSelect: menuSelect,
        setEnabled: setEnabled,
        isEnabled: isEnabled
    };
})();
