/**
 * Tracking App - Polling & Refresh Logic
 *
 * Periodically fetches the latest family member locations from the API and
 * pushes updates into state, markers, and the family panel.
 *
 * Supports adaptive polling: a shorter interval when the tab is visible and
 * a longer interval when hidden. Also handles keepalive pings for Mode 1
 * (session-based) tracking.
 *
 * Requires: Tracking.api, Tracking.setState, Tracking.getState,
 *           Tracking.map, Tracking.familyPanel
 *
 * Usage:
 *   Tracking.polling.start();   // default 10 s
 *   Tracking.polling.stop();
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    // -----------------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------------

    var ACTIVE_INTERVAL  = 10000; // 10 s when tab is visible
    var HIDDEN_INTERVAL  = 30000; // 30 s when tab is hidden

    /** @type {number|null} Location polling timer id. */
    var pollTimer = null;

    /** @type {number|null} Keepalive polling timer id. */
    var keepaliveTimer = null;

    /** Currently active polling interval in ms. */
    var currentInterval = ACTIVE_INTERVAL;

    /** Whether polling has been started. */
    var running = false;

    // -----------------------------------------------------------------------
    // Core polling
    // -----------------------------------------------------------------------

    /**
     * Execute a single poll cycle: fetch locations, update state & UI.
     */
    function poll() {
        Tracking.api.getCurrentLocations()
            .then(function (res) {
                var members = (res && res.data) || [];
                Tracking.setState('members', members);

                if (Tracking.map) {
                    Tracking.map.updateMemberMarkers(members);
                }
                if (Tracking.familyPanel) {
                    Tracking.familyPanel.render(members);
                }
            })
            .catch(function (err) {
                console.error('[Polling] Failed to fetch locations:', err);
            });
    }

    /**
     * Schedule the next poll cycle.
     */
    function scheduleNext() {
        if (!running) return;
        pollTimer = setTimeout(function () {
            poll();
            scheduleNext();
        }, currentInterval);
    }

    /**
     * Start the location polling loop.
     *
     * @param {number} [intervalMs] - Override the default active interval.
     */
    function start(intervalMs) {
        if (running) return;
        running = true;

        if (intervalMs && typeof intervalMs === 'number') {
            ACTIVE_INTERVAL = intervalMs;
            currentInterval = isPageVisible() ? ACTIVE_INTERVAL : HIDDEN_INTERVAL;
        }

        // Immediate first poll.
        poll();
        scheduleNext();
        startKeepalive();

        document.addEventListener('visibilitychange', onVisibilityChange);
    }

    /**
     * Stop all polling.
     */
    function stop() {
        running = false;
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        stopKeepalive();
        document.removeEventListener('visibilitychange', onVisibilityChange);
    }

    // -----------------------------------------------------------------------
    // Keepalive (Mode 1)
    // -----------------------------------------------------------------------

    /**
     * Start the keepalive ping based on settings.keepalive_interval.
     * Only active when sessionActive is true (Mode 1).
     */
    function startKeepalive() {
        stopKeepalive();

        var settings = Tracking.getState('settings') || {};
        var interval = (settings.keepalive_interval || 60) * 1000;

        keepaliveTimer = setInterval(function () {
            if (!Tracking.getState('sessionActive')) return;

            Tracking.api.keepalive().catch(function (err) {
                console.warn('[Polling] Keepalive failed:', err);
            });
        }, interval);
    }

    /**
     * Stop keepalive polling.
     */
    function stopKeepalive() {
        if (keepaliveTimer) {
            clearInterval(keepaliveTimer);
            keepaliveTimer = null;
        }
    }

    // -----------------------------------------------------------------------
    // Adaptive polling (visibility change)
    // -----------------------------------------------------------------------

    /**
     * @returns {boolean} Whether the document is currently visible.
     */
    function isPageVisible() {
        return document.visibilityState !== 'hidden';
    }

    /**
     * Adjust polling interval when the page visibility changes.
     */
    function onVisibilityChange() {
        if (!running) return;

        if (isPageVisible()) {
            currentInterval = ACTIVE_INTERVAL;
            // Fetch immediately on becoming visible.
            poll();
        } else {
            currentInterval = HIDDEN_INTERVAL;
        }

        // Restart the timer with the new interval.
        if (pollTimer) {
            clearTimeout(pollTimer);
        }
        scheduleNext();
    }

    // -----------------------------------------------------------------------
    // Public interface
    // -----------------------------------------------------------------------

    Tracking.polling = {
        start: start,
        stop: stop,
        poll: poll,
    };
})();
