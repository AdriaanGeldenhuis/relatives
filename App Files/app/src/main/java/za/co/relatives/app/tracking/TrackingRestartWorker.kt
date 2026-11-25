package za.co.relatives.app.tracking

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.utils.PreferencesManager
import java.util.concurrent.TimeUnit

/** Connected queue recovery for an opted-in device. Android controls service restarts. */
class TrackingRestartWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    companion object {
        private const val WATCHDOG_NAME = "tracking_watchdog"
        private const val KEY_DISCARD_SCOPE = "discard_consent_scope"

        fun discard(context: Context, scope: String) {
            WorkManager.getInstance(context).enqueue(
                OneTimeWorkRequestBuilder<TrackingRestartWorker>()
                    .setInputData(workDataOf(KEY_DISCARD_SCOPE to scope)).build(),
            )
        }

        fun enqueuePeriodic(context: Context) {
            val request = PeriodicWorkRequestBuilder<TrackingRestartWorker>(30, TimeUnit.MINUTES, 10, TimeUnit.MINUTES)
                .setInitialDelay(30, TimeUnit.MINUTES)
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(WATCHDOG_NAME, ExistingPeriodicWorkPolicy.UPDATE, request)
        }

        fun cancel(context: Context) {
            val manager = WorkManager.getInstance(context)
            manager.cancelUniqueWork(WATCHDOG_NAME)
            manager.cancelUniqueWork("tracking_restart")
            manager.cancelUniqueWork("tracking_wake_fix")
        }
    }

    override suspend fun doWork(): Result {
        val context = applicationContext
        val store = TrackingStore(context)
        inputData.getString(KEY_DISCARD_SCOPE)?.let { scope ->
            store.discardUnsentScope(scope)
            return Result.success()
        }
        val prefs = PreferencesManager(context)
        val consent = prefs.trackingConsent() ?: return Result.success()
        if (prefs.trackingPolicy().canCollect && store.unsentCount(consent.scope) > 0) {
            LocationUploadWorker.enqueue(context)
        }
        return Result.success()
    }
}
