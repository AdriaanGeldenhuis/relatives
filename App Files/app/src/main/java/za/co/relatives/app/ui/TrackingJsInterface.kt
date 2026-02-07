package za.co.relatives.app.ui

import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Log
import android.webkit.JavascriptInterface
import za.co.relatives.app.MainActivity
import za.co.relatives.app.services.TrackingLocationService
import za.co.relatives.app.utils.PreferencesManager

/**
 * JavaScript interface exposed to WebView as "TrackingBridge".
 *
 * startTracking() ALWAYS routes through MainActivity.requestTrackingPermissions()
 * which shows disclosure dialog -> OS permission -> starts service. No flags, no
 * early returns, no second taps required.
 */
class TrackingJsInterface(private val context: Context) {

    companion object {
        private const val TAG = "TrackingJsInterface"
    }

    @JavascriptInterface
    fun isTrackingEnabled(): Boolean {
        val enabled = PreferencesManager.isTrackingEnabled()
        Log.d(TAG, "isTrackingEnabled() -> $enabled")
        return enabled
    }

    /**
     * Start location tracking. Called when user taps tracking button in web UI.
     *
     * ALWAYS goes through MainActivity.requestTrackingPermissions() which handles:
     * - Already granted? -> starts service immediately
     * - Not granted? -> shows disclosure -> OS permission -> starts service
     *
     * The disclosure Continue button directly calls requestPermissions() in the
     * SAME tap. No flag-and-return pattern.
     */
    @JavascriptInterface
    fun startTracking() {
        Log.d(TAG, "startTracking() called")

        val activity = MainActivity.getInstance()
        if (activity == null) {
            Log.e(TAG, "startTracking() - MainActivity not available!")
            return
        }

        // ALWAYS route through requestTrackingPermissions.
        // It handles both "already granted" and "need to ask" cases.
        activity.runOnUiThread {
            Log.d(TAG, "startTracking() -> calling requestTrackingPermissions on UI thread")
            activity.requestTrackingPermissions()
        }
    }

    @JavascriptInterface
    fun stopTracking() {
        Log.d(TAG, "stopTracking()")
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
        Log.d(TAG, "onTrackingScreenVisible")
        if (!PreferencesManager.isTrackingEnabled()) return
        sendServiceAction(TrackingLocationService.ACTION_SCREEN_VISIBLE)
    }

    @JavascriptInterface
    fun onTrackingScreenHidden() {
        Log.d(TAG, "onTrackingScreenHidden")
        if (!PreferencesManager.isTrackingEnabled()) return
        sendServiceAction(TrackingLocationService.ACTION_SCREEN_HIDDEN)
    }

    @JavascriptInterface
    fun requestLocationBoost(seconds: Int) {
        Log.d(TAG, "requestLocationBoost: ${seconds}s")
        onTrackingScreenVisible()
    }

    @JavascriptInterface
    fun wakeAllDevices() {
        Log.d(TAG, "wakeAllDevices()")
        if (PreferencesManager.isTrackingEnabled()) {
            sendServiceAction(TrackingLocationService.ACTION_WAKE_TRACKING)
        } else {
            startTracking()
        }
    }

    @JavascriptInterface
    fun getTrackingMode(): String {
        return if (PreferencesManager.isTrackingEnabled()) "active" else "off"
    }

    private fun sendServiceAction(action: String) {
        val intent = Intent(context, TrackingLocationService::class.java).apply {
            this.action = action
        }
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to send $action to service", e)
        }
    }
}
