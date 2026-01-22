/**
 * 30 Seconds Party - Offline Mode Service Worker
 * Provides complete offline functionality for airplane mode play
 */

const CACHE_VERSION = 'v1.0.0';
const CACHE_NAME = `thirty-seconds-offline-${CACHE_VERSION}`;

// Static assets to cache for offline use
const STATIC_ASSETS = [
    '/games/30_seconds_offline/',
    '/games/30_seconds_offline/index.php',
    '/games/30_seconds_offline/lobby.php',
    '/games/30_seconds_offline/turn.php',
    '/games/30_seconds_offline/scoreboard.php',
    '/games/30_seconds_offline/results.php',
    '/games/30_seconds_offline/view.php',
    '/games/30_seconds_offline/assets/css/offline.css',
    '/games/30_seconds_offline/assets/js/app.js',
    '/games/30_seconds_offline/assets/js/state.js',
    '/games/30_seconds_offline/assets/js/speech.js',
    '/games/30_seconds_offline/assets/js/matcher.js',
    '/games/30_seconds_offline/assets/js/timer.js',
    '/games/30_seconds_offline/assets/js/ui.js',
    '/games/30_seconds_offline/assets/js/sharecard.js',
    '/games/30_seconds_offline/prompts/prompts.json',
    '/games/30_seconds_offline/manifest.json'
];

// Install event - cache all static assets
self.addEventListener('install', (event) => {
    console.log('[SW] Installing 30 Seconds Offline service worker...');

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[SW] Caching static assets...');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => {
                console.log('[SW] All assets cached successfully');
                return self.skipWaiting();
            })
            .catch((error) => {
                console.error('[SW] Failed to cache assets:', error);
            })
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating 30 Seconds Offline service worker...');

    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames
                        .filter((cacheName) => {
                            // Delete old caches for this game
                            return cacheName.startsWith('thirty-seconds-offline-') &&
                                   cacheName !== CACHE_NAME;
                        })
                        .map((cacheName) => {
                            console.log('[SW] Deleting old cache:', cacheName);
                            return caches.delete(cacheName);
                        })
                );
            })
            .then(() => {
                console.log('[SW] Taking control of all clients');
                return self.clients.claim();
            })
    );
});

// Fetch event - serve from cache first, then network
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Only handle requests to our game
    if (!url.pathname.startsWith('/games/30_seconds_offline/')) {
        return;
    }

    event.respondWith(
        caches.match(event.request)
            .then((cachedResponse) => {
                if (cachedResponse) {
                    // Return cached response
                    console.log('[SW] Serving from cache:', url.pathname);
                    return cachedResponse;
                }

                // Not in cache, try network
                console.log('[SW] Fetching from network:', url.pathname);
                return fetch(event.request)
                    .then((networkResponse) => {
                        // Cache the new response for future offline use
                        if (networkResponse.ok) {
                            const responseClone = networkResponse.clone();
                            caches.open(CACHE_NAME)
                                .then((cache) => {
                                    cache.put(event.request, responseClone);
                                });
                        }
                        return networkResponse;
                    })
                    .catch((error) => {
                        console.error('[SW] Network fetch failed:', error);

                        // Return a fallback offline page
                        return new Response(
                            createOfflineFallbackHTML(),
                            {
                                status: 200,
                                headers: { 'Content-Type': 'text/html' }
                            }
                        );
                    });
            })
    );
});

// Create an offline fallback HTML page
function createOfflineFallbackHTML() {
    return `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>30 Seconds - Offline</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #fff;
        }
        .container {
            text-align: center;
            max-width: 400px;
        }
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.8;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 16px;
        }
        p {
            color: rgba(255,255,255,0.7);
            margin-bottom: 24px;
            line-height: 1.6;
        }
        button {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 14px 32px;
            font-size: 16px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
        }
        button:active {
            transform: scale(0.98);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">📴</div>
        <h1>Page Not Cached</h1>
        <p>This page hasn't been cached yet. Please connect to the internet once to cache all game files, then you can play offline.</p>
        <button onclick="location.reload()">Try Again</button>
    </div>
</body>
</html>
    `;
}

// Listen for messages from the main app
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }

    if (event.data && event.data.type === 'GET_CACHE_STATUS') {
        caches.open(CACHE_NAME)
            .then((cache) => cache.keys())
            .then((keys) => {
                event.ports[0].postMessage({
                    cached: keys.length,
                    total: STATIC_ASSETS.length,
                    ready: keys.length >= STATIC_ASSETS.length
                });
            });
    }
});
