package za.co.relatives.app.tracking

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import com.google.android.gms.location.ActivityTransition
import com.google.android.gms.location.ActivityTransitionResult
import com.google.android.gms.location.DetectedActivity

class ActivityTransitionsReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "ActivityReceiver"
    }

    override fun onReceive(context: Context, intent: Intent) {
        if (!isTrackingEnabled(context)) {
            Log.d(TAG, "Tracking disabled, ignoring activity transition.")
            return
        }

        if (ActivityTransitionResult.hasResult(intent)) {
            val result = ActivityTransitionResult.extractResult(intent)
            result?.transitionEvents?.forEach { event ->
                val activityType = nameForActivity(event.activityType)
                val transitionType = nameForTransition(event.transitionType)
                Log.d(TAG, "Activity Event: $activityType ($transitionType)")

                when (event.transitionType) {
                    ActivityTransition.ACTIVITY_TRANSITION_ENTER -> handleEnterTransition(context, event.activityType)
                    ActivityTransition.ACTIVITY_TRANSITION_EXIT -> handleExitTransition(context, event.activityType)
                }
            }
        }
    }

    private fun handleEnterTransition(context: Context, activityType: Int) {
        when (activityType) {
            DetectedActivity.STILL -> {
                Log.i(TAG, "Stillness detected (ENTER), notifying service to go IDLE.")
                TrackingService.motionStopped(context)
            }
            DetectedActivity.IN_VEHICLE,
            DetectedActivity.ON_BICYCLE,
            DetectedActivity.ON_FOOT,
            DetectedActivity.RUNNING,
            DetectedActivity.WALKING -> {
                Log.i(TAG, "Movement detected (ENTER), notifying service.")
                TrackingService.motionStarted(context)
            }
        }
    }

    private fun handleExitTransition(context: Context, activityType: Int) {
        when (activityType) {
            // Exiting a moving state could mean we are now still.
            // Let the service know so it can re-evaluate its state.
            DetectedActivity.IN_VEHICLE,
            DetectedActivity.ON_BICYCLE,
            DetectedActivity.ON_FOOT,
            DetectedActivity.RUNNING,
            DetectedActivity.WALKING -> {
                Log.i(TAG, "Movement stopped (EXIT), notifying service.")
                TrackingService.motionStopped(context)
            }
        }
    }

    private fun isTrackingEnabled(context: Context): Boolean {
        val prefs = context.getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)
        return prefs.getBoolean("tracking_enabled", false)
    }

    private fun nameForActivity(activityType: Int): String = when (activityType) {
        DetectedActivity.IN_VEHICLE -> "IN_VEHICLE"
        DetectedActivity.ON_BICYCLE -> "ON_BICYCLE"
        DetectedActivity.ON_FOOT -> "ON_FOOT"
        DetectedActivity.RUNNING -> "RUNNING"
        DetectedActivity.STILL -> "STILL"
        DetectedActivity.WALKING -> "WALKING"
        else -> "UNKNOWN"
    }

    private fun nameForTransition(transitionType: Int): String = when (transitionType) {
        ActivityTransition.ACTIVITY_TRANSITION_ENTER -> "ENTER"
        ActivityTransition.ACTIVITY_TRANSITION_EXIT -> "EXIT"
        else -> "UNKNOWN"
    }
}
