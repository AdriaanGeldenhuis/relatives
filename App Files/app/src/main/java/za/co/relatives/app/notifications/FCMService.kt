package za.co.relatives.app.notifications

import android.content.Intent
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import za.co.relatives.app.data.PreferencesManager
import za.co.relatives.app.tracking.LocationTrackingService

class FCMService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        PreferencesManager(this).setFCMToken(token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        val data = message.data
        val type = data["type"] ?: ""

        when (type) {
            "wake_tracking" -> {
                // Start location tracking service
                val intent = Intent(this, LocationTrackingService::class.java).apply {
                    action = LocationTrackingService.ACTION_START
                }
                startForegroundService(intent)
            }
            "geofence_alert" -> {
                val title = data["title"] ?: "Geofence Alert"
                val body = data["message"] ?: data["body"] ?: ""
                NotificationHelper.showAlert(this, title, body)
            }
            "family_alert" -> {
                val title = data["title"] ?: "Family Alert"
                val body = data["message"] ?: data["body"] ?: ""
                NotificationHelper.showAlert(this, title, body)
            }
            "location_request" -> {
                // Silent: just start tracking
                val intent = Intent(this, LocationTrackingService::class.java).apply {
                    action = LocationTrackingService.ACTION_START
                }
                startForegroundService(intent)
            }
            else -> {
                // Show notification from payload
                message.notification?.let { notif ->
                    NotificationHelper.showAlert(
                        this,
                        notif.title ?: "Relatives",
                        notif.body ?: ""
                    )
                }
            }
        }
    }
}
