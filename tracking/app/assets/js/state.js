/**
 * Tracking App - Global State Manager
 *
 * Provides a centralized, observable state store for the tracking application.
 * Supports event-based listeners so modules can react to state changes without
 * tight coupling.
 *
 * Usage:
 *   Tracking.setState('members', [...]);
 *   Tracking.getState('members');
 *   Tracking.onStateChange('members', (newValue, oldValue) => { ... });
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    /**
     * Internal state object. Every key listed here is considered a valid
     * state property. Additional keys can be set at runtime.
     */
    var state = {
        members: [],
        selectedMember: null,
        geofences: [],
        places: [],
        settings: {},
        sessionActive: false,
        mapReady: false,
        isNative: false,
        consentGiven: false,
    };

    /**
     * Map of state key -> array of listener callbacks.
     * Each callback receives (newValue, oldValue).
     * @type {Object.<string, Function[]>}
     */
    var listeners = {};

    /**
     * Set a value in the global state and notify listeners.
     *
     * @param {string} key   - The state property name.
     * @param {*}      value - The new value.
     */
    function setState(key, value) {
        var oldValue = state[key];
        state[key] = value;

        if (listeners[key]) {
            for (var i = 0; i < listeners[key].length; i++) {
                try {
                    listeners[key][i](value, oldValue);
                } catch (err) {
                    console.error('[State] Listener error for "' + key + '":', err);
                }
            }
        }
    }

    /**
     * Retrieve a value from the global state.
     *
     * @param {string} key - The state property name.
     * @returns {*} The current value, or undefined if the key does not exist.
     */
    function getState(key) {
        return state[key];
    }

    /**
     * Register a callback that fires whenever a given state key changes.
     *
     * @param {string}   key      - The state property to observe.
     * @param {Function} callback - Called with (newValue, oldValue).
     * @returns {Function} An unsubscribe function that removes this listener.
     */
    function onStateChange(key, callback) {
        if (typeof callback !== 'function') {
            throw new TypeError('[State] onStateChange callback must be a function');
        }

        if (!listeners[key]) {
            listeners[key] = [];
        }
        listeners[key].push(callback);

        // Return an unsubscribe function for easy cleanup.
        return function unsubscribe() {
            var idx = listeners[key].indexOf(callback);
            if (idx !== -1) {
                listeners[key].splice(idx, 1);
            }
        };
    }

    // Expose on the Tracking namespace.
    Tracking.state = state;
    Tracking.setState = setState;
    Tracking.getState = getState;
    Tracking.onStateChange = onStateChange;
})();
