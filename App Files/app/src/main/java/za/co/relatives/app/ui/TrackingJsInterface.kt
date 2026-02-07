package za.co.relatives.app.ui

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.util.Log
import android.webkit.JavascriptInterface
import za.co.relatives.app.MainActivity
import za.co.relatives.app.services.TrackingLocationService
import za.co.relatives.app.utils.PreferencesManager

/**
 * JavaScript interface for WebView tracking page.
 *
 * v3.1: Added startTracking/stopTracking/wakeAllDevices for web UI control.
 * Screen visibility is a BOOST hint, not a core tracking controller.
 * Core tracking behavior (IDLE/MOVING/BURST) runs independently of the WebView.
 * Screen-visible = shorter interval as a boost. Screen-hidden = normal mode engine.
 */
class TrackingJsInterface(private val context: Context) {

    companion object {
        private const val TAG = "TrackingJsInterface"
    }

    /**
     * Returns true if native tracking is enabled.
     * Called by web page to skip browser-based geolocation.
     */
    @JavascriptInterface
    fun isTrackingEnabled(): Boolean {
        val enabled = PreferencesManager.isTrackingEnabled()
        Log.d(TAG, "isTrackingEnabled() called - returning: $enabled")
        return enabled
    }

    /**
     * Start location tracking.
     * Called when user enables tracking in the web UI.
     *
     * This does NOT directly start the service. It goes through
     * MainActivity's permission flow which shows the prominent
     * disclosure dialog first (Google Play requirement).
     *
     * Flow: startTracking() -> MainActivity.requestTrackingPermissions()
     *       -> disclosure dialog -> OS permission -> service starts
     */
    @JavascriptInterface
    fun startTracking() {
        Log.d(TAG, "startTracking - initiating permission flow")

        // Check if we already have permission
        val activity = getMainActivity()
        if (activity != null && activity.hasLocationPermission()) {
            // Permission already granted - start directly
            Log.d(TAG, "Permission already granted, starting service")
            PreferencesManager.setTrackingEnabled(true)
            TrackingLocationService.startTracking(context)
            return
        }

        // No permission yet - run disclosure flow on UI thread
        activity?.runOnUiThread {
            activity.requestTrackingPermissions()
        }
    }

    /**
     * Stop location tracking.
     * Called when user disables tracking in the web UI.
     */
    @JavascriptInterface
    fun stopTracking() {
        Log.d(TAG, "stopTracking")
        PreferencesManager.setTrackingEnabled(false)
        val intent = Intent(context, TrackingLocationService::class.java).apply {
            action = TrackingLocationService.ACTION_STOP_TRACKING
        }
        try {
            context.startService(intent)
        } catch (e: Exception) {
            Log.e(TAG, "Failed to stop tracking service", e)
        }
    }

    @JavascriptInterface
    fun onTrackingScreenVisible() {
        Log.d(TAG, "onTrackingScreenVisible - sending boost hint to service")
        val intent = Intent(context, TrackingLocationService::class.java).apply {
            action = TrackingLocationService.ACTION_SCREEN_VISIBLE
        }
        if (PreferencesManager.isTrackingEnabled()) {
            try {
                context.startService(intent)
            } catch (e: Exception) {
                Log.e(TAG, "Failed to send screen visible", e)
            }
        }
    }

    @JavascriptInterface
    fun onTrackingScreenHidden() {
        Log.d(TAG, "onTrackingScreenHidden - reverting to mode-based interval")
        val intent = Intent(context, TrackingLocationService::class.java).apply {
            action = TrackingLocationService.ACTION_SCREEN_HIDDEN
        }
        if (PreferencesManager.isTrackingEnabled()) {
            try {
                context.startService(intent)
            } catch (e: Exception) {
                Log.e(TAG, "Failed to send screen hidden", e)
            }
        }
    }

    @JavascriptInterface
    fun requestLocationBoost(seconds: Int) {
        Log.d(TAG, "requestLocationBoost: $seconds seconds (hint only)")
        // Boost is now handled internally by the service via screen visibility
        // This call just triggers screen visible mode
        onTrackingScreenVisible()
    }

    /**
     * Wake all family devices - triggers BURST mode on this device
     * and the web app calls the wake_devices.php API to send FCM
     * push notifications to all other family devices.
     */
    @JavascriptInterface
    fun wakeAllDevices() {
        Log.d(TAG, "wakeAllDevices - triggering local wake + API call handled by web")
        // Wake this device's tracking service
        val intent = Intent(context, TrackingLocationService::class.java).apply {
            action = TrackingLocationService.ACTION_WAKE_TRACKING
        }
        try {
            if (PreferencesManager.isTrackingEnabled()) {
                context.startService(intent)
            } else {
                // If not tracking, start tracking first
                startTracking()
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to wake tracking", e)
        }
    }

    /**
     * Get current tracking mode description.
     */
    @JavascriptInterface
    fun getTrackingMode(): String {
        return if (PreferencesManager.isTrackingEnabled()) "active" else "off"
    }

    private fun getMainActivity(): MainActivity? {
        // Try to get MainActivity from context chain
        var ctx = context
        while (ctx is android.content.ContextWrapper) {
            if (ctx is MainActivity) return ctx
            ctx = ctx.baseContext
        }
        return null
    }
}
