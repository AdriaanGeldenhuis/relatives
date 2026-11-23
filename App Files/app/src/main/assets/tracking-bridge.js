(function () {
    'use strict';
    if (window.top !== window || !window.RelativesNative) return;

    const port = window.RelativesNative;
    const snapshot = { mode: 'loading', age: Infinity, receivedAt: 0, family: '[]' };
    let lastRequest = -Infinity;

    function send(command) {
        port.postMessage(command);
    }

    function requestState() {
        const now = performance.now();
        if (now - lastRequest < 750) return;
        lastRequest = now;
        send('state');
    }

    let navigationReply;
    port.onmessage = function (event) {
        const data = JSON.parse(event.data);
        if (data.type === 'state') {
            snapshot.mode = data.mode;
            snapshot.age = data.cacheAge;
            snapshot.receivedAt = performance.now();
            snapshot.family = data.family;
            window.dispatchEvent(new CustomEvent('relativestrackingstate', { detail: snapshot.mode }));
        } else if (data.type === 'navigation' && navigationReply) {
            const reply = navigationReply;
            navigationReply = undefined;
            reply(data.allowed === true);
        }
    };

    window.TrackingBridge = Object.freeze({
        getTrackingMode: function () {
            requestState();
            return snapshot.mode;
        },
        isTrackingEnabled: function () {
            requestState();
            return snapshot.mode === 'enabled';
        },
        getFamilyCacheAgeMillis: function () {
            requestState();
            return snapshot.age + performance.now() - snapshot.receivedAt;
        },
        getCachedFamily: function () { return snapshot.family; },
        startTracking: function () { send('start'); },
        stopTracking: function () { send('stop'); },
        wakeAllDevices: function () { send('refresh_device'); },
        onTrackingScreenVisible: function () { send('map_visible'); },
        onTrackingScreenHidden: function () { send('map_hidden'); },
        requestNotificationPermission: function () { send('notifications'); },
        prepareNavigation: function () {
            return new Promise(function (resolve) {
                if (navigationReply) {
                    resolve(false);
                    return;
                }
                const timeout = setTimeout(function () {
                    navigationReply = undefined;
                    resolve(false);
                }, 3000);
                navigationReply = function (allowed) {
                    clearTimeout(timeout);
                    resolve(allowed);
                };
                send('navigation');
            });
        }
    });
}());
