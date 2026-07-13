package za.co.relatives.app.tracking

import android.annotation.SuppressLint
import android.content.Context
import android.util.Log
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.Worker
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.Tasks
import java.util.concurrent.TimeUnit
import za.co.relatives.app.data.QueuedLocationEntity
import za.co.relatives.app.data.TrackingStore

/**
 * TrackingRestartWorker — the tracking watchdog.
 *
 * Two schedules share this worker:
 *  - a one-time run 15s after task swipe-away ([enqueue], from
 *    TrackingService.onTaskRemoved), and
 *  - a 15-minute periodic run ([enqueuePeriodic], armed whenever tracking
 *    starts) that survives process death — this is what brings tracking
 *    back after Doze/OEM battery killers silently kill the service, which
 *    onTaskRemoved never sees.
 *
 * Each run: if tracking should be on but the service is dead, restart it
 * (the FGS-from-background refusal on some devices is swallowed inside
 * TrackingService.safeStart). Independently, if no fix has been captured
 * for [STALE_FIX_MS], capture one directly here and queue it for upload —
 * so the family still sees a recent dot even when the service cannot be
 * restarted from the background.
 */
class TrackingRestartWorker(
    ctx: Context,
    params: WorkerParameters,
) : Worker(ctx, params) {

    companion object {
        private const val TAG = "TrackingRestartWorker"
        private const val WORK_NAME = "tracking_restart"
        private const val WATCHDOG_NAME = "tracking_watchdog"

        /** No fix for this long → the watchdog captures one itself. */
        private const val STALE_FIX_MS = 20 * 60 * 1000L

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

        /** Arm (or keep) the periodic watchdog. 15 min is WorkManager's floor. */
        fun enqueuePeriodic(ctx: Context) {
            val req = PeriodicWorkRequestBuilder<TrackingRestartWorker>(15, TimeUnit.MINUTES)
                .build()
            WorkManager.getInstance(ctx).enqueueUniquePeriodicWork(
                WATCHDOG_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                req
            )
        }
    }

    override fun doWork(): Result {
        val ctx = applicationContext
        val prefs = ctx.getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)

        if (!prefs.getBoolean("tracking_enabled", false)) {
            // User turned tracking off — disarm the watchdog so it stops
            // waking the device for nothing. Re-armed on next enable.
            Log.d(TAG, "Tracking disabled; disarming watchdog.")
            WorkManager.getInstance(ctx).cancelUniqueWork(WATCHDOG_NAME)
            return Result.success()
        }
        if (!TrackingService.hasLocationPermission(ctx)) {
            return Result.success()
        }
        if (!TrackingService.canReviveFromBackground(ctx)) {
            // Without background location a service started from here would
            // sit fix-less; MainActivity.onResume remains the revival path.
            return Result.success()
        }

        if (!TrackingService.isRunning) {
            Log.i(TAG, "Watchdog: TrackingService not running — reviving.")
            TrackingService.revive(ctx)
        }

        // Freshness backstop: even if the restart was refused (Android 12+
        // background-FGS block on some paths), keep the family map alive.
        val lastFix = prefs.getLong("last_fix_time", 0L)
        if (System.currentTimeMillis() - lastFix > STALE_FIX_MS) {
            captureFallbackFix(ctx)
        }
        return Result.success()
    }

    @SuppressLint("MissingPermission")
    private fun captureFallbackFix(ctx: Context) {
        try {
            val task = LocationServices.getFusedLocationProviderClient(ctx)
                .getCurrentLocation(Priority.PRIORITY_BALANCED_POWER_ACCURACY, null)
            val loc = Tasks.await(task, 50, TimeUnit.SECONDS) ?: return

            val now = System.currentTimeMillis()
            TrackingStore(ctx).enqueueLocation(
                QueuedLocationEntity(
                    lat = loc.latitude, lng = loc.longitude,
                    accuracy = if (loc.hasAccuracy()) loc.accuracy else null,
                    altitude = if (loc.hasAltitude()) loc.altitude else null,
                    bearing = if (loc.hasBearing()) loc.bearing else null,
                    speed = if (loc.hasSpeed()) loc.speed else null,
                    speedKmh = if (loc.hasSpeed()) loc.speed * 3.6f else null,
                    isMoving = false,
                    batteryLevel = null,
                    timestamp = now,
                )
            )
            ctx.getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)
                .edit().putLong("last_fix_time", now).apply()

            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()
            // 15s delay matches TrackingService.scheduleUpload: the Room
            // insert above is async, and an instant worker could run before
            // the row commits, uploading nothing.
            val upload = OneTimeWorkRequestBuilder<LocationUploadWorker>()
                .setConstraints(constraints)
                .setInitialDelay(15, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(ctx).enqueueUniqueWork(
                LocationUploadWorker.WORK_NAME,
                ExistingWorkPolicy.KEEP,
                upload
            )
            Log.i(TAG, "Watchdog: captured fallback fix.")
        } catch (t: Throwable) {
            Log.w(TAG, "Watchdog fallback fix failed", t)
        }
    }
}
