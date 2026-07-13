/**
 * ============================================
 * FAMILY TRACKING - SERVICE WORKER
 * Cache app shell, network-first for API,
 * cache-first for static assets, offline fallback.
 * ============================================
 */

const CACHE_VERSION = 'tracking-v1.2.0';
const STATIC_CACHE = 'tracking-static-' + CACHE_VERSION;
const API_CACHE = 'tracking-api-' + CACHE_VERSION;

/**
 * App shell files to pre-cache on install — static assets ONLY. The PHP
 * pages are rendered per-user with the session's data (Mapbox token, family
 * ids, geofence coordinates) embedded; pre-caching them kept one user's
 * private family data on disk after logout and could serve it to the next
 * login on a shared device. Navigations stay network-first with the offline
 * page as fallback.
 */
const APP_SHELL = [
    '/tracking/app/offline.html',
    '/tracking/app/assets/css/tracking.css',
    '/tracking/app/assets/js/state.js',
    '/tracking/app/manifest.json',
    '/icon-192.png',
    '/icon-512.png'
];

/**
 * Static asset URL patterns (cache-first).
 */
const STATIC_PATTERNS = [
    /\.css(\?|$)/,
    /\.js(\?|$)/,
    /\.png(\?|$)/,
    /\.jpg(\?|$)/,
    /\.jpeg(\?|$)/,
    /\.webp(\?|$)/,
    /\.svg(\?|$)/,
    /\.woff2?(\?|$)/,
    /fonts\.googleapis\.com/,
    /fonts\.gstatic\.com/,
    /api\.mapbox\.com\/mapbox-gl-js/
];

/**
 * API URL patterns (network-first).
 */
const API_PATTERNS = [
    /\/tracking\/api\//,
    /\/api\//
];

// ============================================
// INSTALL
// ============================================
self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(function (cache) {
            return cache.addAll(APP_SHELL).catch(function (err) {
                console.warn('[SW] Pre-cache partial failure:', err);
            });
        })
    );
    self.skipWaiting();
});

// ============================================
// ACTIVATE - clean old caches
// ============================================
self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys
                    .filter(function (key) {
                        return key !== STATIC_CACHE && key !== API_CACHE;
                    })
                    .map(function (key) {
                        return caches.delete(key);
                    })
            );
        })
    );
    self.clients.claim();
});

// ============================================
// FETCH
// ============================================
self.addEventListener('fetch', function (event) {
    var request = event.request;

    // Skip non-GET requests
    if (request.method !== 'GET') {
        return;
    }

    var url = request.url;

    // Tracking API: network only, never cached. Every endpoint returns
    // private family data (exact geofence/home coordinates, movement events,
    // live positions); persisting it to Cache Storage left one user's data on
    // disk after logout and could serve it to the next login on a shared
    // device. It was also a disk write per poll (battery).
    if (url.indexOf('/tracking/api/') !== -1) {
        return;
    }

    // Other API calls: network-first with cache fallback
    if (isApiRequest(url)) {
        event.respondWith(networkFirst(request, API_CACHE));
        return;
    }

    // Static assets: cache-first with network fallback
    if (isStaticAsset(url)) {
        event.respondWith(cacheFirst(request, STATIC_CACHE));
        return;
    }

    // Navigation requests: network-first with offline fallback
    if (request.mode === 'navigate') {
        event.respondWith(navigationHandler(request));
        return;
    }

    // Default: network with cache fallback
    event.respondWith(networkFirst(request, STATIC_CACHE));
});

// ============================================
// STRATEGIES
// ============================================

/**
 * Network-first: try network, fall back to cache.
 */
function networkFirst(request, cacheName) {
    return fetch(request)
        .then(function (response) {
            if (response && response.status === 200) {
                var clone = response.clone();
                caches.open(cacheName).then(function (cache) {
                    cache.put(request, clone);
                });
            }
            return response;
        })
        .catch(function () {
            return caches.match(request);
        });
}

/**
 * Cache-first: try cache, fall back to network.
 */
function cacheFirst(request, cacheName) {
    return caches.match(request).then(function (cached) {
        if (cached) {
            // Refresh cache in background
            fetch(request).then(function (response) {
                if (response && response.status === 200) {
                    caches.open(cacheName).then(function (cache) {
                        cache.put(request, response);
                    });
                }
            }).catch(function () {});
            return cached;
        }
        return fetch(request).then(function (response) {
            if (response && response.status === 200) {
                var clone = response.clone();
                caches.open(cacheName).then(function (cache) {
                    cache.put(request, clone);
                });
            }
            return response;
        });
    });
}

/**
 * Navigation handler: network with offline fallback page. Authenticated
 * PHP pages are never persisted (see APP_SHELL note) — offline users get
 * the offline page, not a stale copy of someone's family data.
 */
function navigationHandler(request) {
    return fetch(request)
        .catch(function () {
            return caches.match('/tracking/app/offline.html');
        });
}

// ============================================
// HELPERS
// ============================================

function isStaticAsset(url) {
    return STATIC_PATTERNS.some(function (pattern) {
        return pattern.test(url);
    });
}

function isApiRequest(url) {
    return API_PATTERNS.some(function (pattern) {
        return pattern.test(url);
    });
}
