package za.co.relatives.app.services

import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.util.Log
import androidx.core.app.NotificationCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import za.co.relatives.app.MainActivity
import za.co.relatives.app.utils.NotificationHelper
import za.co.relatives.app.utils.PreferencesManager

/**
 * Firebase Cloud Messaging Service
 * Handles incoming push notifications and routes them to correct screens
 */
class RelativesFirebaseService : FirebaseMessagingService() {

    companion object {
        private const val TAG = "FCMService"

        // Notification types matching server
        const val TYPE_MESSAGE = "message"
        const val TYPE_SHOPPING = "shopping"
        const val TYPE_CALENDAR = "calendar"
        const val TYPE_SCHEDULE = "schedule"
        const val TYPE_TRACKING = "tracking"
        const val TYPE_WEATHER = "weather"
        const val TYPE_NOTE = "note"
        const val TYPE_SYSTEM = "system"
    }

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        Log.d(TAG, "New FCM token: $token")
        // Token will be registered when user logs in
        // Store it for later registration
        PreferencesManager.init(applicationContext)
        getSharedPreferences("fcm_prefs", Context.MODE_PRIVATE)
            .edit()
            .putString("fcm_token", token)
            .putBoolean("token_needs_sync", true)
            .apply()
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        Log.d(TAG, "FCM message received from: ${message.from}")

        // Get data payload
        val data = message.data
        val notification = message.notification

        // Extract notification details
        val title = notification?.title ?: data["title"] ?: "New Notification"
        val body = notification?.body ?: data["body"] ?: data["message"] ?: ""
        val type = data["type"] ?: TYPE_SYSTEM
        val actionUrl = data["action_url"] ?: getDefaultUrlForType(type)
        val notificationId = data["notification_id"]?.toIntOrNull() ?: System.currentTimeMillis().toInt()

        Log.d(TAG, "Notification - Type: $type, Title: $title, ActionUrl: $actionUrl")

        // Show the notification with correct deep link
        showNotification(notificationId, title, body, type, actionUrl, data)
    }

    private fun showNotification(
        notificationId: Int,
        title: String,
        body: String,
        type: String,
        actionUrl: String,
        data: Map<String, String>
    ) {
        // Create notification channel if needed
        NotificationHelper.createNotificationChannel(this)

        // Build full URL
        val fullUrl = if (actionUrl.startsWith("http")) {
            actionUrl
        } else {
            "https://www.relatives.co.za${actionUrl}"
        }

        // Intent to open MainActivity with the correct URL
        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra("open_url", fullUrl)
            putExtra("notification_type", type)
            putExtra("notification_id", notificationId.toString())
        }

        val pendingIntent = PendingIntent.getActivity(
            this,
            notificationId, // Unique request code per notification
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        // Get appropriate icon for type
        val icon = getIconForType(type)

        // Build notification
        val notification = NotificationCompat.Builder(this, NotificationHelper.CHANNEL_ALERTS)
            .setSmallIcon(icon)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(getCategoryForType(type))
            .setContentIntent(pendingIntent)
            .setAutoCancel(true)
            .setGroup(type) // Group by notification type
            .build()

        val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        notificationManager.notify(notificationId, notification)
    }

    /**
     * Get default URL for notification type
     */
    private fun getDefaultUrlForType(type: String): String {
        return when (type) {
            TYPE_MESSAGE -> "/messages/"
            TYPE_SHOPPING -> "/shopping/"
            TYPE_CALENDAR -> "/calendar/"
            TYPE_SCHEDULE -> "/schedule/"
            TYPE_TRACKING -> "/tracking/"
            TYPE_WEATHER -> "/weather/"
            TYPE_NOTE -> "/notes/"
            TYPE_SYSTEM -> "/notifications/"
            else -> "/notifications/"
        }
    }

    /**
     * Get icon for notification type
     */
    private fun getIconForType(type: String): Int {
        return when (type) {
            TYPE_MESSAGE -> android.R.drawable.ic_dialog_email
            TYPE_SHOPPING -> android.R.drawable.ic_menu_agenda
            TYPE_CALENDAR -> android.R.drawable.ic_menu_my_calendar
            TYPE_SCHEDULE -> android.R.drawable.ic_menu_recent_history
            TYPE_TRACKING -> android.R.drawable.ic_menu_mylocation
            TYPE_WEATHER -> android.R.drawable.ic_menu_compass
            TYPE_NOTE -> android.R.drawable.ic_menu_edit
            else -> android.R.drawable.ic_dialog_info
        }
    }

    /**
     * Get notification category for type
     */
    private fun getCategoryForType(type: String): String {
        return when (type) {
            TYPE_MESSAGE -> NotificationCompat.CATEGORY_MESSAGE
            TYPE_CALENDAR, TYPE_SCHEDULE -> NotificationCompat.CATEGORY_EVENT
            TYPE_TRACKING -> NotificationCompat.CATEGORY_NAVIGATION
            TYPE_WEATHER -> NotificationCompat.CATEGORY_STATUS
            else -> NotificationCompat.CATEGORY_SOCIAL
        }
    }
}
