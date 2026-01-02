/**
 * ============================================
 * GLOBAL HEADER JAVASCRIPT v3.2
 * Added notification badge updates
 * ============================================
 */

(function() {
    'use strict';
    
    console.log('🔧 Header JavaScript v3.2 initializing...');
    
    let isMenuOpen = false;
    let elements = {};
    let notificationCheckInterval = null;
    
    function cacheElements() {
        elements = {
            hamburger: document.getElementById('hamburgerMenuBtn'),
            closeBtn: document.getElementById('closeSidebarBtn'),
            overlay: document.getElementById('mobileMenuOverlay'),
            sidebar: document.getElementById('mobileSidebar'),
            loader: document.getElementById('appLoader'),
            notificationBell: document.getElementById('notificationBell'),
            notificationBadge: document.getElementById('notificationBadge')
        };
        
        const missing = [];
        if (!elements.hamburger) missing.push('hamburgerMenuBtn');
        if (!elements.closeBtn) missing.push('closeSidebarBtn');
        if (!elements.overlay) missing.push('mobileMenuOverlay');
        if (!elements.sidebar) missing.push('mobileSidebar');
        
        if (missing.length > 0) {
            console.warn('⚠️ Missing elements:', missing.join(', '));
        }
        
        return missing.length === 0;
    }
    
    function toggleMenu() {
        if (!elements.overlay || !elements.sidebar) {
            console.error('❌ Cannot toggle menu - elements missing');
            return;
        }
        
        isMenuOpen = !isMenuOpen;
        
        if (isMenuOpen) {
            elements.overlay.classList.add('active');
            elements.sidebar.classList.add('active');
            document.body.style.overflow = 'hidden';
        } else {
            elements.overlay.classList.remove('active');
            elements.sidebar.classList.remove('active');
            document.body.style.overflow = '';
        }
    }
    
    function closeMenu() {
        if (isMenuOpen) {
            toggleMenu();
        }
    }
    
    function hideLoader() {
        if (elements.loader) {
            elements.loader.classList.add('hidden');
            console.log('✅ App loader hidden');
        }
    }
    
    // Fetch unread notification count
    async function fetchNotificationCount() {
        try {
            const response = await fetch('/notifications/api/count.php', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error('Failed to fetch notification count');
            }
            
            const data = await response.json();
            updateNotificationBadge(data.count || 0);
            
        } catch (error) {
            console.error('Error fetching notification count:', error);
        }
    }
    
    // Update notification badge
    function updateNotificationBadge(count) {
        const bell = document.querySelector('.notification-bell');
        const badge = elements.notificationBadge;
        
        if (count > 0) {
            // Show badge
            if (badge) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'flex';
            } else {
                // Create badge if it doesn't exist
                const newBadge = document.createElement('span');
                newBadge.className = 'notification-badge';
                newBadge.id = 'notificationBadge';
                newBadge.textContent = count > 99 ? '99+' : count;
                bell.appendChild(newBadge);
                elements.notificationBadge = newBadge;
            }
            
            // Add animation class
            if (bell) {
                bell.classList.add('has-notifications');
            }
            
        } else {
            // Hide badge
            if (badge) {
                badge.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => {
                    badge.style.display = 'none';
                }, 300);
            }
            
            // Remove animation class
            if (bell) {
                bell.classList.remove('has-notifications');
            }
        }
        
        window.unreadNotificationCount = count;
    }
    
    // Start notification polling (every 30 seconds)
    function startNotificationPolling() {
        // Initial fetch
        fetchNotificationCount();
        
        // Poll every 30 seconds
        notificationCheckInterval = setInterval(() => {
            fetchNotificationCount();
        }, 30000);
        
        console.log('✅ Notification polling started');
    }
    
    // Stop notification polling
    function stopNotificationPolling() {
        if (notificationCheckInterval) {
            clearInterval(notificationCheckInterval);
            notificationCheckInterval = null;
            console.log('⏹️ Notification polling stopped');
        }
    }
    
    function attachEvents() {
        if (elements.hamburger) {
            elements.hamburger.addEventListener('click', toggleMenu);
            console.log('✅ Hamburger button attached');
        }
        
        if (elements.closeBtn) {
            elements.closeBtn.addEventListener('click', toggleMenu);
            console.log('✅ Close button attached');
        }
        
        if (elements.overlay) {
            elements.overlay.addEventListener('click', closeMenu);
            console.log('✅ Overlay attached');
        }
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && isMenuOpen) {
                closeMenu();
            }
        });
        console.log('✅ Escape key listener attached');
        
        // Listen for visibility changes to pause/resume polling
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopNotificationPolling();
            } else {
                startNotificationPolling();
            }
        });
    }
    
    function init() {
        console.log('🚀 Starting header initialization...');
        cacheElements();
        attachEvents();
        setTimeout(hideLoader, 500);
        
        // Start notification polling if user is logged in
        if (document.body.hasAttribute('data-logged-in')) {
            startNotificationPolling();
        }
        
        console.log('✅ Header JavaScript initialized');
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    window.HeaderMenu = {
        toggle: toggleMenu,
        close: closeMenu,
        open: () => { if (!isMenuOpen) toggleMenu(); },
        updateNotificationBadge: updateNotificationBadge,
        refreshNotifications: fetchNotificationCount
    };
    
})();

console.log('✅ Header JavaScript loaded');