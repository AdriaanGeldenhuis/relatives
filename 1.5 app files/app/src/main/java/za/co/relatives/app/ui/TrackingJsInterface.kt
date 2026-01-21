package za.co.relatives.app.ui

import android.content.Context
import android.content.Intent
import android.util.Log
import android.webkit.JavascriptInterface
import za.co.relatives.app.services.TrackingLocationService
import za.co.relatives.app.utils.PreferencesManager

/**
 * JavaScript interface for the tracking WebView.
 * Allows the web page to communicate with native Android tracking.
 */
class TrackingJsInterface(private val context: Context) {

    companion object {
        private const val TAG = "TrackingJsInterface"
        // Interval when actively viewing tracking map (fast updates for smooth movement)
        private const val VIEWING_INTERVAL_SECONDS = 10
        // Minimum allowed interval (prevent battery drain from too-fast updates)
        private const val MIN_INTERVAL_SECONDS = 5
        // Maximum allowed interval (safety cap)
        private const val MAX_INTERVAL_SECONDS = 300
    }

    // Store the user's original interval so we can restore it
    private var originalInterval: Int? = null

    @JavascriptInterface
    fun onTrackingScreenVisible() {
        Log.d(TAG, "Tracking screen visible - boosting updates")
        // Save original interval if not already saved
        if (originalInterval == null) {
            originalInterval = PreferencesManager.getUpdateInterval()
        }
        // Only boost if current interval is slower than viewing interval
        val currentInterval = PreferencesManager.getUpdateInterval()
        if (currentInterval > VIEWING_INTERVAL_SECONDS) {
            setIntervalSilently(VIEWING_INTERVAL_SECONDS)
        }
    }

    @JavascriptInterface
    fun onTrackingScreenHidden() {
        Log.d(TAG, "Tracking screen hidden - restoring normal interval")
        // Restore original interval
        originalInterval?.let { original ->
            setIntervalSilently(original)
            originalInterval = null
        }
    }

    @JavascriptInterface
    fun requestLocationBoost(intervalSeconds: Int) {
        // Allow faster intervals (5-300s) for smooth map movement when viewing
        val safeInterval = intervalSeconds.coerceIn(MIN_INTERVAL_SECONDS, MAX_INTERVAL_SECONDS)
        Log.d(TAG, "Location boost requested: ${intervalSeconds}s -> using ${safeInterval}s")
        setIntervalSilently(safeInterval)
    }

    private fun setIntervalSilently(seconds: Int) {
        PreferencesManager.setUpdateInterval(seconds)

        // Notify service to update interval (no Toast!)
        if (PreferencesManager.isTrackingEnabled()) {
            val intent = Intent(context, TrackingLocationService::class.java).apply {
                action = TrackingLocationService.ACTION_UPDATE_INTERVAL
            }
            context.startService(intent)
        }
    }
}
