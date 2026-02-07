/**
 * Tracking App - REST API Client
 *
 * Provides a promise-based HTTP client for all tracking API endpoints.
 * Every method returns a Promise that resolves with the parsed JSON response
 * body or rejects with an Error.
 *
 * The base URL is `/tracking/api/`. All responses follow the shape:
 *   { success: true|false, data: ..., error: ... }
 *
 * Usage:
 *   Tracking.api.getCurrentLocations().then(res => { ... });
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    var BASE_URL = '/tracking/api/';

    // -----------------------------------------------------------------------
    // Generic HTTP helpers
    // -----------------------------------------------------------------------

    /**
     * Build a query-string from a plain object. Skips null/undefined values.
     *
     * @param {Object} params
     * @returns {string} e.g. "?foo=1&bar=hello" or "" if empty.
     */
    function buildQuery(params) {
        if (!params || typeof params !== 'object') {
            return '';
        }
        var parts = [];
        var keys = Object.keys(params);
        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            if (params[key] !== null && params[key] !== undefined) {
                parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key]));
            }
        }
        return parts.length ? '?' + parts.join('&') : '';
    }

    /**
     * Core request function. Handles JSON encoding, credentials, and
     * standardised error handling.
     *
     * @param {string} method   - HTTP method (GET, POST, PUT, DELETE).
     * @param {string} endpoint - Path relative to BASE_URL (no leading slash).
     * @param {Object} [options]
     * @param {Object} [options.params] - Query-string parameters.
     * @param {Object} [options.body]   - JSON body for POST/PUT.
     * @returns {Promise<Object>} Parsed JSON response.
     */
    function request(method, endpoint, options) {
        options = options || {};
        var url = BASE_URL + endpoint + buildQuery(options.params);

        var fetchOpts = {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
            },
        };

        // Attach CSRF token if present (Django / Laravel convention).
        var csrfToken = getCSRFToken();
        if (csrfToken) {
            fetchOpts.headers['X-CSRFToken'] = csrfToken;
        }

        if (options.body !== undefined && options.body !== null) {
            fetchOpts.headers['Content-Type'] = 'application/json';
            fetchOpts.body = JSON.stringify(options.body);
        }

        return fetch(url, fetchOpts)
            .then(function (response) {
                if (!response.ok) {
                    return response.json().catch(function () {
                        return { success: false, error: response.statusText };
                    }).then(function (body) {
                        var err = new Error(body.error || 'Request failed (' + response.status + ')');
                        err.status = response.status;
                        err.body = body;
                        throw err;
                    });
                }
                return response.json();
            })
            .then(function (body) {
                if (body && body.success === false) {
                    var err = new Error(body.error || 'API returned an error');
                    err.body = body;
                    throw err;
                }
                return body;
            });
    }

    /**
     * Read the CSRF token from a cookie (supports Django's csrftoken cookie).
     * @returns {string|null}
     */
    function getCSRFToken() {
        var match = document.cookie.match(/(?:^|;\s*)csrftoken=([^\s;]+)/);
        return match ? decodeURIComponent(match[1]) : null;
    }

    // -----------------------------------------------------------------------
    // Public convenience methods
    // -----------------------------------------------------------------------

    /**
     * HTTP GET.
     * @param {string} endpoint
     * @param {Object} [params] - Query-string parameters.
     * @returns {Promise<Object>}
     */
    function get(endpoint, params) {
        return request('GET', endpoint, { params: params });
    }

    /**
     * HTTP POST.
     * @param {string} endpoint
     * @param {Object} [data] - JSON body.
     * @returns {Promise<Object>}
     */
    function post(endpoint, data) {
        return request('POST', endpoint, { body: data });
    }

    /**
     * HTTP PUT.
     * @param {string} endpoint
     * @param {Object} [data] - JSON body.
     * @returns {Promise<Object>}
     */
    function put(endpoint, data) {
        return request('PUT', endpoint, { body: data });
    }

    /**
     * HTTP DELETE.
     * @param {string} endpoint
     * @param {Object} [params] - Query-string parameters.
     * @returns {Promise<Object>}
     */
    function del(endpoint, params) {
        return request('DELETE', endpoint, { params: params });
    }

    // -----------------------------------------------------------------------
    // Domain-specific API methods
    // -----------------------------------------------------------------------

    /** Check whether the user has an active tracking session. */
    function getSessionStatus() {
        return get('session/status');
    }

    /** Keep the current session alive (Mode 1). */
    function keepalive() {
        return post('session/keepalive');
    }

    /**
     * Submit a single location update.
     * @param {Object} loc - { latitude, longitude, accuracy, speed, heading, altitude, timestamp }
     */
    function submitLocation(loc) {
        return post('location/submit', loc);
    }

    /**
     * Upload a batch of buffered location points.
     * @param {Object[]} locs - Array of location objects.
     */
    function batchUpload(locs) {
        return post('location/batch', { locations: locs });
    }

    /** Get the latest location for every family member. */
    function getCurrentLocations() {
        return get('locations/current');
    }

    /**
     * Get location history for a specific user.
     * @param {number|string} userId
     * @param {string}        from - ISO-8601 start datetime.
     * @param {string}        to   - ISO-8601 end datetime.
     */
    function getHistory(userId, from, to) {
        return get('locations/history', { user_id: userId, from: from, to: to });
    }

    /** Retrieve current app settings. */
    function getSettings() {
        return get('settings');
    }

    /**
     * Save (update) app settings.
     * @param {Object} data - Key/value settings to persist.
     */
    function saveSettings(data) {
        return post('settings', data);
    }

    /** Get all geofences. */
    function getGeofences() {
        return get('geofences');
    }

    /**
     * Create a new geofence.
     * @param {Object} data - { name, type, center, radius, polygon, ... }
     */
    function addGeofence(data) {
        return post('geofences', data);
    }

    /**
     * Update an existing geofence.
     * @param {number|string} id
     * @param {Object} data
     */
    function updateGeofence(id, data) {
        return put('geofences/' + id, data);
    }

    /**
     * Delete a geofence.
     * @param {number|string} id
     */
    function deleteGeofence(id) {
        return del('geofences/' + id);
    }

    /** Get all saved places. */
    function getPlaces() {
        return get('places');
    }

    /**
     * Add a new place.
     * @param {Object} data - { name, latitude, longitude, ... }
     */
    function addPlace(data) {
        return post('places', data);
    }

    /**
     * Delete a saved place.
     * @param {number|string} id
     */
    function deletePlace(id) {
        return del('places/' + id);
    }

    /**
     * Retrieve recent events/alerts.
     * @param {number} [limit=50]
     * @param {number} [offset=0]
     * @param {string} [type] - Optional event type filter.
     */
    function getEvents(limit, offset, type) {
        return get('events', { limit: limit, offset: offset, type: type });
    }

    /** Get alert rules for the current family. */
    function getAlertRules() {
        return get('alerts/rules');
    }

    /**
     * Save alert rules.
     * @param {Object} data
     */
    function saveAlertRules(data) {
        return post('alerts/rules', data);
    }

    /**
     * Get driving/walking/cycling directions between two points.
     * @param {{ lat: number, lng: number }} from
     * @param {{ lat: number, lng: number }} to
     * @param {string} [profile='driving'] - 'driving' | 'walking' | 'cycling'
     */
    function getDirections(from, to, profile) {
        return get('directions', {
            from_lat: from.lat,
            from_lng: from.lng,
            to_lat: to.lat,
            to_lng: to.lng,
            profile: profile || 'driving',
        });
    }

    /** Wake all family member devices via push notification. */
    function wakeDevices() {
        return post('devices/wake');
    }

    // -----------------------------------------------------------------------
    // Public interface
    // -----------------------------------------------------------------------

    Tracking.api = {
        // Generic
        get: get,
        post: post,
        put: put,
        del: del,

        // Session
        getSessionStatus: getSessionStatus,
        keepalive: keepalive,

        // Location
        submitLocation: submitLocation,
        batchUpload: batchUpload,
        getCurrentLocations: getCurrentLocations,
        getHistory: getHistory,

        // Settings
        getSettings: getSettings,
        saveSettings: saveSettings,

        // Geofences
        getGeofences: getGeofences,
        addGeofence: addGeofence,
        updateGeofence: updateGeofence,
        deleteGeofence: deleteGeofence,

        // Places
        getPlaces: getPlaces,
        addPlace: addPlace,
        deletePlace: deletePlace,

        // Events & Alerts
        getEvents: getEvents,
        getAlertRules: getAlertRules,
        saveAlertRules: saveAlertRules,

        // Directions
        getDirections: getDirections,

        // Devices
        wakeDevices: wakeDevices,
    };
})();
