package com.relatives.app.tracking

import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.location.Location
import android.os.BatteryManager
import android.os.Build
import android.util.Log
import okhttp3.Call
import okhttp3.Callback
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.TimeUnit

/**
 * Handles location uploads to the server with:
 * - Auth failure detection (401/403)
 * - Exponential backoff for transient failures
 * - Battery level reporting
 */
class LocationUploader(
    private val context: Context,
    private val prefs: PreferencesManager
) {
    companion object {
        private const val TAG = "LocationUploader"

        // API endpoint - should match your server
        private const val UPLOAD_URL = "https://relatives.app/tracking/api/update_location.php"

        // Timeouts
        private const val CONNECT_TIMEOUT_SECONDS = 10L
        private const val READ_TIMEOUT_SECONDS = 15L
        private const val WRITE_TIMEOUT_SECONDS = 15L
    }

    private val httpClient = OkHttpClient.Builder()
        .connectTimeout(CONNECT_TIMEOUT_SECONDS, TimeUnit.SECONDS)
        .readTimeout(READ_TIMEOUT_SECONDS, TimeUnit.SECONDS)
        .writeTimeout(WRITE_TIMEOUT_SECONDS, TimeUnit.SECONDS)
        .build()

    private val jsonMediaType = "application/json; charset=utf-8".toMediaType()

    /**
     * Upload location to server asynchronously.
     *
     * @param location The location to upload
     * @param isMoving Whether the user is currently moving
     * @param onSuccess Called when upload succeeds
     * @param onAuthFailure Called on 401/403 - should block further uploads
     * @param onTransientFailure Called on timeout/5xx - should trigger backoff
     */
    fun uploadLocation(
        location: Location,
        isMoving: Boolean,
        onSuccess: () -> Unit,
        onAuthFailure: () -> Unit,
        onTransientFailure: () -> Unit
    ) {
        // Validate auth
        if (!prefs.hasValidAuth()) {
            Log.w(TAG, "No valid auth data, skipping upload")
            onAuthFailure()
            return
        }

        val deviceUuid = prefs.deviceUuid ?: generateDeviceUuid()

        // Build request body
        val body = JSONObject().apply {
            put("device_uuid", deviceUuid)
            put("latitude", location.latitude)
            put("longitude", location.longitude)
            put("accuracy_m", location.accuracy)
            put("speed_kmh", location.speed * 3.6) // m/s to km/h
            put("heading_deg", if (location.hasBearing()) location.bearing else JSONObject.NULL)
            put("altitude_m", if (location.hasAltitude()) location.altitude else JSONObject.NULL)
            put("is_moving", isMoving)
            put("battery_level", getBatteryLevel())
            put("source", "native")
            put("session_token", prefs.sessionToken) // Fallback auth for problematic clients
        }

        val request = Request.Builder()
            .url(UPLOAD_URL)
            .addHeader("Content-Type", "application/json")
            .addHeader("Authorization", "Bearer ${prefs.sessionToken}")
            .addHeader("User-Agent", "RelativesAndroid/${getAppVersion()}")
            .post(body.toString().toRequestBody(jsonMediaType))
            .build()

        Log.d(TAG, "Uploading location: lat=${location.latitude}, lng=${location.longitude}")

        httpClient.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                Log.e(TAG, "Upload failed with network error", e)
                onTransientFailure()
            }

            override fun onResponse(call: Call, response: Response) {
                response.use { resp ->
                    val code = resp.code
                    Log.d(TAG, "Upload response: $code")

                    when {
                        code in 200..299 -> {
                            Log.d(TAG, "Upload successful")
                            onSuccess()
                        }
                        code == 401 || code == 403 -> {
                            Log.w(TAG, "Auth failure: $code")
                            onAuthFailure()
                        }
                        code == 402 -> {
                            // Subscription locked - treat like auth failure
                            Log.w(TAG, "Subscription locked: $code")
                            onAuthFailure()
                        }
                        code == 429 -> {
                            // Rate limited - treat as transient, will back off
                            Log.w(TAG, "Rate limited: $code")
                            onTransientFailure()
                        }
                        code in 500..599 -> {
                            Log.w(TAG, "Server error: $code")
                            onTransientFailure()
                        }
                        else -> {
                            Log.w(TAG, "Unexpected response: $code")
                            onTransientFailure()
                        }
                    }
                }
            }
        })
    }

    /**
     * Upload location synchronously (blocking).
     * Use only from background threads.
     *
     * @return true if upload succeeded, false otherwise
     */
    fun uploadLocationSync(location: Location, isMoving: Boolean): UploadResult {
        if (!prefs.hasValidAuth()) {
            return UploadResult.AUTH_FAILURE
        }

        val deviceUuid = prefs.deviceUuid ?: generateDeviceUuid()

        val body = JSONObject().apply {
            put("device_uuid", deviceUuid)
            put("latitude", location.latitude)
            put("longitude", location.longitude)
            put("accuracy_m", location.accuracy)
            put("speed_kmh", location.speed * 3.6)
            put("heading_deg", if (location.hasBearing()) location.bearing else JSONObject.NULL)
            put("altitude_m", if (location.hasAltitude()) location.altitude else JSONObject.NULL)
            put("is_moving", isMoving)
            put("battery_level", getBatteryLevel())
            put("source", "native")
            put("session_token", prefs.sessionToken)
        }

        val request = Request.Builder()
            .url(UPLOAD_URL)
            .addHeader("Content-Type", "application/json")
            .addHeader("Authorization", "Bearer ${prefs.sessionToken}")
            .addHeader("User-Agent", "RelativesAndroid/${getAppVersion()}")
            .post(body.toString().toRequestBody(jsonMediaType))
            .build()

        return try {
            val response = httpClient.newCall(request).execute()
            response.use { resp ->
                when {
                    resp.code in 200..299 -> UploadResult.SUCCESS
                    resp.code == 401 || resp.code == 403 || resp.code == 402 -> UploadResult.AUTH_FAILURE
                    else -> UploadResult.TRANSIENT_FAILURE
                }
            }
        } catch (e: IOException) {
            Log.e(TAG, "Sync upload failed", e)
            UploadResult.TRANSIENT_FAILURE
        }
    }

    enum class UploadResult {
        SUCCESS,
        AUTH_FAILURE,
        TRANSIENT_FAILURE
    }

    private fun getBatteryLevel(): Int? {
        return try {
            val batteryStatus = context.registerReceiver(
                null,
                IntentFilter(Intent.ACTION_BATTERY_CHANGED)
            )
            batteryStatus?.let { intent ->
                val level = intent.getIntExtra(BatteryManager.EXTRA_LEVEL, -1)
                val scale = intent.getIntExtra(BatteryManager.EXTRA_SCALE, -1)
                if (level >= 0 && scale > 0) {
                    (level * 100 / scale)
                } else null
            }
        } catch (e: Exception) {
            Log.w(TAG, "Error getting battery level", e)
            null
        }
    }

    private fun generateDeviceUuid(): String {
        val uuid = java.util.UUID.randomUUID().toString()
        prefs.deviceUuid = uuid
        return uuid
    }

    private fun getAppVersion(): String {
        return try {
            val packageInfo = context.packageManager.getPackageInfo(context.packageName, 0)
            packageInfo.versionName ?: "unknown"
        } catch (e: Exception) {
            "unknown"
        }
    }
}
