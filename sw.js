/* Background notifications for the Relatives web app. */
function sameOriginPath(value) {
    if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')) {
        return '/notifications/';
    }
    const url = new URL(value, self.location.origin);
    return url.origin === self.location.origin ? url.pathname + url.search + url.hash : '/notifications/';
}

self.addEventListener('push', event => {
    event.waitUntil((async () => {
        let payload;
        try {
            payload = event.data?.json();
        } catch {
            return;
        }
        if (!payload?.receipt || typeof payload.notification_id !== 'string') return;
        let verified = false;
        try {
            const response = await fetch('/api/push/receipt.php', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ receipt: payload.receipt }),
                signal: AbortSignal.timeout(3000)
            });
            verified = response.ok;
            if ([401, 404].includes(response.status)) return;
        } catch {
            // The browser can still surface an account-neutral alert while the site reconnects.
        }
        if (!verified && payload.location_alert === '1') return;
        await self.registration.showNotification(verified ? payload.title : 'Relatives', {
            body: verified ? payload.body : 'Open Relatives to see your updates',
            icon: '/icon-192.png',
            tag: 'relatives-' + payload.notification_id,
            silent: payload.silent === '1',
            data: { path: verified ? sameOriginPath(payload.action_url) : '/notifications/' }
        });
        if (verified) {
            const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            clients.forEach(client => client.postMessage({ type: 'relatives-notification' }));
        }
    })());
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil((async () => {
        const target = new URL(sameOriginPath(event.notification.data?.path), self.location.origin).href;
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const open = clients.find(client => client.url === target);
        if (open) return open.focus();
        return self.clients.openWindow(target);
    })());
});
