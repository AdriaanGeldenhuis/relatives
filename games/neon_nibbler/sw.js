/**
 * NEON NIBBLER - Service Worker
 * Offline-first caching strategy
 */
var CACHE_NAME = 'neon-nibbler-v1';
var ASSETS = [
    '/games/neon_nibbler/',
    '/games/neon_nibbler/index.php',
    '/games/neon_nibbler/assets/css/game.css',
    '/games/neon_nibbler/assets/js/game.js',
    '/games/neon_nibbler/assets/js/engine.js',
    '/games/neon_nibbler/assets/js/levels.js',
    '/games/neon_nibbler/assets/js/input.js',
    '/games/neon_nibbler/assets/js/audio.js',
    '/games/neon_nibbler/assets/js/storage.js',
    '/games/neon_nibbler/assets/js/api.js',
    '/games/neon_nibbler/assets/js/ui.js',
    '/games/neon_nibbler/assets/js/particles.js',
    '/games/neon_nibbler/manifest.json'
];

self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return cache.addAll(ASSETS);
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(keys) {
            return Promise.all(
                keys.filter(function(key) {
                    return key !== CACHE_NAME;
                }).map(function(key) {
                    return caches.delete(key);
                })
            );
        })
    );
    self.clients.claim();
});

self.addEventListener('fetch', function(event) {
    var url = new URL(event.request.url);

    // API requests: network first, no cache
    if (url.pathname.indexOf('/api/') === 0) {
        event.respondWith(
            fetch(event.request).catch(function() {
                return new Response(JSON.stringify({ ok: false, error: 'offline' }), {
                    headers: { 'Content-Type': 'application/json' }
                });
            })
        );
        return;
    }

    // Assets: cache first, then network
    event.respondWith(
        caches.match(event.request).then(function(cached) {
            if (cached) return cached;
            return fetch(event.request).then(function(response) {
                if (response.status === 200) {
                    var clone = response.clone();
                    caches.open(CACHE_NAME).then(function(cache) {
                        cache.put(event.request, clone);
                    });
                }
                return response;
            });
        })
    );
});
