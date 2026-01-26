/* =============================================
   MAHJONG SOLITAIRE - Procedural Audio (Web Audio)
   ============================================= */

var MahjongAudio = (function() {
    'use strict';

    var ctx = null;
    var enabled = true;
    var masterGain = null;

    function init() {
        try {
            ctx = new (window.AudioContext || window.webkitAudioContext)();
            masterGain = ctx.createGain();
            masterGain.gain.value = 0.3;
            masterGain.connect(ctx.destination);
        } catch (e) {
            console.warn('Web Audio not supported');
        }
    }

    function resume() {
        if (ctx && ctx.state === 'suspended') {
            ctx.resume();
        }
    }

    function setEnabled(value) {
        enabled = value;
    }

    function play(sound) {
        if (!ctx || !enabled) return;

        // Resume context on user interaction
        if (ctx.state === 'suspended') {
            ctx.resume();
        }

        switch (sound) {
            case 'select':
                playSelect();
                break;
            case 'match':
                playMatch();
                break;
            case 'error':
                playError();
                break;
            case 'win':
                playWin();
                break;
            case 'shuffle':
                playShuffle();
                break;
        }
    }

    function playSelect() {
        // Short click sound
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();

        osc.type = 'sine';
        osc.frequency.setValueAtTime(800, ctx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(1200, ctx.currentTime + 0.05);

        gain.gain.setValueAtTime(0.15, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.08);

        osc.connect(gain);
        gain.connect(masterGain);

        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.08);
    }

    function playMatch() {
        // Pleasant chime for matching
        var now = ctx.currentTime;
        var frequencies = [523.25, 659.25, 783.99]; // C5, E5, G5 (C major chord)

        frequencies.forEach(function(freq, i) {
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();

            osc.type = 'sine';
            osc.frequency.value = freq;

            var startTime = now + i * 0.05;
            gain.gain.setValueAtTime(0, startTime);
            gain.gain.linearRampToValueAtTime(0.12, startTime + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.01, startTime + 0.4);

            osc.connect(gain);
            gain.connect(masterGain);

            osc.start(startTime);
            osc.stop(startTime + 0.4);
        });
    }

    function playError() {
        // Low buzzy sound for error
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();

        osc.type = 'square';
        osc.frequency.setValueAtTime(150, ctx.currentTime);
        osc.frequency.linearRampToValueAtTime(100, ctx.currentTime + 0.15);

        gain.gain.setValueAtTime(0.08, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.15);

        osc.connect(gain);
        gain.connect(masterGain);

        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.15);
    }

    function playWin() {
        // Victory fanfare
        var now = ctx.currentTime;
        var notes = [
            { freq: 523.25, time: 0, dur: 0.15 },      // C5
            { freq: 659.25, time: 0.12, dur: 0.15 },   // E5
            { freq: 783.99, time: 0.24, dur: 0.15 },   // G5
            { freq: 1046.5, time: 0.36, dur: 0.4 }     // C6
        ];

        notes.forEach(function(note) {
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();

            osc.type = 'sine';
            osc.frequency.value = note.freq;

            var startTime = now + note.time;
            gain.gain.setValueAtTime(0, startTime);
            gain.gain.linearRampToValueAtTime(0.15, startTime + 0.02);
            gain.gain.setValueAtTime(0.15, startTime + note.dur - 0.05);
            gain.gain.exponentialRampToValueAtTime(0.01, startTime + note.dur);

            osc.connect(gain);
            gain.connect(masterGain);

            osc.start(startTime);
            osc.stop(startTime + note.dur);
        });

        // Add a shimmer effect
        for (var i = 0; i < 5; i++) {
            var shimmer = ctx.createOscillator();
            var shimmerGain = ctx.createGain();

            shimmer.type = 'sine';
            shimmer.frequency.value = 1500 + Math.random() * 1000;

            var shimmerStart = now + 0.5 + i * 0.08;
            shimmerGain.gain.setValueAtTime(0, shimmerStart);
            shimmerGain.gain.linearRampToValueAtTime(0.05, shimmerStart + 0.02);
            shimmerGain.gain.exponentialRampToValueAtTime(0.001, shimmerStart + 0.2);

            shimmer.connect(shimmerGain);
            shimmerGain.connect(masterGain);

            shimmer.start(shimmerStart);
            shimmer.stop(shimmerStart + 0.2);
        }
    }

    function playShuffle() {
        // Shuffling cards sound
        var now = ctx.currentTime;

        for (var i = 0; i < 8; i++) {
            var noise = ctx.createOscillator();
            var noiseGain = ctx.createGain();
            var filter = ctx.createBiquadFilter();

            noise.type = 'sawtooth';
            noise.frequency.value = 200 + Math.random() * 100;

            filter.type = 'bandpass';
            filter.frequency.value = 1000 + Math.random() * 500;
            filter.Q.value = 2;

            var startTime = now + i * 0.04;
            noiseGain.gain.setValueAtTime(0, startTime);
            noiseGain.gain.linearRampToValueAtTime(0.06, startTime + 0.01);
            noiseGain.gain.exponentialRampToValueAtTime(0.001, startTime + 0.06);

            noise.connect(filter);
            filter.connect(noiseGain);
            noiseGain.connect(masterGain);

            noise.start(startTime);
            noise.stop(startTime + 0.06);
        }
    }

    return {
        init: init,
        resume: resume,
        setEnabled: setEnabled,
        play: play
    };

})();
