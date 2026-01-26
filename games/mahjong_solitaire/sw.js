/* =============================================
   MAHJONG SOLITAIRE - Service Worker
   ============================================= */

const CACHE_NAME = 'mahjong-v1';
const ASSETS = [
    '/games/mahjong_solitaire/',
    '/games/mahjong_solitaire/index.php',
    '/games/mahjong_solitaire/assets/css/game.css',
    '/games/mahjong_solitaire/assets/js/layouts.js',
    '/games/mahjong_solitaire/assets/js/audio.js',
    '/games/mahjong_solitaire/assets/js/renderer.js',
    '/games/mahjong_solitaire/assets/js/game.js'
];

// Install
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(ASSETS))
            .then(() => self.skipWaiting())
    );
});

// Activate
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch
self.addEventListener('fetch', event => {
    // Network-first for API calls, cache-first for assets
    if (event.request.url.includes('/api/')) {
        event.respondWith(
            fetch(event.request)
                .catch(() => caches.match(event.request))
        );
    } else {
        event.respondWith(
            caches.match(event.request)
                .then(cached => cached || fetch(event.request))
        );
    }
});
