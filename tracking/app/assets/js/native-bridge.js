/**
 * Tracking App - Native Android Bridge
 *
 * Provides a safe interface to the Android WebView's TrackingBridge JS
 * interface. Every method checks for the presence of the native bridge
 * before invoking, so callers never need to guard against missing
 * native functionality themselves.
 *
 * Usage:
 *   if (Tracking.nativeBridge.isNative()) {
 *       Tracking.nativeBridge.startTracking();
 *   }
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    /**
     * Detect whether the page is running inside the native Android WebView.
     * The native app injects a `TrackingBridge` object on `window`.
     *
     * @returns {boolean}
     */
    function isNative() {
        return !!(window.TrackingBridge);
    }

    /**
     * Run initial detection and update global state.
     * Called once during app bootstrap.
     */
    function detect() {
        var native = isNative();
        Tracking.setState('isNative', native);

        if (native) {
            console.info('[NativeBridge] Running inside native Android WebView.');
        }
    }

    /**
     * Safely invoke a method on the native TrackingBridge.
     *
     * @param {string} method - Method name on TrackingBridge.
     * @param {...*}   args   - Arguments forwarded to the native method.
     * @returns {*} Return value from the native method, or undefined.
     */
    function invoke(method) {
        if (!window.TrackingBridge || typeof window.TrackingBridge[method] !== 'function') {
            console.warn('[NativeBridge] Method "' + method + '" is not available.');
            return undefined;
        }
        try {
            var args = Array.prototype.slice.call(arguments, 1);
            return window.TrackingBridge[method].apply(window.TrackingBridge, args);
        } catch (err) {
            console.error('[NativeBridge] Error calling "' + method + '":', err);
            return undefined;
        }
    }

    /**
     * Tell the native app to start location tracking.
     */
    function startTracking() {
        invoke('startTracking');
    }

    /**
     * Tell the native app to stop location tracking.
     */
    function stopTracking() {
        invoke('stopTracking');
    }

    /**
     * Ask the native app to send a wake push to all family devices.
     */
    function wakeAllDevices() {
        invoke('wakeAllDevices');
    }

    /**
     * Query the native app's current tracking mode.
     *
     * @returns {*} Mode value from native side (typically a number/string),
     *              or undefined if not available.
     */
    function getTrackingMode() {
        return invoke('getTrackingMode');
    }

    /**
     * Notify the native app that the WebView screen is now visible.
     * Useful for adjusting polling/tracking intensity.
     */
    function onScreenVisible() {
        invoke('onScreenVisible');
    }

    /**
     * Notify the native app that the WebView screen is now hidden.
     */
    function onScreenHidden() {
        invoke('onScreenHidden');
    }

    // -----------------------------------------------------------------------
    // Public interface
    // -----------------------------------------------------------------------

    Tracking.nativeBridge = {
        detect: detect,
        isNative: isNative,
        startTracking: startTracking,
        stopTracking: stopTracking,
        wakeAllDevices: wakeAllDevices,
        getTrackingMode: getTrackingMode,
        onScreenVisible: onScreenVisible,
        onScreenHidden: onScreenHidden,
    };
})();
