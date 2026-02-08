package za.co.relatives.app.receivers

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import za.co.relatives.app.tracking.TrackingService

/**
 * Restarts [TrackingService] after device boot or app update,
 * but only if the user had tracking enabled before.
 */
class BootReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "BootReceiver"
    }

    override fun onReceive(context: Context, intent: Intent) {
        val validActions = setOf(
            Intent.ACTION_BOOT_COMPLETED,
            "android.intent.action.QUICKBOOT_POWERON",
            "com.htc.intent.action.QUICKBOOT_POWERON",
            Intent.ACTION_MY_PACKAGE_REPLACED,
        )
        if (intent.action !in validActions) return

        val prefs = context.getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)
        val enabled = prefs.getBoolean("tracking_enabled", false)

        if (enabled) {
            Log.i(TAG, "Tracking was enabled, restarting service after boot")
            try {
                TrackingService.start(context)
            } catch (e: Exception) {
                Log.e(TAG, "Failed to restart tracking on boot", e)
            }
        }
    }
}
