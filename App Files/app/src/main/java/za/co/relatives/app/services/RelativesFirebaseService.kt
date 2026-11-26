package za.co.relatives.app.services

import android.util.Log
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import za.co.relatives.app.R
import za.co.relatives.app.RelativesApplication
import za.co.relatives.app.tracking.FamilyWake
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
            else -> handleVisibleNotification(type, data, message.notification)
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
        )
        if (posted) data["receipt"]?.let { PushReceiptWorker.enqueue(this, it) }
        (application as? RelativesApplication)?.notificationEvents?.tryEmit(Unit)
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
