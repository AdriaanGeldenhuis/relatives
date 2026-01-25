/**
 * RELATIVES - LEGAL DOCUMENTS JAVASCRIPT
 * Simple particle system and smooth scroll
 */

console.log('⚖️ Legal Documents JS loading...');

// ============================================
// PARTICLE SYSTEM - DISABLED FOR PERFORMANCE
// Canvas hidden via CSS, class is a no-op stub
// ============================================

const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

class ParticleSystem {
    constructor(canvasId) {
        // Disabled - canvas hidden via CSS for performance
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