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
import kotlinx.coroutines.CancellationException
import za.co.relatives.app.network.ApiClient
import za.co.relatives.app.network.ApiException
import za.co.relatives.app.utils.PreferencesManager
import za.co.relatives.app.tracking.GmsSupport
import za.co.relatives.app.tracking.TrackingService

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

        fun cancel(ctx: Context) {
            WorkManager.getInstance(ctx).cancelUniqueWork(WORK_NAME)
        }

        fun enqueue(ctx: Context) {
            if (!GmsSupport.available(ctx)) return
            val prefs = PreferencesManager(ctx)
            val token = prefs.fcmToken ?: return
            val cookie = prefs.sessionToken ?: return
            if (prefs.registrationMatches(cookie, token, TrackingService.wakeCapability(ctx))) return
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
        val token = prefs.fcmToken ?: return Result.success()
        val session = prefs.sessionToken ?: return Result.success()

        return try {
            val capability = TrackingService.wakeCapability(applicationContext)
            if (prefs.registrationMatches(session, token, capability)) return Result.success()
            val registration = ApiClient(applicationContext).registerFcmToken(token, session, prefs.deviceUuid, capability)
            val recipient = registration.get("recipient_user_id")?.asString ?: return Result.failure()
            if (prefs.sessionToken == session && prefs.fcmToken == token) {
                prefs.bindPushRecipient(session, recipient)
                prefs.recordRegistration(session, token, capability)
            }
            Result.success()
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (error: Exception) {
            Log.w(TAG, "FCM registration failed (attempt $runAttemptCount)")
            if ((error is ApiException && error.httpCode == 401) || runAttemptCount >= MAX_ATTEMPTS) {
                Result.failure()
            } else Result.retry()
        }
    }
}
