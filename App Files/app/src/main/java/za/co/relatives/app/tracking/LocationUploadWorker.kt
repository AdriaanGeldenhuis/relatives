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
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.network.NetworkClient
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
        private const val MAX_RETRIES = 5

        fun enqueue(context: Context) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()
            val request = OneTimeWorkRequestBuilder<LocationUploadWorker>()
                .setConstraints(constraints)
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context)
                .enqueueUniqueWork(WORK_NAME, ExistingWorkPolicy.KEEP, request)
        }
    }

    private val store = TrackingStore(applicationContext)
    private val gson = Gson()
    private val http = NetworkClient.getInstance(applicationContext)

    override suspend fun doWork(): Result {
        Log.d(TAG, "Upload starting (attempt $runAttemptCount)")

        val batch = store.getUnsentLocations(100)
        if (batch.isEmpty()) {
            store.cleanupSent()
            return Result.success()
        }

        // Filter exhausted items
        val viable = batch.filter { it.retryCount < MAX_RETRIES }
        batch.filter { it.retryCount >= MAX_RETRIES }.forEach { store.markSent(it.clientEventId) }
        if (viable.isEmpty()) {
            store.cleanupSent()
            return Result.success()
        }

        return try {
            val payload = viable.map { e ->
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
                    "timestamp" to e.timestamp,
                )
            }
            val jsonBody = gson.toJson(mapOf("locations" to payload))

            val request = Request.Builder()
                .url(BATCH_URL)
                .post(jsonBody.toRequestBody(JSON_MEDIA))
                .build()

            val response = http.newCall(request).execute()
            val body = response.body?.string().orEmpty()

            when (response.code) {
                in 200..299 -> {
                    viable.forEach { store.markSent(it.clientEventId) }
                    store.cleanupSent()
                    store.lastUploadTime = System.currentTimeMillis()
                    Log.d(TAG, "Uploaded ${viable.size} locations")
                    Result.success()
                }
                401, 403 -> {
                    Log.w(TAG, "Auth failure (${response.code}), aborting")
                    Result.failure()
                }
                else -> {
                    Log.w(TAG, "Upload failed (${response.code}): $body")
                    viable.forEach { store.incrementRetry(it.clientEventId) }
                    Result.retry()
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Upload exception", e)
            viable.forEach { store.incrementRetry(it.clientEventId) }
            Result.retry()
        }
    }
}
