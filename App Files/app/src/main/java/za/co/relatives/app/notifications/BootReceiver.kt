package za.co.relatives.app.notifications

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import za.co.relatives.app.data.PreferencesManager
import za.co.relatives.app.tracking.LocationTrackingService

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED) {
            val prefs = PreferencesManager(context)
            if (prefs.getTrackingState() != "IDLE") {
                val serviceIntent = Intent(context, LocationTrackingService::class.java).apply {
                    action = LocationTrackingService.ACTION_START
                }
                context.startForegroundService(serviceIntent)
            }
        }
    }
}
