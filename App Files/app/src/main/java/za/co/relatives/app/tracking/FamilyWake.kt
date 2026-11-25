package za.co.relatives.app.tracking

import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.SystemClock
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.OutOfQuotaPolicy
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import com.google.gson.JsonObject
import kotlinx.coroutines.CancellationException
import za.co.relatives.app.utils.PreferencesManager
import java.util.concurrent.TimeUnit

internal data class FamilyWake(val id: String, val scope: String, val expiresAtMs: Long) {
    fun putInto(intent: Intent): Intent = intent.putExtra(ID, id).putExtra(SCOPE, scope).putExtra(EXPIRES, expiresAtMs)

    companion object {
        private const val ID = "family_wake_id"
        private const val SCOPE = "family_wake_scope"
        private const val EXPIRES = "family_wake_expires"
        private val idPattern = Regex("[a-fA-F0-9]{8}(-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}")

        fun from(intent: Intent?): FamilyWake? {
            val id = intent?.getStringExtra(ID)?.takeIf(idPattern::matches) ?: return null
            val scope = intent.getStringExtra(SCOPE) ?: return null
            return FamilyWake(id, scope, intent.getLongExtra(EXPIRES, 0))
        }

        fun receive(context: Context, data: Map<String, String>, highPriority: Boolean) {
            val prefs = PreferencesManager(context)
            val id = data["request_id"]?.takeIf(idPattern::matches) ?: return
            if (data["installation_id"] != prefs.deviceUuid) return
            val expiry = data["expires_at_ms"]?.toLongOrNull() ?: return
            val consent = prefs.trackingConsent()
            val capability = TrackingService.wakeCapability(context)
            if (consent == null || capability != "ready") {
                val state = if (consent == null) "paused" else capability
                WakeReceiptWorker.enqueue(context, id, state, expiry, highPriority)
                return
            }

            val wake = FamilyWake(id, consent.scope, expiry)
            val started = TrackingService.appVisible ||
                (TrackingService.canReviveFromBackground(context) && (TrackingService.isRunning || highPriority))
            val refreshing = started && TrackingService.startFamilyWake(context, wake)
            if (prefs.claimWakeNotice(id)) {
                TrackingService.showFamilyRefreshNotification(context, data["requester_name"])
            }
            if (!refreshing) WakeReceiptWorker.enqueue(context, id, "background_blocked", expiry, highPriority)
        }

        suspend fun acknowledge(context: Context, cookie: String, id: String, state: String): TrackingTransport.Reply? {
            val prefs = PreferencesManager(context)
            if (prefs.sessionToken != cookie) return null
            return try {
                TrackingTransport(context).call("wake_ack.php", cookie, JsonObject().apply {
                    addProperty("request_id", id)
                    addProperty("device_id", prefs.deviceUuid)
                    addProperty("state", state)
                })
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (_: Exception) {
                null
            }
        }

        fun deadline(remainingMs: Long): Long =
            SystemClock.elapsedRealtime() + remainingMs.coerceIn(0, 60_000)
    }
}

class WakeReceiptWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    companion object {
        internal fun enqueue(context: Context, id: String, state: String, expiresAtMs: Long, expedited: Boolean) {
            val prefs = PreferencesManager(context)
            val binding = prefs.sessionBinding() ?: return
            val builder = OneTimeWorkRequestBuilder<WakeReceiptWorker>()
                .setInputData(workDataOf("id" to id, "state" to state, "expires" to expiresAtMs, "binding" to binding))
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setBackoffCriteria(androidx.work.BackoffPolicy.EXPONENTIAL, 10, TimeUnit.SECONDS)
            if (expedited && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                builder.setExpedited(OutOfQuotaPolicy.RUN_AS_NON_EXPEDITED_WORK_REQUEST)
            }
            WorkManager.getInstance(context).enqueueUniqueWork("wake_receipt_$id", ExistingWorkPolicy.KEEP, builder.build())
        }
    }

    override suspend fun doWork(): Result {
        val id = inputData.getString("id") ?: return Result.success()
        val state = inputData.getString("state") ?: return Result.success()
        val prefs = PreferencesManager(applicationContext)
        val cookie = prefs.sessionToken ?: return Result.success()
        if (prefs.sessionBinding() != inputData.getString("binding")) return Result.success()
        val reply = FamilyWake.acknowledge(applicationContext, cookie, id, state)
        if ((reply == null || reply.code >= 500) && runAttemptCount < 2 &&
            System.currentTimeMillis() < inputData.getLong("expires", 0L)
        ) return Result.retry()
        return Result.success()
    }
}
