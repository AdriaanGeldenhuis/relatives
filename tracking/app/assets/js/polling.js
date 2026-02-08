/**
 * Tracking App — Polling & Cache Refresh
 *
 * On native Android: reads cached family locations from TrackingBridge
 * (which reads from TrackingStore). No API call needed — the FamilyPoller
 * on the native side handles server communication.
 *
 * On browser: falls back to API polling.
 *
 * Either way, updates are pushed into state and the MapboxController.
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    var ACTIVE_INTERVAL = 10000;  // 10s when visible
    var HIDDEN_INTERVAL = 30000;  // 30s when hidden

    var pollTimer = null;
    var currentInterval = ACTIVE_INTERVAL;
    var running = false;

    /**
     * Single poll cycle: get data from cache or API, update state + map.
     */
    function poll() {
        var members;

        // Prefer native cache (instant, no network)
        if (Tracking.nativeBridge && Tracking.nativeBridge.isNative()) {
            members = Tracking.nativeBridge.getCachedFamily();
            applyMembers(members);
            return;
        }

        // Fallback: API poll (browser mode)
        if (Tracking.api && Tracking.api.getCurrentLocations) {
            Tracking.api.getCurrentLocations()
                .then(function (res) {
                    applyMembers((res && res.data) || []);
                })
                .catch(function (err) {
                    console.error('[Polling] API fetch failed:', err);
                });
        }
    }

    function applyMembers(members) {
        if (!members || !members.length) return;
        Tracking.setState('members', members);
        if (Tracking.map) {
            Tracking.map.updateMembers(members);
        }
    }

    function scheduleNext() {
        if (!running) return;
        pollTimer = setTimeout(function () {
            poll();
            scheduleNext();
        }, currentInterval);
    }

    function start(intervalMs) {
        if (running) return;
        running = true;
        if (intervalMs) {
            ACTIVE_INTERVAL = intervalMs;
            currentInterval = isPageVisible() ? ACTIVE_INTERVAL : HIDDEN_INTERVAL;
        }
        poll();
        scheduleNext();
        document.addEventListener('visibilitychange', onVisibilityChange);
    }

    function stop() {
        running = false;
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        document.removeEventListener('visibilitychange', onVisibilityChange);
    }

    function isPageVisible() {
        return document.visibilityState !== 'hidden';
    }

    function onVisibilityChange() {
        if (!running) return;
        if (isPageVisible()) {
            currentInterval = ACTIVE_INTERVAL;
            poll();
        } else {
            currentInterval = HIDDEN_INTERVAL;
        }
        if (pollTimer) clearTimeout(pollTimer);
        scheduleNext();
    }

    Tracking.polling = {
        start: start,
        stop: stop,
        poll: poll,
    };
})();
