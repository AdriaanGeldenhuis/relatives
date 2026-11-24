/* Cache public tracking assets only. Family data always uses the network. */
const CACHE = 'tracking-static-v1.3.1';
const OFFLINE_URL = '/tracking/app/offline.html';
const PUBLIC_FILES = new Set([
    OFFLINE_URL,
    '/tracking/app/assets/css/tracking.css',
    '/tracking/app/assets/js/state.js',
    '/tracking/app/manifest.json',
    '/icon-192.png',
    '/icon-512.png',
]);

self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE).then(cache => cache.addAll([...PUBLIC_FILES])));
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        for (const key of await caches.keys()) {
            if (key.startsWith('tracking-') && key !== CACHE) await caches.delete(key);
        }
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    if (url.pathname.startsWith('/tracking/app/') && request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(async () => (await caches.open(CACHE)).match(OFFLINE_URL)));
        return;
    }

    if (!PUBLIC_FILES.has(url.pathname) && !url.pathname.startsWith('/tracking/app/assets/')) return;
    event.respondWith((async () => {
        const cache = await caches.open(CACHE);
        const cached = await cache.match(request);
        if (cached) return cached;
        const response = await fetch(request);
        if (response.ok) await cache.put(request, response.clone());
        return response;
    })());
});
