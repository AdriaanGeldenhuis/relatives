/**
 * ============================================
 * FLASH CHALLENGE - Service Worker
 * Offline support and caching
 * ============================================
 */

const CACHE_NAME = 'flash-challenge-v1.0.2';
const STATIC_ASSETS = [
    '/games/flash_challenge/',
    '/games/flash_challenge/index.php',
    '/games/flash_challenge/assets/css/flash.css',
    '/games/flash_challenge/assets/js/storage.js',
    '/games/flash_challenge/assets/js/api.js',
    '/games/flash_challenge/assets/js/voice.js',
    '/games/flash_challenge/assets/js/animations.js',
    '/games/flash_challenge/assets/js/ui.js',
    '/games/flash_challenge/assets/js/flash.js',
    '/games/flash_challenge/manifest.json'
];

const API_CACHE_NAME = 'flash-api-v1';
const API_ENDPOINTS = [
    '/api/games/flash/get_daily.php',
    '/api/games/flash/leaderboard.php',
    '/api/games/flash/history.php'
];

/**
 * Install event - cache static assets
 */
self.addEventListener('install', event => {
    console.log('[Flash SW] Installing...');

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('[Flash SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => {
                console.log('[Flash SW] Install complete');
                return self.skipWaiting();
            })
            .catch(err => {
                console.error('[Flash SW] Install failed:', err);
            })
    );
});

/**
 * Activate event - clean old caches
 */
self.addEventListener('activate', event => {
    console.log('[Flash SW] Activating...');

    event.waitUntil(
        caches.keys()
            .then(cacheNames => {
                return Promise.all(
                    cacheNames
                        .filter(name => {
                            return name.startsWith('flash-') &&
                                   name !== CACHE_NAME &&
                                   name !== API_CACHE_NAME;
                        })
                        .map(name => {
                            console.log('[Flash SW] Deleting old cache:', name);
                            return caches.delete(name);
                        })
                );
            })
            .then(() => {
                console.log('[Flash SW] Activate complete');
                return self.clients.claim();
            })
    );
});

/**
 * Fetch event - serve from cache, fallback to network
 */
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Skip external URLs
    if (url.origin !== location.origin) {
        return;
    }

    // Handle API requests differently
    if (url.pathname.startsWith('/api/games/flash/')) {
        event.respondWith(handleAPIRequest(event.request));
        return;
    }

    // Handle static assets
    if (isStaticAsset(url.pathname)) {
        event.respondWith(handleStaticRequest(event.request));
        return;
    }

    // Default: network first, cache fallback
    event.respondWith(
        fetch(event.request)
            .then(response => {
                // Clone and cache successful responses
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => {
                        cache.put(event.request, clone);
                    });
                }
                return response;
            })
            .catch(() => {
                return caches.match(event.request);
            })
    );
});

/**
 * Check if URL is a static asset
 */
function isStaticAsset(pathname) {
    return pathname.startsWith('/games/flash_challenge/') &&
           (pathname.endsWith('.css') ||
            pathname.endsWith('.js') ||
            pathname.endsWith('.json') ||
            pathname.endsWith('.png') ||
            pathname.endsWith('.svg') ||
            pathname.endsWith('.ico'));
}

/**
 * Handle static asset requests
 * Strategy: Cache first, network fallback
 */
async function handleStaticRequest(request) {
    const cached = await caches.match(request);

    if (cached) {
        // Return cached, but fetch in background to update
        fetchAndCache(request);
        return cached;
    }

    return fetchAndCache(request);
}

/**
 * Fetch and cache a request
 */
async function fetchAndCache(request) {
    try {
        const response = await fetch(request);

        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }

        return response;
    } catch (err) {
        // Try cache as last resort
        const cached = await caches.match(request);
        if (cached) {
            return cached;
        }
        throw err;
    }
}

/**
 * Handle API requests
 * Strategy: Network first with short timeout, cache fallback
 */
async function handleAPIRequest(request) {
    const url = new URL(request.url);

    // Only cache GET requests to specific endpoints
    const cacheable = API_ENDPOINTS.some(ep => url.pathname.includes(ep));

    if (!cacheable) {
        return fetch(request);
    }

    try {
        // Try network with timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 5000);

        const response = await fetch(request, { signal: controller.signal });
        clearTimeout(timeoutId);

        if (response.ok) {
            // Cache the response
            const cache = await caches.open(API_CACHE_NAME);
            cache.put(request, response.clone());
        }

        return response;

    } catch (err) {
        console.log('[Flash SW] Network failed, trying cache:', url.pathname);

        // Try cache
        const cached = await caches.match(request);

        if (cached) {
            // Add header to indicate cached response
            const headers = new Headers(cached.headers);
            headers.set('X-Cache-Status', 'HIT');

            return new Response(cached.body, {
                status: cached.status,
                statusText: cached.statusText,
                headers: headers
            });
        }

        // Return error response
        return new Response(JSON.stringify({
            success: false,
            error: 'You are offline and no cached data is available',
            offline: true
        }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' }
        });
    }
}

/**
 * Handle messages from main thread
 */
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }

    if (event.data && event.data.type === 'CLEAR_CACHE') {
        caches.keys().then(names => {
            names.forEach(name => {
                if (name.startsWith('flash-')) {
                    caches.delete(name);
                }
            });
        });
    }
});

/**
 * Background sync for offline attempts
 */
self.addEventListener('sync', event => {
    if (event.tag === 'flash-sync-attempts') {
        event.waitUntil(syncAttempts());
    }
});

/**
 * Sync queued attempts
 */
async function syncAttempts() {
    // This would be called by background sync
    // The actual sync logic is in api.js (client-side)
    // This is a placeholder for future enhancement

    console.log('[Flash SW] Background sync triggered');

    // Notify clients to sync
    const clients = await self.clients.matchAll();
    clients.forEach(client => {
        client.postMessage({ type: 'SYNC_ATTEMPTS' });
    });
}

/**
 * Push notification handler (future feature)
 */
self.addEventListener('push', event => {
    if (!event.data) return;

    const data = event.data.json();

    if (data.type === 'daily-challenge') {
        event.waitUntil(
            self.registration.showNotification('⚡ Flash Challenge', {
                body: data.message || 'A new challenge is ready!',
                icon: '/games/flash_challenge/assets/img/icon-192.png',
                badge: '/games/flash_challenge/assets/img/badge-72.png',
                tag: 'flash-daily',
                requireInteraction: false,
                actions: [
                    { action: 'play', title: 'Play Now' },
                    { action: 'dismiss', title: 'Later' }
                ]
            })
        );
    }
});

/**
 * Notification click handler
 */
self.addEventListener('notificationclick', event => {
    event.notification.close();

    if (event.action === 'play' || !event.action) {
        event.waitUntil(
            clients.openWindow('/games/flash_challenge/')
        );
    }
});

console.log('[Flash SW] Service worker loaded');
