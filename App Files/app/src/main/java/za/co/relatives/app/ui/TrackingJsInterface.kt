package za.co.relatives.app.ui

import android.content.Context
import android.content.Intent
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.webkit.JavascriptInterface
import android.widget.Toast
import za.co.relatives.app.services.TrackingLocationService
import za.co.relatives.app.utils.PreferencesManager

class TrackingJsInterface(private val context: Context) {

    companion object {
        private const val TAG = "TrackingJsInterface"
    }

    @JavascriptInterface
    fun onTrackingScreenVisible() {
        Log.d(TAG, "onTrackingScreenVisible called")
        // Example: Boost update rate to 10 seconds when user is actively watching
        updateInterval(10)
    }

    @JavascriptInterface
    fun onTrackingScreenHidden() {
        Log.d(TAG, "onTrackingScreenHidden called")
        // Revert to default rate (e.g., 30 seconds)
        updateInterval(30)
    }

    @JavascriptInterface
    fun requestLocationBoost(intervalSeconds: Int) {
        Log.d(TAG, "requestLocationBoost called with: $intervalSeconds")
        updateInterval(intervalSeconds)
    }

    private fun updateInterval(seconds: Int) {
        PreferencesManager.setUpdateInterval(seconds)
        
        // Show Feedback on UI Thread
        Handler(Looper.getMainLooper()).post {
            Toast.makeText(context, "Tracking Interval: ${seconds}s", Toast.LENGTH_SHORT).show()
        }

        val intent = Intent(context, TrackingLocationService::class.java).apply {
            action = TrackingLocationService.ACTION_UPDATE_INTERVAL
        }
        // Only notify service if it's already running or meant to be running
        if (PreferencesManager.isTrackingEnabled()) {
            context.startService(intent)
        }
    }
}