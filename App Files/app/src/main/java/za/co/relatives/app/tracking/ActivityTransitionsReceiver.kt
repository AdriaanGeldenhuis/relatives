package za.co.relatives.app.tracking

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.SystemClock
import com.google.android.gms.location.ActivityTransition
import com.google.android.gms.location.ActivityTransitionResult
import com.google.android.gms.location.DetectedActivity
import java.util.concurrent.TimeUnit

class ActivityTransitionsReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (!TrackingService.isTrackingEnabled(context) ||
            !TrackingService.hasLocationPermission(context) ||
            !TrackingService.canReviveFromBackground(context)
        ) return

        val event = ActivityTransitionResult.extractResult(intent)?.transitionEvents
            ?.lastOrNull { it.transitionType == ActivityTransition.ACTIVITY_TRANSITION_ENTER }
            ?: return
        val age = SystemClock.elapsedRealtimeNanos() - event.elapsedRealTimeNanos
        if (age !in 0..TimeUnit.MILLISECONDS.toNanos(TrackingMotion.IDLE_AFTER_MS)) return

        when (event.activityType) {
            DetectedActivity.STILL -> TrackingService.motionStopped(context)
            DetectedActivity.IN_VEHICLE -> TrackingService.motionStarted(context, driving = true)
            DetectedActivity.ON_BICYCLE, DetectedActivity.ON_FOOT,
            DetectedActivity.RUNNING, DetectedActivity.WALKING -> TrackingService.motionStarted(context)
        }
    }
}
