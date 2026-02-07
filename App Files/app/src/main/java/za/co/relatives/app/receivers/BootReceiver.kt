package za.co.relatives.app.receivers

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import za.co.relatives.app.services.TrackingLocationService
import za.co.relatives.app.workers.TrackingServiceChecker

/**
 * Broadcast receiver that restarts [TrackingLocationService] after the device
 * boots (or the app is updated).
 *
 * Listens for:
 * - [Intent.ACTION_BOOT_COMPLETED]
 * - `android.intent.action.QUICKBOOT_POWERON` (HTC / some OEMs)
 * - `com.htc.intent.action.QUICKBOOT_POWERON`
 * - [Intent.ACTION_MY_PACKAGE_REPLACED]
 *
 * The receiver only restarts the service if the user had tracking enabled
 * before the reboot (persisted in SharedPreferences).
 */
class BootReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "BootReceiver"
        private const val PREF_NAME = "relatives_prefs"
        private const val PREF_TRACKING_ENABLED = "tracking_enabled"
    }

    override fun onReceive(context: Context, intent: Intent) {
        val action = intent.action
        Log.d(TAG, "Received broadcast: $action")

        val validActions = setOf(
            Intent.ACTION_BOOT_COMPLETED,
            "android.intent.action.QUICKBOOT_POWERON",
            "com.htc.intent.action.QUICKBOOT_POWERON",
            Intent.ACTION_MY_PACKAGE_REPLACED
        )

        if (action !in validActions) {
            Log.d(TAG, "Ignoring unrelated action: $action")
            return
        }

        val prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
        val trackingEnabled = prefs.getBoolean(PREF_TRACKING_ENABLED, false)

        if (trackingEnabled) {
            Log.i(TAG, "Tracking was enabled before reboot, restarting service")
            try {
                TrackingLocationService.start(context)
                // Also reschedule the periodic watchdog.
                TrackingServiceChecker.schedule(context)
            } catch (e: Exception) {
                Log.e(TAG, "Failed to restart tracking service on boot", e)
            }
        } else {
            Log.d(TAG, "Tracking was not enabled, not restarting")
        }
    }
}
