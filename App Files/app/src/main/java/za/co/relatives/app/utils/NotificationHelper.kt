package za.co.relatives.app.utils

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationChannelGroup
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Bundle
import androidx.core.app.NotificationCompat
import androidx.core.app.Person
import java.util.concurrent.atomic.AtomicInteger
import za.co.relatives.app.MainActivity
import za.co.relatives.app.R
import za.co.relatives.app.services.ChatNotificationReceiver

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
    private const val CHAT_GROUP = "relatives_messages"
    private const val CHAT_ALERTS = "relatives_messages_normal"
    private const val CHAT_SOUND = "relatives_messages_sound"
    private const val CHAT_VIBRATION = "relatives_messages_vibration"
    private const val CHAT_QUIET = "relatives_messages_quiet"
    private const val CHAT_SUMMARY_ID = 9003
    private const val EXTRA_MESSAGE_ID = "za.co.relatives.app.message_id"
    private val CHAT_RECEIPT = Regex("[a-f0-9]{32}")
    private val recentReads = linkedSetOf<String>()

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
            privateChannel(LOCATION_ALERTS, LOCATION_GROUP, "Geofence and place alerts", true, true),
            privateChannel(LOCATION_SOUND, LOCATION_GROUP, "Geofence alerts with sound", true, false),
            privateChannel(LOCATION_VIBRATION, LOCATION_GROUP, "Geofence alerts with vibration", false, true),
            privateChannel(LOCATION_QUIET, LOCATION_GROUP, "Silent geofence alerts", false, false),
        ))
        val chat = context.getString(R.string.channel_chat)
        manager.createNotificationChannelGroup(NotificationChannelGroup(CHAT_GROUP, chat))
        manager.createNotificationChannels(listOf(
            privateChannel(CHAT_ALERTS, CHAT_GROUP, chat, true, true),
            privateChannel(CHAT_SOUND, CHAT_GROUP, context.getString(R.string.channel_chat_sound), true, false),
            privateChannel(CHAT_VIBRATION, CHAT_GROUP, context.getString(R.string.channel_chat_vibration), false, true),
            privateChannel(CHAT_QUIET, CHAT_GROUP, context.getString(R.string.channel_chat_quiet), false, false),
        ))
    }

    private fun privateChannel(id: String, channelGroup: String, name: String, sound: Boolean, vibrate: Boolean): NotificationChannel =
        NotificationChannel(id, name, if (sound || vibrate) NotificationManager.IMPORTANCE_HIGH else NotificationManager.IMPORTANCE_LOW).apply {
            group = channelGroup
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
        return canShowInGroup(context, LOCATION_GROUP, listOf(LOCATION_ALERTS, LOCATION_SOUND, LOCATION_VIBRATION, LOCATION_QUIET))
    }

    fun canShowChatAlerts(context: Context): Boolean {
        return canShowInGroup(context, CHAT_GROUP, listOf(CHAT_ALERTS, CHAT_SOUND, CHAT_VIBRATION, CHAT_QUIET))
    }

    private fun canShowInGroup(context: Context, group: String, channels: List<String>): Boolean {
        val manager = context.getSystemService(NotificationManager::class.java) ?: return false
        val groupBlocked = Build.VERSION.SDK_INT >= Build.VERSION_CODES.P &&
            manager.getNotificationChannelGroup(group)?.isBlocked == true
        return manager.areNotificationsEnabled() &&
            !groupBlocked &&
            channels.any {
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

    @Synchronized
    fun showAlertNotification(
        context: Context,
        title: String,
        body: String,
        actionUrl: String? = null,
        stableId: String? = null,
        silent: Boolean = false,
        vibrate: Boolean = true,
        locationAlert: Boolean = false,
        messageId: String? = null,
        newMessage: Boolean = false,
        recipientId: String? = null,
        receipt: String? = null,
    ): Boolean {
        val preferences = PreferencesManager(context)
        if (messageId != null && !preferences.isPushRecipient(recipientId)) return false
        if (newMessage && messageId in recentReads) return false
        val channel = if (messageId != null) when {
            silent && vibrate -> CHAT_VIBRATION
            silent -> CHAT_QUIET
            !vibrate -> CHAT_SOUND
            else -> CHAT_ALERTS
        } else if (locationAlert) when {
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
        if (locationAlert || messageId != null) {
            builder.setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
                .setPublicVersion(NotificationCompat.Builder(context, channel)
                    .setSmallIcon(R.drawable.ic_notification)
                    .setContentTitle(context.getString(
                        if (locationAlert) R.string.channel_location_alerts else R.string.channel_chat
                    ))
                    .build())
        }
        if (messageId != null) {
            val chat = context.getString(R.string.channel_chat)
            val sender = Person.Builder().setName(title).build()
            builder.setStyle(NotificationCompat.MessagingStyle(Person.Builder().setName(context.getString(R.string.app_name)).build())
                .setConversationTitle(chat)
                .setGroupConversation(true)
                .addMessage(body, System.currentTimeMillis(), sender))
                .addExtras(Bundle().apply { putString(EXTRA_MESSAGE_ID, messageId) })
                .setGroup(CHAT_GROUP)
                .setGroupAlertBehavior(NotificationCompat.GROUP_ALERT_CHILDREN)
            val binding = preferences.sessionBinding()
            if (receipt != null && CHAT_RECEIPT.matches(receipt) && binding != null) {
                val markRead = Intent(context, ChatNotificationReceiver::class.java).apply {
                    putExtra("receipt", receipt)
                    putExtra("message_id", messageId)
                    putExtra("binding", binding)
                }
                builder.addAction(NotificationCompat.Action.Builder(
                    0, context.getString(R.string.chat_mark_as_read),
                    PendingIntent.getBroadcast(context, notificationId, markRead,
                        PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE),
                ).setSemanticAction(NotificationCompat.Action.SEMANTIC_ACTION_MARK_AS_READ)
                    .setShowsUserInterface(false).build())
            }
        }

        val manager =
            context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (!manager.areNotificationsEnabled() ||
            (messageId != null && !canShowChatAlerts(context)) ||
            manager.getNotificationChannel(channel)?.importance == NotificationManager.IMPORTANCE_NONE
        ) return false
        val notification = builder.build()
        if (stableId == null) manager.notify(notificationId, notification)
        else manager.notify("relatives-$stableId", 0, notification)
        if (messageId != null) showChatSummary(context, manager, channel)
        return true
    }

    private fun showChatSummary(context: Context, manager: NotificationManager, channel: String) {
        val chat = context.getString(R.string.channel_chat)
        val intent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra("action_url", "/messages/")
        }
        val open = PendingIntent.getActivity(context, CHAT_SUMMARY_ID, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val summary = NotificationCompat.Builder(context, channel)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(chat)
            .setContentIntent(open)
            .setGroup(CHAT_GROUP)
            .setGroupSummary(true)
            .setGroupAlertBehavior(NotificationCompat.GROUP_ALERT_CHILDREN)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setOnlyAlertOnce(true)
            .setAutoCancel(true)
            .build()
        manager.notify(CHAT_SUMMARY_ID, summary)
    }

    @Synchronized
    fun markMessagesRead(context: Context, ids: Set<String>) {
        recentReads.addAll(ids)
        while (recentReads.size > 400) recentReads.remove(recentReads.first())
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        val alerts = manager.activeNotifications.filter { it.notification.extras?.containsKey(EXTRA_MESSAGE_ID) == true }
        val read = alerts.filter { it.notification.extras.getString(EXTRA_MESSAGE_ID) in ids }
        read.forEach { manager.cancel(it.tag, it.id) }
        if (read.size == alerts.size) manager.cancel(CHAT_SUMMARY_ID)
    }

    @Synchronized
    fun clearChatAlerts(context: Context) {
        recentReads.clear()
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        manager.activeNotifications.filter { it.notification.group == CHAT_GROUP }.forEach { manager.cancel(it.tag, it.id) }
    }
}
