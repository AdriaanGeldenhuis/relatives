package za.co.relatives.app.utils

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import za.co.relatives.app.MainActivity
import za.co.relatives.app.services.TrackingLocationService
import za.co.relatives.app.R

object NotificationHelper {
    const val CHANNEL_ID = "tracking_channel"
    const val CHANNEL_ALERTS = "relatives_alerts"
    const val NOTIFICATION_ID = 1001
    const val ALERT_NOTIFICATION_ID = 2001

    fun createNotificationChannel(context: Context) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val notificationManager: NotificationManager =
                context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

            // 1. Tracking Channel (Low importance, silent)
            val trackingName = "Tracking Service"
            val trackingDesc = "Notifications for location tracking service"
            val trackingImportance = NotificationManager.IMPORTANCE_LOW
            val trackingChannel = NotificationChannel(CHANNEL_ID, trackingName, trackingImportance).apply {
                description = trackingDesc
            }
            notificationManager.createNotificationChannel(trackingChannel)

            // 2. Alerts Channel (High importance, sound)
            val alertsName = "Relatives Alerts"
            val alertsDesc = "Notifications for messages and alerts"
            val alertsImportance = NotificationManager.IMPORTANCE_HIGH
            val alertsChannel = NotificationChannel(CHANNEL_ALERTS, alertsName, alertsImportance).apply {
                description = alertsDesc
                enableVibration(true)
                enableLights(true)
            }
            notificationManager.createNotificationChannel(alertsChannel)
        }
    }

    fun buildTrackingNotification(context: Context, isPaused: Boolean): Notification {
        // 1. Content Intent: Open App on Click
        val openAppIntent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
        }
        val contentPendingIntent = PendingIntent.getActivity(
            context,
            0,
            openAppIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val builder = NotificationCompat.Builder(context, CHANNEL_ID)
            .setContentTitle("Location tracking active")
            .setContentText("Your location is being shared with your family.")
            .setSmallIcon(android.R.drawable.ic_menu_mylocation)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setOngoing(true) // Prevents swiping away
            .setCategory(NotificationCompat.CATEGORY_SERVICE) // Tells system this is a service notification
            .setForegroundServiceBehavior(NotificationCompat.FOREGROUND_SERVICE_IMMEDIATE) // Show immediately
            .setContentIntent(contentPendingIntent)

        if (isPaused) {
            val resumeIntent = Intent(context, TrackingLocationService::class.java).apply {
                action = TrackingLocationService.ACTION_RESUME_TRACKING
            }
            val resumePendingIntent = PendingIntent.getService(
                context,
                2,
                resumeIntent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            builder.addAction(
                android.R.drawable.ic_media_play,
                "Resume Tracking",
                resumePendingIntent
            ).setContentText("Tracking is paused.")
        } else {
            val pauseIntent = Intent(context, TrackingLocationService::class.java).apply {
                action = TrackingLocationService.ACTION_PAUSE_TRACKING
            }
            val pausePendingIntent = PendingIntent.getService(
                context,
                1,
                pauseIntent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            builder.addAction(
                android.R.drawable.ic_media_pause,
                "Pause Tracking",
                pausePendingIntent
            )
        }

        return builder.build()
    }

    fun updateTrackingNotification(context: Context, isPaused: Boolean) {
        val notification = buildTrackingNotification(context, isPaused)
        val notificationManager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        notificationManager.notify(NOTIFICATION_ID, notification)
    }

    fun showNewMessageNotification(context: Context, unreadCount: Int, latestTitle: String? = null, latestMessage: String? = null) {
        // Intent to open MainActivity when clicked
        val intent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
            // We can add data to tell MainActivity to open /notifications/
            putExtra("open_url", "https://www.relatives.co.za/notifications/")
        }

        val pendingIntent: PendingIntent = PendingIntent.getActivity(
            context,
            0,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        // Use custom title/message if provided, otherwise generic fallback
        val title = if (!latestTitle.isNullOrEmpty()) latestTitle else "New Notification"

        val text = if (!latestMessage.isNullOrEmpty()) {
            latestMessage
        } else {
            if (unreadCount == 1) "You have 1 unread notification." else "You have $unreadCount unread notifications."
        }

        val notification = NotificationCompat.Builder(context, CHANNEL_ALERTS)
            .setSmallIcon(android.R.drawable.ic_dialog_info) // Or app icon
            .setContentTitle(title)
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text)) // Expandable text for long messages
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setContentIntent(pendingIntent)
            .setAutoCancel(true)
            .build()

        val notificationManager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        notificationManager.notify(ALERT_NOTIFICATION_ID, notification)
    }
}
