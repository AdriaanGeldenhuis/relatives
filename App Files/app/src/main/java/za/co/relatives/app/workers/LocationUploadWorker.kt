package za.co.relatives.app.workers

import android.content.Context
import android.os.BatteryManager
import android.os.Build
import android.util.Log
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import za.co.relatives.app.data.TrackingDatabase
import za.co.relatives.app.utils.PreferencesManager
import java.util.concurrent.TimeUnit

/**
 * WorkManager-based location batch uploader.
 *
 * Decoupled from collection: runs when network is available,
 * flushes Room queue in batches, marks sent, cleans up.
 *
 * Survives service death, app kills, and device restarts.
 */
class LocationUploadWorker(
    context: Context,
    params: WorkerParameters
) : CoroutineWorker(context, params) {

    companion object {
        private const val TAG = "LocationUploadWorker"
        private const val WORK_NAME = "location_upload"
        private const val BATCH_UPLOAD_URL = "https://www.relatives.co.za/tracking/api/update_location_batch.php"

        /**
         * Schedule a one-time upload when network becomes available.
         * Uses KEEP policy to avoid duplicate work.
         */
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

            Log.d(TAG, "Upload work enqueued (network-constrained)")
        }
    }

    private val httpClient = OkHttpClient.Builder()
        .connectTimeout(30, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .build()
    private val jsonMediaType = "application/json; charset=utf-8".toMediaType()

    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        Log.d(TAG, "Upload worker starting (attempt: $runAttemptCount)")

        val dao = TrackingDatabase.getInstance(applicationContext).queuedLocationDao()
        val unsent = dao.getUnsent()

        if (unsent.isEmpty()) {
            Log.d(TAG, "No unsent locations in queue")
            dao.deleteSent() // Cleanup any previously sent
            return@withContext Result.success()
        }

        Log.d(TAG, "Found ${unsent.size} unsent locations to upload")

        val sessionToken = PreferencesManager.sessionToken
        if (sessionToken.isNullOrBlank()) {
            Log.w(TAG, "No session token - retrying later")
            return@withContext Result.retry()
        }

        val deviceUuid = PreferencesManager.getDeviceUuid()

        // Build batch request
        val locationsArray = JSONArray()
        unsent.forEach { loc ->
            locationsArray.put(JSONObject().apply {
                put("latitude", loc.latitude)
                put("longitude", loc.longitude)
                put("accuracy_m", loc.accuracy.toInt())
                put("speed_kmh", loc.speedKmh)
                put("heading_deg", loc.bearing ?: 0)
                put("altitude_m", loc.altitude ?: JSONObject.NULL)
                put("is_moving", loc.isMoving)
                put("battery_level", loc.batteryLevel ?: JSONObject.NULL)
                put("client_event_id", loc.clientEventId)
                put("client_timestamp", loc.timestamp)
            })
        }

        val body = JSONObject().apply {
            put("device_uuid", deviceUuid)
            put("platform", "android")
            put("device_name", Build.MODEL)
            put("source", "native")
            put("session_token", sessionToken)
            put("locations", locationsArray)
        }

        val request = Request.Builder()
            .url(BATCH_UPLOAD_URL)
            .addHeader("Content-Type", "application/json")
            .addHeader("Authorization", "Bearer $sessionToken")
            .addHeader("User-Agent", "RelativesAndroid/1.0")
            .post(body.toString().toRequestBody(jsonMediaType))
            .build()

        try {
            val response = httpClient.newCall(request).execute()
            val responseBody = response.body?.string()

            when {
                response.isSuccessful -> {
                    Log.d(TAG, "Batch upload successful (${unsent.size} locations)")

                    // Parse results for partial failures
                    val json = JSONObject(responseBody ?: "{}")
                    val results = json.optJSONArray("results")
                    val sentIds = mutableListOf<String>()
                    val failedIds = mutableListOf<String>()

                    if (results != null) {
                        for (i in 0 until results.length()) {
                            val result = results.getJSONObject(i)
                            val clientEventId = result.optString("client_event_id")
                            if (result.optBoolean("success", false) || result.optBoolean("already_exists", false)) {
                                sentIds.add(clientEventId)
                            } else {
                                failedIds.add(clientEventId)
                            }
                        }
                    } else {
                        // No per-item results, assume all sent
                        sentIds.addAll(unsent.map { it.clientEventId })
                    }

                    if (sentIds.isNotEmpty()) {
                        dao.markSent(sentIds)
                    }
                    if (failedIds.isNotEmpty()) {
                        dao.incrementRetry(failedIds)
                    }

                    // Cleanup sent items
                    dao.deleteSent()

                    // Apply server settings if present
                    PreferencesManager.applyServerSettings(json.optJSONObject("server_settings"))

                    PreferencesManager.lastUploadTime = System.currentTimeMillis()

                    return@withContext Result.success()
                }
                response.code in listOf(401, 403) -> {
                    Log.w(TAG, "Auth failure (${response.code}) - will retry after token refresh")
                    return@withContext Result.retry()
                }
                response.code == 402 -> {
                    Log.w(TAG, "Subscription locked - not retrying")
                    return@withContext Result.failure()
                }
                else -> {
                    Log.e(TAG, "Upload failed (${response.code}): $responseBody")
                    dao.incrementRetry(unsent.map { it.clientEventId })
                    return@withContext Result.retry()
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Upload worker exception: ${e.message}", e)
            dao.incrementRetry(unsent.map { it.clientEventId })
            return@withContext Result.retry()
        }
    }

}
