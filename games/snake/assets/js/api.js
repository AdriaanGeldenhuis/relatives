/**
 * Snake Classic - API Communication Layer
 * Handles server communication and background sync.
 */

const SnakeAPI = (function() {
    'use strict';

    const config = window.SNAKE_CONFIG || {
        apiBase: '/api/games/snake'
    };

    let isSyncing = false;
    let syncCallbacks = [];

    /**
     * Check if browser is online
     */
    function isOnline() {
        return navigator.onLine;
    }

    /**
     * Make an API request with error handling
     */
    async function apiRequest(endpoint, options = {}) {
        const url = endpoint.startsWith('/') ? endpoint : `${config.apiBase}/${endpoint}`;

        const defaultOptions = {
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        };

        const response = await fetch(url, { ...defaultOptions, ...options });

        if (!response.ok) {
            const error = new Error(`API error: ${response.status}`);
            error.status = response.status;
            try {
                error.data = await response.json();
            } catch (e) {
                error.data = null;
            }
            throw error;
        }

        return response.json();
    }

    /**
     * Fetch current user info
     */
    async function fetchMe() {
        return apiRequest('/api/me.php');
    }

    /**
     * Submit a score to the server
     */
    async function submitScore(scoreData) {
        return apiRequest('submit_score.php', {
            method: 'POST',
            body: JSON.stringify(scoreData)
        });
    }

    /**
     * Fetch leaderboards
     * @param {string} range - 'today' or 'week'
     */
    async function fetchLeaderboards(range = 'today') {
        return apiRequest(`leaderboard.php?range=${encodeURIComponent(range)}`);
    }

    /**
     * Get leaderboards with cache fallback
     */
    async function getLeaderboards(range = 'today', forceRefresh = false) {
        // Try cache first if not forcing refresh
        if (!forceRefresh) {
            const cached = SnakeStorage.getCachedLeaderboards();
            if (cached && cached.isFresh && cached.data[range]) {
                return {
                    data: cached.data[range],
                    fromCache: true,
                    fresh: true
                };
            }
        }

        // Try network
        if (isOnline()) {
            try {
                const data = await fetchLeaderboards(range);

                // Cache the response (merge with existing cache for other ranges)
                const existing = SnakeStorage.getCachedLeaderboards();
                const cacheData = existing ? existing.data : {};
                cacheData[range] = data;
                SnakeStorage.cacheLeaderboards(cacheData);

                return {
                    data: data,
                    fromCache: false,
                    fresh: true
                };
            } catch (e) {
                console.error('Error fetching leaderboards:', e);
            }
        }

        // Fall back to stale cache
        const cached = SnakeStorage.getCachedLeaderboards();
        if (cached && cached.data[range]) {
            return {
                data: cached.data[range],
                fromCache: true,
                fresh: false
            };
        }

        return null;
    }

    /**
     * Submit score with offline fallback
     * Returns sync status
     */
    async function submitScoreWithFallback(scoreData) {
        // Always save locally first
        SnakeStorage.saveScoreRun(scoreData);

        if (!isOnline()) {
            return {
                synced: false,
                savedLocally: true,
                message: 'Saved locally (offline)'
            };
        }

        try {
            const result = await submitScore(scoreData);

            // Remove from queue if successful
            const queue = SnakeStorage.getScoreQueue();
            if (queue.length > 0) {
                // Find and remove this score from queue
                const idx = queue.findIndex(q =>
                    q.run_started_at === scoreData.run_started_at &&
                    q.score === scoreData.score
                );
                if (idx !== -1) {
                    SnakeStorage.removeFromQueue(idx);
                }
            }

            return {
                synced: true,
                savedLocally: true,
                message: 'Synced to server'
            };
        } catch (e) {
            console.error('Error submitting score:', e);
            return {
                synced: false,
                savedLocally: true,
                message: e.status === 400 ? 'Invalid score' : 'Saved locally'
            };
        }
    }

    /**
     * Sync all queued scores
     */
    async function syncQueue() {
        if (isSyncing || !isOnline()) {
            return {
                synced: 0,
                failed: 0,
                remaining: SnakeStorage.getQueueLength()
            };
        }

        isSyncing = true;
        notifySyncStatus('syncing');

        const queue = SnakeStorage.getScoreQueue();
        let synced = 0;
        let failed = 0;

        for (let i = queue.length - 1; i >= 0; i--) {
            const scoreData = queue[i];
            try {
                await submitScore(scoreData);
                SnakeStorage.removeFromQueue(i);
                synced++;
            } catch (e) {
                console.error('Failed to sync score:', e);
                // Remove invalid scores (400 errors)
                if (e.status === 400) {
                    SnakeStorage.removeFromQueue(i);
                }
                failed++;
            }
        }

        isSyncing = false;

        const remaining = SnakeStorage.getQueueLength();
        notifySyncStatus(remaining > 0 ? 'pending' : 'synced');

        return {
            synced,
            failed,
            remaining
        };
    }

    /**
     * Register a sync status callback
     */
    function onSyncStatusChange(callback) {
        syncCallbacks.push(callback);
        return () => {
            syncCallbacks = syncCallbacks.filter(cb => cb !== callback);
        };
    }

    /**
     * Notify all callbacks of sync status change
     */
    function notifySyncStatus(status) {
        syncCallbacks.forEach(cb => cb(status));
    }

    /**
     * Get current sync status
     */
    function getSyncStatus() {
        if (!isOnline()) return 'offline';
        if (isSyncing) return 'syncing';
        if (SnakeStorage.getQueueLength() > 0) return 'pending';
        return 'synced';
    }

    /**
     * Initialize online/offline listeners
     */
    function init() {
        window.addEventListener('online', () => {
            console.log('Back online, syncing...');
            syncQueue();
        });

        window.addEventListener('offline', () => {
            notifySyncStatus('offline');
        });

        // Initial sync if online and has queued items
        if (isOnline() && SnakeStorage.getQueueLength() > 0) {
            setTimeout(syncQueue, 1000);
        }

        // Initial status
        notifySyncStatus(getSyncStatus());
    }

    // Public API
    return {
        init,
        isOnline,
        fetchMe,
        submitScore,
        fetchLeaderboards,
        getLeaderboards,
        submitScoreWithFallback,
        syncQueue,
        getSyncStatus,
        onSyncStatusChange
    };
})();

// Export for module systems if available
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SnakeAPI;
}
