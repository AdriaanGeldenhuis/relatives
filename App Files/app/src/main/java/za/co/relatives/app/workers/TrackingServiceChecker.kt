package za.co.relatives.app.workers

import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import za.co.relatives.app.services.TrackingLocationService
import za.co.relatives.app.utils.PreferencesManager
import java.util.concurrent.TimeUnit

/**
 * WorkManager-based service checker - aggressive restart for killed services.
 *
 * Runs every 5 minutes (WorkManager minimum for periodic is 15, but we use
 * REPLACE policy to force fresh scheduling). Restarts tracking service if
 * no upload happened within 6 minutes (heartbeat is 5 min, so 6 min = missed).
 */
class TrackingServiceChecker(
    context: Context,
    params: WorkerParameters
) : CoroutineWorker(context, params) {

    companion object {
        private const val TAG = "TrackingServiceChecker"
        private const val WORK_NAME = "tracking_service_checker"
        private const val DEAD_THRESHOLD_MS = 6 * 60 * 1000L  // 6 minutes = 1 missed heartbeat

        /**
         * Schedule periodic checks every 15 minutes (WorkManager minimum).
         * Combined with AlarmManager in the service for tighter checks.
         */
        fun schedule(context: Context) {
            val workRequest = PeriodicWorkRequestBuilder<TrackingServiceChecker>(
                15, TimeUnit.MINUTES,
                5, TimeUnit.MINUTES  // Flex interval
            ).build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                workRequest
            )

            Log.d(TAG, "Scheduled periodic tracking service checker (every 15 min)")
        }

        /**
         * Cancel periodic checks.
         * Call this when tracking is disabled.
         */
        fun cancel(context: Context) {
            WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
            Log.d(TAG, "Cancelled tracking service checker")
        }
    }

    override suspend fun doWork(): Result {
        Log.d(TAG, "Checking tracking service status...")

        // Initialize PreferencesManager if needed
        PreferencesManager.init(applicationContext)

        // Check if tracking should be enabled
        if (!PreferencesManager.isTrackingEnabled()) {
            Log.d(TAG, "Tracking disabled in preferences, skipping restart")
            return Result.success()
        }

        // Check if service is running by checking last upload time
        val lastUploadTime = PreferencesManager.lastUploadTime
        val timeSinceLastUpload = System.currentTimeMillis() - lastUploadTime

        if (timeSinceLastUpload > DEAD_THRESHOLD_MS) {
            // Service hasn't uploaded within heartbeat window - likely killed
            Log.w(TAG, "No upload in ${timeSinceLastUpload / 1000}s (threshold: ${DEAD_THRESHOLD_MS / 1000}s) - restarting")
            TrackingLocationService.startTracking(applicationContext)
            // Also flush any queued locations
            LocationUploadWorker.enqueue(applicationContext)
        } else {
            Log.d(TAG, "Service healthy (last upload ${timeSinceLastUpload / 1000}s ago)")
        }

        return Result.success()
    }
}
