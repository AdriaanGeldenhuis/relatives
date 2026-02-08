/**
 * Tracking App — Bootstrap
 *
 * Startup sequence:
 *   1. Detect native bridge
 *   2. Initialize Mapbox map (NO permission needed)
 *   3. Show cached pins immediately (from TrackingStore via bridge)
 *   4. Start polling (native cache or API)
 *   5. Wire up tracking toggle (Enable/Disable live location)
 *   6. Wire up wake button
 *   7. Wire up visibility change for native bridge
 *
 * The map loads and shows pins WITHOUT any permission.
 * Permission is ONLY requested when user taps "Enable live location".
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    function init() {
        console.info('[Bootstrap] Starting tracking app...');

        // 1. Detect native bridge
        if (Tracking.nativeBridge) {
            Tracking.nativeBridge.detect();
        }

        // 2. Initialize map (no permission required)
        if (Tracking.map) {
            Tracking.map.init('map');
            console.info('[Bootstrap] Map initialized (no permission needed).');
        }

        // 3. Show cached pins immediately
        if (Tracking.nativeBridge && Tracking.nativeBridge.isNative()) {
            var cached = Tracking.nativeBridge.getCachedFamily();
            if (cached && cached.length > 0) {
                Tracking.setState('members', cached);
                if (Tracking.map) {
                    Tracking.map.updateMembers(cached);
                    Tracking.map.fitToMembers();
                }
                console.info('[Bootstrap] Rendered ' + cached.length + ' cached pins.');
            }
        }

        // 4. Start polling
        if (Tracking.polling) {
            Tracking.polling.start();
            console.info('[Bootstrap] Polling started.');
        }

        // 5. Update tracking toggle state
        updateTrackingUI();

        // 6. Wire up tracking toggle button
        var toggleBtn = document.getElementById('tracking-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function () {
                onTrackingToggle();
            });
        }

        // 7. Wire up wake button
        var wakeBtn = document.getElementById('wake-btn');
        if (wakeBtn) {
            wakeBtn.addEventListener('click', function () {
                if (Tracking.nativeBridge && Tracking.nativeBridge.isNative()) {
                    Tracking.nativeBridge.wakeAllDevices();
                    showToast('Wake signal sent to all devices');
                } else if (Tracking.api && Tracking.api.wakeDevices) {
                    Tracking.api.wakeDevices()
                        .then(function () { showToast('Wake signal sent'); })
                        .catch(function () { showToast('Failed to send wake signal'); });
                }
            });
        }

        // 8. Visibility change → notify native
        document.addEventListener('visibilitychange', function () {
            if (!Tracking.nativeBridge || !Tracking.nativeBridge.isNative()) return;
            if (document.visibilityState === 'visible') {
                Tracking.nativeBridge.onScreenVisible();
            } else {
                Tracking.nativeBridge.onScreenHidden();
            }
        });

        console.info('[Bootstrap] Initialization complete.');
    }

    // ── Tracking toggle ─────────────────────────────────────────────────

    function onTrackingToggle() {
        if (!Tracking.nativeBridge || !Tracking.nativeBridge.isNative()) {
            console.warn('[Bootstrap] Tracking toggle only works in native app.');
            return;
        }

        var mode = Tracking.nativeBridge.getTrackingMode();
        if (mode === 'enabled') {
            Tracking.nativeBridge.stopTracking();
            Tracking.setState('trackingEnabled', false);
        } else {
            // This triggers PermissionGate → disclosure → permission → start
            Tracking.nativeBridge.startTracking();
            Tracking.setState('trackingEnabled', true);
        }

        // Update UI after a short delay to let native side process
        setTimeout(updateTrackingUI, 500);
    }

    function updateTrackingUI() {
        var toggleBtn = document.getElementById('tracking-toggle');
        if (!toggleBtn) return;

        if (!Tracking.nativeBridge || !Tracking.nativeBridge.isNative()) {
            toggleBtn.textContent = 'Enable Live Location';
            toggleBtn.className = 'tracking-btn tracking-btn-start';
            return;
        }

        var mode = Tracking.nativeBridge.getTrackingMode();
        Tracking.setState('trackingEnabled', mode === 'enabled');

        if (mode === 'enabled') {
            toggleBtn.textContent = 'Disable Live Location';
            toggleBtn.className = 'tracking-btn tracking-btn-stop';
        } else {
            toggleBtn.textContent = 'Enable Live Location';
            toggleBtn.className = 'tracking-btn tracking-btn-start';
        }
    }

    // ── Toast helper ────────────────────────────────────────────────────

    function showToast(message) {
        var toast = document.getElementById('toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'toast';
            toast.style.cssText =
                'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);' +
                'background:rgba(0,0,0,0.8);color:#fff;padding:10px 20px;' +
                'border-radius:8px;font-size:14px;z-index:9999;' +
                'transition:opacity 0.3s;';
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.style.opacity = '1';
        setTimeout(function () {
            toast.style.opacity = '0';
        }, 2500);
    }

    // ── Expose & auto-start ─────────────────────────────────────────────

    Tracking.init = init;
    Tracking.updateTrackingUI = updateTrackingUI;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
