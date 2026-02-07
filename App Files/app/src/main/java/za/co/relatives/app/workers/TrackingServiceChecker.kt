package za.co.relatives.app.workers

import android.app.ActivityManager
import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import za.co.relatives.app.services.TrackingLocationService
import java.util.concurrent.TimeUnit

/**
 * Periodic WorkManager task (every 15 minutes) that acts as a watchdog for
 * [TrackingLocationService].
 *
 * If the user has tracking enabled but the foreground service is no longer
 * running (e.g. killed by the OS), this worker restarts it and triggers a
 * location upload flush.
 */
class TrackingServiceChecker(
    appContext: Context,
    params: WorkerParameters
) : CoroutineWorker(appContext, params) {

    companion object {
        private const val TAG = "TrackingSvcChecker"
        const val WORK_NAME = "tracking_service_checker"

        private const val PREF_NAME = "relatives_prefs"
        private const val PREF_TRACKING_ENABLED = "tracking_enabled"
        private const val PREF_LAST_UPLOAD = "last_upload_time"

        /** Stale threshold: if no upload for 10 minutes the service is dead. */
        private const val STALE_THRESHOLD_MS = 10L * 60 * 1000

        /**
         * Schedule the periodic checker. Safe to call multiple times -- uses
         * [ExistingPeriodicWorkPolicy.KEEP] so duplicate enqueues are ignored.
         */
        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<TrackingServiceChecker>(
                15, TimeUnit.MINUTES
            ).build()
            WorkManager.getInstance(context)
                .enqueueUniquePeriodicWork(
                    WORK_NAME,
                    ExistingPeriodicWorkPolicy.KEEP,
                    request
                )
        }

        /** Cancel the periodic checker (e.g. when tracking is deliberately disabled). */
        fun cancel(context: Context) {
            WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
        }
    }

    override suspend fun doWork(): Result {
        val prefs = applicationContext.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
        val trackingEnabled = prefs.getBoolean(PREF_TRACKING_ENABLED, false)

        if (!trackingEnabled) {
            Log.d(TAG, "Tracking not enabled, skipping check")
            return Result.success()
        }

        val serviceRunning = isServiceRunning()
        val lastUpload = prefs.getLong(PREF_LAST_UPLOAD, 0L)
        val stale = System.currentTimeMillis() - lastUpload > STALE_THRESHOLD_MS

        Log.d(TAG, "Check: serviceRunning=$serviceRunning stale=$stale lastUpload=$lastUpload")

        if (!serviceRunning || stale) {
            Log.w(TAG, "Service appears dead or stale, restarting")
            restartService()
        }

        return Result.success()
    }

    /**
     * Check whether [TrackingLocationService] is currently among the running
     * services for this process.
     */
    @Suppress("DEPRECATION")
    private fun isServiceRunning(): Boolean {
        val am = applicationContext.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
        val services = am.getRunningServices(Int.MAX_VALUE)
        val targetName = TrackingLocationService::class.java.name
        return services.any { it.service.className == targetName }
    }

    /**
     * Restart the tracking service and immediately trigger a location upload
     * to flush any items that accumulated while the service was dead.
     */
    private fun restartService() {
        try {
            TrackingLocationService.start(applicationContext)
            // Flush queued locations.
            LocationUploadWorker.enqueue(applicationContext)
            Log.i(TAG, "Tracking service restarted and upload flushed")
        } catch (e: Exception) {
            Log.e(TAG, "Failed to restart tracking service", e)
        }
    }
}
