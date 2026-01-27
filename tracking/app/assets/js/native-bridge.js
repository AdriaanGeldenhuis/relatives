/**
 * Native App Bridge Module
 *
 * Provides a unified interface for communicating with native app shells:
 * - Android WebView (window.Android)
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
        // Check for Android WebView bridge
        if (typeof window.Android !== 'undefined') {
            this.platform = 'android';
            this.isNativeApp = true;
            this.isAndroid = true;
            return;
        }

        // Check for iOS WKWebView
        if (window.webkit?.messageHandlers?.tracking) {
            this.platform = 'ios';
            this.isNativeApp = true;
            this.isIOS = true;
            return;
        }

        // Check for Capacitor
        if (typeof window.Capacitor !== 'undefined') {
            this.platform = window.Capacitor.getPlatform?.() || 'capacitor';
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
        if (this.isAndroid && window.Android?.onTrackingScreenVisible) {
            window.Android.onTrackingScreenVisible();
        } else if (this.isIOS && window.webkit?.messageHandlers?.tracking) {
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
        if (this.isAndroid && window.Android?.onTrackingScreenHidden) {
            window.Android.onTrackingScreenHidden();
        } else if (this.isIOS && window.webkit?.messageHandlers?.tracking) {
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

        const config = window.TRACKING_CONFIG;
        if (!config?.userId) return;

        // Get session token from cookie or config
        const sessionToken = this.getSessionToken();

        if (this.isAndroid && window.Android?.setAuthData) {
            window.Android.setAuthData(
                String(config.userId),
                sessionToken || ''
            );
        } else if (this.isIOS && window.webkit?.messageHandlers?.tracking) {
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
    updateTrackingSettings(intervalSeconds, highAccuracyMode = true) {
        if (this.isAndroid && window.Android?.updateTrackingSettings) {
            window.Android.updateTrackingSettings(intervalSeconds, highAccuracyMode);
        } else if (this.isIOS && window.webkit?.messageHandlers?.tracking) {
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
    requestLocationBoost(durationSeconds = 60) {
        if (this.isAndroid && window.Android?.requestLocationBoost) {
            window.Android.requestLocationBoost(durationSeconds);
        } else if (this.isIOS && window.webkit?.messageHandlers?.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'locationBoost',
                duration: durationSeconds
            });
        }
    },

    /**
     * Start native location tracking
     */
    startTracking() {
        if (this.isAndroid && window.Android?.startTracking) {
            window.Android.startTracking();
            return true;
        } else if (this.isIOS && window.webkit?.messageHandlers?.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'startTracking'
            });
            return true;
        }
        return false;
    },

    /**
     * Stop native location tracking
     */
    stopTracking() {
        if (this.isAndroid && window.Android?.stopTracking) {
            window.Android.stopTracking();
            return true;
        } else if (this.isIOS && window.webkit?.messageHandlers?.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'stopTracking'
            });
            return true;
        }
        return false;
    },

    /**
     * Check if native tracking is currently enabled
     */
    isTrackingEnabled() {
        if (this.isAndroid && window.Android?.isTrackingEnabled) {
            return window.Android.isTrackingEnabled();
        }
        // For iOS and others, we don't have a sync way to check
        return false;
    },

    /**
     * Get FCM token for push notifications
     */
    getFCMToken() {
        if (this.isAndroid && window.Android?.getFCMToken) {
            return window.Android.getFCMToken();
        }
        return null;
    },

    /**
     * Clear auth data on logout
     */
    clearAuth() {
        if (this.isAndroid && window.Android?.clearAuthData) {
            window.Android.clearAuthData();
        } else if (this.isIOS && window.webkit?.messageHandlers?.tracking) {
            window.webkit.messageHandlers.tracking.postMessage({
                action: 'clearAuth'
            });
        }
    },

    /**
     * Log message to native console
     */
    log(tag, message) {
        if (this.isAndroid && window.Android?.logFromJS) {
            window.Android.logFromJS(tag, message);
        }
        // Always log to browser console too
        console.log(`[${tag}]`, message);
    },

    /**
     * Notify native that web app is ready
     */
    notifyNativeReady() {
        if (this.isAndroid && window.Android?.logFromJS) {
            window.Android.logFromJS('NativeBridge', 'Web app ready');
        }
    },

    /**
     * Post message to Capacitor plugins
     */
    postCapacitorMessage(action, data = {}) {
        if (!window.Capacitor?.Plugins?.Tracking) return;

        try {
            window.Capacitor.Plugins.Tracking[action]?.(data);
        } catch (e) {
            console.warn('[NativeBridge] Capacitor message failed:', e);
        }
    },

    /**
     * Request native permissions (location, notifications)
     */
    async requestPermissions() {
        if (this.isCapacitor && window.Capacitor?.Plugins?.Permissions) {
            try {
                const result = await window.Capacitor.Plugins.Permissions.query({
                    name: 'location'
                });
                if (result.state !== 'granted') {
                    await window.Capacitor.Plugins.Permissions.request({
                        name: 'location'
                    });
                }
            } catch (e) {
                console.warn('[NativeBridge] Permission request failed:', e);
            }
        }
    },

    /**
     * Vibrate device (for notifications/alerts)
     */
    vibrate(pattern = [100]) {
        if ('vibrate' in navigator) {
            navigator.vibrate(pattern);
        }
    },

    /**
     * Show native toast/snackbar message
     */
    showNativeToast(message) {
        if (this.isCapacitor && window.Capacitor?.Plugins?.Toast) {
            window.Capacitor.Plugins.Toast.show({
                text: message,
                duration: 'short'
            });
        }
        // Fallback to web toast handled elsewhere
    }
};
