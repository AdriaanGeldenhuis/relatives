/**
 * Tracking Service Worker
 * Handles offline caching for the tracking PWA
 */
var CACHE_NAME = 'tracking-v2';
var CACHE_URLS = [
    '/tracking/app/',
    '/tracking/app/assets/css/tracking.css',
    '/tracking/app/assets/js/state.js',
    '/tracking/app/manifest.json'
];

self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return cache.addAll(CACHE_URLS);
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(names) {
            return Promise.all(
                names.filter(function(name) { return name !== CACHE_NAME; })
                     .map(function(name) { return caches.delete(name); })
            );
        })
    );
    self.clients.claim();
});

self.addEventListener('fetch', function(event) {
    // Skip API calls - always go to network
    if (event.request.url.includes('/api/')) return;

    event.respondWith(
        fetch(event.request).catch(function() {
            return caches.match(event.request);
        })
    );
});

// Handle push notifications
self.addEventListener('push', function(event) {
    var data = event.data ? event.data.json() : {};
    var title = data.title || 'Relatives Tracking';
    var options = {
        body: data.body || data.message || '',
        icon: '/icon-192.png',
        badge: '/icon-192.png',
        data: data.data || {},
        actions: [
            { action: 'open', title: 'Open Map' }
        ]
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    event.waitUntil(clients.openWindow('/tracking/app/'));
});
