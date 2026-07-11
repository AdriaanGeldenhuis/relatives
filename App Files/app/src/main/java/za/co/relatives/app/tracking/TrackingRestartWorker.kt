package za.co.relatives.app.tracking

import android.content.Context
import android.util.Log
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.Worker
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import java.util.concurrent.TimeUnit

/**
 * TrackingRestartWorker — restarts TrackingService after swipe-away or kill.
 *
 * Scheduled by TrackingService.onTaskRemoved() with a short delay so that
 * the service comes back even when the OS destroys it.
 */
class TrackingRestartWorker(
    ctx: Context,
    params: WorkerParameters,
) : Worker(ctx, params) {

    companion object {
        private const val TAG = "TrackingRestartWorker"
        private const val WORK_NAME = "tracking_restart"

        fun enqueue(ctx: Context) {
            val req = OneTimeWorkRequestBuilder<TrackingRestartWorker>()
                .setInitialDelay(15, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(ctx).enqueueUniqueWork(
                WORK_NAME,
                ExistingWorkPolicy.REPLACE,
                req
            )
        }
    }

    override fun doWork(): Result {
        val enabled = applicationContext
            .getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)
            .getBoolean("tracking_enabled", false)

        if (enabled && !TrackingService.isRunning && TrackingService.hasLocationPermission(applicationContext)) {
            // Works on Android 8-11; on 12+ background FGS starts are blocked
            // and TrackingService.start() swallows the refusal — the geofence /
            // activity-recognition triggers will revive the service instead.
            Log.i(TAG, "Restarting TrackingService after task removal.")
            TrackingService.start(applicationContext)
        } else {
            Log.d(TAG, "Restart not needed (enabled=$enabled, running=${TrackingService.isRunning}).")
        }
        return Result.success()
    }
}
