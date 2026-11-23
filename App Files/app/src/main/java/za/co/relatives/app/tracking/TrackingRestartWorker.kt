package za.co.relatives.app.tracking

import android.content.Context
import android.os.Build
import android.os.PowerManager
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.OutOfQuotaPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import java.util.concurrent.TimeUnit
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.utils.PreferencesManager

/** A low-frequency backstop and the one-shot path when a Wake arrives without a service. */
class TrackingRestartWorker(
    context: Context,
    params: WorkerParameters,
) : CoroutineWorker(context, params) {

    companion object {
        private const val WORK_NAME = "tracking_restart"
        private const val WATCHDOG_NAME = "tracking_watchdog"
        private const val WAKE_FIX_NAME = "tracking_wake_fix"
        private const val KEY_FORCE_FIX = "force_fix"
        private const val KEY_FIX_DEADLINE = "fix_deadline"
        private const val KEY_DISCARD_SCOPE = "discard_consent_scope"

        fun discard(context: Context, scope: String) {
            WorkManager.getInstance(context).enqueue(
                OneTimeWorkRequestBuilder<TrackingRestartWorker>()
                    .setInputData(workDataOf(KEY_DISCARD_SCOPE to scope)).build(),
            )
        }

        fun enqueue(context: Context) {
            val request = OneTimeWorkRequestBuilder<TrackingRestartWorker>()
                .setInitialDelay(15, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork(WORK_NAME, ExistingWorkPolicy.KEEP, request)
        }

        fun enqueueWakeFix(context: Context, expedited: Boolean) {
            val builder = OneTimeWorkRequestBuilder<TrackingRestartWorker>().setInputData(workDataOf(
                KEY_FORCE_FIX to true,
                KEY_FIX_DEADLINE to System.currentTimeMillis() + 60_000L,
            ))
            if (expedited && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                builder.setExpedited(OutOfQuotaPolicy.RUN_AS_NON_EXPEDITED_WORK_REQUEST)
            }
            val request = builder.build()
            WorkManager.getInstance(context).enqueueUniqueWork(WAKE_FIX_NAME, ExistingWorkPolicy.KEEP, request)
        }

        fun enqueuePeriodic(context: Context) {
            val request = PeriodicWorkRequestBuilder<TrackingRestartWorker>(30, TimeUnit.MINUTES, 10, TimeUnit.MINUTES)
                .setInitialDelay(30, TimeUnit.MINUTES)
                .build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WATCHDOG_NAME, ExistingPeriodicWorkPolicy.UPDATE, request,
            )
        }

        fun cancel(context: Context) {
            val manager = WorkManager.getInstance(context)
            manager.cancelUniqueWork(WORK_NAME)
            manager.cancelUniqueWork(WATCHDOG_NAME)
            manager.cancelUniqueWork(WAKE_FIX_NAME)
        }
    }

    override suspend fun doWork(): Result {
        val context = applicationContext
        val store = TrackingStore(context)
        inputData.getString(KEY_DISCARD_SCOPE)?.let { scope ->
            store.discardUnsentScope(scope)
            return Result.success()
        }
        val consent = PreferencesManager(context).trackingConsent()
        if (consent == null || !TrackingService.hasLocationPermission(context) ||
            !TrackingService.canShowTrackingNotification(context)
        ) {
            cancel(context)
            return Result.success()
        }
        if (store.unsentCount(consent.scope) > 0) LocationUploadWorker.enqueue(context)
        val wake = inputData.getBoolean(KEY_FORCE_FIX, false)
        if (wake && System.currentTimeMillis() > inputData.getLong(KEY_FIX_DEADLINE, 0L)) return Result.success()
        if (TrackingService.isRunning && (!wake || TrackingService.requestRunningWake(context))) {
            return Result.success()
        }
        if (!TrackingService.hasBackgroundLocation(context)) return Result.success()
        if (!wake) {
            // Android decides whether this restart is allowed. Regular location
            // sampling always belongs to the visible foreground service.
            TrackingService.revive(context)
            return Result.success()
        }

        // A family refresh has its own visible notification and a short deadline.
        val battery = context.getSystemService(android.os.BatteryManager::class.java)
            ?.getIntProperty(android.os.BatteryManager.BATTERY_PROPERTY_CAPACITY)
        val powerSave = context.getSystemService(PowerManager::class.java)?.isPowerSaveMode == true
        val fix = TrackingFix.current(context, highAccuracy = wake && !powerSave && (battery == null || battery >= 20))
            ?: return Result.success()
        if (TrackingService.isTrackingEnabled(context) &&
            store.enqueueLocation(TrackingFix.asQueueEntry(fix, moving = false), consent.scope)
        ) {
            LocationUploadWorker.enqueue(context)
        }
        return Result.success()
    }
}
