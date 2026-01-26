/**
 * Browser Location Tracking Module
 *
 * Enables web browser-based location tracking as a fallback
 * when native app tracking is not available.
 *
 * Features:
 * - Requests geolocation permission on init
 * - Uploads current user's location every 30 seconds
 * - Stops when tab is hidden, resumes when visible
 * - Skips if native Android app is tracking
 */

window.BrowserTracking = {
    watchId: null,
    lastUploadTime: 0,
    UPLOAD_INTERVAL_MS: 30000, // 30 seconds
    MAX_ACCURACY_M: 500, // Only upload if accuracy better than 500m

    init() {
        // Only run in browser context
        if (!navigator.geolocation) {
            console.log('Browser geolocation not supported');
            return;
        }

        // Skip if native app is handling tracking
        // Check TrackingBridge (Android WebView interface)
        const nativeInterface = window.TrackingBridge || window.Android;
        if (nativeInterface?.isTrackingEnabled && nativeInterface.isTrackingEnabled()) {
            console.log('Native app tracking active - skipping browser geolocation');
            return;
        }

        // Start tracking when map is ready
        window.addEventListener('map:ready', () => this.start());

        // Handle visibility changes
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stop();
            } else {
                this.start();
            }
        });
    },

    start() {
        if (this.watchId !== null) return;

        console.log('Starting browser location tracking...');

        // Get initial position
        navigator.geolocation.getCurrentPosition(
            (position) => {
                console.log('Browser geolocation permission granted');
                this.uploadLocation(position);
                this.startWatch();
            },
            (error) => {
                console.warn('Browser geolocation error:', error.message);
                if (error.code === error.PERMISSION_DENIED) {
                    Toast.show('Enable location to see yourself on map', 'info');
                }
            },
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 60000
            }
        );
    },

    startWatch() {
        if (this.watchId !== null) return;

        this.watchId = navigator.geolocation.watchPosition(
            (position) => {
                const now = Date.now();
                if (now - this.lastUploadTime >= this.UPLOAD_INTERVAL_MS) {
                    this.uploadLocation(position);
                }
            },
            (error) => {
                console.warn('Browser location watch error:', error.message);
            },
            {
                enableHighAccuracy: true,
                timeout: 30000,
                maximumAge: 30000
            }
        );

        console.log('Browser location watch started');
    },

    stop() {
        if (this.watchId !== null) {
            navigator.geolocation.clearWatch(this.watchId);
            this.watchId = null;
            console.log('Browser location watch stopped');
        }
    },

    async uploadLocation(position) {
        const accuracy = Math.round(position.coords.accuracy) || 9999;

        // Skip if accuracy is too poor
        if (accuracy > this.MAX_ACCURACY_M) {
            console.log(`Browser location skipped: accuracy ${accuracy}m > ${this.MAX_ACCURACY_M}m threshold`);
            return;
        }

        this.lastUploadTime = Date.now();

        const locationData = {
            lat: position.coords.latitude,
            lng: position.coords.longitude,
            accuracy_m: Math.round(position.coords.accuracy) || 50,
            speed_mps: position.coords.speed || 0,
            bearing_deg: position.coords.heading || null,
            altitude_m: position.coords.altitude ? Math.round(position.coords.altitude) : null,
            device_id: 'web-browser-' + (window.TRACKING_CONFIG?.userId || 'unknown'),
            platform: 'web',
            app_version: 'web-1.0'
        };

        try {
            const response = await TrackingAPI.sendLocation(locationData);

            if (response.success) {
                console.log(`Browser location uploaded: ${locationData.lat.toFixed(5)}, ${locationData.lng.toFixed(5)} (±${locationData.accuracy_m}m)`);
            } else {
                console.warn('Location upload rejected:', response.message || response.error);
            }
        } catch (error) {
            // Don't spam errors - network issues are expected sometimes
            if (error.status !== 429) { // Ignore rate limit errors
                console.error('Failed to upload browser location:', error.message || error);
            }
        }
    }
};
