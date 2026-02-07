package za.co.relatives.app.utils

import android.app.Notification
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
 * Channel IDs
 * - [CHANNEL_TRACKING] : low-importance, used by the foreground location service.
 * - [CHANNEL_ALERTS]   : high-importance, used for family alerts and FCM messages.
 */
object NotificationHelper {

    // ── Channel IDs ────────────────────────────────────────────────────────

    const val CHANNEL_TRACKING = "relatives_tracking"
    const val CHANNEL_ALERTS = "relatives_alerts"

    // ── Notification IDs ───────────────────────────────────────────────────

    const val TRACKING_NOTIFICATION_ID = 1
    private var nextAlertId = 2_000

    // ── Broadcast actions for pause / resume ───────────────────────────────

    const val ACTION_PAUSE_TRACKING = "za.co.relatives.app.ACTION_PAUSE_TRACKING"
    const val ACTION_RESUME_TRACKING = "za.co.relatives.app.ACTION_RESUME_TRACKING"

    // ── Channel creation ───────────────────────────────────────────────────

    /**
     * Idempotent channel registration.  Safe to call on every app start;
     * the system ignores duplicate channel creation.
     */
    fun createChannels(context: Context) {
        val manager =
            context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

        val trackingChannel = NotificationChannel(
            CHANNEL_TRACKING,
            "Location Tracking",
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = "Ongoing notification while location tracking is active"
            setShowBadge(false)
        }

        val alertsChannel = NotificationChannel(
            CHANNEL_ALERTS,
            "Alerts & Messages",
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = "Important notifications from your family group"
            enableVibration(true)
            enableLights(true)
        }

        manager.createNotificationChannels(listOf(trackingChannel, alertsChannel))
    }

    // ── Foreground-service notification ─────────────────────────────────────

    /**
     * Build the persistent notification shown while the location tracking
     * foreground service is running.
     *
     * @param isPaused When `true` the notification shows a "Resume" action;
     *                 otherwise it shows "Pause".
     */
    fun buildTrackingNotification(
        context: Context,
        isPaused: Boolean = false,
    ): Notification {
        // Tap -> open the app
        val contentIntent = PendingIntent.getActivity(
            context,
            0,
            Intent(context, MainActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_SINGLE_TOP
            },
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        // Pause / Resume toggle
        val toggleAction =
            if (isPaused) ACTION_RESUME_TRACKING else ACTION_PAUSE_TRACKING
        val toggleIntent = PendingIntent.getBroadcast(
            context,
            1,
            Intent(toggleAction).setPackage(context.packageName),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val toggleLabel = if (isPaused) "Resume" else "Pause"
        val toggleIcon = if (isPaused) {
            android.R.drawable.ic_media_play
        } else {
            android.R.drawable.ic_media_pause
        }

        val statusText = if (isPaused) {
            "Location tracking paused"
        } else {
            "Sharing your location with family"
        }

        return NotificationCompat.Builder(context, CHANNEL_TRACKING)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle("Relatives")
            .setContentText(statusText)
            .setOngoing(true)
            .setContentIntent(contentIntent)
            .addAction(toggleIcon, toggleLabel, toggleIntent)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setForegroundServiceBehavior(NotificationCompat.FOREGROUND_SERVICE_IMMEDIATE)
            .build()
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
        val notificationId = nextAlertId++

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
