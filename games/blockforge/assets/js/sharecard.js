/* =============================================
   BLOCKFORGE - Share Result Card Generator
   ============================================= */

var BlockShareCard = (function() {
    'use strict';

    function generate(result) {
        var canvas = document.getElementById('share-canvas');
        var ctx = canvas.getContext('2d');
        var w = canvas.width;
        var h = canvas.height;

        // Background gradient
        var bgGrad = ctx.createLinearGradient(0, 0, w, h);
        bgGrad.addColorStop(0, '#0a0a2a');
        bgGrad.addColorStop(0.5, '#0f0f3a');
        bgGrad.addColorStop(1, '#0a0a1a');
        ctx.fillStyle = bgGrad;
        ctx.fillRect(0, 0, w, h);

        // Decorative grid lines
        ctx.strokeStyle = 'rgba(0, 245, 255, 0.05)';
        ctx.lineWidth = 1;
        for (var i = 0; i < w; i += 30) {
            ctx.beginPath();
            ctx.moveTo(i, 0);
            ctx.lineTo(i, h);
            ctx.stroke();
        }
        for (var j = 0; j < h; j += 30) {
            ctx.beginPath();
            ctx.moveTo(0, j);
            ctx.lineTo(w, j);
            ctx.stroke();
        }

        // Decorative blocks
        var colors = ['#00f5ff', '#ff00ff', '#b44aff', '#00ff88', '#ffee00', '#4a6aff', '#ff6600'];
        for (var b = 0; b < 15; b++) {
            var bx = (b * 97 + 20) % (w - 40);
            var by = (b * 137 + 50) % (h - 40);
            var bs = 15 + (b * 7) % 25;
            ctx.globalAlpha = 0.08;
            ctx.fillStyle = colors[b % colors.length];
            ctx.shadowColor = colors[b % colors.length];
            ctx.shadowBlur = bs;
            ctx.beginPath();
            ctx.roundRect(bx, by, bs, bs, 4);
            ctx.fill();
        }
        ctx.globalAlpha = 1;
        ctx.shadowBlur = 0;

        // Title
        ctx.font = 'bold 42px Orbitron, monospace';
        ctx.textAlign = 'center';

        var titleGrad = ctx.createLinearGradient(w / 2 - 100, 80, w / 2 + 100, 80);
        titleGrad.addColorStop(0, '#00f5ff');
        titleGrad.addColorStop(0.5, '#ff00ff');
        titleGrad.addColorStop(1, '#b44aff');
        ctx.fillStyle = titleGrad;
        ctx.shadowColor = 'rgba(0, 245, 255, 0.5)';
        ctx.shadowBlur = 15;
        ctx.fillText('BLOCKFORGE', w / 2, 90);
        ctx.shadowBlur = 0;

        // Mode label
        ctx.font = '600 16px Inter, sans-serif';
        ctx.fillStyle = 'rgba(255, 255, 255, 0.5)';
        var modeText = result.mode === 'daily' ? 'DAILY CHALLENGE' :
                       result.mode === 'family' ? 'FAMILY BOARD' : 'SOLO ENDLESS';
        ctx.fillText(modeText, w / 2, 125);

        // Score (big)
        ctx.font = 'bold 72px Orbitron, monospace';
        var scoreGrad = ctx.createLinearGradient(w / 2 - 120, 220, w / 2 + 120, 220);
        scoreGrad.addColorStop(0, '#00f5ff');
        scoreGrad.addColorStop(1, '#00ff88');
        ctx.fillStyle = scoreGrad;
        ctx.shadowColor = 'rgba(0, 245, 255, 0.4)';
        ctx.shadowBlur = 20;
        ctx.fillText(formatNumber(result.score), w / 2, 230);
        ctx.shadowBlur = 0;

        ctx.font = '600 14px Inter, sans-serif';
        ctx.fillStyle = 'rgba(255, 255, 255, 0.4)';
        ctx.fillText('FINAL SCORE', w / 2, 260);

        // Stats card
        var cardY = 300;
        var cardH = 200;
        ctx.fillStyle = 'rgba(255, 255, 255, 0.04)';
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.1)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.roundRect(60, cardY, w - 120, cardH, 16);
        ctx.fill();
        ctx.stroke();

        // Stats rows
        var stats = [
            ['Lines Cleared', result.lines],
            ['Level Reached', result.level],
            ['Max Combo', result.maxCombo + 'x'],
            ['Duration', formatDuration(result.duration)]
        ];

        ctx.textAlign = 'left';
        stats.forEach(function(stat, idx) {
            var sy = cardY + 45 + idx * 42;
            ctx.font = '600 16px Inter, sans-serif';
            ctx.fillStyle = 'rgba(255, 255, 255, 0.6)';
            ctx.fillText(stat[0], 90, sy);

            ctx.textAlign = 'right';
            ctx.font = 'bold 20px Orbitron, monospace';
            ctx.fillStyle = '#00f5ff';
            ctx.fillText(stat[1].toString(), w - 90, sy);
            ctx.textAlign = 'left';
        });

        // Badges
        var badges = getBadges(result);
        if (badges.length > 0) {
            var badgeY = cardY + cardH + 40;
            ctx.textAlign = 'center';
            ctx.font = '600 12px Inter, sans-serif';
            ctx.fillStyle = 'rgba(255, 238, 0, 0.8)';

            var badgeText = badges.slice(0, 4).join('  |  ');
            ctx.fillText(badgeText, w / 2, badgeY);
        }

        // Date
        ctx.textAlign = 'center';
        ctx.font = '500 13px Inter, sans-serif';
        ctx.fillStyle = 'rgba(255, 255, 255, 0.3)';
        var dateStr = new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        ctx.fillText(dateStr, w / 2, h - 60);

        // Footer
        ctx.font = '600 12px Inter, sans-serif';
        ctx.fillStyle = 'rgba(255, 255, 255, 0.2)';
        ctx.fillText('Can you beat my score?', w / 2, h - 30);

        return canvas;
    }

    function save() {
        var canvas = document.getElementById('share-canvas');
        try {
            canvas.toBlob(function(blob) {
                if (!blob) return;

                // Try native share API first
                if (navigator.share && navigator.canShare) {
                    var file = new File([blob], 'blockforge-result.png', { type: 'image/png' });
                    var shareData = { files: [file], title: 'BlockForge Result' };
                    if (navigator.canShare(shareData)) {
                        navigator.share(shareData).catch(function() {
                            downloadBlob(blob);
                        });
                        return;
                    }
                }

                // Fallback: download
                downloadBlob(blob);
            }, 'image/png');
        } catch(e) {
            // Canvas tainted or other error
            alert('Could not generate share image.');
        }
    }

    function downloadBlob(blob) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'blockforge-result.png';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function() { URL.revokeObjectURL(url); }, 1000);
    }

    function getBadges(result) {
        var badges = [];
        if (result.score >= 10000) badges.push('10K Club');
        if (result.score >= 50000) badges.push('50K Legend');
        if (result.lines >= 40) badges.push('Line Master');
        if (result.maxCombo >= 5) badges.push('Combo King');
        if (result.level >= 10) badges.push('Level 10+');
        return badges;
    }

    function formatNumber(n) {
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function formatDuration(ms) {
        var s = Math.floor(ms / 1000);
        var m = Math.floor(s / 60);
        s = s % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    return {
        generate: generate,
        save: save
    };
})();
