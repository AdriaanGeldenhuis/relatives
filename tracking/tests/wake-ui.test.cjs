const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

const page = fs.readFileSync(new URL('../app/index.php', `file://${__filename}`), 'utf8');
const handler = page.slice(page.indexOf('    var wakeFab ='), page.indexOf('    // ── TRACKING INTEGRATION'));

function harness(replies) {
    let click;
    let localWakes = 0;
    let mapReads = 0;
    const text = [];
    const label = {};
    const wakeFab = { disabled: false, querySelector: () => label, addEventListener: (_, callback) => { click = callback; } };
    const result = {
        classList: { toggle() {} },
        replaceChildren(message) { text.splice(0, text.length, message); },
        appendChild(row) { text.push(row.textContent); }
    };
    const context = vm.createContext({
        window: { TrackingConfig: { apiBase: '/tracking/api', wakeCsrf: 'csrf' } },
        document: {
            getElementById: id => id === 'wakeFab' ? wakeFab : result,
            createTextNode: message => message,
            createElement: () => ({})
        },
        nativeBridge: { wakeAllDevices() { localWakes++; } },
        AbortController, crypto: webcrypto, performance, encodeURIComponent,
        setTimeout() {}, clearTimeout() {}, showToast() {}, clearFamilySnapshot() {},
        fetchMembers() { mapReads++; },
        fetch: async () => {
            const [status, body] = replies.shift();
            return { status, ok: status < 400, json: async () => body };
        }
    });
    vm.runInContext(handler, context);
    return { click, context, label, text, localWakes: () => localWakes, mapReads: () => mapReads };
}

test('zero targets and rate limiting leave the requesting phone idle', async () => {
    const ui = harness([
        [200, { success: true, data: { devices_targeted: 0, sent: 0 } }],
        [429, { success: false, retry_after: 27 }]
    ]);
    await ui.click();
    assert.equal(ui.text[0], 'No family phones ready for Wake');
    assert.equal(ui.label.textContent, 'Wake');
    await ui.click();
    assert.equal(ui.text[0], 'Try Wake again in 27s');
    assert.equal(ui.localWakes(), 0);
});

test('the map refreshes when the recipient uploads a fix', async () => {
    const request = {
        request_id: 'wake', remaining_ms: 50000, complete: false,
        targets: [{ name: 'Family member', state: 'received' }]
    };
    const ui = harness([
        [200, { success: true, data: request }],
        [200, { success: true, data: {
            ...request, complete: true, targets: [{ name: 'Family member', state: 'refreshed' }]
        } }]
    ]);
    await ui.click();
    assert.match(ui.text[1], /Request received/);
    assert.equal(ui.localWakes(), 0);
    assert.equal(ui.mapReads(), 0);
    await ui.context.checkWakeStatus();
    assert.equal(ui.text[0], '1 location updated');
    assert.equal(ui.mapReads(), 1);
    assert.equal(ui.localWakes(), 0);
});

test('Firebase setup failures explain why Wake could not start', async () => {
    const ui = harness([[503, {
        success: false,
        error: 'wake_service_unavailable',
        reason: 'firebase_key_missing'
    }]]);
    await ui.click();
    assert.equal(ui.text[0], 'Wake needs a Firebase key on the server');
    assert.equal(ui.localWakes(), 0);
});
