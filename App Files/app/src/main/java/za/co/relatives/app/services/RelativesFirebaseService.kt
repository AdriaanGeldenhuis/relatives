package za.co.relatives.app.services

import android.Manifest
import android.app.PendingIntent
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import za.co.relatives.app.R
import za.co.relatives.app.MainActivity
import za.co.relatives.app.RelativesApplication
import za.co.relatives.app.tracking.FamilyWake
import za.co.relatives.app.tracking.TrackingClock
import za.co.relatives.app.tracking.TrackingService
import za.co.relatives.app.tracking.WakeReceiptWorker
import za.co.relatives.app.utils.NotificationHelper
import za.co.relatives.app.utils.PreferencesManager

/**
 * Firebase Cloud Messaging service for the Relatives app.
 *
 * Handles incoming push notifications of various types and manages FCM token
 * registration with the backend.
 *
 * Supported notification types:
 * - `message`, `shopping`, `calendar`, `schedule`, `tracking`, `weather`,
 *   `note`, `system` -- displayed as a visible notification with optional deep link.
 * - `wake_tracking` -- requests one fix if sharing is active.
 */
class RelativesFirebaseService : FirebaseMessagingService() {

    companion object {
        private const val TAG = "RelativesFCM"
        private val LIVE_ID = Regex("[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}")
    }

    // ------------------------------------------------------------------ //
    //  Token management
    // ------------------------------------------------------------------ //

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        Log.d(TAG, "New FCM token received")

        // Persist via PreferencesManager (safe cast to avoid crash if Application init failed).
        val prefs = (application as? RelativesApplication)?.preferencesManager
            ?: za.co.relatives.app.utils.PreferencesManager(applicationContext)
        prefs.fcmToken = token

        // Register with backend through WorkManager so the attempt survives
        // this short-lived service and retries with backoff — a plain
        // fire-and-forget call here left rotated tokens unregistered (and the
        // device unreachable) until the next app open.
        FcmRegistrationWorker.enqueue(applicationContext)
    }

    // ------------------------------------------------------------------ //
    //  Message handling
    // ------------------------------------------------------------------ //

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        val data = message.data
        if (!PreferencesManager(this).isPushRecipient(data["recipient_user_id"])) return
        val type = data["type"] ?: "system"

        Log.d(TAG, "Message received: type=$type")

        when (type) {
            "wake_tracking" -> FamilyWake.receive(this, data)
            "family_live_start" -> handleFamilyLive(message, data)
            else -> handleVisibleNotification(type, data, message.notification)
        }
    }

    private fun handleFamilyLive(message: RemoteMessage, data: Map<String, String>) {
        val prefs = PreferencesManager(this)
        val id = data["session_id"]?.takeIf(LIVE_ID::matches) ?: return
        val expiry = data["expires_at_ms"]?.toLongOrNull() ?: return
        if (data["installation_id"] != prefs.deviceUuid || prefs.trackingConsent() == null) return
        if (TrackingService.wakeCapability(this) != "silent_ready") return
        val remaining = (expiry - TrackingClock.now()).coerceIn(0, 75_000)
        if (remaining == 0L) return
        val running = TrackingService.isRunning
        val starting = TrackingService.joinFamilyLive(this, message.priority == RemoteMessage.PRIORITY_HIGH)
        if (starting && running) return

        if (!starting) WakeReceiptWorker.enqueue(this, id, "open_required", expiry, live = true)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) return
        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra("action_url", "https://www.relatives.co.za/tracking/app/")
        }
        val action = PendingIntent.getActivity(this, TrackingService.LIVE_JOIN_NOTIFICATION_ID, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val channel = TrackingService.ensureTrackingChannel(this)
        val notification = NotificationCompat.Builder(this, channel)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(getString(R.string.tracking_live_notification))
            .setContentText(getString(R.string.tracking_live_join))
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setContentIntent(action)
            .setOnlyAlertOnce(true)
            .setAutoCancel(true)
            .setTimeoutAfter(remaining)
            .build()
        runCatching { NotificationManagerCompat.from(this).notify(TrackingService.LIVE_JOIN_NOTIFICATION_ID, notification) }
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
        remoteNotification: RemoteMessage.Notification?,
    ) {
        val title = data["title"]
            ?: remoteNotification?.title
            ?: getNotificationTitle(type)
        val body = data["body"]
            ?: remoteNotification?.body
            ?: ""
        val actionUrl = data["action_url"]
        val locationAlert = type == "tracking" && data["location_alert"] == "1"
        if (type == "tracking" && !locationAlert) return
        val messageId = data["message_id"]?.takeIf {
            type == "message" && it.length in 1..19 && it.all { char -> char in '0'..'9' }
        }
        val newMessage = messageId != null && data["new_message"] == "1"
        val app = application as? RelativesApplication
        if (newMessage && app?.viewingLatestMessages == true && app.notificationEvents.tryEmit(Unit)) {
            data["receipt"]?.let { PushReceiptWorker.enqueue(this, it) }
            return
        }

        // Use the centralised alert notification helper
        val posted = NotificationHelper.showAlertNotification(
            context = this,
            title = title,
            body = body,
            actionUrl = actionUrl,
            stableId = data["notification_id"]?.takeIf { it.length <= 20 && it.all(Char::isDigit) },
            silent = data["silent"] == "1",
            vibrate = data["vibrate"] != "0",
            locationAlert = locationAlert,
            messageId = messageId,
            newMessage = newMessage,
            recipientId = data["recipient_user_id"],
            receipt = data["receipt"],
        )
        if (posted) data["receipt"]?.let { PushReceiptWorker.enqueue(this, it) }
        app?.notificationEvents?.tryEmit(Unit)
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    private fun getNotificationTitle(type: String): String = when (type) {
        "message"  -> getString(R.string.notif_title_message)
        "shopping" -> getString(R.string.notif_title_shopping)
        "calendar" -> getString(R.string.notif_title_calendar)
        "schedule" -> getString(R.string.notif_title_schedule)
        "tracking" -> getString(R.string.notif_title_tracking)
        "weather"  -> getString(R.string.notif_title_weather)
        "note"     -> getString(R.string.notif_title_note)
        "system"   -> getString(R.string.notif_title_system)
        else       -> getString(R.string.notif_title_system)
    }
}
