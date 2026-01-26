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

        // Initialize in order
        Toast.init();
        TrackingMap.init();
        FamilyPanel.init();
        UIControls.init();
        Follow.init();
        Directions.init();
        Polling.init();
        BrowserTracking.init();

        console.log('Tracking app initialized');
    });
})();
