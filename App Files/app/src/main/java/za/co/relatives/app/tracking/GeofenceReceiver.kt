package za.co.relatives.app.tracking

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import com.google.android.gms.location.Geofence
import com.google.android.gms.location.GeofencingEvent

class GeofenceReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "GeofenceReceiver"
    }

    override fun onReceive(context: Context, intent: Intent?) {
        val prefs = context.getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)
        if (!prefs.getBoolean("tracking_enabled", false)) {
            Log.d(TAG, "Tracking disabled, ignoring geofence event.")
            return
        }

        if (intent == null) return
        val geofencingEvent = GeofencingEvent.fromIntent(intent)
        if (geofencingEvent == null || geofencingEvent.hasError()) {
            val errorCode = geofencingEvent?.errorCode ?: "unknown"
            Log.e(TAG, "Geofence error: $errorCode")
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
