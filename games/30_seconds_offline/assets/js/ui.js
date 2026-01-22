/**
 * 30 Seconds Party - UI Utilities Module
 * Handles DOM manipulation, animations, and confetti
 */

(function() {
    'use strict';

    // Confetti configuration
    const CONFETTI_CONFIG = {
        PARTICLE_COUNT: 150,
        COLORS: ['#667eea', '#764ba2', '#10b981', '#f59e0b', '#ef4444', '#ec4899'],
        GRAVITY: 0.3,
        FRICTION: 0.99,
        DURATION: 4000
    };

    window.GameUI = {
        // Cached elements
        elements: {},

        // Confetti state
        confettiCanvas: null,
        confettiCtx: null,
        confettiParticles: [],
        confettiAnimationId: null,

        /**
         * Initialize UI module
         */
        init: function() {
            this.setupThemeToggle();
            this.setupConfettiCanvas();
        },

        /**
         * Cache DOM elements for a page
         */
        cacheElements: function(selectors) {
            this.elements = {};
            for (const [key, selector] of Object.entries(selectors)) {
                if (selector.startsWith('#')) {
                    this.elements[key] = document.querySelector(selector);
                } else if (selector.startsWith('.')) {
                    this.elements[key] = document.querySelectorAll(selector);
                } else {
                    this.elements[key] = document.getElementById(selector);
                }
            }
            return this.elements;
        },

        /**
         * Show a screen and hide others
         */
        showScreen: function(screenId) {
            document.querySelectorAll('.screen').forEach(screen => {
                screen.classList.remove('active');
            });

            const targetScreen = document.getElementById(screenId);
            if (targetScreen) {
                targetScreen.classList.add('active');
            }
        },

        /**
         * Setup theme toggle
         */
        setupThemeToggle: function() {
            const toggle = document.querySelector('.theme-toggle-btn');
            if (toggle) {
                toggle.addEventListener('click', () => {
                    GameState.toggleTheme();
                    this.updateThemeIcon();
                });
                this.updateThemeIcon();
            }
        },

        /**
         * Update theme toggle icon
         */
        updateThemeIcon: function() {
            const toggle = document.querySelector('.theme-toggle-btn');
            if (toggle) {
                toggle.textContent = GameState.settings.theme === 'dark' ? '☀️' : '🌙';
            }
        },

        /**
         * Setup confetti canvas
         */
        setupConfettiCanvas: function() {
            let canvas = document.getElementById('confetti-canvas');

            if (!canvas) {
                canvas = document.createElement('canvas');
                canvas.id = 'confetti-canvas';
                document.body.appendChild(canvas);
            }

            this.confettiCanvas = canvas;
            this.confettiCtx = canvas.getContext('2d');

            // Handle resize
            this.resizeConfettiCanvas();
            window.addEventListener('resize', () => this.resizeConfettiCanvas());
        },

        /**
         * Resize confetti canvas
         */
        resizeConfettiCanvas: function() {
            if (this.confettiCanvas) {
                this.confettiCanvas.width = window.innerWidth;
                this.confettiCanvas.height = window.innerHeight;
            }
        },

        /**
         * Create a confetti particle
         */
        createParticle: function() {
            return {
                x: Math.random() * this.confettiCanvas.width,
                y: -20,
                vx: (Math.random() - 0.5) * 10,
                vy: Math.random() * 5 + 5,
                rotation: Math.random() * 360,
                rotationSpeed: (Math.random() - 0.5) * 10,
                color: CONFETTI_CONFIG.COLORS[Math.floor(Math.random() * CONFETTI_CONFIG.COLORS.length)],
                size: Math.random() * 10 + 5,
                shape: Math.random() > 0.5 ? 'rect' : 'circle'
            };
        },

        /**
         * Update confetti particles
         */
        updateParticles: function() {
            this.confettiParticles.forEach((particle, index) => {
                particle.vy += CONFETTI_CONFIG.GRAVITY;
                particle.vx *= CONFETTI_CONFIG.FRICTION;
                particle.vy *= CONFETTI_CONFIG.FRICTION;

                particle.x += particle.vx;
                particle.y += particle.vy;
                particle.rotation += particle.rotationSpeed;

                // Remove particles that are off screen
                if (particle.y > this.confettiCanvas.height + 50) {
                    this.confettiParticles.splice(index, 1);
                }
            });
        },

        /**
         * Draw confetti particles
         */
        drawParticles: function() {
            this.confettiCtx.clearRect(0, 0, this.confettiCanvas.width, this.confettiCanvas.height);

            this.confettiParticles.forEach(particle => {
                this.confettiCtx.save();
                this.confettiCtx.translate(particle.x, particle.y);
                this.confettiCtx.rotate(particle.rotation * Math.PI / 180);
                this.confettiCtx.fillStyle = particle.color;

                if (particle.shape === 'rect') {
                    this.confettiCtx.fillRect(-particle.size / 2, -particle.size / 4, particle.size, particle.size / 2);
                } else {
                    this.confettiCtx.beginPath();
                    this.confettiCtx.arc(0, 0, particle.size / 2, 0, Math.PI * 2);
                    this.confettiCtx.fill();
                }

                this.confettiCtx.restore();
            });
        },

        /**
         * Animate confetti
         */
        animateConfetti: function() {
            this.updateParticles();
            this.drawParticles();

            if (this.confettiParticles.length > 0) {
                this.confettiAnimationId = requestAnimationFrame(() => this.animateConfetti());
            } else {
                cancelAnimationFrame(this.confettiAnimationId);
                this.confettiAnimationId = null;
            }
        },

        /**
         * Fire confetti celebration
         */
        fireConfetti: function() {
            // Cancel existing animation
            if (this.confettiAnimationId) {
                cancelAnimationFrame(this.confettiAnimationId);
            }

            // Create particles
            this.confettiParticles = [];
            for (let i = 0; i < CONFETTI_CONFIG.PARTICLE_COUNT; i++) {
                this.confettiParticles.push(this.createParticle());
            }

            // Start animation
            this.animateConfetti();
        },

        /**
         * Show toast notification
         */
        showToast: function(message, type = 'info', duration = 3000) {
            // Remove existing toast
            const existingToast = document.querySelector('.toast');
            if (existingToast) {
                existingToast.remove();
            }

            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `
                <span class="toast-message">${message}</span>
            `;

            // Style the toast
            toast.style.cssText = `
                position: fixed;
                bottom: 80px;
                left: 50%;
                transform: translateX(-50%);
                padding: 12px 24px;
                background: ${type === 'error' ? 'var(--danger)' : type === 'success' ? 'var(--success)' : 'var(--glass-bg)'};
                color: white;
                border-radius: var(--radius-full);
                font-weight: 600;
                box-shadow: var(--shadow-lg);
                z-index: 1000;
                animation: toastIn 0.3s ease-out;
            `;

            document.body.appendChild(toast);

            // Remove after duration
            setTimeout(() => {
                toast.style.animation = 'toastOut 0.3s ease-out';
                setTimeout(() => toast.remove(), 300);
            }, duration);
        },

        /**
         * Create QR code (simple version using API fallback)
         */
        createQRCode: function(container, data, size = 200) {
            // For offline use, we'll create a simple text display
            // In production, you'd use a library like qrcode-generator
            container.innerHTML = `
                <div style="
                    width: ${size}px;
                    height: ${size}px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: #f0f0f0;
                    border-radius: 8px;
                    text-align: center;
                    padding: 16px;
                    word-break: break-all;
                    font-size: 10px;
                    font-family: monospace;
                ">
                    <div>
                        <strong style="font-size: 12px; display: block; margin-bottom: 8px;">State Code:</strong>
                        ${data.substring(0, 50)}...
                    </div>
                </div>
            `;

            // If online, try to use a QR code API
            if (navigator.onLine) {
                const img = document.createElement('img');
                img.src = `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&data=${encodeURIComponent(data)}`;
                img.alt = 'QR Code';
                img.className = 'qr-code';
                img.onerror = () => {
                    // Keep the text fallback
                };
                img.onload = () => {
                    container.innerHTML = '';
                    container.appendChild(img);
                };
            }
        },

        /**
         * Format time remaining
         */
        formatTime: function(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return mins > 0 ? `${mins}:${secs.toString().padStart(2, '0')}` : `${secs}s`;
        },

        /**
         * Create loading spinner
         */
        createSpinner: function() {
            return `<div class="loading-spinner"></div>`;
        },

        /**
         * Scroll element into view
         */
        scrollIntoView: function(element, options = {}) {
            if (element && element.scrollIntoView) {
                element.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center',
                    ...options
                });
            }
        },

        /**
         * Animate element
         */
        animate: function(element, animationClass, duration = 300) {
            return new Promise(resolve => {
                element.classList.add(animationClass);
                setTimeout(() => {
                    element.classList.remove(animationClass);
                    resolve();
                }, duration);
            });
        },

        /**
         * Trigger haptic feedback
         */
        haptic: function(type = 'light') {
            if (!navigator.vibrate) return;

            switch (type) {
                case 'light':
                    navigator.vibrate(10);
                    break;
                case 'medium':
                    navigator.vibrate(30);
                    break;
                case 'heavy':
                    navigator.vibrate(50);
                    break;
                case 'success':
                    navigator.vibrate([30, 50, 30]);
                    break;
                case 'error':
                    navigator.vibrate([50, 30, 50, 30, 50]);
                    break;
                case 'warning':
                    navigator.vibrate([100, 50, 100]);
                    break;
            }
        },

        /**
         * Check if device is mobile
         */
        isMobile: function() {
            return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        },

        /**
         * Get ordinal suffix for a number
         */
        getOrdinal: function(n) {
            const s = ['th', 'st', 'nd', 'rd'];
            const v = n % 100;
            return n + (s[(v - 20) % 10] || s[v] || s[0]);
        },

        /**
         * Format date for display
         */
        formatDate: function(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        /**
         * Create modal
         */
        showModal: function(options) {
            const { title, content, buttons = [] } = options;

            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 1000;
                padding: 20px;
            `;

            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.style.cssText = `
                background: var(--glass-bg);
                backdrop-filter: blur(20px);
                border: 1px solid var(--glass-border);
                border-radius: var(--radius-lg);
                padding: var(--spacing-xl);
                max-width: 400px;
                width: 100%;
            `;

            modal.innerHTML = `
                <h2 style="margin-bottom: var(--spacing-md);">${title}</h2>
                <div style="margin-bottom: var(--spacing-lg); color: var(--text-secondary);">${content}</div>
                <div style="display: flex; gap: var(--spacing-sm); justify-content: flex-end;">
                    ${buttons.map((btn, i) => `
                        <button class="btn ${btn.primary ? 'btn-primary' : 'btn-secondary'}" data-btn-index="${i}">
                            ${btn.label}
                        </button>
                    `).join('')}
                </div>
            `;

            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            // Handle button clicks
            modal.querySelectorAll('button').forEach((btn, i) => {
                btn.addEventListener('click', () => {
                    overlay.remove();
                    if (buttons[i] && buttons[i].onClick) {
                        buttons[i].onClick();
                    }
                });
            });

            // Close on overlay click
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    overlay.remove();
                }
            });

            return overlay;
        },

        /**
         * Confirm dialog
         */
        confirm: function(message) {
            return new Promise(resolve => {
                this.showModal({
                    title: 'Confirm',
                    content: message,
                    buttons: [
                        { label: 'Cancel', onClick: () => resolve(false) },
                        { label: 'Confirm', primary: true, onClick: () => resolve(true) }
                    ]
                });
            });
        }
    };

    // Add toast animation styles
    const style = document.createElement('style');
    style.textContent = `
        @keyframes toastIn {
            from { opacity: 0; transform: translate(-50%, 20px); }
            to { opacity: 1; transform: translate(-50%, 0); }
        }
        @keyframes toastOut {
            from { opacity: 1; transform: translate(-50%, 0); }
            to { opacity: 0; transform: translate(-50%, 20px); }
        }
    `;
    document.head.appendChild(style);
})();
