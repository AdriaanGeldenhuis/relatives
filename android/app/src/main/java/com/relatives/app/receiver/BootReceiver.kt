package com.relatives.app.receiver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import com.relatives.app.tracking.PreferencesManager
import com.relatives.app.tracking.TrackingLocationService

/**
 * Broadcast receiver to restart tracking service after device boot.
 *
 * CRITICAL: Only restarts if:
 * 1. tracking_enabled == true (user explicitly enabled tracking)
 * 2. userRequestedStop == false (user didn't explicitly stop)
 * 3. Has valid auth credentials
 *
 * This prevents zombie service restarts after user stops tracking.
 */
class BootReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "BootReceiver"
    }

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED &&
            intent.action != Intent.ACTION_MY_PACKAGE_REPLACED &&
            intent.action != "android.intent.action.QUICKBOOT_POWERON") {
            return
        }

        Log.d(TAG, "Boot completed or package replaced, checking if should restart tracking")

        val prefs = PreferencesManager(context)

        // Guard 1: Tracking must be enabled
        if (!prefs.isTrackingEnabled) {
            Log.d(TAG, "Tracking not enabled, not restarting service")
            return
        }

        // Guard 2: User must not have explicitly stopped
        if (prefs.userRequestedStop) {
            Log.d(TAG, "User requested stop, not restarting service")
            return
        }

        // Guard 3: Must have valid auth
        if (!prefs.hasValidAuth()) {
            Log.d(TAG, "No valid auth credentials, not restarting service")
            return
        }

        // All guards passed - restart the service
        Log.d(TAG, "All guards passed, restarting tracking service")
        TrackingLocationService.startTracking(context)
    }
}
