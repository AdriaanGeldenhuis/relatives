package com.relatives.app.webview

import android.content.Intent
import android.util.Log
import android.webkit.JavascriptInterface
import com.relatives.app.MainActivity
import com.relatives.app.tracking.PreferencesManager
import com.relatives.app.tracking.TrackingLocationService

/**
 * JavaScript interface exposed to WebView as `window.Android`.
 *
 * Methods match the web interface defined in native-bridge.js:
 * - startTracking() / stopTracking()
 * - startVoice()
 * - onTrackingScreenVisible() / onTrackingScreenHidden()
 * - updateTrackingSettings(intervalSeconds, highAccuracyMode)
 * - setAuthData(userId, sessionToken)
 * - requestLocationBoost(intervalSeconds)
 *
 * IMPORTANT: startTracking() routes through MainActivity.requestTrackingPermissions()
 * to ensure proper permission flow with disclosure dialogs. It does NOT directly
 * start the service.
 */
class WebViewBridge(private val activity: MainActivity) {

    companion object {
        private const val TAG = "WebViewBridge"
        const val INTERFACE_NAME = "Android"
    }

    private val prefs = PreferencesManager(activity)

    // ============ TRACKING CONTROL ============

    /**
     * Start location tracking.
     * Called when user taps "Start Tracking" in the web UI.
     *
     * Routes through MainActivity to handle the full permission flow:
     * disclosure -> OS permission -> auto-start service on grant.
     *
     * Does NOT directly start the service.
     */
    @JavascriptInterface
    fun startTracking() {
        Log.d(TAG, "BRIDGE startTracking called")

        // Must run on UI thread for AlertDialog
        activity.runOnUiThread {
            Log.d(TAG, "startTracking: routing to MainActivity.requestTrackingPermissions()")
            activity.requestTrackingPermissions()
        }
    }

    /**
     * Stop location tracking.
     * Called when user disables tracking in the app.
     */
    @JavascriptInterface
    fun stopTracking() {
        Log.d(TAG, "BRIDGE stopTracking called")
        TrackingLocationService.stopTracking(activity)
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

    // ============ VOICE CONTROL ============

    /**
     * Start voice input.
     * Called when user taps the voice/mic button in the web UI.
     *
     * Routes through MainActivity to handle mic permission flow:
     * disclosure -> OS permission -> auto-start voice on grant.
     */
    @JavascriptInterface
    fun startVoice() {
        Log.d(TAG, "BRIDGE startVoice called")

        // Must run on UI thread for AlertDialog
        activity.runOnUiThread {
            Log.d(TAG, "startVoice: routing to MainActivity.requestMicPermission()")
            activity.requestMicPermission()
        }
    }

    // ============ TRACKING SCREEN VISIBILITY ============

    /**
     * Called when tracking screen becomes visible (viewer watching).
     * Switches to LIVE mode for high-frequency updates.
     */
    @JavascriptInterface
    fun onTrackingScreenVisible() {
        Log.d(TAG, "onTrackingScreenVisible")

        // Only send intent if tracking is active
        if (!prefs.isTrackingEnabled) {
            Log.d(TAG, "Tracking not enabled, ignoring visibility change")
            return
        }

        val intent = Intent(activity, TrackingLocationService::class.java).apply {
            action = TrackingLocationService.ACTION_VIEWER_VISIBLE
        }
        activity.startService(intent)
    }

    /**
     * Called when tracking screen becomes hidden (viewer no longer watching).
     * Service will drop to MOVING/IDLE based on activity.
     */
    @JavascriptInterface
    fun onTrackingScreenHidden() {
        Log.d(TAG, "onTrackingScreenHidden")

        // Only send intent if tracking is active
        if (!prefs.isTrackingEnabled) {
            Log.d(TAG, "Tracking not enabled, ignoring visibility change")
            return
        }

        val intent = Intent(activity, TrackingLocationService::class.java).apply {
            action = TrackingLocationService.ACTION_VIEWER_HIDDEN
        }
        activity.startService(intent)
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

        val intent = Intent(activity, TrackingLocationService::class.java).apply {
            action = TrackingLocationService.ACTION_UPDATE_SETTINGS
            putExtra(TrackingLocationService.EXTRA_INTERVAL_SECONDS, intervalSeconds)
            putExtra(TrackingLocationService.EXTRA_HIGH_ACCURACY, highAccuracyMode)
        }
        activity.startService(intent)
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

    /**
     * Clear auth data (logout).
     */
    @JavascriptInterface
    fun clearAuthData() {
        Log.d(TAG, "clearAuthData")

        prefs.userId = null
        prefs.sessionToken = null

        // Stop tracking since user logged out
        TrackingLocationService.stopTracking(activity)
    }

    // ============ UTILITY ============

    /**
     * Log message from JavaScript for debugging.
     */
    @JavascriptInterface
    fun logFromJS(tag: String, message: String) {
        Log.d("JS:$tag", message)
    }
}
