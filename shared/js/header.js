/**
 * ============================================
 * GLOBAL HEADER JAVASCRIPT v3.4
 * Added user profile dropdown menu
 * ============================================
 */

(function() {
    'use strict';

    console.log('🔧 Header JavaScript v3.4 initializing...');

    // Detect native Android app WebView
    const isNativeApp = navigator.userAgent.includes('RelativesAndroidApp');

    let isMenuOpen = false;
    let elements = {};
    let notificationCheckInterval = null;

    // Polling interval for web browsers (60 seconds)
    const WEB_POLLING_INTERVAL = 60000;
    
    function cacheElements() {
        elements = {
            hamburger: document.getElementById('hamburgerMenuBtn'),
            closeBtn: document.getElementById('closeSidebarBtn'),
            overlay: document.getElementById('mobileMenuOverlay'),
            sidebar: document.getElementById('mobileSidebar'),
            loader: document.getElementById('appLoader'),
            notificationBell: document.getElementById('notificationBell'),
            notificationBadge: document.getElementById('notificationBadge'),
            userProfileToggle: document.getElementById('userProfileToggle'),
            userProfileDropdown: document.getElementById('userProfileDropdown')
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

    // User Profile Dropdown Toggle
    function toggleUserProfileDropdown(e) {
        if (!elements.userProfileToggle) return;

        // Don't toggle if clicking on a link inside the dropdown
        if (e && e.target.closest('.profile-dropdown-item')) {
            return;
        }

        e && e.stopPropagation();
        elements.userProfileToggle.classList.toggle('active');
    }

    function closeUserProfileDropdown() {
        if (elements.userProfileToggle) {
            elements.userProfileToggle.classList.remove('active');
        }
    }

    function hideLoader() {
        if (elements.loader) {
            elements.loader.classList.add('hidden');
            console.log('✅ App loader hidden');

            // Cancel fallback timer since we handled it
            if (window._loaderFallbackTimer) {
                clearTimeout(window._loaderFallbackTimer);
                window._loaderFallbackTimer = null;
            }
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
    
    // Start notification polling (for web browsers only)
    function startNotificationPolling() {
        // Don't start polling for native app
        if (isNativeApp) {
            console.log('📱 Native app detected - skipping setInterval polling');
            return;
        }

        // Initial fetch
        fetchNotificationCount();

        // Poll at reduced interval (60 seconds) for web
        notificationCheckInterval = setInterval(() => {
            fetchNotificationCount();
        }, WEB_POLLING_INTERVAL);

        console.log('✅ Notification polling started (web mode, ' + WEB_POLLING_INTERVAL + 'ms interval)');
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
            if (e.key === 'Escape') {
                closeUserProfileDropdown();
            }
        });
        console.log('✅ Escape key listener attached');

        // User Profile Dropdown Toggle
        if (elements.userProfileToggle) {
            elements.userProfileToggle.addEventListener('click', toggleUserProfileDropdown);
            console.log('✅ User profile dropdown toggle attached');

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (elements.userProfileToggle &&
                    !elements.userProfileToggle.contains(e.target)) {
                    closeUserProfileDropdown();
                }
            });
        }

        // Setup visibility/focus handling based on app mode
        if (isNativeApp) {
            // Native app: fetch on focus/visibility/pageshow only (no polling)
            bindFocusVisibilityRefresh();
        } else {
            // Web browser: pause/resume polling on visibility
            bindVisibilityPauseResume();
        }
    }

    // Native app: refresh notification count on user-visible events only
    function bindFocusVisibilityRefresh() {
        // When page becomes visible
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                console.log('📱 Native: visibility change - refreshing notifications');
                fetchNotificationCount();
            }
        });

        // When window gains focus
        window.addEventListener('focus', () => {
            console.log('📱 Native: focus event - refreshing notifications');
            fetchNotificationCount();
        });

        // When page is shown (e.g., back/forward navigation)
        window.addEventListener('pageshow', (e) => {
            console.log('📱 Native: pageshow event - refreshing notifications');
            fetchNotificationCount();
        });

        console.log('✅ Native app visibility handlers attached');
    }

    // Web browser: stop polling when hidden, resume when visible
    function bindVisibilityPauseResume() {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopNotificationPolling();
            } else {
                startNotificationPolling();
            }
        });

        console.log('✅ Web visibility pause/resume handlers attached');
    }
    
    function init() {
        console.log('🚀 Starting header initialization...');
        console.log('📱 Native app mode:', isNativeApp);
        cacheElements();
        attachEvents();
        setTimeout(hideLoader, 500);

        // Initialize notifications if user is logged in
        if (document.body.hasAttribute('data-logged-in')) {
            if (isNativeApp) {
                // Native app: just do initial fetch, no polling
                fetchNotificationCount();
                console.log('✅ Native app: initial notification fetch (no polling)');
            } else {
                // Web browser: start polling with visibility pause/resume
                startNotificationPolling();
            }
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
        refreshNotifications: fetchNotificationCount,
        toggleProfileDropdown: toggleUserProfileDropdown,
        closeProfileDropdown: closeUserProfileDropdown
    };
    
})();

console.log('✅ Header JavaScript loaded');