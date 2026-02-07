package com.relatives.app.webview

import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Log
import android.webkit.JavascriptInterface
import com.relatives.app.tracking.PreferencesManager
import com.relatives.app.tracking.TrackingLocationService

/**
 * JavaScript interface exposed to WebView as `window.Android`.
 *
 * Methods match the web interface defined in tracking.js:
 * - onTrackingScreenVisible()
 * - onTrackingScreenHidden()
 * - updateTrackingSettings(intervalSeconds, highAccuracyMode)
 * - setAuthData(userId, sessionToken)
 * - requestLocationBoost(intervalSeconds)
 */
class WebViewBridge(private val context: Context) {

    companion object {
        private const val TAG = "WebViewBridge"
        const val INTERFACE_NAME = "Android"
    }

    private val prefs = PreferencesManager(context)

    // ============ TRACKING SCREEN VISIBILITY ============

    /**
     * Called when tracking screen becomes visible (viewer watching).
     * Switches to LIVE mode for high-frequency updates.
     */
    @JavascriptInterface
    fun onTrackingScreenVisible() {
        Log.d(TAG, "onTrackingScreenVisible")
        sendServiceIntent(TrackingLocationService.ACTION_VIEWER_VISIBLE)
    }

    /**
     * Called when tracking screen becomes hidden (viewer no longer watching).
     * Service will drop to MOVING/IDLE based on activity.
     */
    @JavascriptInterface
    fun onTrackingScreenHidden() {
        Log.d(TAG, "onTrackingScreenHidden")
        sendServiceIntent(TrackingLocationService.ACTION_VIEWER_HIDDEN)
    }

    /**
     * Wake all family devices - triggers LIVE mode on this device
     * and the web app calls the wake_devices.php API to send FCM
     * push notifications to all other family devices.
     */
    @JavascriptInterface
    fun wakeAllDevices() {
        Log.d(TAG, "wakeAllDevices")
        sendServiceIntent(TrackingLocationService.ACTION_WAKE_TRACKING)
    }

    // ============ SETTINGS ============

    /**
     * Update tracking settings from web.
     *
     * @param intervalSeconds Update interval for MOVING mode (10-300)
     * @param highAccuracyMode Whether to use GPS in MOVING mode
     */
    @JavascriptInterface
    fun updateTrackingSettings(intervalSeconds: Int, highAccuracyMode: Boolean) {
        Log.d(TAG, "updateTrackingSettings: interval=$intervalSeconds, highAccuracy=$highAccuracyMode")

        // Persist to preferences
        prefs.movingIntervalSeconds = intervalSeconds
        prefs.highAccuracyMode = highAccuracyMode

        // Push to running service
        val intent = Intent(context, TrackingLocationService::class.java).apply {
            action = TrackingLocationService.ACTION_UPDATE_SETTINGS
            putExtra(TrackingLocationService.EXTRA_INTERVAL_SECONDS, intervalSeconds)
            putExtra(TrackingLocationService.EXTRA_HIGH_ACCURACY, highAccuracyMode)
        }
        sendServiceIntent(intent)
    }

    /**
     * Request temporary location boost (higher frequency).
     * Equivalent to showing tracking screen temporarily.
     *
     * @param intervalSeconds Requested interval (ignored, uses LIVE mode settings)
     */
    @JavascriptInterface
    fun requestLocationBoost(intervalSeconds: Int) {
        Log.d(TAG, "requestLocationBoost: interval=$intervalSeconds")

        // Treat as viewer becoming visible
        onTrackingScreenVisible()
    }

    // ============ AUTHENTICATION ============

    /**
     * Set auth credentials from web after login.
     *
     * @param userId The logged-in user's ID
     * @param sessionToken The session token for API calls
     */
    @JavascriptInterface
    fun setAuthData(userId: String, sessionToken: String) {
        Log.d(TAG, "setAuthData: userId=$userId")

        prefs.userId = userId
        prefs.sessionToken = sessionToken

        // Clear any auth block since we have new credentials
        prefs.clearAuthBlock()
    }

    /**
     * Get FCM token for push notifications.
     * Returns empty string if not available.
     */
    @JavascriptInterface
    fun getFCMToken(): String {
        // This would be implemented with Firebase Cloud Messaging
        // For now, return empty string - FCM setup is separate
        Log.d(TAG, "getFCMToken called")
        return ""
    }

    // ============ TRACKING CONTROL ============

    /**
     * Start location tracking.
     * Called when user enables tracking in the app.
     */
    @JavascriptInterface
    fun startTracking() {
        Log.d(TAG, "startTracking")
        TrackingLocationService.startTracking(context)
    }

    /**
     * Stop location tracking.
     * Called when user disables tracking in the app.
     */
    @JavascriptInterface
    fun stopTracking() {
        Log.d(TAG, "stopTracking")
        TrackingLocationService.stopTracking(context)
    }

    /**
     * Check if tracking is currently enabled.
     *
     * @return true if tracking is enabled
     */
    @JavascriptInterface
    fun isTrackingEnabled(): Boolean {
        return prefs.isTrackingEnabled
    }

    /**
     * Clear auth data (logout).
     */
    @JavascriptInterface
    fun clearAuthData() {
        Log.d(TAG, "clearAuthData")

        prefs.userId = null
        prefs.sessionToken = null

        // Stop tracking since user logged out
        TrackingLocationService.stopTracking(context)
    }

    // ============ UTILITY ============

    /**
     * Log message from JavaScript for debugging.
     */
    @JavascriptInterface
    fun logFromJS(tag: String, message: String) {
        Log.d("JS:$tag", message)
    }

    /**
     * Get current tracking mode (live/moving/idle/off).
     */
    @JavascriptInterface
    fun getTrackingMode(): String {
        return if (prefs.isTrackingEnabled) "active" else "off"
    }

    // ============ INTERNAL HELPERS ============

    /**
     * Send intent to tracking service, using startForegroundService on Android O+.
     */
    private fun sendServiceIntent(action: String) {
        val intent = Intent(context, TrackingLocationService::class.java).apply {
            this.action = action
        }
        sendServiceIntent(intent)
    }

    private fun sendServiceIntent(intent: Intent) {
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to send service intent: ${intent.action}", e)
        }
    }
}
