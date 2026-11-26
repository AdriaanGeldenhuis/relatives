package za.co.relatives.app.utils

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationChannelGroup
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import java.util.concurrent.atomic.AtomicInteger
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
    private const val CHANNEL_SOUND = "relatives_alerts_sound"
    private const val CHANNEL_VIBRATION = "relatives_alerts_vibration"
    private const val CHANNEL_QUIET = "relatives_alerts_quiet"
    private const val LOCATION_GROUP = "relatives_location_alerts"
    private const val LOCATION_ALERTS = "relatives_location_normal"
    private const val LOCATION_SOUND = "relatives_location_sound"
    private const val LOCATION_VIBRATION = "relatives_location_vibration"
    private const val LOCATION_QUIET = "relatives_location_quiet"

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
        manager.createNotificationChannels(listOf(
            NotificationChannel(CHANNEL_SOUND, context.getString(R.string.channel_alerts_sound), NotificationManager.IMPORTANCE_HIGH),
            NotificationChannel(CHANNEL_VIBRATION, context.getString(R.string.channel_alerts_vibration), NotificationManager.IMPORTANCE_HIGH).apply {
                setSound(null, null)
                enableVibration(true)
            },
            NotificationChannel(CHANNEL_QUIET, context.getString(R.string.channel_alerts_quiet), NotificationManager.IMPORTANCE_LOW).apply {
                setSound(null, null)
            },
        ))
        manager.createNotificationChannelGroup(NotificationChannelGroup(LOCATION_GROUP, "Geofence and place alerts"))
        manager.createNotificationChannels(listOf(
            locationChannel(LOCATION_ALERTS, "Geofence and place alerts", true, true),
            locationChannel(LOCATION_SOUND, "Geofence alerts with sound", true, false),
            locationChannel(LOCATION_VIBRATION, "Geofence alerts with vibration", false, true),
            locationChannel(LOCATION_QUIET, "Silent geofence alerts", false, false),
        ))
    }

    private fun locationChannel(id: String, name: String, sound: Boolean, vibrate: Boolean): NotificationChannel =
        NotificationChannel(id, name, if (sound || vibrate) NotificationManager.IMPORTANCE_HIGH else NotificationManager.IMPORTANCE_LOW).apply {
            group = LOCATION_GROUP
            if (!sound) setSound(null, null)
            enableVibration(vibrate)
            lockscreenVisibility = Notification.VISIBILITY_PRIVATE
        }

    fun clearObsoleteTrackingAlerts(context: Context) {
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        manager.cancel(9002)
        manager.activeNotifications.filter { alert ->
            alert.id != 9001 && alert.notification.extras?.getCharSequence(Notification.EXTRA_TEXT)?.toString() == "Family tracking"
        }.forEach { manager.cancel(it.tag, it.id) }
    }

    fun canShowLocationAlerts(context: Context): Boolean {
        val manager = context.getSystemService(NotificationManager::class.java) ?: return false
        val groupBlocked = Build.VERSION.SDK_INT >= Build.VERSION_CODES.P &&
            manager.getNotificationChannelGroup(LOCATION_GROUP)?.isBlocked == true
        return manager.areNotificationsEnabled() &&
            !groupBlocked &&
            listOf(LOCATION_ALERTS, LOCATION_SOUND, LOCATION_VIBRATION, LOCATION_QUIET).any {
                (manager.getNotificationChannel(it)?.importance ?: 0) > NotificationManager.IMPORTANCE_NONE
            }
    }

    // ── Alert / push notification ──────────────────────────────────────────

    /**
     * Post a high-priority alert notification.
     *
     * @param actionUrl Optional deep-link path or full URL.  When the user
     *                  taps the notification the [MainActivity] will load
     *                  this URL in the WebView.
     */
    /**
     * Monotonic ID generator. Raw System.currentTimeMillis().toInt() let two
     * notifications arriving in the same millisecond (or truncating to the
     * same int) replace each other in the shade.
     */
    private val nextNotificationId =
        AtomicInteger((System.currentTimeMillis() and 0x0FFFFFFF).toInt())

    fun showAlertNotification(
        context: Context,
        title: String,
        body: String,
        actionUrl: String? = null,
        stableId: String? = null,
        silent: Boolean = false,
        vibrate: Boolean = true,
        locationAlert: Boolean = false,
    ): Boolean {
        val channel = if (locationAlert) when {
            silent && vibrate -> LOCATION_VIBRATION
            silent -> LOCATION_QUIET
            !vibrate -> LOCATION_SOUND
            else -> LOCATION_ALERTS
        } else when {
            silent && vibrate -> CHANNEL_VIBRATION
            silent -> CHANNEL_QUIET
            !vibrate -> CHANNEL_SOUND
            else -> CHANNEL_ALERTS
        }
        val notificationId = nextNotificationId.incrementAndGet()

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

        // Status-bar icons must be flat alpha-only drawables; the adaptive
        // launcher mipmap rendered as a blank grey square on Android 8+.
        val builder = NotificationCompat.Builder(context, channel)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setAutoCancel(true)
            .setOnlyAlertOnce(true)
            .setContentIntent(pendingIntent)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(if (locationAlert) NotificationCompat.CATEGORY_EVENT else NotificationCompat.CATEGORY_MESSAGE)
        if (locationAlert) {
            builder.setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
                .setPublicVersion(NotificationCompat.Builder(context, channel)
                    .setSmallIcon(R.drawable.ic_notification)
                    .setContentTitle(context.getString(R.string.channel_location_alerts))
                    .build())
        }

        val manager =
            context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (!manager.areNotificationsEnabled() ||
            manager.getNotificationChannel(channel)?.importance == NotificationManager.IMPORTANCE_NONE
        ) return false
        val notification = builder.build()
        if (stableId == null) manager.notify(notificationId, notification)
        else manager.notify("relatives-$stableId", 0, notification)
        return true
    }
}
