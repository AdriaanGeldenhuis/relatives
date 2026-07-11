package za.co.relatives.app.services

import android.util.Log
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
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

    override fun onDestroy() {
        serviceScope.cancel()
        super.onDestroy()
    }

    // ------------------------------------------------------------------ //
    //  Token management
    // ------------------------------------------------------------------ //

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        Log.d(TAG, "New FCM token received")

        // Persist via PreferencesManager.
        val prefs = (application as RelativesApplication).preferencesManager
        prefs.fcmToken = token

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
     * Used when another family member taps "Wake" on the tracking screen.
     *
     * Only acts when this device has tracking enabled AND location permission —
     * otherwise the service cannot legally run as a location foreground
     * service and the wake must be ignored (previously this crashed every
     * family device that had never enabled tracking).
     */
    private fun handleWakeTracking() {
        if (!TrackingService.isTrackingEnabled(this)) {
            Log.d(TAG, "Wake tracking ignored: tracking not enabled on this device")
            return
        }
        if (!TrackingService.hasLocationPermission(this)) {
            Log.d(TAG, "Wake tracking ignored: no location permission")
            return
        }
        if (!TrackingService.canReviveFromBackground(this)) {
            // A service *started* from the background without background
            // location never receives fixes — it would just sit in MOVING
            // mode with a notification, burning battery for nothing.
            Log.d(TAG, "Wake tracking ignored: no background location and service not running")
            return
        }
        Log.d(TAG, "Wake tracking: triggering motion mode")
        try {
            TrackingService.motionStarted(this)
        } catch (e: Exception) {
            Log.w(TAG, "Wake tracking failed", e)
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
