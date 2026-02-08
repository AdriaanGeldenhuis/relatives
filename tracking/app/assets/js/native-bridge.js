/**
 * Tracking App — Native Android Bridge
 *
 * Safe wrapper around `window.TrackingBridge` (injected by Android WebView).
 * Every method checks for the bridge before invoking, so callers never
 * need to guard against missing native functionality.
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    function isNative() {
        return !!(window.TrackingBridge);
    }

    function invoke(method) {
        if (!window.TrackingBridge || typeof window.TrackingBridge[method] !== 'function') {
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

    /** Run native detection and update global state. */
    function detect() {
        Tracking.setState('isNative', isNative());
    }

    /** Start tracking (goes through PermissionGate on native side). */
    function startTracking() {
        invoke('startTracking');
    }

    /** Stop tracking service. */
    function stopTracking() {
        invoke('stopTracking');
    }

    /**
     * Get cached family locations from TrackingStore.
     * Returns parsed JSON array or empty array.
     */
    function getCachedFamily() {
        var raw = invoke('getCachedFamily');
        if (!raw) return [];
        try {
            return JSON.parse(raw);
        } catch (e) {
            return [];
        }
    }

    /** Get tracking mode: "enabled" / "disabled" / "no_permission". */
    function getTrackingMode() {
        return invoke('getTrackingMode');
    }

    /** Check if tracking is currently enabled and permitted. */
    function isTrackingEnabled() {
        return invoke('isTrackingEnabled') === true;
    }

    /** Send wake push to all family devices. */
    function wakeAllDevices() {
        invoke('wakeAllDevices');
    }

    /** Notify native that tracking screen is visible (boost polling). */
    function onScreenVisible() {
        invoke('onTrackingScreenVisible');
    }

    /** Notify native that tracking screen is hidden (reduce polling). */
    function onScreenHidden() {
        invoke('onTrackingScreenHidden');
    }

    Tracking.nativeBridge = {
        detect: detect,
        isNative: isNative,
        startTracking: startTracking,
        stopTracking: stopTracking,
        getCachedFamily: getCachedFamily,
        getTrackingMode: getTrackingMode,
        isTrackingEnabled: isTrackingEnabled,
        wakeAllDevices: wakeAllDevices,
        onScreenVisible: onScreenVisible,
        onScreenHidden: onScreenHidden,
    };
})();
