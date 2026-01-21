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
 * WorkManager-based service checker for Huawei/Honor/Xiaomi devices.
 *
 * These devices have aggressive battery management that kills foreground services
 * even with ongoing notifications. WorkManager uses Android's JobScheduler which
 * is more resilient to battery optimization.
 *
 * This worker runs every 15 minutes and restarts the tracking service if:
 * 1. Tracking is enabled in preferences
 * 2. The service is not currently running
 */
class TrackingServiceChecker(
    context: Context,
    params: WorkerParameters
) : CoroutineWorker(context, params) {

    companion object {
        private const val TAG = "TrackingServiceChecker"
        private const val WORK_NAME = "tracking_service_checker"

        /**
         * Schedule periodic checks every 15 minutes.
         * Call this when tracking is enabled.
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
        val tenMinutesMs = 10 * 60 * 1000L

        if (timeSinceLastUpload > tenMinutesMs) {
            // Service hasn't uploaded in over 10 minutes - likely killed
            Log.w(TAG, "No upload in ${timeSinceLastUpload / 1000}s - restarting tracking service")
            TrackingLocationService.startTracking(applicationContext)
        } else {
            Log.d(TAG, "Service appears healthy (last upload ${timeSinceLastUpload / 1000}s ago)")
        }

        return Result.success()
    }
}
