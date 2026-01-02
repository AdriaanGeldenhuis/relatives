/**
 * RELATIVES - LEGAL DOCUMENTS JAVASCRIPT
 * Simple particle system and smooth scroll
 */

console.log('⚖️ Legal Documents JS loading...');

// ============================================
// PARTICLE SYSTEM (COPY FROM HOME)
// ============================================

const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

class ParticleSystem {
    constructor(canvasId) {
        this.canvas = document.getElementById(canvasId);
        if (!this.canvas) return;
        
        this.ctx = this.canvas.getContext('2d');
        this.particles = [];
        this.particleCount = isMobile ? 50 : 100;
        
        this.resize();
        this.init();
        this.animate();
        
        window.addEventListener('resize', () => this.resize());
    }
    
    resize() {
        this.canvas.width = window.innerWidth;
        this.canvas.height = window.innerHeight;
    }
    
    init() {
        this.particles = [];
        const colors = [
            'rgba(102, 126, 234, ',
            'rgba(118, 75, 162, ',
            'rgba(240, 147, 251, '
        ];
        
        for (let i = 0; i < this.particleCount; i++) {
            const baseColor = colors[Math.floor(Math.random() * colors.length)];
            this.particles.push({
                x: Math.random() * this.canvas.width,
                y: Math.random() * this.canvas.height,
                size: Math.random() * 2 + 1,
                speedX: (Math.random() - 0.5) * 0.5,
                speedY: (Math.random() - 0.5) * 0.5,
                baseColor: baseColor,
                opacity: Math.random() * 0.5 + 0.3
            });
        }
    }
    
    animate() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        
        this.particles.forEach(particle => {
            particle.x += particle.speedX;
            particle.y += particle.speedY;
            
            if (particle.x > this.canvas.width || particle.x < 0) {
                particle.speedX *= -1;
            }
            if (particle.y > this.canvas.height || particle.y < 0) {
                particle.speedY *= -1;
            }
            
            this.ctx.beginPath();
            this.ctx.arc(particle.x, particle.y, particle.size, 0, Math.PI * 2);
            
            const gradient = this.ctx.createRadialGradient(
                particle.x, particle.y, 0,
                particle.x, particle.y, particle.size * 3
            );
            gradient.addColorStop(0, particle.baseColor + particle.opacity + ')');
            gradient.addColorStop(1, particle.baseColor + '0)');
            
            this.ctx.fillStyle = gradient;
            this.ctx.fill();
        });
        
        requestAnimationFrame(() => this.animate());
    }
}

// ============================================
// SMOOTH SCROLL
// ============================================

document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        if (href === '#') return;
        
        e.preventDefault();
        const target = document.querySelector(href);
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    console.log('⚖️ Initializing Legal Documents...');
    
    if (!isMobile) {
        new ParticleSystem('particles');
    }
    
    // Smooth scroll to top on tab change
    window.scrollTo({ top: 0, behavior: 'smooth' });
    
    console.log('✅ Legal Documents initialized');
});

console.log('✅ Legal Documents JS loaded');