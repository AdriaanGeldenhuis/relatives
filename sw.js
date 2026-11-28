/* Background notifications for the Relatives web app. */
self.addEventListener('install', event => event.waitUntil(self.skipWaiting()));

function sameOriginPath(value) {
    if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')) {
        return '/notifications/';
    }
    const url = new URL(value, self.location.origin);
    return url.origin === self.location.origin ? url.pathname + url.search + url.hash : '/notifications/';
}

function pushReceipt(receipt, action) {
    return fetch('/api/push/receipt.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ receipt, action }),
        signal: AbortSignal.timeout(3500)
    });
}

async function closeMessageAlerts(ids) {
    const notifications = await self.registration.getNotifications();
    for (const notification of notifications) {
        if (ids.has(notification.data?.messageId)) notification.close();
    }
}

async function refreshOpenPages() {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    clients.forEach(client => client.postMessage({ type: 'relatives-notification' }));
}

function openInChat(client, id) {
    return new Promise(resolve => {
        const channel = new MessageChannel();
        const finish = opened => {
            clearTimeout(timer);
            channel.port1.close();
            resolve(opened);
        };
        const timer = setTimeout(() => finish(false), 450);
        channel.port1.onmessage = event => finish(event.data === true);
        try {
            client.postMessage({ type: 'relatives-open-message', id }, [channel.port2]);
        } catch {
            finish(false);
        }
    });
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
            const response = await pushReceipt(payload.receipt);
            verified = response.ok;
            if ([401, 404].includes(response.status)) return;
        } catch {
            // The browser can still surface an account-neutral alert while the site reconnects.
        }
        if (!verified && payload.location_alert === '1') return;
        const messageId = verified && typeof payload.message_id === 'string' && /^[1-9][0-9]*$/.test(payload.message_id)
            ? payload.message_id : null;
        await self.registration.showNotification(verified ? payload.title : 'Relatives', {
            body: verified ? payload.body : 'Open Relatives to see your updates',
            icon: '/icon-192.png',
            tag: 'relatives-' + payload.notification_id,
            silent: payload.silent === '1',
            actions: messageId ? [{ action: 'mark-read', title: 'Mark as read' }] : [],
            data: {
                path: verified ? sameOriginPath(payload.action_url) : '/notifications/',
                messageId,
                receipt: messageId ? payload.receipt : null
            }
        });
        if (verified) await refreshOpenPages();
    })());
});

self.addEventListener('message', event => {
    if (event.data?.type !== 'relatives-messages-read' || !Array.isArray(event.data.ids)) return;
    event.waitUntil(closeMessageAlerts(new Set(event.data.ids)));
});

self.addEventListener('notificationclick', event => {
    if (event.action === 'mark-read') {
        event.waitUntil((async () => {
            const { receipt, messageId } = event.notification.data || {};
            if (!receipt || !messageId) return;
            const response = await pushReceipt(receipt, 'mark_read').catch(() => null);
            if (response?.ok) {
                event.notification.close();
                await closeMessageAlerts(new Set([messageId]));
                await refreshOpenPages();
            }
        })());
        return;
    }
    event.notification.close();
    event.waitUntil((async () => {
        const target = new URL(sameOriginPath(event.notification.data?.path), self.location.origin);
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        if (target.pathname === '/messages/') {
            const chat = clients.find(client => new URL(client.url).pathname === target.pathname);
            const id = target.searchParams.get('message');
            if (chat && id && await openInChat(chat, id)) return chat.focus();
            const focused = await chat?.navigate(target.href).catch(() => null);
            if (focused) return focused.focus();
        }
        const open = clients.find(client => client.url === target.href);
        if (open) return open.focus();
        return self.clients.openWindow(target.href);
    })());
});
