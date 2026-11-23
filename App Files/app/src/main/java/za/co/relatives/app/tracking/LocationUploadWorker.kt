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
import com.google.gson.JsonObject
import kotlinx.coroutines.CancellationException
import okhttp3.CookieJar
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.network.NetworkClient
import za.co.relatives.app.network.readTextResponse
import za.co.relatives.app.utils.PreferencesManager
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.concurrent.TimeUnit

/**
 * LocationUploadWorker — batch-uploads queued locations to the server.
 *
 * Reads from TrackingStore's offline queue, POSTs as JSON, marks sent.
 * Transient failures -> exponential backoff. Auth failures -> immediate abort.
 */
class LocationUploadWorker(
    appContext: Context,
    params: WorkerParameters,
) : CoroutineWorker(appContext, params) {

    companion object {
        private const val TAG = "LocationUploadWorker"
        const val WORK_NAME = "location_upload"
        private const val BATCH_URL = "https://www.relatives.co.za/tracking/api/batch.php"
        private val JSON_MEDIA = "application/json; charset=utf-8".toMediaType()

        /** Points older than this are dropped unsent — worthless on a live map. */
        private const val MAX_AGE_MS = 7L * 24 * 60 * 60 * 1000

        fun enqueue(context: Context, delaySeconds: Long = 0) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()
            val request = OneTimeWorkRequestBuilder<LocationUploadWorker>()
                .setConstraints(constraints)
                .setInitialDelay(delaySeconds, TimeUnit.SECONDS)
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context)
                .enqueueUniqueWork(WORK_NAME, ExistingWorkPolicy.KEEP, request)
        }
    }

    private val store = TrackingStore(applicationContext)
    private val gson = Gson()
    private val http = NetworkClient.getInstance(applicationContext).newBuilder()
        .cookieJar(CookieJar.NO_COOKIES).followRedirects(false).build()
    private val prefs = PreferencesManager(applicationContext)

    // Server expects "recorded_at" as UTC "yyyy-MM-dd HH:mm:ss"; a raw epoch-ms
    // "timestamp" fails its strtotime() parse and queued offline points would
    // all be stamped with upload time instead of capture time.
    private val utcFormat = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply {
        timeZone = TimeZone.getTimeZone("UTC")
    }

    private enum class BatchOutcome { UPLOADED, BLOCKED, RETRY }

    override suspend fun doWork(): Result {
        val consent = prefs.trackingConsent() ?: return Result.success()
        if (!TrackingService.canShowTrackingNotification(applicationContext)) return Result.success()

        // Retain unsent fixes through transient network failures within the
        // queue's age and size limits.
        store.expireOlderThan(System.currentTimeMillis() - MAX_AGE_MS)

        // Drain the whole queue (max 300 items ≈ 3 batches), not just the
        // first 100 — the queue trims oldest-first, so leaving points behind
        // loses history.
        repeat(3) {
            when (uploadBatch(consent)) {
                BatchOutcome.UPLOADED -> if (store.unsentCount(consent.scope) == 0) return Result.success()
                BatchOutcome.BLOCKED -> return Result.failure()
                BatchOutcome.RETRY -> return Result.retry()
            }
        }
        return if (store.unsentCount(consent.scope) == 0) Result.success() else Result.retry()
    }

    private suspend fun uploadBatch(consent: PreferencesManager.TrackingConsent): BatchOutcome {
        val batch = store.getUnsentLocations(consent.scope)
        if (batch.isEmpty()) {
            store.cleanupSent()
            return BatchOutcome.UPLOADED
        }

        if (prefs.trackingConsent()?.scope != consent.scope) return BatchOutcome.BLOCKED

        return try {
            val deviceId = prefs.deviceUuid
            val payload = batch.map { e ->
                mapOf(
                    "client_event_id" to e.clientEventId,
                    "lat" to e.lat,
                    "lng" to e.lng,
                    "accuracy" to e.accuracy,
                    "altitude" to e.altitude,
                    "bearing" to e.bearing,
                    "speed" to e.speed,
                    "speed_kmh" to e.speedKmh,
                    "is_moving" to e.isMoving,
                    "battery_level" to e.batteryLevel,
                    "recorded_at" to utcFormat.format(Date(e.timestamp)),
                    "device_id" to deviceId,
                    "platform" to "android",
                )
            }
            val jsonBody = gson.toJson(mapOf("locations" to payload))

            val request = Request.Builder()
                .url(BATCH_URL)
                .header("Cookie", "RELATIVES_SESSION=${consent.sessionCookie}")
                .post(jsonBody.toRequestBody(JSON_MEDIA))
                .build()

            val (status, responseBody) = http.newCall(request).readTextResponse()
            when (status) {
                in 200..299 -> {
                    val ack = gson.fromJson(responseBody, JsonObject::class.java)
                    if (ack?.get("success")?.asBoolean != true) return BatchOutcome.RETRY
                    if (ack.get("message")?.asString == "tracking_disabled") {
                        if (prefs.trackingConsent()?.scope == consent.scope) TrackingService.stop(applicationContext)
                        return BatchOutcome.BLOCKED
                    }
                    if (ack.getAsJsonObject("data")?.get("processed")?.asInt != batch.size) {
                        return BatchOutcome.RETRY
                    }

                    batch.forEach { store.markSent(it.clientEventId) }
                    store.cleanupSent()
                    BatchOutcome.UPLOADED
                }
                401 -> {
                    if (prefs.trackingConsent()?.scope == consent.scope) TrackingService.stop(applicationContext)
                    BatchOutcome.BLOCKED
                }
                403 -> {
                    val error = gson.fromJson(responseBody, JsonObject::class.java)?.get("error")
                    if (error?.asString == "location_sharing_disabled" && prefs.trackingConsent()?.scope == consent.scope) {
                        TrackingService.stop(applicationContext)
                    }
                    BatchOutcome.BLOCKED
                }
                else -> {
                    Log.w(TAG, "Upload failed ($status)")
                    BatchOutcome.RETRY
                }
            }
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (e: Exception) {
            // Network error (timeout, no connectivity, captive portal).
            // Retry; the points stay queued until they upload or age out.
            Log.e(TAG, "Upload exception", e)
            BatchOutcome.RETRY
        }
    }
}
