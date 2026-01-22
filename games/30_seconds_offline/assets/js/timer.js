/**
 * 30 Seconds Party - Timer Module
 * Handles countdown timer with SVG ring animation
 */

(function() {
    'use strict';

    const TIMER_CONFIG = {
        TOTAL_DURATION: 30,
        WARNING_THRESHOLD: 10,
        DANGER_THRESHOLD: 5,
        UPDATE_INTERVAL: 100 // ms
    };

    window.GameTimer = {
        // Timer state
        duration: TIMER_CONFIG.TOTAL_DURATION,
        remaining: TIMER_CONFIG.TOTAL_DURATION,
        isRunning: false,
        isPaused: false,
        intervalId: null,
        startTime: null,
        pausedTime: null,

        // DOM elements
        elements: {
            container: null,
            ring: null,
            ringProgress: null,
            display: null,
            seconds: null,
            label: null
        },

        // Callbacks
        callbacks: {
            onTick: null,
            onWarning: null,
            onDanger: null,
            onComplete: null
        },

        // SVG ring configuration
        ringConfig: {
            radius: 90,
            circumference: 0
        },

        /**
         * Initialize the timer
         */
        init: function(options = {}) {
            this.duration = options.duration || TIMER_CONFIG.TOTAL_DURATION;
            this.remaining = this.duration;

            this.callbacks = {
                onTick: options.onTick || null,
                onWarning: options.onWarning || null,
                onDanger: options.onDanger || null,
                onComplete: options.onComplete || null
            };

            this.ringConfig.circumference = 2 * Math.PI * this.ringConfig.radius;

            this.cacheElements();
            this.createTimerSVG();
            this.updateDisplay();
        },

        /**
         * Cache DOM elements
         */
        cacheElements: function() {
            this.elements.container = document.getElementById('timer-container');
        },

        /**
         * Create the SVG timer ring
         */
        createTimerSVG: function() {
            if (!this.elements.container) return;

            const { radius, circumference } = this.ringConfig;
            const size = 200;
            const center = size / 2;
            const strokeWidth = 8;

            this.elements.container.innerHTML = `
                <svg class="timer-ring" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
                    <defs>
                        <linearGradient id="timer-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" style="stop-color:#667eea"/>
                            <stop offset="100%" style="stop-color:#764ba2"/>
                        </linearGradient>
                    </defs>
                    <circle
                        class="timer-ring-bg"
                        cx="${center}"
                        cy="${center}"
                        r="${radius}"
                    />
                    <circle
                        class="timer-ring-progress"
                        cx="${center}"
                        cy="${center}"
                        r="${radius}"
                        stroke-dasharray="${circumference}"
                        stroke-dashoffset="0"
                    />
                </svg>
                <div class="timer-display">
                    <div class="timer-seconds">${this.duration}</div>
                    <div class="timer-label">seconds</div>
                </div>
            `;

            this.elements.ring = this.elements.container.querySelector('.timer-ring');
            this.elements.ringProgress = this.elements.container.querySelector('.timer-ring-progress');
            this.elements.seconds = this.elements.container.querySelector('.timer-seconds');
            this.elements.label = this.elements.container.querySelector('.timer-label');
        },

        /**
         * Start the timer
         */
        start: function() {
            if (this.isRunning && !this.isPaused) return;

            if (this.isPaused) {
                // Resume from pause
                const pausedDuration = Date.now() - this.pausedTime;
                this.startTime += pausedDuration;
                this.isPaused = false;
            } else {
                // Fresh start
                this.remaining = this.duration;
                this.startTime = Date.now();
            }

            this.isRunning = true;

            // Trigger haptic feedback
            this.triggerHaptic('start');

            this.intervalId = setInterval(() => this.tick(), TIMER_CONFIG.UPDATE_INTERVAL);
        },

        /**
         * Pause the timer
         */
        pause: function() {
            if (!this.isRunning || this.isPaused) return;

            this.isPaused = true;
            this.pausedTime = Date.now();
            clearInterval(this.intervalId);
            this.intervalId = null;
        },

        /**
         * Stop the timer
         */
        stop: function() {
            this.isRunning = false;
            this.isPaused = false;
            clearInterval(this.intervalId);
            this.intervalId = null;
        },

        /**
         * Reset the timer
         */
        reset: function() {
            this.stop();
            this.remaining = this.duration;
            this.startTime = null;
            this.pausedTime = null;
            this.updateDisplay();
            this.updateRing(1);
            this.clearWarningState();
        },

        /**
         * Timer tick
         */
        tick: function() {
            const elapsed = (Date.now() - this.startTime) / 1000;
            this.remaining = Math.max(0, this.duration - elapsed);

            const progress = this.remaining / this.duration;
            this.updateDisplay();
            this.updateRing(progress);

            // Check thresholds
            if (this.remaining <= TIMER_CONFIG.DANGER_THRESHOLD && this.remaining > 0) {
                this.setDangerState();
                if (this.callbacks.onDanger && Math.floor(this.remaining) !== Math.floor(this.remaining + 0.1)) {
                    this.callbacks.onDanger(this.remaining);
                }
            } else if (this.remaining <= TIMER_CONFIG.WARNING_THRESHOLD) {
                this.setWarningState();
                if (this.callbacks.onWarning && !this._warningFired) {
                    this._warningFired = true;
                    this.callbacks.onWarning(this.remaining);
                }
            }

            // Fire tick callback
            if (this.callbacks.onTick) {
                this.callbacks.onTick(this.remaining);
            }

            // Check completion
            if (this.remaining <= 0) {
                this.complete();
            }
        },

        /**
         * Update the display
         */
        updateDisplay: function() {
            if (this.elements.seconds) {
                this.elements.seconds.textContent = Math.ceil(this.remaining);
            }
        },

        /**
         * Update the ring progress
         */
        updateRing: function(progress) {
            if (!this.elements.ringProgress) return;

            const offset = this.ringConfig.circumference * (1 - progress);
            this.elements.ringProgress.style.strokeDashoffset = offset;
        },

        /**
         * Set warning state (10 seconds)
         */
        setWarningState: function() {
            if (this.elements.ringProgress) {
                this.elements.ringProgress.classList.remove('danger');
                this.elements.ringProgress.classList.add('warning');
            }
        },

        /**
         * Set danger state (5 seconds)
         */
        setDangerState: function() {
            if (this.elements.ringProgress) {
                this.elements.ringProgress.classList.remove('warning');
                this.elements.ringProgress.classList.add('danger');
            }
            // Haptic on each second in danger zone
            if (Math.ceil(this.remaining) !== this._lastDangerSecond) {
                this._lastDangerSecond = Math.ceil(this.remaining);
                this.triggerHaptic('danger');
            }
        },

        /**
         * Clear warning/danger state
         */
        clearWarningState: function() {
            if (this.elements.ringProgress) {
                this.elements.ringProgress.classList.remove('warning', 'danger');
            }
            this._warningFired = false;
            this._lastDangerSecond = null;
        },

        /**
         * Complete the timer
         */
        complete: function() {
            this.stop();
            this.remaining = 0;
            this.updateDisplay();
            this.updateRing(0);

            // Strong haptic on complete
            this.triggerHaptic('complete');

            if (this.callbacks.onComplete) {
                this.callbacks.onComplete();
            }
        },

        /**
         * Trigger haptic feedback
         */
        triggerHaptic: function(type) {
            if (!navigator.vibrate) return;

            switch (type) {
                case 'start':
                    navigator.vibrate([50, 30, 50]);
                    break;
                case 'warning':
                    navigator.vibrate(100);
                    break;
                case 'danger':
                    navigator.vibrate(50);
                    break;
                case 'complete':
                    navigator.vibrate([100, 50, 100, 50, 200]);
                    break;
                case 'strike':
                    navigator.vibrate([100, 50, 100]);
                    break;
                case 'correct':
                    navigator.vibrate(30);
                    break;
                default:
                    navigator.vibrate(50);
            }
        },

        /**
         * Get remaining time in seconds
         */
        getRemaining: function() {
            return this.remaining;
        },

        /**
         * Check if timer is running
         */
        getIsRunning: function() {
            return this.isRunning && !this.isPaused;
        },

        /**
         * Set duration dynamically
         */
        setDuration: function(seconds) {
            this.duration = seconds;
            if (!this.isRunning) {
                this.remaining = seconds;
                this.updateDisplay();
            }
        }
    };
})();
