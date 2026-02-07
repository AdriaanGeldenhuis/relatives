package za.co.relatives.app.notifications

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import za.co.relatives.app.tracking.LocationTrackingService

class NotificationActionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        when (intent.action) {
            "STOP_TRACKING" -> {
                val serviceIntent = Intent(context, LocationTrackingService::class.java).apply {
                    action = LocationTrackingService.ACTION_STOP
                }
                context.startService(serviceIntent)
            }
        }
    }
}
