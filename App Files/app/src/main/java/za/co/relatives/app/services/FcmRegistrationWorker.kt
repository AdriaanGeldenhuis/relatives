package za.co.relatives.app.services

import android.content.Context
import android.util.Log
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import java.util.concurrent.TimeUnit
import za.co.relatives.app.network.ApiClient
import za.co.relatives.app.utils.PreferencesManager

/**
 * Registers the device's FCM token with the backend, retrying with backoff.
 *
 * onNewToken() fires when Play Services rotates the token — often while the
 * app is backgrounded or before the user has logged in. Its previous
 * fire-and-forget network call left a rotated token unregistered until the
 * next app open, a window in which the server was still pushing to the dead
 * old token (and pruning it as UNREGISTERED), so no notification could reach
 * this device.
 */
class FcmRegistrationWorker(
    ctx: Context,
    params: WorkerParameters,
) : CoroutineWorker(ctx, params) {

    companion object {
        private const val TAG = "FcmRegWorker"
        private const val WORK_NAME = "fcm_token_registration"
        private const val MAX_ATTEMPTS = 6

        fun enqueue(ctx: Context) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()
            val req = OneTimeWorkRequestBuilder<FcmRegistrationWorker>()
                .setConstraints(constraints)
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(ctx).enqueueUniqueWork(
                WORK_NAME,
                ExistingWorkPolicy.REPLACE,
                req,
            )
        }
    }

    override suspend fun doWork(): Result {
        val prefs = PreferencesManager(applicationContext)
        val token = prefs.fcmToken
        if (token.isNullOrBlank()) return Result.success()

        return try {
            ApiClient(applicationContext).registerFcmToken(token)
            Log.d(TAG, "FCM token registered with backend")
            Result.success()
        } catch (e: Exception) {
            // Typically 401 (not logged in yet) or offline. Retry a few
            // times; beyond that MainActivity.syncFcmToken registers on the
            // next app open.
            Log.w(TAG, "FCM token registration failed (attempt $runAttemptCount)", e)
            if (runAttemptCount >= MAX_ATTEMPTS) Result.failure() else Result.retry()
        }
    }
}
