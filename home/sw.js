'use strict';

const CACHE_PREFIX = 'relatives-home-offline-';
// Bump this when any of the offline files change, including shared home CSS or JS.
const OFFLINE_VERSION = '15';
const CACHE_NAME = CACHE_PREFIX + OFFLINE_VERSION;
const OFFLINE_PAGE = '/home/offline.php';

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE_NAME);
        // The public offline page contains its styles and script. Never cache the signed-in dashboard.
        await cache.add(new Request(OFFLINE_PAGE + '?offline=' + OFFLINE_VERSION, {
            cache: 'reload',
            credentials: 'omit',
            redirect: 'error',
        }));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names
            .filter((name) => name.startsWith(CACHE_PREFIX) && name !== CACHE_NAME)
            .map((name) => caches.delete(name)));
        await self.clients.claim();
    })());
});

async function cachedOfflinePage() {
    const cache = await caches.open(CACHE_NAME);
    return cache.match(OFFLINE_PAGE, { ignoreSearch: true });
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    if (request.mode === 'navigate' && ['/home/', '/home/index.php', OFFLINE_PAGE].includes(url.pathname)) {
        event.respondWith(fetch(request).catch(async () =>
            await cachedOfflinePage() || Response.error()
        ));
    }
});
