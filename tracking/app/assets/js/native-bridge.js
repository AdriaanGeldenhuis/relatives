/**
 * Native App Bridge Module
 *
 * Provides a unified interface for communicating with native app shells:
 * - Android WebView via window.TrackingBridge (za.co.relatives.app)
 * - Android WebView via window.Android (com.relatives.app)
 * - iOS WKWebView (window.webkit.messageHandlers)
 * - Capacitor/Ionic (window.Capacitor)
 *
 * Falls back gracefully when running in a standard browser.
 */

window.NativeBridge = {
    // Platform detection flags
    platform: 'web',
    isNativeApp: false,
    isAndroid: false,
    isIOS: false,
    isCapacitor: false,
    isPWA: false,

    // Reference to the Android bridge object (TrackingBridge or Android)
    _androidBridge: null,

    /**
     * Initialize the native bridge and detect platform
     */
    init() {
        this.detectPlatform();
        this.setupViewportForNative();
        this.notifyNativeReady();

        console.log(`[NativeBridge] Platform: ${this.platform}, Native: ${this.isNativeApp}`);

        // Notify when tracking page becomes visible/hidden
        this.setupVisibilityHandlers();

        // Pass auth data to native app if available
        this.syncAuthToNative();

        return this;
    },

    /**
     * Detect the current platform/shell
     */
    detectPlatform() {
        // Check for Android WebView bridge (TrackingBridge = za.co.relatives.app)
        if (typeof window.TrackingBridge !== 'undefined') {
            this.platform = 'android';
            this.isNativeApp = true;
            this.isAndroid = true;
            this._androidBridge = window.TrackingBridge;
            return;
        }

        // Check for Android WebView bridge (Android = com.relatives.app)
        if (typeof window.Android !== 'undefined') {
            this.platform = 'android';
            this.isNativeApp = true;
            this.isAndroid = true;
            this._androidBridge = window.Android;
            return;
        }

        // Check for iOS WKWebView
        if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.tracking) {
            this.platform = 'ios';
            this.isNativeApp = true;
            this.isIOS = true;
            return;
        }

        // Check for Capacitor
        if (typeof window.Capacitor !== 'undefined') {
            this.platform = (window.Capacitor.getPlatform && window.Capacitor.getPlatform()) || 'capacitor';
            this.isNativeApp = true;
            this.isCapacitor = true;
            this.isAndroid = this.platform === 'android';
            this.isIOS = this.platform === 'ios';
            return;
        }

        // Check for PWA standalone mode
        if (window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true) {
            this.platform = 'pwa';
            this.isPWA = true;
            return;
        }

        // Default: standard browser
        this.platform = 'web';
    },

    /**
     * Setup viewport meta for native app shells
     */
    setupViewportForNative() {
        if (!this.isNativeApp && !this.isPWA) return;

        // Add native app class to body for CSS targeting
        document.documentElement.classList.add('native-app');
        document.documentElement.classList.add(`platform-${this.platform}`);

        // Ensure viewport-fit=cover for safe areas
        let viewport = document.querySelector('meta[name="viewport"]');
        if (viewport) {
            const content = viewport.getAttribute('content') || '';
            if (!content.includes('viewport-fit')) {
                viewport.setAttribute('content', content + ', viewport-fit=cover');
            }
        }
    },

    /**
     * Setup page visibility handlers for native communication
     */
    setupVisibilityHandlers() {
        if (!this.isNativeApp) return;

        // Notify native when page visibility changes
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.onTrackingHidden();
            } else {
                this.onTrackingVisible();
            }
        });

        // Also handle page show/hide for iOS
        window.addEventListener('pageshow', () => this.onTrackingVisible());
        window.addEventListener('pagehide', () => this.onTrackingHidden());

        // Initial notification
        if (!document.hidden) {
            this.onTrackingVisible();
        }
    },

    /**
     * Notify native that tracking screen is visible
     */
    onTrackingVisible() {
        if (this.isAndroid && this._androidBridge && this._androidBridge.onTrackingScreenVisible) {
            this._androidBridge.onTrackingScreenVisible();
        } else if (this.isIOS && window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'screenVisible'
            });
        } else if (this.isCapacitor) {
            this.postCapacitorMessage('trackingVisible');
        }
    },

    /**
     * Notify native that tracking screen is hidden
     */
    onTrackingHidden() {
        if (this.isAndroid && this._androidBridge && this._androidBridge.onTrackingScreenHidden) {
            this._androidBridge.onTrackingScreenHidden();
        } else if (this.isIOS && window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'screenHidden'
            });
        } else if (this.isCapacitor) {
            this.postCapacitorMessage('trackingHidden');
        }
    },

    /**
     * Sync auth credentials to native app
     */
    syncAuthToNative() {
        if (!this.isNativeApp) return;

        var config = window.TRACKING_CONFIG;
        if (!config || !config.userId) return;

        // Get session token from cookie or config
        var sessionToken = this.getSessionToken();

        if (this.isAndroid && this._androidBridge && this._androidBridge.setAuthData) {
            this._androidBridge.setAuthData(
                String(config.userId),
                sessionToken || ''
            );
        } else if (this.isIOS && window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'setAuth',
                userId: config.userId,
                sessionToken: sessionToken
            });
        }
    },

    /**
     * Get session token from cookie
     */
    getSessionToken() {
        const cookies = document.cookie.split(';');
        for (const cookie of cookies) {
            const [name, value] = cookie.trim().split('=');
            if (name === 'RELATIVES_SESSION') {
                return value;
            }
        }
        return null;
    },

    /**
     * Update tracking settings on native side
     */
    updateTrackingSettings(intervalSeconds, highAccuracyMode) {
        if (highAccuracyMode === undefined) highAccuracyMode = true;
        if (this.isAndroid && this._androidBridge && this._androidBridge.updateTrackingSettings) {
            this._androidBridge.updateTrackingSettings(intervalSeconds, highAccuracyMode);
        } else if (this.isIOS && window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'updateSettings',
                interval: intervalSeconds,
                highAccuracy: highAccuracyMode
            });
        }
    },

    /**
     * Request temporary high-frequency location updates
     */
    requestLocationBoost(durationSeconds) {
        if (durationSeconds === undefined) durationSeconds = 60;
        if (this.isAndroid && this._androidBridge && this._androidBridge.requestLocationBoost) {
            this._androidBridge.requestLocationBoost(durationSeconds);
        } else if (this.isIOS && window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'locationBoost',
                duration: durationSeconds
            });
        }
    },

    /**
     * Start native location tracking.
     * On Android, this routes through the native permission flow:
     * disclosure dialog -> OS permission -> auto-start service on grant.
     */
    startTracking: function() {
        console.log('[NativeBridge] startTracking: web button pressed');
        if (this.isAndroid && this._androidBridge && this._androidBridge.startTracking) {
            console.log('[NativeBridge] startTracking: calling native startTracking()');
            this._androidBridge.startTracking();
            return true;
        } else if (this.isIOS && window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'startTracking'
            });
            return true;
        }
        console.log('[NativeBridge] startTracking: no native handler available');
        return false;
    },

    /**
     * Stop native location tracking
     */
    stopTracking: function() {
        console.log('[NativeBridge] stopTracking: web button pressed');
        if (this.isAndroid && this._androidBridge && this._androidBridge.stopTracking) {
            this._androidBridge.stopTracking();
            return true;
        } else if (this.isIOS && window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'stopTracking'
            });
            return true;
        }
        return false;
    },

    /**
     * Start native voice input.
     * On Android, this routes through the native mic permission flow:
     * disclosure dialog -> OS mic permission -> auto-start voice on grant.
     */
    startVoice: function() {
        console.log('[NativeBridge] startVoice: web button pressed');
        if (this.isAndroid && this._androidBridge && this._androidBridge.startVoice) {
            console.log('[NativeBridge] startVoice: calling native startVoice()');
            this._androidBridge.startVoice();
            return true;
        } else if (this.isIOS && window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'startVoice'
            });
            return true;
        }
        console.log('[NativeBridge] startVoice: no native handler, falling back to browser');
        return false;
    },

    /**
     * Check if native tracking is currently enabled
     */
    isTrackingEnabled: function() {
        if (this.isAndroid && this._androidBridge && this._androidBridge.isTrackingEnabled) {
            return this._androidBridge.isTrackingEnabled();
        }
        // For iOS and others, we don't have a sync way to check
        return false;
    },

    /**
     * Wake all family devices - triggers LIVE mode on this device
     * and notifies other devices via FCM
     */
    wakeAllDevices: function() {
        if (this.isAndroid && this._androidBridge && this._androidBridge.wakeAllDevices) {
            this._androidBridge.wakeAllDevices();
            return true;
        } else if (this.isIOS && window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'wakeAllDevices'
            });
            return true;
        }
        return false;
    },

    /**
     * Get FCM token for push notifications
     */
    getFCMToken: function() {
        if (this.isAndroid && this._androidBridge && this._androidBridge.getFCMToken) {
            return this._androidBridge.getFCMToken();
        }
        return null;
    },

    /**
     * Clear auth data on logout
     */
    clearAuth: function() {
        if (this.isAndroid && this._androidBridge && this._androidBridge.clearAuthData) {
            this._androidBridge.clearAuthData();
        } else if (this.isIOS && window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'clearAuth'
            });
        }
    },

    /**
     * Log message to native console
     */
    log: function(tag, message) {
        if (this.isAndroid && this._androidBridge && this._androidBridge.logFromJS) {
            this._androidBridge.logFromJS(tag, message);
        }
        // Always log to browser console too
        console.log('[' + tag + ']', message);
    },

    /**
     * Notify native that web app is ready
     */
    notifyNativeReady: function() {
        if (this.isAndroid && this._androidBridge && this._androidBridge.logFromJS) {
            this._androidBridge.logFromJS('NativeBridge', 'Web app ready');
        }
    },

    /**
     * Post message to Capacitor plugins
     */
    postCapacitorMessage: function(action, data) {
        if (data === undefined) data = {};
        if (!window.Capacitor || !window.Capacitor.Plugins || !window.Capacitor.Plugins.Tracking) return;

        try {
            var trackingPlugin = window.Capacitor.Plugins.Tracking;
            if (trackingPlugin[action]) {
                trackingPlugin[action](data);
            }
        } catch (e) {
            console.warn('[NativeBridge] Capacitor message failed:', e);
        }
    },

    /**
     * Request native permissions (location, notifications)
     */
    requestPermissions: function() {
        var self = this;
        if (self.isCapacitor && window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.Permissions) {
            return window.Capacitor.Plugins.Permissions.query({
                name: 'location'
            }).then(function(result) {
                if (result.state !== 'granted') {
                    return window.Capacitor.Plugins.Permissions.request({
                        name: 'location'
                    });
                }
            }).catch(function(e) {
                console.warn('[NativeBridge] Permission request failed:', e);
            });
        }
        return Promise.resolve();
    },

    /**
     * Vibrate device (for notifications/alerts)
     */
    vibrate: function(pattern) {
        if (pattern === undefined) pattern = [100];
        if ('vibrate' in navigator) {
            navigator.vibrate(pattern);
        }
    },

    /**
     * Show native toast/snackbar message
     */
    showNativeToast: function(message) {
        if (this.isCapacitor && window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.Toast) {
            window.Capacitor.Plugins.Toast.show({
                text: message,
                duration: 'short'
            });
        }
        // Fallback to web toast handled elsewhere
    },

    /**
     * Called by native app after permission disclosure flow completes.
     * Triggered from MainActivity.notifyWebPermissionResult().
     *
     * @param {boolean} granted - Whether location permission was granted
     */
    onPermissionResult: function(granted) {
        console.log('[NativeBridge] Permission result:', granted);

        if (granted) {
            // Permission granted - now start the tracking service
            if (this.isAndroid && this._androidBridge && this._androidBridge.startTracking) {
                this._androidBridge.startTracking();
            }

            if (window.Toast) {
                Toast.show('Location sharing enabled', 'success');
            }
        } else {
            if (window.Toast) {
                Toast.show('Location permission is needed for tracking', 'info');
            }
        }

        // Fire custom event for any UI that needs to know
        window.dispatchEvent(new CustomEvent('native:permissionResult', {
            detail: { granted: granted }
        }));
    }
};
