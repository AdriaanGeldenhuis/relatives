package za.co.relatives.app.tracking

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
import com.google.gson.Gson
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import za.co.relatives.app.BuildConfig
import za.co.relatives.app.data.QueuedLocationEntity
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.utils.PreferencesManager
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.concurrent.TimeUnit

class LocationUploadWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    companion object {
        const val WORK_NAME = "location_upload"

        fun enqueue(context: Context, delaySeconds: Long = 0) {
            val request = OneTimeWorkRequestBuilder<LocationUploadWorker>()
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setInitialDelay(delaySeconds, TimeUnit.SECONDS)
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork(WORK_NAME, ExistingWorkPolicy.KEEP, request)
        }
    }

    override suspend fun doWork(): Result {
        val prefs = PreferencesManager(applicationContext)
        val consent = prefs.trackingConsent() ?: return Result.success()
        val store = TrackingStore(applicationContext)
        store.expireOlderThan(System.currentTimeMillis() - 7L * 24 * 60 * 60 * 1000)
        repeat(3) {
            when (LocationUploader.send(applicationContext, consent)) {
                UploadResult.EMPTY, UploadResult.BLOCKED -> return Result.success()
                UploadResult.RETRY -> return Result.retry()
                UploadResult.SENT -> Unit
            }
        }
        return if (store.unsentCount(consent.scope) == 0) Result.success() else Result.retry()
    }
}

internal enum class UploadResult { EMPTY, SENT, BLOCKED, RETRY }

/** One sender for the foreground fast path and WorkManager's durable drain. */
internal object LocationUploader {
    private val gate = Mutex()
    private val gson = Gson()

    suspend fun send(context: Context, consent: PreferencesManager.TrackingConsent, urgentId: String? = null): UploadResult =
        gate.withLock {
            val prefs = PreferencesManager(context)
            if (prefs.trackingConsent()?.scope != consent.scope || !prefs.trackingPolicy().canCollect ||
                !TrackingService.hasLocationPermission(context) || !TrackingService.canShowTrackingNotification(context)
            ) return@withLock UploadResult.BLOCKED

            val store = TrackingStore(context)
            store.expireWake(System.currentTimeMillis())
            val fixes = if (urgentId == null) store.getUnsentLocations(consent.scope)
                else listOfNotNull(store.getUnsentLocation(consent.scope, urgentId))
            if (fixes.isEmpty()) return@withLock UploadResult.EMPTY
            if (prefs.trackingConsent()?.scope != consent.scope) return@withLock UploadResult.BLOCKED

            try {
                val reply = TrackingTransport(context).call("batch.php", consent.sessionCookie, payload(fixes, prefs.deviceUuid))
                if (prefs.trackingConsent()?.scope != consent.scope) return@withLock UploadResult.BLOCKED
                when {
                    reply.code == 401 -> {
                        TrackingService.signInRequired(context)
                        UploadResult.BLOCKED
                    }
                    reply.code == 403 && reply.error == "location_sharing_disabled" -> {
                        TrackingService.accountSharingDisabled(context, consent.sessionCookie)
                        UploadResult.BLOCKED
                    }
                    reply.success && reply.body?.text("message") == "tracking_disabled" -> {
                        TrackingService.applyServerPolicy(context, consent.sessionCookie, JsonObject().apply {
                            addProperty("mode", 0)
                        })
                        UploadResult.BLOCKED
                    }
                    reply.success -> {
                        val settings = reply.data?.getAsJsonObject("server_settings")
                        if (settings != null) TrackingService.applyServerPolicy(context, consent.sessionCookie, settings)
                        val results = reply.data?.getAsJsonArray("results")
                        val acknowledged = UploadReceipts.acknowledged(fixes.map { it.clientEventId }, results)
                        for ((id, status) in acknowledged) {
                            if (status == "skipped" || status == "error") {
                                Log.i("TrackingUpload", "Location dropped with server result $status")
                            }
                            store.markSent(id)
                        }
                        store.cleanupSent()
                        if (acknowledged.size == fixes.size) UploadResult.SENT else UploadResult.RETRY
                    }
                    else -> {
                        Log.w("TrackingUpload", "Location upload HTTP ${reply.code}")
                        UploadResult.RETRY
                    }
                }
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (_: Exception) {
                Log.w("TrackingUpload", "Location transport unavailable")
                UploadResult.RETRY
            }
        }

    private fun payload(fixes: List<QueuedLocationEntity>, deviceId: String): JsonObject {
        val utc = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply {
            timeZone = TimeZone.getTimeZone("UTC")
        }
        val locations = fixes.map { fix ->
            mapOf(
                "client_event_id" to fix.clientEventId,
                "lat" to fix.lat,
                "lng" to fix.lng,
                "accuracy_m" to fix.accuracy,
                "altitude_m" to fix.altitude,
                "bearing_deg" to fix.bearing,
                "speed_mps" to fix.speed,
                "recorded_at" to utc.format(Date(fix.timestamp)),
                "recorded_at_ms" to fix.timestamp,
                "wake_request_id" to fix.wakeRequestId,
                "device_id" to deviceId,
                "platform" to "android",
                "app_version" to BuildConfig.VERSION_NAME,
            )
        }
        return gson.toJsonTree(mapOf("locations" to locations)).asJsonObject
    }
}

internal object UploadReceipts {
    fun acknowledged(ids: List<String>, results: JsonArray?): Map<String, String> {
        val accepted = mutableMapOf<String, String>()
        for (element in results ?: return accepted) {
            if (!element.isJsonObject) continue
            val result = element.asJsonObject
            val index = result.number("index")?.toInt() ?: continue
            val id = ids.getOrNull(index) ?: continue
            val returned = result.text("client_event_id")
            if (returned != null && returned != id) continue
            when (val status = result.text("status")) {
                "stored", "duplicate", "skipped", "error" -> accepted[id] = status
            }
        }
        return accepted
    }
}
