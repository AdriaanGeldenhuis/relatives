package za.co.relatives.app.tracking

import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import com.google.android.gms.location.ActivityRecognition
import com.google.android.gms.location.ActivityTransition
import com.google.android.gms.location.ActivityTransitionRequest
import com.google.android.gms.location.DetectedActivity

class MotionDetector(private val context: Context) {

    private val transitions = listOf(
        ActivityTransition.Builder()
            .setActivityType(DetectedActivity.STILL)
            .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
            .build(),
        ActivityTransition.Builder()
            .setActivityType(DetectedActivity.WALKING)
            .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
            .build(),
        ActivityTransition.Builder()
            .setActivityType(DetectedActivity.RUNNING)
            .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
            .build(),
        ActivityTransition.Builder()
            .setActivityType(DetectedActivity.IN_VEHICLE)
            .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
            .build(),
        ActivityTransition.Builder()
            .setActivityType(DetectedActivity.ON_BICYCLE)
            .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
            .build()
    )

    fun startMonitoring() {
        val request = ActivityTransitionRequest(transitions)
        val pendingIntent = PendingIntent.getBroadcast(
            context, 100,
            Intent(context, MotionTransitionReceiver::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
        )

        try {
            ActivityRecognition.getClient(context)
                .requestActivityTransitionUpdates(request, pendingIntent)
        } catch (e: SecurityException) {
            android.util.Log.e("MotionDetector", "Permission denied", e)
        }
    }

    fun stopMonitoring() {
        val pendingIntent = PendingIntent.getBroadcast(
            context, 100,
            Intent(context, MotionTransitionReceiver::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
        )
        try {
            ActivityRecognition.getClient(context)
                .removeActivityTransitionUpdates(pendingIntent)
        } catch (_: Exception) {}
    }
}

// Simple receiver - in production, this would be a separate file
class MotionTransitionReceiver : android.content.BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        // When motion detected, start tracking service
        if (ActivityTransition.hasResult(intent)) {
            val result = ActivityTransition.extractResult(intent)
            result?.transitionEvents?.forEach { event ->
                if (event.activityType != DetectedActivity.STILL) {
                    val serviceIntent = Intent(context, LocationTrackingService::class.java).apply {
                        action = LocationTrackingService.ACTION_START
                    }
                    context.startForegroundService(serviceIntent)
                }
            }
        }
    }
}
