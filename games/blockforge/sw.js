/* =============================================
   BLOCKFORGE - Service Worker (Offline-First)
   ============================================= */

var CACHE_NAME = 'blockforge-v1.0.0';
var ASSETS = [
    '/games/blockforge/',
    '/games/blockforge/index.php',
    '/games/blockforge/assets/css/game.css',
    '/games/blockforge/assets/js/pieces.js',
    '/games/blockforge/assets/js/storage.js',
    '/games/blockforge/assets/js/audio.js',
    '/games/blockforge/assets/js/particles.js',
    '/games/blockforge/assets/js/renderer.js',
    '/games/blockforge/assets/js/engine.js',
    '/games/blockforge/assets/js/input.js',
    '/games/blockforge/assets/js/api.js',
    '/games/blockforge/assets/js/ui.js',
    '/games/blockforge/assets/js/sharecard.js',
    '/games/blockforge/assets/js/game.js',
    '/games/blockforge/manifest.json'
];

// Install: cache all assets
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return cache.addAll(ASSETS);
        }).then(function() {
            return self.skipWaiting();
        })
    );
});

// Activate: clean old caches
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(keys) {
            return Promise.all(
                keys.filter(function(key) {
                    return key !== CACHE_NAME && key.startsWith('blockforge-');
                }).map(function(key) {
                    return caches.delete(key);
                })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

// Fetch: cache-first for assets, network-first for API
self.addEventListener('fetch', function(event) {
    var url = new URL(event.request.url);

    // API requests: network first, no cache
    if (url.pathname.startsWith('/api/')) {
        event.respondWith(
            fetch(event.request).catch(function() {
                return new Response(JSON.stringify({ error: 'offline' }), {
                    status: 503,
                    headers: { 'Content-Type': 'application/json' }
                });
            })
        );
        return;
    }

    // Game assets: cache first, network fallback
    if (url.pathname.startsWith('/games/blockforge/')) {
        event.respondWith(
            caches.match(event.request).then(function(cached) {
                if (cached) {
                    // Update cache in background
                    fetch(event.request).then(function(response) {
                        if (response && response.status === 200) {
                            caches.open(CACHE_NAME).then(function(cache) {
                                cache.put(event.request, response);
                            });
                        }
                    }).catch(function() {});
                    return cached;
                }
                return fetch(event.request).then(function(response) {
                    if (response && response.status === 200) {
                        var clone = response.clone();
                        caches.open(CACHE_NAME).then(function(cache) {
                            cache.put(event.request, clone);
                        });
                    }
                    return response;
                });
            })
        );
        return;
    }

    // Everything else: network first
    event.respondWith(
        fetch(event.request).catch(function() {
            return caches.match(event.request);
        })
    );
});
