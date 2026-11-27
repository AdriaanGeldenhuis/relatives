/* Background notifications for the Relatives web app. */
self.addEventListener('install', event => event.waitUntil(self.skipWaiting()));

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
            data: {
                path: verified ? sameOriginPath(payload.action_url) : '/notifications/',
                messageId: verified && /^\d+$/.test(payload.message_id) ? payload.message_id : null
            }
        });
        if (verified) {
            const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            clients.forEach(client => client.postMessage({ type: 'relatives-notification' }));
        }
    })());
});

self.addEventListener('message', event => {
    if (event.data?.type !== 'relatives-messages-read' || !Array.isArray(event.data.ids)) return;
    const ids = new Set(event.data.ids);
    event.waitUntil((async () => {
        const notifications = await self.registration.getNotifications();
        for (const notification of notifications) {
            if (ids.has(notification.data?.messageId)) notification.close();
        }
    })());
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil((async () => {
        const target = new URL(sameOriginPath(event.notification.data?.path), self.location.origin);
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        if (target.pathname === '/messages/') {
            const chat = clients.find(client => new URL(client.url).pathname === target.pathname);
            const focused = await chat?.navigate(target.href).catch(() => null);
            if (focused) return focused.focus();
        }
        const open = clients.find(client => client.url === target.href);
        if (open) return open.focus();
        return self.clients.openWindow(target.href);
    })());
});
