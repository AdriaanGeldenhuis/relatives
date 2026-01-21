package za.co.relatives.app.receivers

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import za.co.relatives.app.services.TrackingLocationService
import za.co.relatives.app.utils.PreferencesManager

/**
 * Restarts location tracking after device reboot if it was enabled before.
 */
class BootReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "BootReceiver"
    }

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED ||
            intent.action == "android.intent.action.QUICKBOOT_POWERON" ||
            intent.action == "com.htc.intent.action.QUICKBOOT_POWERON") {

            Log.d(TAG, "Boot completed, checking if tracking should restart")

            // Initialize PreferencesManager if needed
            PreferencesManager.init(context)

            // Check if tracking was enabled before reboot
            if (PreferencesManager.isTrackingEnabled()) {
                Log.d(TAG, "Tracking was enabled, restarting service")
                TrackingLocationService.startTracking(context)
            } else {
                Log.d(TAG, "Tracking was not enabled, skipping restart")
            }
        }
    }
}
