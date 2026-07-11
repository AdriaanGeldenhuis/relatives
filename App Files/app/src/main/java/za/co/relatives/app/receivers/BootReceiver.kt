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

        if (enabled &&
            TrackingService.hasLocationPermission(context) &&
            TrackingService.canReviveFromBackground(context)
        ) {
            // On Android 15+ a BOOT_COMPLETED receiver may not start a
            // location foreground service; TrackingService.start() swallows
            // the refusal and MainActivity re-starts tracking on next open.
            // Without background location a boot-started service would get
            // no fixes at all, so that case also waits for the next app open.
            Log.i(TAG, "Tracking was enabled, restarting service after boot")
            TrackingService.start(context)
        }
    }
}
