package za.co.relatives.app.ui

import android.content.Context
import android.content.Intent
import android.util.Log
import android.webkit.JavascriptInterface
import za.co.relatives.app.services.TrackingLocationService
import za.co.relatives.app.utils.PreferencesManager

/**
 * JavaScript interface for WebView tracking page.
 *
 * v3.0: Screen visibility is now a BOOST hint, not a core tracking controller.
 * Core tracking behavior (IDLE/MOVING/BURST) runs independently of the WebView.
 * Screen-visible = shorter interval as a boost. Screen-hidden = normal mode engine.
 */
class TrackingJsInterface(private val context: Context) {

    companion object {
        private const val TAG = "TrackingJsInterface"
    }

    @JavascriptInterface
    fun onTrackingScreenVisible() {
        Log.d(TAG, "onTrackingScreenVisible - sending boost hint to service")
        val intent = Intent(context, TrackingLocationService::class.java).apply {
            action = TrackingLocationService.ACTION_SCREEN_VISIBLE
        }
        if (PreferencesManager.isTrackingEnabled()) {
            context.startService(intent)
        }
    }

    @JavascriptInterface
    fun onTrackingScreenHidden() {
        Log.d(TAG, "onTrackingScreenHidden - reverting to mode-based interval")
        val intent = Intent(context, TrackingLocationService::class.java).apply {
            action = TrackingLocationService.ACTION_SCREEN_HIDDEN
        }
        if (PreferencesManager.isTrackingEnabled()) {
            context.startService(intent)
        }
    }

    @JavascriptInterface
    fun requestLocationBoost(seconds: Int) {
        Log.d(TAG, "requestLocationBoost: $seconds seconds (hint only)")
        // Boost is now handled internally by the service via screen visibility
        // This call just triggers screen visible mode
        onTrackingScreenVisible()
    }
}
