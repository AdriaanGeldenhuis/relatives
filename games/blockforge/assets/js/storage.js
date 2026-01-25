/* =============================================
   BLOCKFORGE - Offline Storage (localStorage + IndexedDB)
   ============================================= */

var BlockStorage = (function() {
    'use strict';

    var DB_NAME = 'blockforge_db';
    var DB_VERSION = 1;
    var db = null;

    // Generate stable device ID
    function getDeviceId() {
        var id = localStorage.getItem('bf_device_id');
        if (!id) {
            id = 'bf_' + Date.now().toString(36) + '_' + Math.random().toString(36).substr(2, 9);
            localStorage.setItem('bf_device_id', id);
        }
        return id;
    }

    // Init IndexedDB
    function initDB() {
        return new Promise(function(resolve, reject) {
            if (db) { resolve(db); return; }
            var request = indexedDB.open(DB_NAME, DB_VERSION);
            request.onupgradeneeded = function(e) {
                var d = e.target.result;
                if (!d.objectStoreNames.contains('scores')) {
                    var scoreStore = d.createObjectStore('scores', { keyPath: 'id', autoIncrement: true });
                    scoreStore.createIndex('mode', 'mode', { unique: false });
                    scoreStore.createIndex('synced', 'synced', { unique: false });
                }
                if (!d.objectStoreNames.contains('familyMoves')) {
                    var moveStore = d.createObjectStore('familyMoves', { keyPath: 'id', autoIncrement: true });
                    moveStore.createIndex('synced', 'synced', { unique: false });
                }
                if (!d.objectStoreNames.contains('cache')) {
                    d.createObjectStore('cache', { keyPath: 'key' });
                }
            };
            request.onsuccess = function(e) {
                db = e.target.result;
                resolve(db);
            };
            request.onerror = function() {
                reject(new Error('IndexedDB init failed'));
            };
        });
    }

    // Settings
    function getSettings() {
        var defaults = {
            theme: 'neon-dark',
            sound: true,
            haptics: true,
            controls: true,
            ghost: true
        };
        try {
            var saved = JSON.parse(localStorage.getItem('bf_settings'));
            if (saved) {
                for (var k in defaults) {
                    if (saved[k] !== undefined) defaults[k] = saved[k];
                }
            }
        } catch(e) {}
        return defaults;
    }

    function saveSettings(settings) {
        localStorage.setItem('bf_settings', JSON.stringify(settings));
    }

    // Best scores
    function getBestScores() {
        try {
            return JSON.parse(localStorage.getItem('bf_best')) || { solo: 0, daily: 0, family: 0 };
        } catch(e) {
            return { solo: 0, daily: 0, family: 0 };
        }
    }

    function saveBestScore(mode, score) {
        var bests = getBestScores();
        if (score > (bests[mode] || 0)) {
            bests[mode] = score;
            localStorage.setItem('bf_best', JSON.stringify(bests));
            return true;
        }
        return false;
    }

    // Streaks
    function getStreak() {
        try {
            return JSON.parse(localStorage.getItem('bf_streak')) || { count: 0, lastDate: '' };
        } catch(e) {
            return { count: 0, lastDate: '' };
        }
    }

    function updateStreak() {
        var streak = getStreak();
        var today = new Date().toISOString().split('T')[0];
        if (streak.lastDate === today) return streak;

        var yesterday = new Date(Date.now() - 86400000).toISOString().split('T')[0];
        if (streak.lastDate === yesterday) {
            streak.count++;
        } else {
            streak.count = 1;
        }
        streak.lastDate = today;
        localStorage.setItem('bf_streak', JSON.stringify(streak));
        return streak;
    }

    // Daily cache
    function getCachedDaily() {
        try {
            var data = JSON.parse(localStorage.getItem('bf_daily_cache'));
            if (data && data.date === new Date().toISOString().split('T')[0]) {
                return data;
            }
        } catch(e) {}
        return null;
    }

    function cacheDaily(data) {
        localStorage.setItem('bf_daily_cache', JSON.stringify(data));
    }

    // Family board cache
    function getCachedFamilyBoard() {
        try {
            var data = JSON.parse(localStorage.getItem('bf_family_cache'));
            if (data && data.date === new Date().toISOString().split('T')[0]) {
                return data;
            }
        } catch(e) {}
        return null;
    }

    function cacheFamilyBoard(data) {
        localStorage.setItem('bf_family_cache', JSON.stringify(data));
    }

    // Queue score for sync
    function queueScore(scoreData) {
        return initDB().then(function(database) {
            return new Promise(function(resolve, reject) {
                var tx = database.transaction('scores', 'readwrite');
                var store = tx.objectStore('scores');
                scoreData.synced = false;
                scoreData.timestamp = Date.now();
                scoreData.device_id = getDeviceId();
                store.add(scoreData);
                tx.oncomplete = function() { resolve(); };
                tx.onerror = function() { reject(new Error('Score queue failed')); };
            });
        });
    }

    // Get unsynced scores
    function getUnsyncedScores() {
        return initDB().then(function(database) {
            return new Promise(function(resolve, reject) {
                var tx = database.transaction('scores', 'readonly');
                var store = tx.objectStore('scores');
                var index = store.index('synced');
                var request = index.getAll(false);
                request.onsuccess = function() { resolve(request.result || []); };
                request.onerror = function() { reject(new Error('Get unsynced failed')); };
            });
        });
    }

    // Mark scores as synced
    function markScoresSynced(ids) {
        return initDB().then(function(database) {
            return new Promise(function(resolve, reject) {
                var tx = database.transaction('scores', 'readwrite');
                var store = tx.objectStore('scores');
                ids.forEach(function(id) {
                    var req = store.get(id);
                    req.onsuccess = function() {
                        var item = req.result;
                        if (item) {
                            item.synced = true;
                            store.put(item);
                        }
                    };
                });
                tx.oncomplete = function() { resolve(); };
                tx.onerror = function() { reject(new Error('Mark synced failed')); };
            });
        });
    }

    // Queue family move
    function queueFamilyMove(moveData) {
        return initDB().then(function(database) {
            return new Promise(function(resolve, reject) {
                var tx = database.transaction('familyMoves', 'readwrite');
                var store = tx.objectStore('familyMoves');
                moveData.synced = false;
                moveData.timestamp = Date.now();
                moveData.device_id = getDeviceId();
                store.add(moveData);
                tx.oncomplete = function() { resolve(); };
                tx.onerror = function() { reject(new Error('Move queue failed')); };
            });
        });
    }

    // Get unsynced family moves
    function getUnsyncedMoves() {
        return initDB().then(function(database) {
            return new Promise(function(resolve, reject) {
                var tx = database.transaction('familyMoves', 'readonly');
                var store = tx.objectStore('familyMoves');
                var index = store.index('synced');
                var request = index.getAll(false);
                request.onsuccess = function() { resolve(request.result || []); };
                request.onerror = function() { reject(new Error('Get unsynced moves failed')); };
            });
        });
    }

    // Mark family moves synced
    function markMovesSynced(ids) {
        return initDB().then(function(database) {
            return new Promise(function(resolve, reject) {
                var tx = database.transaction('familyMoves', 'readwrite');
                var store = tx.objectStore('familyMoves');
                ids.forEach(function(id) {
                    var req = store.get(id);
                    req.onsuccess = function() {
                        var item = req.result;
                        if (item) {
                            item.synced = true;
                            store.put(item);
                        }
                    };
                });
                tx.oncomplete = function() { resolve(); };
                tx.onerror = function() { reject(new Error('Mark moves synced failed')); };
            });
        });
    }

    // Check if daily was played today
    function isDailyPlayed() {
        var today = new Date().toISOString().split('T')[0];
        return localStorage.getItem('bf_daily_played') === today;
    }

    function markDailyPlayed() {
        localStorage.setItem('bf_daily_played', new Date().toISOString().split('T')[0]);
    }

    // Check if family turn used today
    function isFamilyTurnUsed() {
        var today = new Date().toISOString().split('T')[0];
        return localStorage.getItem('bf_family_turn') === today;
    }

    function markFamilyTurnUsed() {
        localStorage.setItem('bf_family_turn', new Date().toISOString().split('T')[0]);
    }

    // Achievements
    function getAchievements() {
        try {
            return JSON.parse(localStorage.getItem('bf_achievements')) || {};
        } catch(e) {
            return {};
        }
    }

    function unlockAchievement(key) {
        var achievements = getAchievements();
        if (!achievements[key]) {
            achievements[key] = Date.now();
            localStorage.setItem('bf_achievements', JSON.stringify(achievements));
            return true;
        }
        return false;
    }

    // Stats
    function getStats() {
        try {
            return JSON.parse(localStorage.getItem('bf_stats')) || {
                totalGames: 0,
                totalLines: 0,
                totalScore: 0,
                maxCombo: 0,
                maxLevel: 0
            };
        } catch(e) {
            return { totalGames: 0, totalLines: 0, totalScore: 0, maxCombo: 0, maxLevel: 0 };
        }
    }

    function updateStats(gameResult) {
        var stats = getStats();
        stats.totalGames++;
        stats.totalLines += gameResult.lines || 0;
        stats.totalScore += gameResult.score || 0;
        if ((gameResult.maxCombo || 0) > stats.maxCombo) stats.maxCombo = gameResult.maxCombo;
        if ((gameResult.level || 0) > stats.maxLevel) stats.maxLevel = gameResult.level;
        localStorage.setItem('bf_stats', JSON.stringify(stats));
        return stats;
    }

    return {
        init: initDB,
        getDeviceId: getDeviceId,
        getSettings: getSettings,
        saveSettings: saveSettings,
        getBestScores: getBestScores,
        saveBestScore: saveBestScore,
        getStreak: getStreak,
        updateStreak: updateStreak,
        getCachedDaily: getCachedDaily,
        cacheDaily: cacheDaily,
        getCachedFamilyBoard: getCachedFamilyBoard,
        cacheFamilyBoard: cacheFamilyBoard,
        queueScore: queueScore,
        getUnsyncedScores: getUnsyncedScores,
        markScoresSynced: markScoresSynced,
        queueFamilyMove: queueFamilyMove,
        getUnsyncedMoves: getUnsyncedMoves,
        markMovesSynced: markMovesSynced,
        isDailyPlayed: isDailyPlayed,
        markDailyPlayed: markDailyPlayed,
        isFamilyTurnUsed: isFamilyTurnUsed,
        markFamilyTurnUsed: markFamilyTurnUsed,
        getAchievements: getAchievements,
        unlockAchievement: unlockAchievement,
        getStats: getStats,
        updateStats: updateStats
    };
})();
