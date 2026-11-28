const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

const page = fs.readFileSync(new URL('../app/index.php', `file://${__filename}`), 'utf8');
const handler = page.slice(page.indexOf('    var wakeFab ='), page.indexOf('    // ── TRACKING INTEGRATION'));
const liveHandler = page.slice(page.indexOf('    function liveViewerOptions'), page.indexOf('    function startPolling()'));

function harness(replies, { nativeMode = 'enabled', own = null } = {}) {
    let clock = 0;
    let localWakes = 0;
    let mapReads = 0;
    let busy = false;
    const requests = [];
    const wakeFab = {
        setAttribute: () => { busy = true; },
        removeAttribute: () => { busy = false; },
        addEventListener() {},
    };
    const context = vm.createContext({
        window: { TrackingConfig: { apiBase: '/tracking/api', wakeCsrf: 'csrf', userId: 1 } },
        document: { hidden: false, getElementById: () => wakeFab },
        nativeBridge: { getTrackingMode: () => nativeMode, refreshMyLocation() { localWakes++; } },
        isNativeApp: true,
        _members: own ? [own] : [],
        mapPolicy: { mode: 1 },
        AbortController, crypto: webcrypto, performance: { now: () => clock }, encodeURIComponent,
        setTimeout() {}, clearTimeout() {}, clearFamilySnapshot() {},
        serverNow: () => 100_000 + clock, captureTime: member => member.recorded_at_ms,
        fetchMembers() { mapReads++; },
        fetch: async (path, options) => {
            requests.push([path, options?.body ? JSON.parse(options.body) : null]);
            const [status, body] = replies.shift();
            return { status, ok: status < 400, json: async () => body };
        }
    });
    vm.runInContext(handler, context);
    return { context, requests, busy: () => busy, localWakes: () => localWakes, mapReads: () => mapReads,
        advance(ms) { clock += ms; } };
}

test('manual Wake refreshes this sharing phone with no message even without remote targets', async () => {
    const ui = harness([[200, { success: true, data: { complete: true } }]]);
    await ui.context.refreshFamily('manual');
    assert.equal(ui.requests[0][1].mode, 'manual');
    assert.equal(ui.requests[0][1].refreshing_local, true);
    assert.equal(ui.localWakes(), 1);
    assert.equal(ui.busy(), true);
    await ui.context.refreshFamily('manual');
    assert.equal(ui.requests.length, 1);
    ui.context._members = [{ user_id: 1, recorded_at_ms: 100_001 }];
    await ui.context.checkWakeStatus();
    assert.equal(ui.busy(), false);
    assert.doesNotMatch(page, /id="wakeResult"|Sending Wake request|showToast\('Refreshing this phone/);
});

test('opening with a fresh own fix requests stale remote members once without reacquiring locally', async () => {
    const ui = harness([[200, { success: true, data: { complete: true } }]],
        { own: { user_id: 1, has_location: true, recorded_at_ms: 99_000 } });
    ui.context.automaticWakePending = true;
    ui.context.automaticallyRefreshFamily();
    await new Promise(resolve => setImmediate(resolve));
    ui.context.automaticWakePending = true;
    ui.context.automaticallyRefreshFamily();
    assert.equal(ui.requests[0][1].mode, 'automatic');
    assert.equal(ui.requests[0][1].refreshing_local, false);
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.localWakes(), 0);
});

test('the displayed map refreshes as a confirmed fix arrives', async () => {
    const request = { request_id: 'wake', remaining_ms: 50000, complete: false,
        targets: [{ user_id: 2, state: 'received' }] };
    const ui = harness([
        [200, { success: true, data: request }],
        [200, { success: true, data: { ...request, complete: true,
            targets: [{ user_id: 2, state: 'refreshed', recorded_at_ms: 100_123 }] } }],
    ], { nativeMode: 'paused' });
    await ui.context.refreshFamily('manual');
    assert.equal(ui.localWakes(), 0);
    assert.equal(ui.busy(), false);
    assert.equal(ui.mapReads(), 2);
});

test('a remote failure leaves the last shown location and ends quietly', async () => {
    const ui = harness([[503, {}]], { nativeMode: 'paused' });
    await ui.context.refreshFamily('manual');
    assert.equal(ui.busy(), false);
    assert.equal(ui.localWakes(), 0);
    assert.equal(ui.mapReads(), 1);
});

test('a sharing stationary browser checks in every 30 seconds until the viewer lease expires', async () => {
    let now = 0;
    let pending;
    const requests = [];
    const viewer = { id: 'viewer', lease: { id: 'family-session', pulse_ms: 30_000 }, expiresAt: 75_000 };
    const context = vm.createContext({
        isNativeApp: false, document: { hidden: false }, liveViewer: viewer, browserWatchId: 1,
        mapPolicy: { allowed: true }, browserDeviceId: 'web-device', latestPos: null, lastBrowserPulse: null,
        performance: { now: () => now },
        setTimeout(callback, delay) { pending = { callback, at: now + delay }; return pending; },
        async wakeRequest(path, options) { requests.push([now, path, JSON.parse(options.body)]); },
    });
    vm.runInContext(liveHandler, context);
    context.scheduleBrowserPulse(viewer);
    context.scheduleBrowserPulse(viewer);
    for (let i = 0; i < 4; i++) {
        now = pending.at;
        pending.callback();
    }
    assert.deepEqual(requests.map(request => request[0]), [0, 30_000, 60_000]);
    assert.deepEqual(requests[0].slice(1), ['/batch.php', {
        locations: [], live_pulse: { session_id: 'family-session', device_id: 'device' },
    }]);
});

test('a delayed start response releases a viewer after the Tracking screen has closed', async () => {
    const viewer = { id: 'viewer', lease: null };
    let finish;
    const requests = [];
    const context = vm.createContext({
        window: { TrackingConfig: { apiBase: '/tracking/api', wakeCsrf: 'csrf' } },
        document: { hidden: false }, liveViewer: viewer,
        wakeRequest: () => new Promise(resolve => { finish = resolve; }),
        async fetch(path, options) { requests.push([path, JSON.parse(options.body), options.keepalive]); },
    });
    vm.runInContext(liveHandler, context);
    const request = context.renewLiveViewer(viewer);
    context.liveViewer = null;
    finish({ session: { id: 'family-session' } });
    await request;
    assert.deepEqual(requests, [['/tracking/api/live_session.php', { viewer_id: 'viewer', action: 'leave' }, true]]);
});
