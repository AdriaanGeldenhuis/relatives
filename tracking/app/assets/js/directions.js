/**
 * Tracking App - Directions Display
 *
 * Fetches driving/walking/cycling directions between two family members
 * (or arbitrary points) and draws the route on the map. Displays distance
 * and estimated duration in an info bar.
 *
 * Requires: Tracking.api, Tracking.map, Tracking.format, Tracking.getState
 *
 * Usage:
 *   Tracking.directions.showRoute(memberId1, memberId2, 'driving');
 *   Tracking.directions.clearRoute();
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    /** @type {HTMLElement|null} Cached info bar element. */
    var infoBar = null;

    // -----------------------------------------------------------------------
    // Info bar
    // -----------------------------------------------------------------------

    /**
     * Get or create the info bar element that displays route distance and
     * duration.
     *
     * @returns {HTMLElement}
     */
    function getInfoBar() {
        if (infoBar) return infoBar;

        infoBar = document.getElementById('directions-info');
        if (!infoBar) {
            infoBar = document.createElement('div');
            infoBar.id = 'directions-info';
            infoBar.style.cssText =
                'display:none;position:fixed;bottom:60px;left:50%;transform:translateX(-50%);' +
                'background:#fff;border-radius:10px;padding:10px 20px;box-shadow:0 2px 12px rgba(0,0,0,0.15);' +
                'z-index:1000;font-size:14px;text-align:center;max-width:90%;';
            document.body.appendChild(infoBar);
        }
        return infoBar;
    }

    /**
     * Display route information in the info bar.
     *
     * @param {number} distanceMeters
     * @param {number} durationSeconds
     */
    function showInfo(distanceMeters, durationSeconds) {
        var bar = getInfoBar();
        var fmt = Tracking.format || {};

        var distText = fmt.distance ? fmt.distance(distanceMeters) : Math.round(distanceMeters) + ' m';
        var durText = formatDuration(durationSeconds);

        bar.innerHTML =
            '<strong>' + escapeHtml(distText) + '</strong>' +
            ' &middot; ' +
            '<span>' + escapeHtml(durText) + '</span>' +
            '<button id="directions-close" style="margin-left:14px;background:none;border:none;' +
            'cursor:pointer;font-size:16px;vertical-align:middle;" title="Close">&times;</button>';
        bar.style.display = 'block';

        var closeBtn = document.getElementById('directions-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                clearRoute();
            });
        }
    }

    /**
     * Hide the info bar.
     */
    function hideInfo() {
        var bar = getInfoBar();
        bar.style.display = 'none';
        bar.innerHTML = '';
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Format a duration in seconds to a human-readable string.
     *
     * @param {number} seconds
     * @returns {string} e.g. "1 h 23 min", "45 min", "< 1 min"
     */
    function formatDuration(seconds) {
        if (!seconds || seconds < 60) return '< 1 min';
        var mins = Math.round(seconds / 60);
        if (mins < 60) return mins + ' min';
        var hours = Math.floor(mins / 60);
        var remainMins = mins % 60;
        return hours + ' h' + (remainMins > 0 ? ' ' + remainMins + ' min' : '');
    }

    /**
     * Escape HTML for safe insertion.
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    /**
     * Look up a member's current position by id.
     *
     * @param {number|string} memberId
     * @returns {{ lat: number, lng: number }|null}
     */
    function getMemberPosition(memberId) {
        var members = Tracking.getState('members') || [];
        for (var i = 0; i < members.length; i++) {
            if (String(members[i].id) === String(memberId)) {
                var m = members[i];
                if (m.latitude != null && m.longitude != null) {
                    return { lat: parseFloat(m.latitude), lng: parseFloat(m.longitude) };
                }
            }
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // Route display
    // -----------------------------------------------------------------------

    /**
     * Fetch and display directions between two family members.
     *
     * @param {number|string} fromMemberId
     * @param {number|string} toMemberId
     * @param {string} [profile='driving'] - 'driving' | 'walking' | 'cycling'
     * @returns {Promise<void>}
     */
    function showRoute(fromMemberId, toMemberId, profile) {
        var from = getMemberPosition(fromMemberId);
        var to = getMemberPosition(toMemberId);

        if (!from || !to) {
            console.warn('[Directions] Could not resolve member positions.');
            return Promise.reject(new Error('Member position not found'));
        }

        return showRouteToPoint(from.lat, from.lng, to.lat, to.lng, profile);
    }

    /**
     * Fetch and display directions between two arbitrary points.
     *
     * @param {number} fromLat
     * @param {number} fromLng
     * @param {number} toLat
     * @param {number} toLng
     * @param {string} [profile='driving']
     * @returns {Promise<void>}
     */
    function showRouteToPoint(fromLat, fromLng, toLat, toLng, profile) {
        var from = { lat: fromLat, lng: fromLng };
        var to = { lat: toLat, lng: toLng };

        return Tracking.api.getDirections(from, to, profile || 'driving')
            .then(function (res) {
                var data = res.data || res;
                var geometry = data.geometry || data.route;

                if (geometry && Tracking.map) {
                    Tracking.map.drawRoute(geometry);
                }

                var distance = data.distance; // metres
                var duration = data.duration; // seconds
                if (distance != null && duration != null) {
                    showInfo(distance, duration);
                }
            })
            .catch(function (err) {
                console.error('[Directions] Failed to get route:', err);
                throw err;
            });
    }

    /**
     * Remove the route from the map and hide the info bar.
     */
    function clearRoute() {
        if (Tracking.map) {
            Tracking.map.clearRoute();
        }
        hideInfo();
    }

    // -----------------------------------------------------------------------
    // Public interface
    // -----------------------------------------------------------------------

    Tracking.directions = {
        showRoute: showRoute,
        showRouteToPoint: showRouteToPoint,
        clearRoute: clearRoute,
    };
})();
