/**
 * Bootstrap - Initialize tracking app
 */

(function() {
    'use strict';

    // Check config
    if (!window.TRACKING_CONFIG) {
        console.error('TRACKING_CONFIG not defined');
        return;
    }

    if (!window.TRACKING_CONFIG.mapboxToken) {
        console.error('Mapbox token not configured');
        document.getElementById('loading-overlay').innerHTML = `
            <div style="text-align: center; padding: 20px;">
                <h2>Configuration Error</h2>
                <p>Mapbox API key not configured. Please contact support.</p>
            </div>
        `;
        return;
    }

    // Initialize modules
    document.addEventListener('DOMContentLoaded', () => {
        console.log('Initializing tracking app...');

        // Initialize native bridge first (sets up platform detection)
        if (window.NativeBridge) {
            NativeBridge.init();
            console.log(`Platform: ${NativeBridge.platform}, Native: ${NativeBridge.isNativeApp}`);
        }

        // Initialize in order
        Toast.init();
        TrackingMap.init();
        FamilyPanel.init();
        UIControls.init();
        Follow.init();
        Directions.init();
        Polling.init();
        BrowserTracking.init();

        // Setup offline indicator
        setupOfflineIndicator();

        console.log('Tracking app initialized');
    });

    /**
     * Setup offline/online status indicator
     */
    function setupOfflineIndicator() {
        // Create offline indicator element
        const indicator = document.createElement('div');
        indicator.className = 'offline-indicator';
        indicator.innerHTML = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M1 1l22 22M16.72 11.06A10.94 10.94 0 0 1 19 12.55M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/>
                <path d="M10.71 5.05A16 16 0 0 1 22.58 9M1.42 9a15.91 15.91 0 0 1 4.7-2.88M8.53 16.11a6 6 0 0 1 6.95 0M12 20h.01"/>
            </svg>
            <span>You're offline</span>
        `;
        document.body.appendChild(indicator);

        function updateOnlineStatus() {
            if (navigator.onLine) {
                indicator.classList.remove('visible');
            } else {
                indicator.classList.add('visible');
            }
        }

        window.addEventListener('online', updateOnlineStatus);
        window.addEventListener('offline', updateOnlineStatus);
        updateOnlineStatus();
    }
})();
