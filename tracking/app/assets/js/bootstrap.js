/**
 * Tracking App - Bootstrap / Initialisation
 *
 * Orchestrates the startup sequence for the entire tracking application.
 * This file must be loaded LAST, after all other Tracking.* modules.
 *
 * Startup sequence:
 *   1. Check for tracking consent (show dialog if not given).
 *   2. Check notification permission (ask if not granted).
 *   3. Load settings from the API.
 *   4. Initialise the Mapbox map.
 *   5. Initialise the family panel.
 *   6. Detect native Android bridge.
 *   7. Start location polling.
 *   8. Start browser-based location tracking (if supported and not native).
 *   9. Set up visibility-change handler for adaptive polling.
 *  10. Initialise UI controls.
 *  11. Wire up the wake button.
 *
 * Usage (in HTML):
 *   <script src="state.js"></script>
 *   <script src="format.js"></script>
 *   ...all other modules...
 *   <script src="bootstrap.js"></script>
 *   <!-- DOMContentLoaded triggers Tracking.init() automatically -->
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    /**
     * Main initialisation function.
     * Runs the full startup sequence in order.
     */
    function init() {
        console.info('[Bootstrap] Starting tracking app...');

        // 1. Consent
        Tracking.uiControls.showConsentDialog()
            .then(function (consented) {
                if (!consented) {
                    console.warn('[Bootstrap] User declined tracking consent.');
                    // Continue loading the UI but skip location submission.
                }

                // 2. Notification permission (fire-and-forget, non-blocking).
                Tracking.uiControls.requestNotificationPermission()
                    .then(function (result) {
                        console.info('[Bootstrap] Notification permission:', result);
                    })
                    .catch(function () { /* swallow */ });

                // 3. Load settings
                return Tracking.api.getSettings();
            })
            .then(function (res) {
                var settings = (res && res.data) || {};
                Tracking.setState('settings', settings);
                console.info('[Bootstrap] Settings loaded.');

                // Check session status for Mode 1.
                return Tracking.api.getSessionStatus().catch(function () {
                    return { data: { active: false } };
                });
            })
            .then(function (res) {
                var active = !!(res && res.data && res.data.active);
                Tracking.setState('sessionActive', active);

                // 4. Initialise map
                Tracking.map.init('map');
                console.info('[Bootstrap] Map initialised.');

                // 5. Initialise family panel
                Tracking.familyPanel.init('family-panel');
                console.info('[Bootstrap] Family panel initialised.');

                // 6. Detect native bridge
                Tracking.nativeBridge.detect();

                // 7. Start polling
                Tracking.polling.start();
                console.info('[Bootstrap] Polling started.');

                // 8. Browser tracking (only if supported and not inside native app)
                if (!Tracking.nativeBridge.isNative() &&
                    Tracking.browserTracking.isSupported() &&
                    Tracking.getState('consentGiven')) {
                    Tracking.browserTracking.start();
                    console.info('[Bootstrap] Browser tracking started.');
                }

                // 9. Visibility-change handler for native bridge hints
                document.addEventListener('visibilitychange', function () {
                    if (Tracking.nativeBridge.isNative()) {
                        if (document.visibilityState === 'visible') {
                            Tracking.nativeBridge.onScreenVisible();
                        } else {
                            Tracking.nativeBridge.onScreenHidden();
                        }
                    }
                });

                // 10. Initialise UI controls (wake button, settings toggle, etc.)
                Tracking.uiControls.init();
                console.info('[Bootstrap] UI controls initialised.');

                // 11. Load geofences and draw them on the map
                Tracking.api.getGeofences()
                    .then(function (geoRes) {
                        var geofences = (geoRes && geoRes.data) || [];
                        Tracking.setState('geofences', geofences);

                        // Wait for map to be ready before drawing.
                        if (Tracking.getState('mapReady')) {
                            Tracking.map.drawAllGeofences(geofences);
                        } else {
                            Tracking.onStateChange('mapReady', function (ready) {
                                if (ready) {
                                    Tracking.map.drawAllGeofences(
                                        Tracking.getState('geofences') || []
                                    );
                                }
                            });
                        }
                    })
                    .catch(function (err) {
                        console.warn('[Bootstrap] Could not load geofences:', err);
                    });

                console.info('[Bootstrap] Initialisation complete.');
            })
            .catch(function (err) {
                console.error('[Bootstrap] Initialisation failed:', err);
            });
    }

    // Expose on the namespace.
    Tracking.init = init;

    // Auto-start on DOMContentLoaded.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // DOM already ready (script loaded with defer or at end of body).
        init();
    }
})();
