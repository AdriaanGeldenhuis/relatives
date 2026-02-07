package za.co.relatives.app.workers

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
import com.google.gson.reflect.TypeToken
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import za.co.relatives.app.data.QueuedLocationEntity
import za.co.relatives.app.data.TrackingDatabase
import za.co.relatives.app.network.NetworkClient
import java.util.concurrent.TimeUnit

/**
 * WorkManager worker that batch-uploads queued location updates to the
 * Relatives backend.
 *
 * Reads up to 100 unsent rows from the Room queue, POSTs them as JSON, and
 * marks each row as sent on success. Transient failures trigger exponential
 * backoff (starting at 30 s); auth failures (401/403) cause an immediate
 * abort without retry.
 */
class LocationUploadWorker(
    appContext: Context,
    params: WorkerParameters
) : CoroutineWorker(appContext, params) {

    companion object {
        private const val TAG = "LocationUploadWorker"
        const val WORK_NAME = "location_upload"
        private const val BATCH_URL = "https://www.relatives.co.za/tracking/api/batch.php"
        private val JSON_MEDIA = "application/json; charset=utf-8".toMediaType()
        private const val MAX_RETRY_COUNT = 5

        private const val PREF_NAME = "relatives_prefs"
        private const val PREF_LAST_UPLOAD = "last_upload_time"

        /**
         * Convenience to enqueue a one-shot upload with a network constraint.
         */
        fun enqueue(context: Context) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()
            val request = OneTimeWorkRequestBuilder<LocationUploadWorker>()
                .setConstraints(constraints)
                .setBackoffCriteria(
                    BackoffPolicy.EXPONENTIAL,
                    30, TimeUnit.SECONDS
                )
                .build()
            WorkManager.getInstance(context)
                .enqueueUniqueWork(WORK_NAME, ExistingWorkPolicy.KEEP, request)
        }
    }

    private val dao = TrackingDatabase.getInstance(appContext).queuedLocationDao()
    private val gson = Gson()
    private val http = NetworkClient.getInstance(appContext)

    override suspend fun doWork(): Result {
        Log.d(TAG, "Upload worker starting (attempt $runAttemptCount)")

        // Fetch unsent locations.
        val batch = dao.getUnsent(limit = 100)
        if (batch.isEmpty()) {
            Log.d(TAG, "No unsent locations, nothing to do")
            // Clean up old sent rows while we're here.
            dao.deleteSent()
            return Result.success()
        }

        // Filter out items that have exceeded the maximum retry count.
        val viable = batch.filter { it.retryCount < MAX_RETRY_COUNT }
        val exhausted = batch.filter { it.retryCount >= MAX_RETRY_COUNT }
        exhausted.forEach { dao.markSent(it.clientEventId) } // discard
        if (viable.isEmpty()) {
            dao.deleteSent()
            return Result.success()
        }

        return try {
            val responseBody = postBatch(viable)
            handleSuccess(viable, responseBody)
            Result.success()
        } catch (e: AuthFailureException) {
            Log.w(TAG, "Auth failure (${e.httpCode}), not retrying")
            Result.failure()
        } catch (e: Exception) {
            Log.e(TAG, "Upload failed, will retry", e)
            viable.forEach { dao.incrementRetry(it.clientEventId) }
            Result.retry()
        }
    }

    // ------------------------------------------------------------------ //
    //  Network
    // ------------------------------------------------------------------ //

    private fun postBatch(items: List<QueuedLocationEntity>): String {
        val payload = items.map { entity ->
            mapOf(
                "client_event_id" to entity.clientEventId,
                "lat" to entity.lat,
                "lng" to entity.lng,
                "accuracy" to entity.accuracy,
                "altitude" to entity.altitude,
                "bearing" to entity.bearing,
                "speed" to entity.speed,
                "speed_kmh" to entity.speedKmh,
                "is_moving" to entity.isMoving,
                "battery_level" to entity.batteryLevel,
                "timestamp" to entity.timestamp
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
            in 200..299 -> return body
            401, 403 -> throw AuthFailureException(response.code, body)
            else -> throw UploadException(response.code, body)
        }
    }

    // ------------------------------------------------------------------ //
    //  Success handling
    // ------------------------------------------------------------------ //

    private suspend fun handleSuccess(items: List<QueuedLocationEntity>, responseBody: String) {
        // Mark all items as sent.
        items.forEach { dao.markSent(it.clientEventId) }

        // Persist the last upload timestamp.
        applicationContext.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
            .edit()
            .putLong(PREF_LAST_UPLOAD, System.currentTimeMillis())
            .apply()

        // Clean up sent rows.
        dao.deleteSent()

        // Try to parse and apply server settings.
        applyServerSettings(responseBody)

        Log.d(TAG, "Successfully uploaded ${items.size} locations")
    }

    /**
     * Parse the server response for settings that should be applied to the
     * tracking service (e.g. updated interval).
     */
    private fun applyServerSettings(responseBody: String) {
        try {
            val json = gson.fromJson(responseBody, JsonObject::class.java) ?: return
            val settings = json.getAsJsonObject("settings") ?: return

            val prefs = applicationContext.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
            val editor = prefs.edit()

            if (settings.has("update_interval")) {
                editor.putLong(
                    "server_update_interval",
                    settings.get("update_interval").asLong
                )
            }
            if (settings.has("tracking_enabled")) {
                editor.putBoolean(
                    "server_tracking_enabled",
                    settings.get("tracking_enabled").asBoolean
                )
            }

            editor.apply()
            Log.d(TAG, "Applied server settings from response")
        } catch (e: Exception) {
            Log.w(TAG, "Could not parse server settings", e)
        }
    }

    // ------------------------------------------------------------------ //
    //  Exceptions
    // ------------------------------------------------------------------ //

    private class AuthFailureException(val httpCode: Int, message: String) : Exception(message)
    private class UploadException(val httpCode: Int, message: String) : Exception(message)
}
