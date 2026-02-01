/**
 * Service Worker for Family Tracking App
 *
 * Provides:
 * - Asset caching for faster loads
 * - Offline awareness (shows message when offline)
 * - Background sync for location uploads (when supported)
 */

const CACHE_NAME = 'tracking-v6';
const OFFLINE_URL = '/tracking/app/offline.html';

// Assets to cache on install
const PRECACHE_ASSETS = [
    '/tracking/app/assets/css/tracking.css',
    '/tracking/app/assets/js/api.js',
    '/tracking/app/assets/js/bootstrap.js',
    '/tracking/app/assets/js/browser-tracking.js',
    '/tracking/app/assets/js/directions.js',
    '/tracking/app/assets/js/family-panel.js',
    '/tracking/app/assets/js/follow.js',
    '/tracking/app/assets/js/format.js',
    '/tracking/app/assets/js/map.js',
    '/tracking/app/assets/js/native-bridge.js',
    '/tracking/app/assets/js/polling.js',
    '/tracking/app/assets/js/state.js',
    '/tracking/app/assets/js/ui-controls.js',
    OFFLINE_URL
];

// External resources to cache on first use
const CACHE_ON_USE = [
    'https://api.mapbox.com/mapbox-gl-js/',
    'https://fonts.googleapis.com/',
    'https://fonts.gstatic.com/'
];

// Install event - cache core assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[SW] Precaching assets');
                return cache.addAll(PRECACHE_ASSETS);
            })
            .then(() => self.skipWaiting())
    );
});

// Activate event - clean old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames
                        .filter((name) => name !== CACHE_NAME)
                        .map((name) => {
                            console.log('[SW] Deleting old cache:', name);
                            return caches.delete(name);
                        })
                );
            })
            .then(() => self.clients.claim())
    );
});

// Fetch event - serve from cache, fallback to network
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET requests
    if (request.method !== 'GET') {
        return;
    }

    // API requests - network only, queue if offline
    if (url.pathname.startsWith('/tracking/api/')) {
        event.respondWith(
            fetch(request)
                .catch(() => {
                    // Return error response for API calls when offline
                    return new Response(
                        JSON.stringify({
                            success: false,
                            error: 'offline',
                            message: 'You are offline. Changes will sync when reconnected.'
                        }),
                        {
                            status: 503,
                            headers: { 'Content-Type': 'application/json' }
                        }
                    );
                })
        );
        return;
    }

    // Static assets - cache first, then network
    if (shouldCacheFirst(url)) {
        event.respondWith(
            caches.match(request)
                .then((cached) => {
                    if (cached) {
                        // Return cached, but update in background
                        event.waitUntil(updateCache(request));
                        return cached;
                    }
                    return fetchAndCache(request);
                })
        );
        return;
    }

    // HTML pages - network first, fallback to offline page
    if (request.headers.get('Accept')?.includes('text/html')) {
        event.respondWith(
            fetch(request)
                .catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    // Default - network with cache fallback
    event.respondWith(
        fetch(request)
            .then((response) => {
                // Cache external resources on use
                if (shouldCacheOnUse(url)) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME)
                        .then((cache) => cache.put(request, responseClone));
                }
                return response;
            })
            .catch(() => caches.match(request))
    );
});

// Helper: should use cache-first strategy
function shouldCacheFirst(url) {
    return url.pathname.match(/\.(js|css|png|jpg|svg|woff2?)$/i) ||
           PRECACHE_ASSETS.some(asset => url.pathname.endsWith(asset));
}

// Helper: should cache on first use
function shouldCacheOnUse(url) {
    return CACHE_ON_USE.some(prefix => url.href.startsWith(prefix));
}

// Helper: fetch and add to cache
async function fetchAndCache(request) {
    const response = await fetch(request);
    if (response.ok) {
        const cache = await caches.open(CACHE_NAME);
        cache.put(request, response.clone());
    }
    return response;
}

// Helper: update cached resource in background
async function updateCache(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            await cache.put(request, response);
        }
    } catch (e) {
        // Ignore errors - we have cached version
    }
}

// Background sync for location uploads
self.addEventListener('sync', (event) => {
    if (event.tag === 'location-sync') {
        event.waitUntil(syncLocations());
    }
});

async function syncLocations() {
    // Get queued locations from IndexedDB
    const db = await openLocationDB();
    const tx = db.transaction('pending', 'readonly');
    const store = tx.objectStore('pending');
    const locations = await store.getAll();

    if (locations.length === 0) return;

    try {
        const response = await fetch('/tracking/api/batch.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ locations }),
            credentials: 'same-origin'
        });

        if (response.ok) {
            // Clear synced locations
            const clearTx = db.transaction('pending', 'readwrite');
            await clearTx.objectStore('pending').clear();
            console.log('[SW] Synced', locations.length, 'locations');
        }
    } catch (e) {
        console.error('[SW] Location sync failed:', e);
    }
}

function openLocationDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('tracking-offline', 1);
        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);
        request.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains('pending')) {
                db.createObjectStore('pending', { keyPath: 'id', autoIncrement: true });
            }
        };
    });
}

// Message handler for client communication
self.addEventListener('message', (event) => {
    if (event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
