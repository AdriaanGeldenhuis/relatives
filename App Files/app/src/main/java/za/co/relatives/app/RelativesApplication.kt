package za.co.relatives.app

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build

class RelativesApplication : Application() {

    companion object {
        const val CHANNEL_TRACKING = "tracking_service"
        const val CHANNEL_ALERTS = "tracking_alerts"
        const val CHANNEL_GENERAL = "general"
    }

    override fun onCreate() {
        super.onCreate()
        createNotificationChannels()
    }

    private fun createNotificationChannels() {
        val trackingChannel = NotificationChannel(
            CHANNEL_TRACKING,
            "Location Tracking",
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = "Shows when location tracking is active"
            setShowBadge(false)
        }

        val alertsChannel = NotificationChannel(
            CHANNEL_ALERTS,
            "Tracking Alerts",
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = "Geofence and family tracking alerts"
            enableVibration(true)
        }

        val generalChannel = NotificationChannel(
            CHANNEL_GENERAL,
            "General",
            NotificationManager.IMPORTANCE_DEFAULT
        ).apply {
            description = "General notifications"
        }

        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(trackingChannel)
        manager.createNotificationChannel(alertsChannel)
        manager.createNotificationChannel(generalChannel)
    }
}
