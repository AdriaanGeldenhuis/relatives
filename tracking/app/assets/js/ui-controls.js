/**
 * Tracking App - UI Controls & Buttons
 *
 * Wires up interactive UI elements: wake button, consent dialog, notification
 * and microphone permissions, settings panel toggle, and dark mode toggle.
 *
 * Requires: Tracking.api, Tracking.nativeBridge, Tracking.map,
 *           Tracking.getState, Tracking.setState
 *
 * Usage:
 *   Tracking.uiControls.init();
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    var CONSENT_KEY = 'tracking_consent_given';

    // -----------------------------------------------------------------------
    // Initialisation
    // -----------------------------------------------------------------------

    /**
     * Set up all UI event listeners. Should be called once after the DOM is
     * ready and other modules are initialised.
     */
    function init() {
        bindWakeButton();
        bindSettingsToggle();
        bindDarkModeToggle();
    }

    // -----------------------------------------------------------------------
    // Wake button
    // -----------------------------------------------------------------------

    /**
     * Attach handler to the wake button (id="btn-wake").
     * Uses the native bridge when available, otherwise falls back to the API.
     */
    function bindWakeButton() {
        var btn = document.getElementById('btn-wake');
        if (!btn) return;

        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = 'Waking...';

            var promise;
            if (Tracking.nativeBridge && Tracking.nativeBridge.isNative()) {
                Tracking.nativeBridge.wakeAllDevices();
                promise = Promise.resolve();
            } else {
                promise = Tracking.api.wakeDevices();
            }

            promise
                .then(function () {
                    btn.textContent = 'Sent!';
                    setTimeout(function () {
                        btn.textContent = 'Wake Devices';
                        btn.disabled = false;
                    }, 2000);
                })
                .catch(function (err) {
                    console.error('[UIControls] Wake failed:', err);
                    btn.textContent = 'Wake Devices';
                    btn.disabled = false;
                });
        });
    }

    // -----------------------------------------------------------------------
    // Consent dialog
    // -----------------------------------------------------------------------

    /**
     * Check whether the user has previously given tracking consent.
     *
     * @returns {boolean}
     */
    function hasConsent() {
        try {
            return localStorage.getItem(CONSENT_KEY) === 'true';
        } catch (e) {
            return false;
        }
    }

    /**
     * Show the tracking consent dialog if consent has not yet been given.
     * Returns a Promise that resolves to true when consent is granted, or
     * false if declined.
     *
     * Looks for a dialog element with id="consent-dialog" in the DOM. If the
     * element does not exist, a simple one is created dynamically.
     *
     * @returns {Promise<boolean>}
     */
    function showConsentDialog() {
        if (hasConsent()) {
            Tracking.setState('consentGiven', true);
            return Promise.resolve(true);
        }

        return new Promise(function (resolve) {
            var dialog = document.getElementById('consent-dialog');

            // Build a minimal dialog if none exists in the markup.
            if (!dialog) {
                dialog = document.createElement('div');
                dialog.id = 'consent-dialog';
                dialog.style.cssText =
                    'position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;' +
                    'align-items:center;justify-content:center;z-index:9999;';
                dialog.innerHTML =
                    '<div style="background:#fff;border-radius:12px;padding:24px;max-width:400px;' +
                    'margin:16px;text-align:center;">' +
                        '<h3 style="margin:0 0 12px;">Location Tracking Consent</h3>' +
                        '<p style="color:#555;font-size:14px;margin:0 0 20px;">' +
                            'This app tracks your location to share it with your family members. ' +
                            'Your location data is only visible to your family group.' +
                        '</p>' +
                        '<div style="display:flex;gap:10px;justify-content:center;">' +
                            '<button id="consent-decline" style="padding:8px 20px;border:1px solid #d1d5db;' +
                            'border-radius:8px;background:#fff;cursor:pointer;">Decline</button>' +
                            '<button id="consent-accept" style="padding:8px 20px;border:none;' +
                            'border-radius:8px;background:#3b82f6;color:#fff;cursor:pointer;">Allow</button>' +
                        '</div>' +
                    '</div>';
                document.body.appendChild(dialog);
            } else {
                dialog.style.display = 'flex';
            }

            var accept = document.getElementById('consent-accept');
            var decline = document.getElementById('consent-decline');

            function cleanup() {
                if (accept) accept.removeEventListener('click', onAccept);
                if (decline) decline.removeEventListener('click', onDecline);
                dialog.style.display = 'none';
            }

            function onAccept() {
                try { localStorage.setItem(CONSENT_KEY, 'true'); } catch (e) { /* noop */ }
                Tracking.setState('consentGiven', true);
                cleanup();
                resolve(true);
            }

            function onDecline() {
                Tracking.setState('consentGiven', false);
                cleanup();
                resolve(false);
            }

            if (accept) accept.addEventListener('click', onAccept);
            if (decline) decline.addEventListener('click', onDecline);
        });
    }

    // -----------------------------------------------------------------------
    // Notification permission
    // -----------------------------------------------------------------------

    /**
     * Request notification permission from the browser if not already granted.
     *
     * @returns {Promise<string>} The permission state ('granted', 'denied',
     *          'default', or 'unsupported').
     */
    function requestNotificationPermission() {
        if (!('Notification' in window)) {
            return Promise.resolve('unsupported');
        }
        if (Notification.permission === 'granted') {
            return Promise.resolve('granted');
        }
        if (Notification.permission === 'denied') {
            return Promise.resolve('denied');
        }
        return Notification.requestPermission().then(function (result) {
            return result;
        });
    }

    // -----------------------------------------------------------------------
    // Microphone permission
    // -----------------------------------------------------------------------

    /**
     * Request microphone access (for voice assistant in footer).
     *
     * @returns {Promise<boolean>} True if granted, false otherwise.
     */
    function requestMicrophonePermission() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.warn('[UIControls] getUserMedia not supported.');
            return Promise.resolve(false);
        }

        return navigator.mediaDevices.getUserMedia({ audio: true })
            .then(function (stream) {
                // Immediately stop the stream; we only needed the permission.
                stream.getTracks().forEach(function (track) { track.stop(); });
                return true;
            })
            .catch(function (err) {
                console.warn('[UIControls] Microphone permission denied:', err);
                return false;
            });
    }

    // -----------------------------------------------------------------------
    // Settings panel toggle
    // -----------------------------------------------------------------------

    /**
     * Toggle visibility of the settings panel (id="settings-panel").
     */
    function bindSettingsToggle() {
        var btn = document.getElementById('btn-settings');
        var panel = document.getElementById('settings-panel');
        if (!btn || !panel) return;

        btn.addEventListener('click', function () {
            var isOpen = panel.style.display !== 'none' && panel.style.display !== '';
            panel.style.display = isOpen ? 'none' : 'block';
        });
    }

    // -----------------------------------------------------------------------
    // Dark mode toggle
    // -----------------------------------------------------------------------

    /**
     * Toggle dark mode map style and body class.
     */
    function bindDarkModeToggle() {
        var btn = document.getElementById('btn-dark-mode');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var settings = Tracking.getState('settings') || {};
            var isDark = settings.map_style === 'dark';
            var newStyle = isDark ? 'streets' : 'dark';

            Tracking.setState('settings', Object.assign({}, settings, { map_style: newStyle }));

            // Update the Mapbox style.
            var styleUrl = newStyle === 'dark'
                ? 'mapbox://styles/mapbox/dark-v11'
                : 'mapbox://styles/mapbox/streets-v12';

            if (Tracking.map && Tracking.map.setStyle) {
                Tracking.map.setStyle(styleUrl);
            }

            // Toggle body class for non-map UI elements.
            document.body.classList.toggle('dark-mode', newStyle === 'dark');
        });
    }

    // -----------------------------------------------------------------------
    // Public interface
    // -----------------------------------------------------------------------

    Tracking.uiControls = {
        init: init,
        showConsentDialog: showConsentDialog,
        hasConsent: hasConsent,
        requestNotificationPermission: requestNotificationPermission,
        requestMicrophonePermission: requestMicrophonePermission,
    };
})();
