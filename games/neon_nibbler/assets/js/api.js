/**
 * NEON NIBBLER - API Client
 * Score submission + leaderboard fetch + offline queue sync
 */
var NeonAPI = (function() {
    'use strict';

    var BASE = '/api/games/neon';

    function submitScore(scoreData, callback) {
        var payload = {
            score: scoreData.score,
            level_reached: scoreData.level,
            dots_collected: scoreData.dots,
            duration_ms: scoreData.duration,
            device_id: NeonStorage.getDeviceId()
        };

        fetch(BASE + '/submit_score.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.ok) {
                callback(null, { synced: true });
            } else {
                throw new Error(data.error || 'Submit failed');
            }
        })
        .catch(function(err) {
            // Queue for later
            NeonStorage.addToQueue(payload);
            callback(null, { synced: false });
        });
    }

    function syncQueue(callback) {
        var queue = NeonStorage.getQueue();
        if (queue.length === 0) {
            if (callback) callback(0);
            return;
        }

        var synced = 0;
        var pending = queue.length;

        for (var i = 0; i < queue.length; i++) {
            (function(index, item) {
                fetch(BASE + '/submit_score.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(item),
                    credentials: 'same-origin'
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.ok) synced++;
                    pending--;
                    if (pending === 0) finish();
                })
                .catch(function() {
                    pending--;
                    if (pending === 0) finish();
                });
            })(i, queue[i]);
        }

        function finish() {
            if (synced > 0) {
                // Remove synced items (start fresh if all synced)
                if (synced === queue.length) {
                    NeonStorage.clearQueue();
                }
            }
            if (callback) callback(synced);
        }
    }

    function getLeaderboard(range, callback) {
        fetch(BASE + '/leaderboard.php?range=' + encodeURIComponent(range || 'today'), {
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            callback(null, data);
        })
        .catch(function(err) {
            callback(err, null);
        });
    }

    return {
        submitScore: submitScore,
        syncQueue: syncQueue,
        getLeaderboard: getLeaderboard
    };
})();
