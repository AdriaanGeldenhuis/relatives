package za.co.relatives.app.services

import android.util.Log
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import za.co.relatives.app.R
import za.co.relatives.app.RelativesApplication
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
        val type = data["type"] ?: "system"

        Log.d(TAG, "Message received: type=$type data=$data")

        when (type) {
            "wake_tracking" -> handleWakeTracking(keepalive = data["keepalive"] == "1")
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
    private fun handleWakeTracking(keepalive: Boolean) {
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
        try {
            if (keepalive) {
                // Server cron keepalive: this high-priority push grants a
                // temporary background-start exemption, so a service the OS
                // killed can be revived here. Quiet IDLE restart only — a
                // GPS burst every cron tick would drain the battery. revive()
                // (not start()) so a racing user disable always wins.
                Log.d(TAG, "Keepalive wake: ensuring TrackingService is running")
                TrackingService.revive(this)
            } else {
                // A family member tapped "Wake": burst mode + immediate fix.
                Log.d(TAG, "Wake tracking: triggering motion mode")
                TrackingService.motionStarted(this)
            }
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
