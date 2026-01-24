/**
 * Snake Classic - Service Worker
 * Provides offline support with cache-first for static assets
 * and network-first for API calls.
 */

const CACHE_VERSION = 'snake-v1.2.0';
const STATIC_CACHE = CACHE_VERSION + '-static';
const DYNAMIC_CACHE = CACHE_VERSION + '-dynamic';

// Static assets to cache on install
const STATIC_ASSETS = [
    '/games/snake/',
    '/games/snake/index.php',
    '/games/snake/assets/css/snake.css',
    '/games/snake/assets/js/snake.js',
    '/games/snake/assets/js/storage.js',
    '/games/snake/assets/js/api.js',
    '/games/snake/manifest.json'
];

// API paths (network-first)
const API_PATHS = [
    '/api/games/snake/',
    '/api/me.php'
];

/**
 * Install event - cache static assets
 */
self.addEventListener('install', (event) => {
    console.log('[SW] Installing...');

    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => {
                console.log('[SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => {
                console.log('[SW] Install complete');
                return self.skipWaiting();
            })
            .catch((err) => {
                console.error('[SW] Install failed:', err);
            })
    );
});

/**
 * Activate event - clean up old caches
 */
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating...');

    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames
                        .filter((name) => {
                            // Delete caches from old versions
                            return name.startsWith('snake-') &&
                                   name !== STATIC_CACHE &&
                                   name !== DYNAMIC_CACHE;
                        })
                        .map((name) => {
                            console.log('[SW] Deleting old cache:', name);
                            return caches.delete(name);
                        })
                );
            })
            .then(() => {
                console.log('[SW] Activate complete');
                return self.clients.claim();
            })
    );
});

/**
 * Fetch event - handle requests with appropriate strategy
 */
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Only handle same-origin requests
    if (url.origin !== location.origin) {
        return;
    }

    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Check if this is an API request
    const isApiRequest = API_PATHS.some(path => url.pathname.startsWith(path));

    if (isApiRequest) {
        // Network-first for API calls
        event.respondWith(networkFirst(event.request));
    } else {
        // Cache-first for static assets
        event.respondWith(cacheFirst(event.request));
    }
});

/**
 * Stale-while-revalidate strategy
 * Return cached immediately if available, fetch in background to update cache
 */
async function cacheFirst(request) {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(request);

    const fetchPromise = fetch(request).then((networkResponse) => {
        if (networkResponse && networkResponse.ok) {
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    }).catch(() => null);

    // Return cached immediately if exists, otherwise wait for network
    if (cached) {
        return cached;
    }

    const networkResponse = await fetchPromise;
    if (networkResponse) {
        return networkResponse;
    }

    // Offline fallback
    return caches.match('/games/snake/');
}

/**
 * Network-first strategy
 * Try network, fall back to cache
 */
async function networkFirst(request) {
    try {
        const networkResponse = await fetch(request);

        // Cache successful responses
        if (networkResponse.ok) {
            const cache = await caches.open(DYNAMIC_CACHE);
            cache.put(request, networkResponse.clone());
        }

        return networkResponse;
    } catch (error) {
        console.log('[SW] Network failed, trying cache:', request.url);

        // Try cache
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }

        // Return error response for API
        return new Response(
            JSON.stringify({ error: 'Offline', offline: true }),
            {
                status: 503,
                statusText: 'Service Unavailable',
                headers: { 'Content-Type': 'application/json' }
            }
        );
    }
}

/**
 * Handle messages from main thread
 */
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
