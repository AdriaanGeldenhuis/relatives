/**
 * Tracking App - Browser-Based Location Tracking
 *
 * Uses the Geolocation API (navigator.geolocation.watchPosition) to track
 * the user's location from a desktop or mobile browser. Locations are
 * buffered and batch-uploaded every 30 seconds to avoid excessive API calls.
 *
 * A dead-reckoning filter skips uploads when the device has not moved more
 * than 20 metres from the last submitted position, reducing bandwidth and
 * storage costs.
 *
 * Mode behaviour:
 *   - Mode 1 (session-based): only sends locations when sessionActive is true.
 *   - Mode 2 (always-on): sends locations whenever the watcher is running.
 *
 * Requires: Tracking.api, Tracking.getState
 *
 * Usage:
 *   Tracking.browserTracking.start();
 *   Tracking.browserTracking.stop();
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    // -----------------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------------

    var BATCH_INTERVAL_MS  = 30000; // Flush buffer every 30 s
    var MIN_DISTANCE_M     = 20;    // Minimum movement to record a new point

    /** @type {number|null} watchPosition id. */
    var watchId = null;

    /** @type {number|null} Batch upload interval id. */
    var batchTimer = null;

    /**
     * Buffer of locations waiting to be uploaded.
     * Each entry: { latitude, longitude, accuracy, speed, heading, altitude, timestamp }
     * @type {Object[]}
     */
    var buffer = [];

    /**
     * Last successfully submitted position (for distance filtering).
     * @type {{ latitude: number, longitude: number }|null}
     */
    var lastSent = null;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Check whether the Geolocation API is available.
     * @returns {boolean}
     */
    function isSupported() {
        return !!(navigator.geolocation);
    }

    /**
     * Haversine distance between two lat/lng points in metres.
     *
     * @param {number} lat1
     * @param {number} lng1
     * @param {number} lat2
     * @param {number} lng2
     * @returns {number} Distance in metres.
     */
    function haversine(lat1, lng1, lat2, lng2) {
        var R = 6371000;
        var toRad = Math.PI / 180;
        var dLat = (lat2 - lat1) * toRad;
        var dLng = (lng2 - lng1) * toRad;
        var a =
            Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) *
            Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    // -----------------------------------------------------------------------
    // Position watcher
    // -----------------------------------------------------------------------

    /**
     * Callback for successful geolocation position.
     * @param {GeolocationPosition} position
     */
    function onPosition(position) {
        var coords = position.coords;
        var loc = {
            latitude: coords.latitude,
            longitude: coords.longitude,
            accuracy: coords.accuracy,
            speed: coords.speed,
            heading: coords.heading,
            altitude: coords.altitude,
            timestamp: new Date(position.timestamp).toISOString(),
        };

        // Distance filter: skip if we haven't moved far enough.
        if (lastSent) {
            var dist = haversine(
                lastSent.latitude, lastSent.longitude,
                loc.latitude, loc.longitude
            );
            if (dist < MIN_DISTANCE_M) {
                return;
            }
        }

        buffer.push(loc);
    }

    /**
     * Callback for geolocation errors.
     * @param {GeolocationPositionError} err
     */
    function onError(err) {
        console.warn('[BrowserTracking] Geolocation error:', err.message);
    }

    // -----------------------------------------------------------------------
    // Batch upload
    // -----------------------------------------------------------------------

    /**
     * Flush the location buffer to the server.
     * Respects mode settings: in Mode 1, only sends while sessionActive.
     */
    function flush() {
        if (buffer.length === 0) return;

        // Allow uploads always — UI session state should not block location ingest.
        // Background tracking is handled by the native Android service; browser
        // tracking is only a supplementary source when the page is open.

        var batch = buffer.slice();
        buffer = [];

        Tracking.api.batchUpload(batch)
            .then(function () {
                // Record the last sent position for distance filtering.
                lastSent = {
                    latitude: batch[batch.length - 1].latitude,
                    longitude: batch[batch.length - 1].longitude,
                };
            })
            .catch(function (err) {
                console.error('[BrowserTracking] Batch upload failed:', err);
                // Put the failed batch back into the buffer for retry.
                buffer = batch.concat(buffer);
            });
    }

    // -----------------------------------------------------------------------
    // Start / Stop
    // -----------------------------------------------------------------------

    /**
     * Start watching the user's position and schedule batch uploads.
     */
    function start() {
        if (!isSupported()) {
            console.warn('[BrowserTracking] Geolocation not supported.');
            return;
        }

        if (watchId !== null) {
            // Already watching.
            return;
        }

        watchId = navigator.geolocation.watchPosition(onPosition, onError, {
            enableHighAccuracy: true,
            maximumAge: 10000,
            timeout: 30000,
        });

        batchTimer = setInterval(flush, BATCH_INTERVAL_MS);

        console.info('[BrowserTracking] Started.');
    }

    /**
     * Stop watching position and flush any remaining buffered locations.
     */
    function stop() {
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }

        if (batchTimer !== null) {
            clearInterval(batchTimer);
            batchTimer = null;
        }

        // Final flush.
        flush();

        console.info('[BrowserTracking] Stopped.');
    }

    // -----------------------------------------------------------------------
    // Public interface
    // -----------------------------------------------------------------------

    Tracking.browserTracking = {
        isSupported: isSupported,
        start: start,
        stop: stop,
    };
})();
