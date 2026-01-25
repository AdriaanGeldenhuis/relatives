/**
 * NEON NIBBLER - Local Storage & Offline Queue
 */
var NeonStorage = (function() {
    'use strict';

    var SCORES_KEY = 'neon_nibbler_scores';
    var QUEUE_KEY = 'neon_nibbler_queue';
    var PREFS_KEY = 'neon_nibbler_prefs';
    var BEST_KEY = 'neon_nibbler_best';

    function getPrefs() {
        try {
            var raw = localStorage.getItem(PREFS_KEY);
            return raw ? JSON.parse(raw) : { sound: true, dpad: true, theme: 'dark' };
        } catch (e) {
            return { sound: true, dpad: true, theme: 'dark' };
        }
    }

    function savePrefs(prefs) {
        try {
            localStorage.setItem(PREFS_KEY, JSON.stringify(prefs));
        } catch (e) {}
    }

    function getBest() {
        try {
            var raw = localStorage.getItem(BEST_KEY);
            return raw ? JSON.parse(raw) : { score: 0, level: 0 };
        } catch (e) {
            return { score: 0, level: 0 };
        }
    }

    function saveBest(score, level) {
        var current = getBest();
        var updated = false;
        if (score > current.score) {
            current.score = score;
            updated = true;
        }
        if (level > current.level) {
            current.level = level;
            updated = true;
        }
        if (updated) {
            try {
                localStorage.setItem(BEST_KEY, JSON.stringify(current));
            } catch (e) {}
        }
        return updated;
    }

    function getQueue() {
        try {
            var raw = localStorage.getItem(QUEUE_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch (e) {
            return [];
        }
    }

    function addToQueue(scoreData) {
        var queue = getQueue();
        queue.push(scoreData);
        try {
            localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
        } catch (e) {}
    }

    function clearQueue() {
        try {
            localStorage.removeItem(QUEUE_KEY);
        } catch (e) {}
    }

    function removeFromQueue(index) {
        var queue = getQueue();
        queue.splice(index, 1);
        try {
            localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
        } catch (e) {}
    }

    function getDeviceId() {
        var key = 'neon_nibbler_device';
        var id = localStorage.getItem(key);
        if (!id) {
            id = 'dev_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            try { localStorage.setItem(key, id); } catch (e) {}
        }
        return id;
    }

    return {
        getPrefs: getPrefs,
        savePrefs: savePrefs,
        getBest: getBest,
        saveBest: saveBest,
        getQueue: getQueue,
        addToQueue: addToQueue,
        clearQueue: clearQueue,
        removeFromQueue: removeFromQueue,
        getDeviceId: getDeviceId
    };
})();
