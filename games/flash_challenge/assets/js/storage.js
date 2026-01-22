/**
 * ============================================
 * FLASH CHALLENGE - Storage Module
 * Local storage, caching, and offline queue
 * ============================================
 */

(function() {
    'use strict';

    const STORAGE_PREFIX = 'flash_';
    const CACHE_DURATION = 5 * 60 * 1000; // 5 minutes

    /**
     * FlashStorage - Handles all local storage operations
     */
    window.FlashStorage = {
        /**
         * Keys used for storage
         */
        KEYS: {
            DAILY_CHALLENGE: 'daily_challenge',
            ATTEMPTS_QUEUE: 'attempts_queue',
            USER_STATS: 'user_stats',
            THEME: 'theme',
            DEVICE_ID: 'device_id',
            LAST_SYNC: 'last_sync',
            LEADERBOARD_CACHE: 'leaderboard_cache',
            HISTORY_CACHE: 'history_cache'
        },

        /**
         * Get item from storage
         */
        get: function(key) {
            try {
                const data = localStorage.getItem(STORAGE_PREFIX + key);
                if (!data) return null;

                const parsed = JSON.parse(data);
                return parsed;
            } catch (e) {
                console.warn('FlashStorage.get error:', e);
                return null;
            }
        },

        /**
         * Set item in storage
         */
        set: function(key, value) {
            try {
                localStorage.setItem(STORAGE_PREFIX + key, JSON.stringify(value));
                return true;
            } catch (e) {
                console.warn('FlashStorage.set error:', e);
                return false;
            }
        },

        /**
         * Remove item from storage
         */
        remove: function(key) {
            try {
                localStorage.removeItem(STORAGE_PREFIX + key);
                return true;
            } catch (e) {
                return false;
            }
        },

        /**
         * Clear all flash challenge data
         */
        clearAll: function() {
            try {
                Object.keys(localStorage).forEach(key => {
                    if (key.startsWith(STORAGE_PREFIX)) {
                        localStorage.removeItem(key);
                    }
                });
                return true;
            } catch (e) {
                return false;
            }
        },

        /**
         * Get or generate device ID
         */
        getDeviceId: function() {
            let deviceId = this.get(this.KEYS.DEVICE_ID);

            if (!deviceId) {
                deviceId = 'fc_' + Date.now().toString(36) + '_' + Math.random().toString(36).substr(2, 9);
                this.set(this.KEYS.DEVICE_ID, deviceId);
            }

            return deviceId;
        },

        /**
         * Get/set theme preference
         */
        getTheme: function() {
            return this.get(this.KEYS.THEME) || 'auto';
        },

        setTheme: function(theme) {
            this.set(this.KEYS.THEME, theme);
        },

        /**
         * Cache daily challenge
         */
        cacheDailyChallenge: function(data) {
            this.set(this.KEYS.DAILY_CHALLENGE, {
                data: data,
                timestamp: Date.now()
            });
        },

        getCachedDailyChallenge: function() {
            const cached = this.get(this.KEYS.DAILY_CHALLENGE);
            if (!cached) return null;

            // Check if still valid (cache for 1 hour)
            if (Date.now() - cached.timestamp > 60 * 60 * 1000) {
                this.remove(this.KEYS.DAILY_CHALLENGE);
                return null;
            }

            // Check if same date
            const today = new Date().toISOString().split('T')[0];
            if (cached.data.challenge?.challenge_date !== today) {
                this.remove(this.KEYS.DAILY_CHALLENGE);
                return null;
            }

            return cached.data;
        },

        /**
         * Attempts Queue (for offline submissions)
         */
        getAttemptsQueue: function() {
            return this.get(this.KEYS.ATTEMPTS_QUEUE) || [];
        },

        addToAttemptsQueue: function(attempt) {
            const queue = this.getAttemptsQueue();
            attempt.queued_at = Date.now();
            attempt.id = 'q_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5);
            queue.push(attempt);
            this.set(this.KEYS.ATTEMPTS_QUEUE, queue);
            return attempt.id;
        },

        removeFromAttemptsQueue: function(attemptId) {
            const queue = this.getAttemptsQueue();
            const filtered = queue.filter(a => a.id !== attemptId);
            this.set(this.KEYS.ATTEMPTS_QUEUE, filtered);
        },

        clearAttemptsQueue: function() {
            this.set(this.KEYS.ATTEMPTS_QUEUE, []);
        },

        /**
         * Cache leaderboard data
         */
        cacheLeaderboard: function(data) {
            this.set(this.KEYS.LEADERBOARD_CACHE, {
                data: data,
                timestamp: Date.now()
            });
        },

        getCachedLeaderboard: function() {
            const cached = this.get(this.KEYS.LEADERBOARD_CACHE);
            if (!cached) return null;

            if (Date.now() - cached.timestamp > CACHE_DURATION) {
                return null;
            }

            return cached.data;
        },

        /**
         * Cache history data
         */
        cacheHistory: function(data) {
            this.set(this.KEYS.HISTORY_CACHE, {
                data: data,
                timestamp: Date.now()
            });
        },

        getCachedHistory: function() {
            const cached = this.get(this.KEYS.HISTORY_CACHE);
            if (!cached) return null;

            if (Date.now() - cached.timestamp > CACHE_DURATION) {
                return null;
            }

            return cached.data;
        },

        /**
         * Update last sync timestamp
         */
        setLastSync: function() {
            this.set(this.KEYS.LAST_SYNC, Date.now());
        },

        getLastSync: function() {
            return this.get(this.KEYS.LAST_SYNC) || 0;
        },

        /**
         * Check if storage is available
         */
        isAvailable: function() {
            try {
                const test = '__storage_test__';
                localStorage.setItem(test, test);
                localStorage.removeItem(test);
                return true;
            } catch (e) {
                return false;
            }
        }
    };

    // Initialize device ID on load
    if (FlashStorage.isAvailable()) {
        FlashStorage.getDeviceId();
    }

})();
