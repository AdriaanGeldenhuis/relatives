/**
 * 30 Seconds Party - Share Card Module
 * Generates shareable result images locally using Canvas API
 */

(function() {
    'use strict';

    const CARD_CONFIG = {
        WIDTH: 600,
        HEIGHT: 800,
        PADDING: 40,
        COLORS: {
            background: '#0f0c29',
            backgroundGradient1: '#302b63',
            backgroundGradient2: '#24243e',
            primary: '#667eea',
            secondary: '#764ba2',
            success: '#10b981',
            warning: '#f59e0b',
            text: '#ffffff',
            textMuted: 'rgba(255, 255, 255, 0.7)',
            glass: 'rgba(255, 255, 255, 0.08)'
        },
        FONTS: {
            title: 'bold 36px -apple-system, BlinkMacSystemFont, sans-serif',
            subtitle: '20px -apple-system, BlinkMacSystemFont, sans-serif',
            heading: 'bold 24px -apple-system, BlinkMacSystemFont, sans-serif',
            body: '18px -apple-system, BlinkMacSystemFont, sans-serif',
            score: 'bold 72px -apple-system, BlinkMacSystemFont, sans-serif',
            small: '14px -apple-system, BlinkMacSystemFont, sans-serif'
        }
    };

    window.ShareCard = {
        canvas: null,
        ctx: null,

        /**
         * Initialize the share card generator
         */
        init: function() {
            this.canvas = document.createElement('canvas');
            this.canvas.width = CARD_CONFIG.WIDTH;
            this.canvas.height = CARD_CONFIG.HEIGHT;
            this.ctx = this.canvas.getContext('2d');
        },

        /**
         * Draw gradient background
         */
        drawBackground: function() {
            const { ctx, canvas } = this;
            const gradient = ctx.createLinearGradient(0, 0, canvas.width, canvas.height);
            gradient.addColorStop(0, CARD_CONFIG.COLORS.background);
            gradient.addColorStop(0.5, CARD_CONFIG.COLORS.backgroundGradient1);
            gradient.addColorStop(1, CARD_CONFIG.COLORS.backgroundGradient2);

            ctx.fillStyle = gradient;
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        },

        /**
         * Draw rounded rectangle
         */
        drawRoundedRect: function(x, y, width, height, radius) {
            const { ctx } = this;
            ctx.beginPath();
            ctx.moveTo(x + radius, y);
            ctx.lineTo(x + width - radius, y);
            ctx.quadraticCurveTo(x + width, y, x + width, y + radius);
            ctx.lineTo(x + width, y + height - radius);
            ctx.quadraticCurveTo(x + width, y + height, x + width - radius, y + height);
            ctx.lineTo(x + radius, y + height);
            ctx.quadraticCurveTo(x, y + height, x, y + height - radius);
            ctx.lineTo(x, y + radius);
            ctx.quadraticCurveTo(x, y, x + radius, y);
            ctx.closePath();
        },

        /**
         * Draw glass card
         */
        drawGlassCard: function(x, y, width, height, radius = 16) {
            const { ctx } = this;
            this.drawRoundedRect(x, y, width, height, radius);
            ctx.fillStyle = CARD_CONFIG.COLORS.glass;
            ctx.fill();
            ctx.strokeStyle = 'rgba(255, 255, 255, 0.1)';
            ctx.lineWidth = 1;
            ctx.stroke();
        },

        /**
         * Draw text with gradient
         */
        drawGradientText: function(text, x, y, font) {
            const { ctx, canvas } = this;
            ctx.font = font;
            const gradient = ctx.createLinearGradient(x, y - 20, x + ctx.measureText(text).width, y);
            gradient.addColorStop(0, CARD_CONFIG.COLORS.primary);
            gradient.addColorStop(1, CARD_CONFIG.COLORS.secondary);
            ctx.fillStyle = gradient;
            ctx.fillText(text, x, y);
        },

        /**
         * Draw trophy icon
         */
        drawTrophy: function(x, y, size) {
            const { ctx } = this;

            // Trophy cup
            ctx.fillStyle = '#ffd700';
            ctx.beginPath();
            ctx.moveTo(x - size/2, y - size/3);
            ctx.lineTo(x - size/3, y + size/3);
            ctx.lineTo(x + size/3, y + size/3);
            ctx.lineTo(x + size/2, y - size/3);
            ctx.closePath();
            ctx.fill();

            // Trophy base
            ctx.fillRect(x - size/4, y + size/3, size/2, size/6);
            ctx.fillRect(x - size/3, y + size/3 + size/6, size/1.5, size/8);

            // Handles
            ctx.strokeStyle = '#ffd700';
            ctx.lineWidth = size/10;
            ctx.beginPath();
            ctx.arc(x - size/2, y, size/4, -Math.PI/2, Math.PI/2);
            ctx.stroke();
            ctx.beginPath();
            ctx.arc(x + size/2, y, size/4, Math.PI/2, -Math.PI/2);
            ctx.stroke();
        },

        /**
         * Generate result card
         */
        generateResultCard: function(matchData) {
            if (!this.canvas) this.init();

            const { ctx, canvas } = this;
            const { WIDTH, HEIGHT, PADDING, COLORS, FONTS } = CARD_CONFIG;

            // Clear and draw background
            this.drawBackground();

            let currentY = PADDING;

            // Title
            ctx.textAlign = 'center';
            this.drawGradientText('30 SECONDS PARTY', WIDTH / 2, currentY + 36, FONTS.title);
            currentY += 60;

            // Date
            ctx.font = FONTS.small;
            ctx.fillStyle = COLORS.textMuted;
            const date = new Date(matchData.completedAt || Date.now()).toLocaleDateString('en-US', {
                month: 'long',
                day: 'numeric',
                year: 'numeric'
            });
            ctx.fillText(date, WIDTH / 2, currentY);
            currentY += 40;

            // Trophy
            this.drawTrophy(WIDTH / 2, currentY + 30, 60);
            currentY += 80;

            // Winner label
            ctx.font = FONTS.subtitle;
            ctx.fillStyle = COLORS.textMuted;
            ctx.fillText('WINNER', WIDTH / 2, currentY);
            currentY += 35;

            // Winner name
            const winners = matchData.winners || [matchData.teams[0].name];
            this.drawGradientText(winners.join(' & '), WIDTH / 2, currentY, FONTS.heading);
            currentY += 50;

            // Winning score
            const maxScore = Math.max(...matchData.teams.map(t => t.score));
            ctx.font = FONTS.score;
            ctx.fillStyle = COLORS.success;
            ctx.fillText(maxScore.toString(), WIDTH / 2, currentY);
            currentY += 20;

            ctx.font = FONTS.small;
            ctx.fillStyle = COLORS.textMuted;
            ctx.fillText('POINTS', WIDTH / 2, currentY);
            currentY += 50;

            // All team scores card
            this.drawGlassCard(PADDING, currentY, WIDTH - PADDING * 2, matchData.teams.length * 50 + 40);
            currentY += 30;

            ctx.textAlign = 'left';
            matchData.teams
                .sort((a, b) => b.score - a.score)
                .forEach((team, index) => {
                    // Rank badge
                    ctx.beginPath();
                    ctx.arc(PADDING + 30, currentY + 15, 15, 0, Math.PI * 2);
                    ctx.fillStyle = index === 0 ? '#ffd700' : index === 1 ? '#c0c0c0' : index === 2 ? '#cd7f32' : COLORS.glass;
                    ctx.fill();

                    ctx.font = FONTS.body;
                    ctx.fillStyle = index < 3 ? '#000' : COLORS.text;
                    ctx.textAlign = 'center';
                    ctx.fillText((index + 1).toString(), PADDING + 30, currentY + 21);

                    // Team name
                    ctx.textAlign = 'left';
                    ctx.fillStyle = COLORS.text;
                    ctx.fillText(team.name, PADDING + 60, currentY + 21);

                    // Score
                    ctx.textAlign = 'right';
                    ctx.fillStyle = team.score === maxScore ? COLORS.success : COLORS.textMuted;
                    ctx.fillText(team.score.toString(), WIDTH - PADDING - 20, currentY + 21);

                    currentY += 50;
                });

            currentY += 20;

            // MVP card if available
            if (matchData.mvp) {
                ctx.textAlign = 'center';
                this.drawGlassCard(PADDING, currentY, WIDTH - PADDING * 2, 80);

                ctx.font = FONTS.small;
                ctx.fillStyle = COLORS.warning;
                ctx.fillText('⭐ MVP - BEST EXPLAINER', WIDTH / 2, currentY + 30);

                ctx.font = FONTS.body;
                ctx.fillStyle = COLORS.text;
                ctx.fillText(`${matchData.mvp.name} (${matchData.mvp.correct} correct)`, WIDTH / 2, currentY + 55);

                currentY += 100;
            }

            // Stats
            ctx.textAlign = 'center';
            ctx.font = FONTS.small;
            ctx.fillStyle = COLORS.textMuted;
            ctx.fillText(
                `${matchData.turnHistory?.length || 0} turns played`,
                WIDTH / 2,
                HEIGHT - PADDING
            );

            // Watermark
            ctx.font = 'bold 12px -apple-system, sans-serif';
            ctx.fillStyle = 'rgba(255, 255, 255, 0.3)';
            ctx.fillText('relatives.app', WIDTH / 2, HEIGHT - PADDING + 25);

            return this.canvas;
        },

        /**
         * Generate turn summary card
         */
        generateTurnCard: function(turnData, teamName) {
            if (!this.canvas) this.init();

            const { ctx, canvas } = this;
            const { WIDTH, HEIGHT, PADDING, COLORS, FONTS } = CARD_CONFIG;

            // Use smaller height for turn card
            canvas.height = 500;
            this.drawBackground();

            let currentY = PADDING;

            // Title
            ctx.textAlign = 'center';
            this.drawGradientText('TURN COMPLETE', WIDTH / 2, currentY + 30, FONTS.heading);
            currentY += 60;

            // Team name
            ctx.font = FONTS.subtitle;
            ctx.fillStyle = COLORS.textMuted;
            ctx.fillText(teamName, WIDTH / 2, currentY);
            currentY += 50;

            // Score
            const correct = turnData.items.filter(i => i.status === 'correct').length;
            ctx.font = FONTS.score;
            ctx.fillStyle = COLORS.success;
            ctx.fillText(`+${correct}`, WIDTH / 2, currentY);
            currentY += 20;

            ctx.font = FONTS.small;
            ctx.fillStyle = COLORS.textMuted;
            ctx.fillText('POINTS THIS TURN', WIDTH / 2, currentY);
            currentY += 50;

            // Items summary
            this.drawGlassCard(PADDING, currentY, WIDTH - PADDING * 2, 200);
            currentY += 25;

            turnData.items.forEach((item, index) => {
                ctx.textAlign = 'left';

                // Status indicator
                if (item.status === 'correct') {
                    ctx.fillStyle = COLORS.success;
                    ctx.fillText('✓', PADDING + 20, currentY + 15);
                } else if (item.status === 'struck') {
                    ctx.fillStyle = '#ef4444';
                    ctx.fillText('✗', PADDING + 20, currentY + 15);
                } else {
                    ctx.fillStyle = COLORS.textMuted;
                    ctx.fillText('○', PADDING + 20, currentY + 15);
                }

                // Item text
                ctx.font = FONTS.body;
                ctx.fillStyle = item.status === 'struck' ? COLORS.textMuted : COLORS.text;
                ctx.fillText(item.text, PADDING + 50, currentY + 15);

                currentY += 35;
            });

            // Reset canvas height
            canvas.height = HEIGHT;

            return this.canvas;
        },

        /**
         * Convert canvas to blob
         */
        toBlob: function() {
            return new Promise(resolve => {
                this.canvas.toBlob(resolve, 'image/png');
            });
        },

        /**
         * Convert canvas to data URL
         */
        toDataURL: function() {
            return this.canvas.toDataURL('image/png');
        },

        /**
         * Download the card
         */
        download: function(filename = 'result.png') {
            const link = document.createElement('a');
            link.download = filename;
            link.href = this.toDataURL();
            link.click();
        },

        /**
         * Share the card (using Web Share API if available)
         */
        async share: function(title = '30 Seconds Party Results') {
            if (navigator.share && navigator.canShare) {
                try {
                    const blob = await this.toBlob();
                    const file = new File([blob], 'result.png', { type: 'image/png' });

                    if (navigator.canShare({ files: [file] })) {
                        await navigator.share({
                            title: title,
                            text: 'Check out our 30 Seconds Party game results!',
                            files: [file]
                        });
                        return true;
                    }
                } catch (e) {
                    console.warn('Share failed:', e);
                }
            }

            // Fallback to download
            this.download('30-seconds-result.png');
            return false;
        }
    };
})();
