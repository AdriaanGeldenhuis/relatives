(function () {
    'use strict';
    if (window.top !== window || !window.RelativesNative) return;

    const port = window.RelativesNative;
    const snapshot = { mode: 'loading', age: Infinity, receivedAt: 0, family: '[]', remoteWake: true };
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

    let locationReply;

    function prepareLocation(type) {
        return new Promise(resolve => {
            if (locationReply) {
                resolve(false);
                return;
            }

            const timeout = setTimeout(() => {
                locationReply = undefined;
                resolve(false);
            }, 3000);
            locationReply = {
                type,
                finish(allowed) {
                    clearTimeout(timeout);
                    locationReply = undefined;
                    resolve(allowed);
                }
            };
            try {
                send(type);
            } catch {
                locationReply.finish(false);
            }
        });
    }

    port.onmessage = function (event) {
        const data = JSON.parse(event.data);
        if (data.type === 'state') {
            snapshot.mode = data.mode;
            snapshot.age = data.cacheAge;
            snapshot.receivedAt = performance.now();
            snapshot.family = data.family;
            snapshot.remoteWake = data.remoteWake !== false;
            window.dispatchEvent(new CustomEvent('relativestrackingstate', { detail: snapshot.mode }));
        } else if (data.type === 'tracking_health') {
            window.dispatchEvent(new CustomEvent('relativestrackinghealth', { detail: data }));
        } else if (data.type === 'notification_status') {
            window.dispatchEvent(new CustomEvent('relativesnotificationstatus', { detail: data }));
        } else if (locationReply && data.type === locationReply.type) {
            locationReply.finish(data.allowed === true);
        }
    };

    window.TrackingBridge = Object.freeze({
        getTrackingMode: function () {
            requestState();
            return snapshot.mode;
        },
        isTrackingEnabled: function () {
            requestState();
            return ['enabled', 'battery_saver', 'approximate'].includes(snapshot.mode);
        },
        getFamilyCacheAgeMillis: function () {
            requestState();
            return snapshot.age + performance.now() - snapshot.receivedAt;
        },
        getCachedFamily: function () { return snapshot.family; },
        startTracking: function () { send('start'); },
        stopTracking: function () { send('stop'); },
        wakeAllDevices: function () { send('refresh_device'); },
        refreshMyLocation: function () { send('refresh_device'); },
        joinLiveTracking: function () { send('join_live_tracking'); },
        hasRemoteWake: function () { return snapshot.remoteWake; },
        claimFamilyPolling: function () { send('web_family_polling'); },
        requestTrackingHealth: function () { send('tracking_health'); },
        refreshTrackingPolicy: function () { send('refresh_tracking_policy'); },
        onTrackingScreenVisible: function () { send('map_visible'); },
        onTrackingScreenHidden: function () { send('map_hidden'); },
        requestNotificationPermission: function () { send('notifications'); },
        requestNotificationStatus: function () { send('notification_status'); },
        openNotificationSettings: function () { send('notification_settings'); },
        prepareNavigation: () => prepareLocation('navigation'),
        prepareWeatherLocation: () => prepareLocation('weather_location')
    });
}());
