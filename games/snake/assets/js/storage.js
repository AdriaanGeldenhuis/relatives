/**
 * Snake Classic - Local Storage Manager
 * Handles offline-first storage, score queue, and device identification.
 */

const SnakeStorage = (function() {
    'use strict';

    const STORAGE_KEYS = {
        DEVICE_ID: 'snake_device_id',
        BEST_SCORE: 'snake_best_score',
        TODAY_BEST: 'snake_today_best',
        TODAY_DATE: 'snake_today_date',
        SCORE_QUEUE: 'snake_score_queue',
        CACHED_LEADERBOARDS: 'snake_leaderboards',
        LEADERBOARD_TIMESTAMP: 'snake_leaderboards_ts',
        SNAKE_CUSTOM_COLORS: 'snake_custom_colors',
        SNAKE_CUSTOM_FACE: 'snake_custom_face'
    };

    const LEADERBOARD_CACHE_TTL = 60000; // 1 minute cache

    /**
     * Generate a stable device ID
     */
    function generateDeviceId() {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        let result = '';
        const array = new Uint8Array(24);
        crypto.getRandomValues(array);
        for (let i = 0; i < 24; i++) {
            result += chars[array[i] % chars.length];
        }
        return result;
    }

    /**
     * Get or create device ID
     */
    function getDeviceId() {
        let deviceId = localStorage.getItem(STORAGE_KEYS.DEVICE_ID);
        if (!deviceId) {
            deviceId = generateDeviceId();
            localStorage.setItem(STORAGE_KEYS.DEVICE_ID, deviceId);
        }
        return deviceId;
    }

    /**
     * Get today's date string (YYYY-MM-DD in local timezone)
     */
    function getTodayString() {
        const now = new Date();
        return now.getFullYear() + '-' +
            String(now.getMonth() + 1).padStart(2, '0') + '-' +
            String(now.getDate()).padStart(2, '0');
    }

    /**
     * Get current week string (YYYY-Www)
     */
    function getWeekString() {
        const now = new Date();
        const startOfYear = new Date(now.getFullYear(), 0, 1);
        const days = Math.floor((now - startOfYear) / (24 * 60 * 60 * 1000));
        const weekNum = Math.ceil((days + startOfYear.getDay() + 1) / 7);
        return now.getFullYear() + '-W' + String(weekNum).padStart(2, '0');
    }

    /**
     * Check if stored today's date matches actual today
     */
    function isTodayValid() {
        const storedDate = localStorage.getItem(STORAGE_KEYS.TODAY_DATE);
        return storedDate === getTodayString();
    }

    /**
     * Get personal best score (all-time)
     */
    function getBestScore() {
        return parseInt(localStorage.getItem(STORAGE_KEYS.BEST_SCORE), 10) || 0;
    }

    /**
     * Set personal best score
     */
    function setBestScore(score) {
        const current = getBestScore();
        if (score > current) {
            localStorage.setItem(STORAGE_KEYS.BEST_SCORE, score.toString());
            return true;
        }
        return false;
    }

    /**
     * Get today's best score
     */
    function getTodayBest() {
        if (!isTodayValid()) {
            // Reset for new day
            localStorage.setItem(STORAGE_KEYS.TODAY_DATE, getTodayString());
            localStorage.setItem(STORAGE_KEYS.TODAY_BEST, '0');
            return 0;
        }
        return parseInt(localStorage.getItem(STORAGE_KEYS.TODAY_BEST), 10) || 0;
    }

    /**
     * Set today's best score
     */
    function setTodayBest(score) {
        if (!isTodayValid()) {
            localStorage.setItem(STORAGE_KEYS.TODAY_DATE, getTodayString());
        }
        const current = getTodayBest();
        if (score > current) {
            localStorage.setItem(STORAGE_KEYS.TODAY_BEST, score.toString());
            return true;
        }
        return false;
    }

    /**
     * Get score submission queue
     */
    function getScoreQueue() {
        try {
            const queue = localStorage.getItem(STORAGE_KEYS.SCORE_QUEUE);
            return queue ? JSON.parse(queue) : [];
        } catch (e) {
            console.error('Error reading score queue:', e);
            return [];
        }
    }

    /**
     * Add score to submission queue
     */
    function addToQueue(scoreData) {
        const queue = getScoreQueue();
        queue.push({
            ...scoreData,
            queuedAt: new Date().toISOString()
        });
        localStorage.setItem(STORAGE_KEYS.SCORE_QUEUE, JSON.stringify(queue));
    }

    /**
     * Remove item from queue by index
     */
    function removeFromQueue(index) {
        const queue = getScoreQueue();
        if (index >= 0 && index < queue.length) {
            queue.splice(index, 1);
            localStorage.setItem(STORAGE_KEYS.SCORE_QUEUE, JSON.stringify(queue));
        }
    }

    /**
     * Clear entire queue
     */
    function clearQueue() {
        localStorage.setItem(STORAGE_KEYS.SCORE_QUEUE, '[]');
    }

    /**
     * Get queue length
     */
    function getQueueLength() {
        return getScoreQueue().length;
    }

    /**
     * Cache leaderboards data
     */
    function cacheLeaderboards(data) {
        try {
            localStorage.setItem(STORAGE_KEYS.CACHED_LEADERBOARDS, JSON.stringify(data));
            localStorage.setItem(STORAGE_KEYS.LEADERBOARD_TIMESTAMP, Date.now().toString());
        } catch (e) {
            console.error('Error caching leaderboards:', e);
        }
    }

    /**
     * Get cached leaderboards
     */
    function getCachedLeaderboards() {
        try {
            const timestamp = parseInt(localStorage.getItem(STORAGE_KEYS.LEADERBOARD_TIMESTAMP), 10) || 0;
            const data = localStorage.getItem(STORAGE_KEYS.CACHED_LEADERBOARDS);

            if (data) {
                const parsed = JSON.parse(data);
                const isFresh = (Date.now() - timestamp) < LEADERBOARD_CACHE_TTL;
                return {
                    data: parsed,
                    isFresh: isFresh,
                    timestamp: timestamp
                };
            }
        } catch (e) {
            console.error('Error reading cached leaderboards:', e);
        }
        return null;
    }

    /**
     * Store a score run locally and update bests
     */
    function saveScoreRun(scoreData) {
        // Update personal bests
        setBestScore(scoreData.score);
        setTodayBest(scoreData.score);

        // Add to sync queue
        addToQueue(scoreData);

        return {
            isNewBest: scoreData.score >= getBestScore(),
            isNewTodayBest: scoreData.score >= getTodayBest(),
            queueLength: getQueueLength()
        };
    }

    /**
     * Create a score record object
     */
    function createScoreRecord(score, startTime, endTime) {
        return {
            score: score,
            mode: 'classic',
            run_started_at: startTime.toISOString(),
            run_ended_at: endTime.toISOString(),
            device_id: getDeviceId(),
            seed: getWeekString()
        };
    }

    /**
     * Get saved snake custom colors
     */
    function getSnakeColors() {
        try {
            const v = localStorage.getItem(STORAGE_KEYS.SNAKE_CUSTOM_COLORS);
            return v ? JSON.parse(v) : null;
        } catch (e) { return null; }
    }

    /**
     * Save snake custom colors
     */
    function setSnakeColors(colors) {
        localStorage.setItem(STORAGE_KEYS.SNAKE_CUSTOM_COLORS, JSON.stringify(colors));
    }

    /**
     * Get saved snake face emoji
     */
    function getSnakeFace() {
        return localStorage.getItem(STORAGE_KEYS.SNAKE_CUSTOM_FACE) || '';
    }

    /**
     * Save snake face emoji
     */
    function setSnakeFace(face) {
        localStorage.setItem(STORAGE_KEYS.SNAKE_CUSTOM_FACE, face || '');
    }

    // Public API
    return {
        getDeviceId,
        getTodayString,
        getWeekString,
        getBestScore,
        setBestScore,
        getTodayBest,
        setTodayBest,
        getScoreQueue,
        addToQueue,
        removeFromQueue,
        clearQueue,
        getQueueLength,
        cacheLeaderboards,
        getCachedLeaderboards,
        saveScoreRun,
        createScoreRecord,
        getSnakeColors,
        setSnakeColors,
        getSnakeFace,
        setSnakeFace
    };
})();

// Export for module systems if available
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SnakeStorage;
}
