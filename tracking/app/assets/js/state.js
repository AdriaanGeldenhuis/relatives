/**
 * Tracking App — Global State Manager
 *
 * Centralized observable state store. Modules write state;
 * other modules subscribe to changes. Zero coupling between producers
 * and consumers.
 */
window.Tracking = window.Tracking || {};

(function () {
    'use strict';

    var state = {
        members: [],
        mapReady: false,
        isNative: false,
        trackingEnabled: false,
    };

    var listeners = {};

    function setState(key, value) {
        var old = state[key];
        state[key] = value;
        if (listeners[key]) {
            for (var i = 0; i < listeners[key].length; i++) {
                try {
                    listeners[key][i](value, old);
                } catch (err) {
                    console.error('[State] Listener error for "' + key + '":', err);
                }
            }
        }
    }

    function getState(key) {
        return state[key];
    }

    function onStateChange(key, callback) {
        if (!listeners[key]) listeners[key] = [];
        listeners[key].push(callback);
        return function unsubscribe() {
            var idx = listeners[key].indexOf(callback);
            if (idx !== -1) listeners[key].splice(idx, 1);
        };
    }

    Tracking.state = state;
    Tracking.setState = setState;
    Tracking.getState = getState;
    Tracking.onStateChange = onStateChange;
})();
