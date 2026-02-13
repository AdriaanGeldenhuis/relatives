package za.co.relatives.app.utils

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import androidx.core.app.NotificationCompat
import za.co.relatives.app.MainActivity
import za.co.relatives.app.R

/**
 * Centralised helper for notification channels and notification construction.
 *
 * Channel IDs:
 * - [CHANNEL_ALERTS]   : high-importance, used for family alerts and FCM messages.
 *
 * Note: the tracking foreground service ([TrackingService]) manages its own
 * channel ("tracking_channel") and notification internally.
 */
object NotificationHelper {

    // ── Channel IDs ────────────────────────────────────────────────────────

    const val CHANNEL_ALERTS = "relatives_alerts"

    // ── Channel creation ───────────────────────────────────────────────────

    /**
     * Idempotent channel registration.  Safe to call on every app start;
     * the system ignores duplicate channel creation.
     */
    fun createChannels(context: Context) {
        val manager =
            context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

        val alertsChannel = NotificationChannel(
            CHANNEL_ALERTS,
            context.getString(R.string.channel_alerts_name),
            NotificationManager.IMPORTANCE_HIGH,
        ).apply {
            description = context.getString(R.string.channel_alerts_desc)
            enableVibration(true)
            enableLights(true)
        }

        manager.createNotificationChannel(alertsChannel)
    }

    // ── Alert / push notification ──────────────────────────────────────────

    /**
     * Post a high-priority alert notification.
     *
     * @param actionUrl Optional deep-link path or full URL.  When the user
     *                  taps the notification the [MainActivity] will load
     *                  this URL in the WebView.
     */
    fun showAlertNotification(
        context: Context,
        title: String,
        body: String,
        actionUrl: String? = null,
    ) {
        val notificationId = (System.currentTimeMillis().toInt())

        val intent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            if (!actionUrl.isNullOrBlank()) {
                putExtra("action_url", actionUrl)
            }
        }

        val pendingIntent = PendingIntent.getActivity(
            context,
            notificationId,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val notification = NotificationCompat.Builder(context, CHANNEL_ALERTS)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .build()

        val manager =
            context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        manager.notify(notificationId, notification)
    }
}
