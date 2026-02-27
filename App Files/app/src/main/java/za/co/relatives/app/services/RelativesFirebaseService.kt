package za.co.relatives.app.services

import android.util.Log
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import za.co.relatives.app.R
import za.co.relatives.app.RelativesApplication
import za.co.relatives.app.network.ApiClient
import za.co.relatives.app.tracking.TrackingService
import za.co.relatives.app.utils.NotificationHelper

/**
 * Firebase Cloud Messaging service for the Relatives app.
 *
 * Handles incoming push notifications of various types and manages FCM token
 * registration with the backend.
 *
 * Supported notification types:
 * - `message`, `shopping`, `calendar`, `schedule`, `tracking`, `weather`,
 *   `note`, `system` -- displayed as a visible notification with optional deep link.
 * - `wake_tracking` -- silently triggers WAKE mode on [TrackingService]
 *   without showing any notification to the user.
 */
class RelativesFirebaseService : FirebaseMessagingService() {

    companion object {
        private const val TAG = "RelativesFCM"
    }

    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    // ------------------------------------------------------------------ //
    //  Token management
    // ------------------------------------------------------------------ //

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        Log.d(TAG, "New FCM token received")

        // Persist via PreferencesManager (safe cast to avoid crash if Application init failed).
        val prefs = (application as? RelativesApplication)?.preferencesManager
        prefs?.fcmToken = token

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
     * Silently trigger a WAKE location fix without showing any notification.
     * Used when another family member taps "Find Device" or similar.
     */
    private fun handleWakeTracking() {
        Log.d(TAG, "Wake tracking: triggering motion mode")
        try {
            TrackingService.motionStarted(this)
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
        remoteNotification: RemoteMessage.Notification?,
    ) {
        val title = data["title"]
            ?: remoteNotification?.title
            ?: getNotificationTitle(type)
        val body = data["body"]
            ?: remoteNotification?.body
            ?: ""
        val actionUrl = data["action_url"]

        // Use the centralised alert notification helper
        NotificationHelper.showAlertNotification(
            context = this,
            title = title,
            body = body,
            actionUrl = actionUrl,
        )
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
