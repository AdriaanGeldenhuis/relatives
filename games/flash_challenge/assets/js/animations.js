/**
 * ============================================
 * FLASH CHALLENGE - Animations Module
 * Confetti, transitions, and visual effects
 * ============================================
 */

(function() {
    'use strict';

    /**
     * FlashAnimations - Handles all animations
     */
    window.FlashAnimations = {
        /**
         * Confetti configuration
         */
        confettiCanvas: null,
        confettiCtx: null,
        confettiParticles: [],
        confettiAnimationId: null,
        confettiColors: [
            '#667eea', '#764ba2', '#4ecca3', '#ffd93d',
            '#ff6b6b', '#74b9ff', '#00cec9', '#fd79a8'
        ],

        /**
         * Initialize confetti canvas
         */
        initConfetti: function() {
            this.confettiCanvas = document.getElementById('confettiCanvas');
            if (!this.confettiCanvas) return;

            this.confettiCtx = this.confettiCanvas.getContext('2d');
            this.resizeConfettiCanvas();

            window.addEventListener('resize', () => this.resizeConfettiCanvas());
        },

        /**
         * Resize confetti canvas to window size
         */
        resizeConfettiCanvas: function() {
            if (!this.confettiCanvas) return;
            this.confettiCanvas.width = window.innerWidth;
            this.confettiCanvas.height = window.innerHeight;
        },

        /**
         * Create a confetti particle
         */
        createConfettiParticle: function(x, y) {
            return {
                x: x,
                y: y,
                vx: (Math.random() - 0.5) * 15,
                vy: Math.random() * -15 - 5,
                color: this.confettiColors[Math.floor(Math.random() * this.confettiColors.length)],
                size: Math.random() * 10 + 5,
                rotation: Math.random() * 360,
                rotationSpeed: (Math.random() - 0.5) * 15,
                gravity: 0.5,
                friction: 0.99,
                opacity: 1,
                type: Math.random() > 0.5 ? 'rect' : 'circle'
            };
        },

        /**
         * Launch confetti burst
         */
        launchConfetti: function(options = {}) {
            const count = options.count || 100;
            const originX = options.x || window.innerWidth / 2;
            const originY = options.y || window.innerHeight / 3;
            const spread = options.spread || 100;

            for (let i = 0; i < count; i++) {
                const x = originX + (Math.random() - 0.5) * spread;
                const y = originY + (Math.random() - 0.5) * spread / 2;
                this.confettiParticles.push(this.createConfettiParticle(x, y));
            }

            if (!this.confettiAnimationId) {
                this.animateConfetti();
            }
        },

        /**
         * Launch confetti from sides (celebration)
         */
        celebrateConfetti: function() {
            // Left burst
            this.launchConfetti({
                x: 0,
                y: window.innerHeight / 2,
                count: 60,
                spread: 50
            });

            // Right burst
            this.launchConfetti({
                x: window.innerWidth,
                y: window.innerHeight / 2,
                count: 60,
                spread: 50
            });

            // Delayed center burst
            setTimeout(() => {
                this.launchConfetti({
                    x: window.innerWidth / 2,
                    y: window.innerHeight / 4,
                    count: 80,
                    spread: 150
                });
            }, 200);
        },

        /**
         * Animate confetti particles
         */
        animateConfetti: function() {
            if (!this.confettiCtx) return;

            this.confettiCtx.clearRect(0, 0, this.confettiCanvas.width, this.confettiCanvas.height);

            for (let i = this.confettiParticles.length - 1; i >= 0; i--) {
                const p = this.confettiParticles[i];

                // Update physics
                p.vy += p.gravity;
                p.vx *= p.friction;
                p.vy *= p.friction;
                p.x += p.vx;
                p.y += p.vy;
                p.rotation += p.rotationSpeed;
                p.opacity -= 0.008;

                // Remove if off screen or faded
                if (p.opacity <= 0 || p.y > this.confettiCanvas.height + 50) {
                    this.confettiParticles.splice(i, 1);
                    continue;
                }

                // Draw particle
                this.confettiCtx.save();
                this.confettiCtx.translate(p.x, p.y);
                this.confettiCtx.rotate(p.rotation * Math.PI / 180);
                this.confettiCtx.globalAlpha = p.opacity;
                this.confettiCtx.fillStyle = p.color;

                if (p.type === 'rect') {
                    this.confettiCtx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
                } else {
                    this.confettiCtx.beginPath();
                    this.confettiCtx.arc(0, 0, p.size / 2, 0, Math.PI * 2);
                    this.confettiCtx.fill();
                }

                this.confettiCtx.restore();
            }

            if (this.confettiParticles.length > 0) {
                this.confettiAnimationId = requestAnimationFrame(() => this.animateConfetti());
            } else {
                this.confettiAnimationId = null;
            }
        },

        /**
         * Clear all confetti
         */
        clearConfetti: function() {
            this.confettiParticles = [];
            if (this.confettiAnimationId) {
                cancelAnimationFrame(this.confettiAnimationId);
                this.confettiAnimationId = null;
            }
            if (this.confettiCtx) {
                this.confettiCtx.clearRect(0, 0, this.confettiCanvas.width, this.confettiCanvas.height);
            }
        },

        /**
         * Animate number counting up
         */
        animateNumber: function(element, start, end, duration = 1000, suffix = '') {
            if (!element) return;

            const startTime = performance.now();
            const range = end - start;

            const animate = (currentTime) => {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);

                // Ease out quad
                const easeProgress = 1 - (1 - progress) * (1 - progress);
                const current = Math.round(start + range * easeProgress);

                element.textContent = current + suffix;

                if (progress < 1) {
                    requestAnimationFrame(animate);
                }
            };

            requestAnimationFrame(animate);
        },

        /**
         * Animate element entrance
         */
        animateIn: function(element, type = 'fadeIn', duration = 300) {
            if (!element) return;

            element.style.animation = 'none';
            element.offsetHeight; // Trigger reflow
            element.style.animation = `${type} ${duration}ms ease forwards`;
        },

        /**
         * Animate element exit
         */
        animateOut: function(element, type = 'fadeOut', duration = 300) {
            return new Promise(resolve => {
                if (!element) {
                    resolve();
                    return;
                }

                element.style.animation = `${type} ${duration}ms ease forwards`;

                setTimeout(() => {
                    resolve();
                }, duration);
            });
        },

        /**
         * Shake animation for errors
         */
        shake: function(element) {
            if (!element) return;

            element.style.animation = 'none';
            element.offsetHeight;
            element.style.animation = 'shake 0.5s ease';
        },

        /**
         * Pulse animation
         */
        pulse: function(element, times = 1) {
            if (!element) return;

            let count = 0;
            const doPulse = () => {
                element.style.animation = 'none';
                element.offsetHeight;
                element.style.animation = 'pulse 0.3s ease';

                count++;
                if (count < times) {
                    setTimeout(doPulse, 350);
                }
            };

            doPulse();
        },

        /**
         * Timer ring animation
         */
        animateTimerRing: function(progress, remaining) {
            const timerProgress = document.getElementById('activeTimerProgress');
            const timerValue = document.getElementById('activeTimerValue');

            if (!timerProgress) return;

            // Calculate stroke dash offset (full circle = 339.292)
            const circumference = 339.292;
            const offset = circumference * (1 - progress);
            timerProgress.style.strokeDashoffset = offset;

            // Update color based on remaining time
            timerProgress.classList.remove('active', 'warning', 'danger');

            if (remaining <= 5) {
                timerProgress.classList.add('danger');
            } else if (remaining <= 10) {
                timerProgress.classList.add('warning');
            } else {
                timerProgress.classList.add('active');
            }

            // Update value
            if (timerValue) {
                timerValue.textContent = Math.ceil(remaining);
            }
        },

        /**
         * Reset timer ring
         */
        resetTimerRing: function() {
            const timerProgress = document.getElementById('activeTimerProgress');
            const timerValue = document.getElementById('activeTimerValue');
            const preTimerProgress = document.getElementById('timerProgress');
            const preTimerValue = document.getElementById('timerValue');

            if (timerProgress) {
                timerProgress.style.strokeDashoffset = '0';
                timerProgress.classList.remove('warning', 'danger');
                timerProgress.classList.add('active');
            }

            if (timerValue) {
                timerValue.textContent = '30';
            }

            if (preTimerProgress) {
                preTimerProgress.style.strokeDashoffset = '0';
            }

            if (preTimerValue) {
                preTimerValue.textContent = '30';
            }
        },

        /**
         * Create share card on canvas
         */
        createShareCard: function(data) {
            const canvas = document.getElementById('shareCanvas');
            if (!canvas) return null;

            const ctx = canvas.getContext('2d');
            const width = canvas.width;
            const height = canvas.height;

            // Background gradient
            const gradient = ctx.createLinearGradient(0, 0, width, height);
            gradient.addColorStop(0, '#0f0c29');
            gradient.addColorStop(0.5, '#1a1a2e');
            gradient.addColorStop(1, '#16213e');
            ctx.fillStyle = gradient;
            ctx.fillRect(0, 0, width, height);

            // Border
            ctx.strokeStyle = 'rgba(255, 255, 255, 0.2)';
            ctx.lineWidth = 2;
            ctx.roundRect(10, 10, width - 20, height - 20, 16);
            ctx.stroke();

            // Title
            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 24px -apple-system, system-ui, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('⚡ Flash Challenge', width / 2, 50);

            // Date
            ctx.fillStyle = 'rgba(255, 255, 255, 0.6)';
            ctx.font = '14px -apple-system, system-ui, sans-serif';
            ctx.fillText(new Date().toLocaleDateString('en-US', {
                weekday: 'long',
                month: 'short',
                day: 'numeric'
            }), width / 2, 75);

            // Score
            ctx.font = 'bold 64px -apple-system, system-ui, sans-serif';
            const scoreGradient = ctx.createLinearGradient(width / 2 - 50, 100, width / 2 + 50, 160);
            scoreGradient.addColorStop(0, '#667eea');
            scoreGradient.addColorStop(1, '#764ba2');
            ctx.fillStyle = scoreGradient;
            ctx.fillText(data.score, width / 2, 150);

            ctx.fillStyle = 'rgba(255, 255, 255, 0.6)';
            ctx.font = '16px -apple-system, system-ui, sans-serif';
            ctx.fillText('points', width / 2, 175);

            // Verdict
            const verdictColors = {
                correct: '#4ecca3',
                partial: '#ffd93d',
                incorrect: '#ff6b6b'
            };
            ctx.fillStyle = verdictColors[data.verdict] || '#ffffff';
            ctx.font = 'bold 20px -apple-system, system-ui, sans-serif';
            ctx.fillText(data.verdict.charAt(0).toUpperCase() + data.verdict.slice(1), width / 2, 210);

            // Streak
            if (data.streak > 0) {
                ctx.fillStyle = '#ffd93d';
                ctx.font = '18px -apple-system, system-ui, sans-serif';
                ctx.fillText('🔥 ' + data.streak + ' day streak', width / 2, 245);
            }

            // App branding
            ctx.fillStyle = 'rgba(255, 255, 255, 0.4)';
            ctx.font = '12px -apple-system, system-ui, sans-serif';
            ctx.fillText('Relatives Family App', width / 2, height - 20);

            return canvas.toDataURL('image/png');
        },

        /**
         * Download share image
         */
        downloadShareImage: function(data) {
            const dataUrl = this.createShareCard(data);
            if (!dataUrl) return;

            const link = document.createElement('a');
            link.download = 'flash-challenge-' + new Date().toISOString().split('T')[0] + '.png';
            link.href = dataUrl;
            link.click();
        },

        /**
         * Copy share text to clipboard
         */
        copyShareText: async function(data) {
            const text = `⚡ Flash Challenge Results

🎯 Score: ${data.score} points
${data.verdict === 'correct' ? '✅' : data.verdict === 'partial' ? '🟡' : '❌'} ${data.verdict.charAt(0).toUpperCase() + data.verdict.slice(1)}
${data.streak > 0 ? '🔥 ' + data.streak + ' day streak' : ''}

Play now in the Relatives app!`;

            try {
                await navigator.clipboard.writeText(text);
                return true;
            } catch (e) {
                console.warn('Failed to copy to clipboard:', e);
                return false;
            }
        }
    };

    // Add keyframe animations via style injection
    const styleSheet = document.createElement('style');
    styleSheet.textContent = `
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }

        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.8); }
            to { opacity: 1; transform: scale(1); }
        }
    `;
    document.head.appendChild(styleSheet);

    // Add roundRect polyfill if needed
    if (!CanvasRenderingContext2D.prototype.roundRect) {
        CanvasRenderingContext2D.prototype.roundRect = function(x, y, w, h, r) {
            if (w < 2 * r) r = w / 2;
            if (h < 2 * r) r = h / 2;
            this.beginPath();
            this.moveTo(x + r, y);
            this.arcTo(x + w, y, x + w, y + h, r);
            this.arcTo(x + w, y + h, x, y + h, r);
            this.arcTo(x, y + h, x, y, r);
            this.arcTo(x, y, x + w, y, r);
            this.closePath();
            return this;
        };
    }

})();
