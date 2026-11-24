'use strict';

const CACHE_PREFIX = 'relatives-home-offline-';
// Bump this when any of the offline files change, including shared home CSS or JS.
const OFFLINE_VERSION = '7';
const CACHE_NAME = CACHE_PREFIX + OFFLINE_VERSION;
const OFFLINE_PAGE = '/home/offline.html';
const STATIC_ASSETS = [OFFLINE_PAGE, '/home/css/home.css', '/shared/css/family-surfaces.css', '/home/js/home.js', '/favicon.ico'];

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE_NAME);
        // Fetch only public files. Dashboard responses and session redirects are never cached.
        await cache.addAll(STATIC_ASSETS.map((url) => new Request(url + '?offline=' + OFFLINE_VERSION, {
            cache: 'reload',
            credentials: 'omit',
            redirect: 'error',
        })));
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

async function cachedAsset(path) {
    const cache = await caches.open(CACHE_NAME);
    return cache.match(path, { ignoreSearch: true });
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    if (request.mode === 'navigate' && ['/home/', '/home/index.php'].includes(url.pathname)) {
        event.respondWith(fetch(request).catch(async () =>
            await cachedAsset(OFFLINE_PAGE) || Response.error()
        ));
        return;
    }

    if (!url.search && STATIC_ASSETS.includes(url.pathname)) {
        event.respondWith((async () => await cachedAsset(url.pathname) || fetch(request))());
    }
});
