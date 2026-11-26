package za.co.relatives.app.tracking

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import com.google.android.gms.location.Geofence
import com.google.android.gms.location.GeofencingEvent
import za.co.relatives.app.utils.PreferencesManager

class GeofenceReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "GeofenceReceiver"
    }

    override fun onReceive(context: Context, intent: Intent?) {
        val prefs = PreferencesManager(context)
        val consent = prefs.trackingConsent()
        if (consent == null || !prefs.trackingPolicy().canCollect || !TrackingService.hasLocationPermission(context)) {
            Log.d(TAG, "Tracking disabled or permission missing, ignoring geofence event.")
            return
        }
        if (!TrackingService.canReviveFromBackground(context)) {
            Log.d(TAG, "No background location permission and service not running; skipping revival.")
            return
        }

        if (intent == null) return
        val geofencingEvent = GeofencingEvent.fromIntent(intent)
        if (geofencingEvent == null || geofencingEvent.hasError()) {
            val errorCode = geofencingEvent?.errorCode ?: "unknown"
            Log.e(TAG, "Geofence error: $errorCode")
            if (intent.action == FamilyBoundaryMonitor.ACTION) TrackingService.boundaryUnavailable()
            return
        }

        if (intent.action == FamilyBoundaryMonitor.ACTION) {
            if (FamilyBoundaryMonitor.eligible(context) &&
                geofencingEvent.triggeringGeofences.orEmpty().any { FamilyBoundaryMonitor.owns(consent.scope, it.requestId) }
            ) TrackingService.boundaryTriggered(context, geofencingEvent.triggeringLocation)
            return
        }

        // For the idle anchor geofence, we only care about the EXIT event.
        // This is the primary trigger to indicate the user is moving again.
        if (geofencingEvent.geofenceTransition == Geofence.GEOFENCE_TRANSITION_EXIT) {
            Log.i(TAG, "Geofence EXIT triggered, notifying service.")
            TrackingService.motionStarted(context)
        }
    }
}
