package za.co.relatives.app.services

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.util.Log
import androidx.core.app.NotificationCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import za.co.relatives.app.R
import za.co.relatives.app.network.ApiClient
import za.co.relatives.app.tracking.TrackingService

/**
 * Firebase Cloud Messaging service for the Relatives app.
 *
 * Handles incoming push notifications of various types and manages FCM token
 * registration with the backend.
 *
 * Supported notification types:
 * - `message`, `shopping`, `calendar`, `schedule`, `tracking`, `weather`,
 *   `note`, `system` -- displayed as a visible notification with optional deep link.
 * - `wake_tracking` -- silently triggers BURST mode on [TrackingLocationService]
 *   without showing any notification to the user.
 */
class RelativesFirebaseService : FirebaseMessagingService() {

    companion object {
        private const val TAG = "RelativesFCM"

        private const val CHANNEL_ALERTS = "relatives_alerts"
        private const val CHANNEL_TRACKING = "relatives_tracking"

        private const val PREF_NAME = "relatives_prefs"
        private const val PREF_FCM_TOKEN = "fcm_token"
    }

    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    // ------------------------------------------------------------------ //
    //  Token management
    // ------------------------------------------------------------------ //

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        Log.d(TAG, "New FCM token received")

        // Persist locally.
        getSharedPreferences(PREF_NAME, MODE_PRIVATE)
            .edit()
            .putString(PREF_FCM_TOKEN, token)
            .apply()

        // Register with backend.
        serviceScope.launch {
            try {
                val api = ApiClient(applicationContext)
                api.registerFcmToken(token)
                Log.d(TAG, "FCM token registered with backend")
            } catch (e: Exception) {
                Log.e(TAG, "Failed to register FCM token", e)
            }
        }
    }

    // ------------------------------------------------------------------ //
    //  Message handling
    // ------------------------------------------------------------------ //

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        val data = message.data
        val type = data["type"] ?: "system"

        Log.d(TAG, "Message received: type=$type data=$data")

        when (type) {
            "wake_tracking" -> handleWakeTracking()
            else -> handleVisibleNotification(type, data, message.notification)
        }
    }

    // ------------------------------------------------------------------ //
    //  Wake tracking (silent)
    // ------------------------------------------------------------------ //

    /**
     * Silently trigger a BURST location fix without showing any notification.
     * Used when another family member taps "Find Device" or similar.
     */
    private fun handleWakeTracking() {
        Log.d(TAG, "Wake tracking: triggering WAKE mode")
        try {
            TrackingService.wake(this)
        } catch (e: Exception) {
            TrackingService.start(this)
        }
    }

    // ------------------------------------------------------------------ //
    //  Visible notifications
    // ------------------------------------------------------------------ //

    /**
     * Show a user-visible notification for all types except `wake_tracking`.
     */
    private fun handleVisibleNotification(
        type: String,
        data: Map<String, String>,
        remoteNotification: RemoteMessage.Notification?
    ) {
        val title = data["title"]
            ?: remoteNotification?.title
            ?: getNotificationTitle(type)
        val body = data["body"]
            ?: remoteNotification?.body
            ?: ""
        val actionUrl = data["action_url"]

        ensureNotificationChannels()

        val channelId = if (type == "tracking") CHANNEL_TRACKING else CHANNEL_ALERTS
        val notificationId = (type.hashCode() + System.currentTimeMillis().toInt())

        val builder = NotificationCompat.Builder(this, channelId)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(title)
            .setContentText(body)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))

        // Deep link via action_url.
        val contentIntent = buildContentIntent(actionUrl, type, data)
        if (contentIntent != null) {
            builder.setContentIntent(contentIntent)
        }

        val nm = getSystemService(NotificationManager::class.java)
        nm.notify(notificationId, builder.build())
    }

    /**
     * Build a [PendingIntent] that deep-links into the app.
     *
     * If [actionUrl] is provided it is used as a VIEW intent URI; otherwise
     * the app's main activity is opened with extras describing the
     * notification type.
     */
    private fun buildContentIntent(
        actionUrl: String?,
        type: String,
        data: Map<String, String>
    ): PendingIntent? {
        val intent = if (!actionUrl.isNullOrBlank()) {
            Intent(Intent.ACTION_VIEW, Uri.parse(actionUrl)).apply {
                setPackage(packageName)
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
                putExtra("notification_type", type)
                data.forEach { (k, v) -> putExtra(k, v) }
            }
        } else {
            packageManager.getLaunchIntentForPackage(packageName)?.apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
                putExtra("notification_type", type)
                data.forEach { (k, v) -> putExtra(k, v) }
            }
        } ?: return null

        return PendingIntent.getActivity(
            this,
            type.hashCode(),
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    private fun getNotificationTitle(type: String): String = when (type) {
        "message"   -> "New Message"
        "shopping"  -> "Shopping List"
        "calendar"  -> "Calendar Event"
        "schedule"  -> "Schedule Update"
        "tracking"  -> "Location Update"
        "weather"   -> "Weather Alert"
        "note"      -> "New Note"
        "system"    -> "Relatives"
        else        -> "Relatives"
    }

    private fun ensureNotificationChannels() {
        val nm = getSystemService(NotificationManager::class.java)

        if (nm.getNotificationChannel(CHANNEL_ALERTS) == null) {
            val channel = NotificationChannel(
                CHANNEL_ALERTS,
                "Alerts & Messages",
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = "Family messages, shopping lists, calendar events, and more"
            }
            nm.createNotificationChannel(channel)
        }

        if (nm.getNotificationChannel(CHANNEL_TRACKING) == null) {
            val channel = NotificationChannel(
                CHANNEL_TRACKING,
                "Tracking Notifications",
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "Location tracking status and updates"
                setShowBadge(false)
            }
            nm.createNotificationChannel(channel)
        }
    }
}
