/**
 * ============================================
 * FLASH CHALLENGE - API Module
 * Handles all server communication
 * ============================================
 */

(function() {
    'use strict';

    const config = window.FlashConfig || {};
    const API_BASE = config.apiBase || '/api/games/flash';
    const SYNC_INTERVAL = 30000; // 30 seconds

    let syncTimer = null;
    let isSyncing = false;

    /**
     * FlashAPI - Handles all API operations
     */
    window.FlashAPI = {
        /**
         * Status callbacks
         */
        onSyncStart: null,
        onSyncComplete: null,
        onSyncError: null,
        onOnlineChange: null,

        /**
         * Check if online
         */
        isOnline: function() {
            return navigator.onLine;
        },

        /**
         * Make API request
         */
        request: async function(endpoint, options = {}) {
            const url = API_BASE + endpoint;
            const method = options.method || 'GET';
            const body = options.body ? JSON.stringify(options.body) : null;

            const fetchOptions = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            };

            if (body && method !== 'GET') {
                fetchOptions.body = body;
            }

            try {
                const response = await fetch(url, fetchOptions);
                const data = await response.json();

                if (!response.ok) {
                    return {
                        success: false,
                        error: data.error || 'Request failed',
                        status: response.status,
                        data: data
                    };
                }

                return data;
            } catch (error) {
                console.error('API request error:', error);
                return {
                    success: false,
                    error: 'Network error. Please check your connection.',
                    offline: !this.isOnline()
                };
            }
        },

        /**
         * Get today's challenge
         */
        getDailyChallenge: async function(useCache = true) {
            // Try cache first if offline
            if (!this.isOnline()) {
                const cached = FlashStorage.getCachedDailyChallenge();
                if (cached) {
                    return { ...cached, fromCache: true };
                }
                return { success: false, error: 'You are offline', offline: true };
            }

            // Check cache
            if (useCache) {
                const cached = FlashStorage.getCachedDailyChallenge();
                if (cached) {
                    // Still fetch in background to update
                    this.request('/get_daily.php').then(fresh => {
                        if (fresh.success) {
                            FlashStorage.cacheDailyChallenge(fresh);
                        }
                    });
                    return { ...cached, fromCache: true };
                }
            }

            const result = await this.request('/get_daily.php');

            if (result.success) {
                FlashStorage.cacheDailyChallenge(result);
            }

            return result;
        },

        /**
         * Submit answer attempt
         */
        submitAttempt: async function(attemptData) {
            const payload = {
                challenge_date: attemptData.challenge_date,
                answer_text: attemptData.answer_text,
                started_at: attemptData.started_at,
                answered_at: attemptData.answered_at,
                ended_at: attemptData.ended_at,
                device_id: FlashStorage.getDeviceId()
            };

            // If offline, queue the attempt
            if (!this.isOnline()) {
                const queueId = FlashStorage.addToAttemptsQueue(payload);
                return {
                    success: true,
                    queued: true,
                    queueId: queueId,
                    message: 'Answer saved locally. Will sync when online.'
                };
            }

            const result = await this.request('/submit_attempt.php', {
                method: 'POST',
                body: payload
            });

            // If network error and we're actually offline now, queue it
            if (!result.success && result.offline) {
                const queueId = FlashStorage.addToAttemptsQueue(payload);
                return {
                    success: true,
                    queued: true,
                    queueId: queueId,
                    message: 'Answer saved locally. Will sync when online.'
                };
            }

            // If successful, update cache
            if (result.success) {
                // Invalidate challenge cache to reflect played state
                FlashStorage.remove(FlashStorage.KEYS.DAILY_CHALLENGE);
                FlashStorage.remove(FlashStorage.KEYS.LEADERBOARD_CACHE);
            }

            return result;
        },

        /**
         * Get leaderboards
         */
        getLeaderboard: async function(useCache = true) {
            if (!this.isOnline()) {
                const cached = FlashStorage.getCachedLeaderboard();
                if (cached) {
                    return { ...cached, fromCache: true };
                }
                return { success: false, error: 'You are offline', offline: true };
            }

            if (useCache) {
                const cached = FlashStorage.getCachedLeaderboard();
                if (cached) {
                    return { ...cached, fromCache: true };
                }
            }

            const result = await this.request('/leaderboard.php?range=today');

            if (result.success) {
                FlashStorage.cacheLeaderboard(result);
            }

            return result;
        },

        /**
         * Get history
         */
        getHistory: async function(days = 14, useCache = true) {
            if (!this.isOnline()) {
                const cached = FlashStorage.getCachedHistory();
                if (cached) {
                    return { ...cached, fromCache: true };
                }
                return { success: false, error: 'You are offline', offline: true };
            }

            if (useCache) {
                const cached = FlashStorage.getCachedHistory();
                if (cached) {
                    return { ...cached, fromCache: true };
                }
            }

            const result = await this.request('/history.php?days=' + days);

            if (result.success) {
                FlashStorage.cacheHistory(result);
            }

            return result;
        },

        /**
         * Sync queued attempts
         */
        syncQueue: async function() {
            if (isSyncing || !this.isOnline()) {
                return { synced: 0, failed: 0 };
            }

            const queue = FlashStorage.getAttemptsQueue();
            if (queue.length === 0) {
                return { synced: 0, failed: 0 };
            }

            isSyncing = true;
            if (this.onSyncStart) this.onSyncStart();

            let synced = 0;
            let failed = 0;

            for (const attempt of queue) {
                try {
                    const result = await this.request('/submit_attempt.php', {
                        method: 'POST',
                        body: {
                            challenge_date: attempt.challenge_date,
                            answer_text: attempt.answer_text,
                            started_at: attempt.started_at,
                            answered_at: attempt.answered_at,
                            ended_at: attempt.ended_at,
                            device_id: attempt.device_id || FlashStorage.getDeviceId()
                        }
                    });

                    if (result.success || result.status === 409) {
                        // 409 = already submitted, still remove from queue
                        FlashStorage.removeFromAttemptsQueue(attempt.id);
                        synced++;
                    } else {
                        failed++;
                    }
                } catch (e) {
                    failed++;
                }
            }

            FlashStorage.setLastSync();
            isSyncing = false;

            if (this.onSyncComplete) {
                this.onSyncComplete({ synced, failed });
            }

            return { synced, failed };
        },

        /**
         * Start auto-sync timer
         */
        startAutoSync: function() {
            this.stopAutoSync();

            // Initial sync
            this.syncQueue();

            // Periodic sync
            syncTimer = setInterval(() => {
                if (this.isOnline()) {
                    this.syncQueue();
                }
            }, SYNC_INTERVAL);
        },

        /**
         * Stop auto-sync timer
         */
        stopAutoSync: function() {
            if (syncTimer) {
                clearInterval(syncTimer);
                syncTimer = null;
            }
        },

        /**
         * Initialize online/offline listeners
         */
        initNetworkListeners: function() {
            window.addEventListener('online', () => {
                if (this.onOnlineChange) this.onOnlineChange(true);
                // Sync when coming back online
                this.syncQueue();
            });

            window.addEventListener('offline', () => {
                if (this.onOnlineChange) this.onOnlineChange(false);
            });
        },

        /**
         * Get queue status
         */
        getQueueStatus: function() {
            const queue = FlashStorage.getAttemptsQueue();
            return {
                pending: queue.length,
                lastSync: FlashStorage.getLastSync()
            };
        }
    };

    // Initialize network listeners
    FlashAPI.initNetworkListeners();

})();
