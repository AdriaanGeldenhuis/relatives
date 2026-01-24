/* =============================================
   BLOCKFORGE - API Communication Layer
   ============================================= */

var BlockAPI = (function() {
    'use strict';

    var BASE = '/api/games/blockforge';
    var isOnline = navigator.onLine;

    // Track online status
    window.addEventListener('online', function() { isOnline = true; syncAll(); });
    window.addEventListener('offline', function() { isOnline = false; });

    function request(method, url, data) {
        return new Promise(function(resolve, reject) {
            if (!isOnline) {
                reject(new Error('offline'));
                return;
            }

            var xhr = new XMLHttpRequest();
            xhr.open(method, url, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.timeout = 10000;

            xhr.onload = function() {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        resolve(JSON.parse(xhr.responseText));
                    } catch(e) {
                        reject(new Error('Parse error'));
                    }
                } else if (xhr.status === 401) {
                    reject(new Error('unauthorized'));
                } else {
                    reject(new Error('HTTP ' + xhr.status));
                }
            };

            xhr.onerror = function() { reject(new Error('Network error')); };
            xhr.ontimeout = function() { reject(new Error('Timeout')); };

            if (data) {
                xhr.send(JSON.stringify(data));
            } else {
                xhr.send();
            }
        });
    }

    // Get current user info
    function getMe() {
        return request('GET', '/api/me.php');
    }

    // Get daily challenge seed
    function getDaily() {
        var cached = BlockStorage.getCachedDaily();
        if (cached) return Promise.resolve(cached);

        return request('GET', BASE + '/daily.php').then(function(data) {
            BlockStorage.cacheDaily(data);
            return data;
        }).catch(function() {
            // Generate offline seed from date
            var today = new Date().toISOString().split('T')[0];
            return {
                date: today,
                seed: 'offline_' + today,
                mode_rules: { time_limit_ms: 120000, pieces_limit: 200 }
            };
        });
    }

    // Submit score
    function submitScore(scoreData) {
        // Always queue locally first
        return BlockStorage.queueScore(scoreData).then(function() {
            if (isOnline) {
                return request('POST', BASE + '/submit_score.php', {
                    mode: scoreData.mode,
                    score: scoreData.score,
                    lines_cleared: scoreData.lines_cleared,
                    level_reached: scoreData.level_reached,
                    duration_ms: scoreData.duration_ms,
                    seed: scoreData.seed || '',
                    device_id: BlockStorage.getDeviceId()
                }).then(function(result) {
                    return result;
                }).catch(function() {
                    return { ok: true, synced: false };
                });
            }
            return { ok: true, synced: false };
        });
    }

    // Get leaderboard
    function getLeaderboard(mode, range) {
        return request('GET', BASE + '/leaderboard.php?mode=' + mode + '&range=' + (range || 'today'));
    }

    // Get family board
    function getFamilyBoard() {
        var cached = BlockStorage.getCachedFamilyBoard();

        return request('GET', BASE + '/family_board.php').then(function(data) {
            BlockStorage.cacheFamilyBoard(data);
            return data;
        }).catch(function() {
            if (cached) return cached;
            return {
                date: new Date().toISOString().split('T')[0],
                grid: null,
                members_played: 0,
                total_members: 0,
                family_lines: 0,
                your_turn_used: BlockStorage.isFamilyTurnUsed()
            };
        });
    }

    // Submit family turn
    function submitFamilyTurn(turnData) {
        return BlockStorage.queueFamilyMove(turnData).then(function() {
            BlockStorage.markFamilyTurnUsed();
            if (isOnline) {
                return request('POST', BASE + '/family_board.php', {
                    date: turnData.date || new Date().toISOString().split('T')[0],
                    device_id: BlockStorage.getDeviceId(),
                    actions: turnData.actions,
                    result: {
                        lines_cleared: turnData.lines_cleared,
                        score_delta: turnData.score_delta
                    }
                }).catch(function() {
                    return { ok: true, synced: false };
                });
            }
            return { ok: true, synced: false };
        });
    }

    // Sync all pending data
    function syncAll() {
        if (!isOnline) return Promise.resolve();

        return BlockStorage.getUnsyncedScores().then(function(scores) {
            var promises = scores.map(function(s) {
                return request('POST', BASE + '/submit_score.php', {
                    mode: s.mode,
                    score: s.score,
                    lines_cleared: s.lines_cleared,
                    level_reached: s.level_reached,
                    duration_ms: s.duration_ms,
                    seed: s.seed || '',
                    device_id: s.device_id
                }).then(function() {
                    return s.id;
                }).catch(function() {
                    return null;
                });
            });
            return Promise.all(promises);
        }).then(function(results) {
            var synced = results.filter(function(id) { return id !== null; });
            if (synced.length > 0) {
                return BlockStorage.markScoresSynced(synced);
            }
        }).then(function() {
            return BlockStorage.getUnsyncedMoves();
        }).then(function(moves) {
            var promises = moves.map(function(m) {
                return request('POST', BASE + '/family_board.php', {
                    date: m.date,
                    device_id: m.device_id,
                    actions: m.actions,
                    result: m.result
                }).then(function() {
                    return m.id;
                }).catch(function() {
                    return null;
                });
            });
            return Promise.all(promises);
        }).then(function(results) {
            var synced = results.filter(function(id) { return id !== null; });
            if (synced.length > 0) {
                return BlockStorage.markMovesSynced(synced);
            }
        }).catch(function() {
            // Sync failed silently
        });
    }

    function getOnlineStatus() {
        return isOnline;
    }

    return {
        getMe: getMe,
        getDaily: getDaily,
        submitScore: submitScore,
        getLeaderboard: getLeaderboard,
        getFamilyBoard: getFamilyBoard,
        submitFamilyTurn: submitFamilyTurn,
        syncAll: syncAll,
        getOnlineStatus: getOnlineStatus
    };
})();
