(() => {
    const panel = document.querySelector('[data-push-settings]');
    if (!panel) return;
    const status = panel.querySelector('[role="status"]');
    const buttons = [...panel.querySelectorAll('button')];
    const native = /RelativesAndroid/.test(navigator.userAgent);
    const channel = native ? 'fcm' : 'web';
    const url = '/api/push/devices.php';
    let registration;
    let publicKey;
    let busy = false;

    async function request(action, values = {}) {
        const response = await fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': panel.dataset.csrf },
            body: JSON.stringify({ action, channel, ...values })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Could not update notifications.');
        return result.data;
    }

    async function refresh() {
        if (busy) return;
        try {
            const subscription = await registration?.pushManager.getSubscription();
            if (!native && (!registration || !subscription || Notification.permission !== 'granted')) {
                status.textContent = publicKey ? 'Browser notifications are off.' : 'Browser notifications are unavailable.';
                return;
            }
            const result = await request('status', subscription ? { endpoint: subscription.endpoint } : {});
            const state = { accepted: 'Accepted by provider', delivered: 'Received on this device',
                pending: 'Awaiting delivery', skipped: 'Skipped', failed: 'Delivery failed' }[result.last?.state];
            status.textContent = (result.registered ? 'This device is registered.' : 'This device is awaiting registration.')
                + (state ? ' ' + state + '.' : '');
        } catch (error) {
            status.textContent = error.message;
        }
    }

    async function initialize() {
        if (!native && (!('PushManager' in window) || !('Notification' in window) || !navigator.serviceWorker)) {
            status.textContent = 'Browser push needs a supported browser. On iPhone or iPad, open Relatives from your Home Screen.';
            return;
        }
        try {
            const response = await fetch(url, { cache: 'no-store' });
            const config = (await response.json()).data;
            if (!config?.available || (!native && !config.public_key)) {
                status.textContent = 'Device push setup is temporarily unavailable.';
                return;
            }
            publicKey = config.public_key;
            if (!native) {
                registration = await navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' });
            }
            buttons.forEach(button => { button.disabled = false; });
            await refresh();
        } catch {
            status.textContent = 'Could not connect to notification settings.';
        }
    }

    panel.addEventListener('click', async event => {
        const action = event.target.closest('button')?.dataset.pushAction;
        if (!action || busy) return;
        if (native && action !== 'test') {
            if (action === 'enable') window.TrackingBridge?.requestNotificationPermission();
            else window.TrackingBridge?.openNotificationSettings();
            status.textContent = 'Check notifications for Relatives in Android Settings.';
            return;
        }
        busy = true;
        buttons.forEach(button => { button.disabled = true; });
        try {
            if (action === 'enable' && Notification.permission !== 'granted') {
                if (await Notification.requestPermission() !== 'granted') {
                    throw new Error('Allow notifications in your browser settings to enable alerts.');
                }
            }
            let subscription = await registration?.pushManager.getSubscription();
            if (action === 'enable') {
                subscription ??= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: Uint8Array.from(atob(publicKey.replace(/-/g, '+').replace(/_/g, '/') + '='), char => char.charCodeAt(0)) });
                await request('subscribe', { subscription: subscription.toJSON() });
                status.textContent = 'Browser notifications are enabled.';
            } else if (action === 'disable' && subscription) {
                await request('unsubscribe', { endpoint: subscription.endpoint });
                await subscription.unsubscribe();
                status.textContent = 'Browser notifications are off.';
            } else if (action === 'test') {
                await request('test', subscription ? { endpoint: subscription.endpoint } : {});
                status.textContent = 'Test queued for this device.';
            }
        } catch (error) {
            status.textContent = error.name === 'InvalidStateError' ? 'Reload settings and try enabling again.' : error.message;
        } finally {
            busy = false;
            buttons.forEach(button => { button.disabled = false; });
        }
    });
    window.addEventListener('focus', refresh);
    window.addEventListener('relativesnotification', refresh);
    initialize();
})();
