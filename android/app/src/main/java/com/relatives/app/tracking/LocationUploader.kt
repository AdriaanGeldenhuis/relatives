package com.relatives.app.tracking

import android.content.Context
import android.location.Location
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
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.concurrent.TimeUnit

/**
 * Uploads location data to the server.
 *
 * Features:
 * - Posts to /tracking/api/location.php
 * - Uses session cookie for auth
 * - Handles 401/403 → blocks uploads for 30 min
 * - Exponential backoff on transient failures
 * - Async (non-blocking) uploads via OkHttp
 */
class LocationUploader(
    private val context: Context,
    private val prefs: PreferencesManager
) {
    companion object {
        private const val TAG = "LocationUploader"
        private val JSON_MEDIA_TYPE = "application/json; charset=utf-8".toMediaType()
        private const val UPLOAD_ENDPOINT = "/tracking/api/location.php"
        private const val APP_VERSION = "1.0.0"
    }

    private val httpClient = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .writeTimeout(15, TimeUnit.SECONDS)
        .build()

    private val isoDateFormat = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US).apply {
        timeZone = TimeZone.getTimeZone("UTC")
    }

    /**
     * Upload a location point to the server (async).
     *
     * @param location The Android Location object
     * @param motionState Current tracking mode (live, moving, idle)
     */
    fun uploadLocation(location: Location, motionState: String) {
        // Pre-checks
        if (!prefs.hasValidAuth()) {
            Log.w(TAG, "No valid auth, skipping upload")
            return
        }

        if (prefs.isAuthBlocked()) {
            Log.d(TAG, "Auth blocked, skipping upload")
            return
        }

        if (prefs.isInBackoff()) {
            Log.d(TAG, "In backoff window, skipping upload")
            return
        }

        val baseUrl = prefs.baseUrl
        val sessionToken = prefs.sessionToken ?: return
        val url = "$baseUrl$UPLOAD_ENDPOINT"

        // Build JSON payload matching the server's expected format
        val json = JSONObject().apply {
            put("lat", location.latitude)
            put("lng", location.longitude)
            put("accuracy_m", if (location.hasAccuracy()) location.accuracy.toDouble() else JSONObject.NULL)
            put("speed_mps", if (location.hasSpeed()) location.speed.toDouble() else JSONObject.NULL)
            put("bearing_deg", if (location.hasBearing()) location.bearing.toDouble() else JSONObject.NULL)
            put("altitude_m", if (location.hasAltitude()) location.altitude else JSONObject.NULL)
            put("recorded_at", isoDateFormat.format(Date(location.time)))
            put("device_id", prefs.deviceId)
            put("platform", "android")
            put("app_version", APP_VERSION)
            put("motion_state", motionState)
        }

        val body = json.toString().toRequestBody(JSON_MEDIA_TYPE)

        val request = Request.Builder()
            .url(url)
            .post(body)
            .addHeader("Cookie", "RELATIVES_SESSION=$sessionToken")
            .addHeader("Content-Type", "application/json")
            .addHeader("X-App-Platform", "android")
            .addHeader("X-App-Version", APP_VERSION)
            .addHeader("X-Device-Id", prefs.deviceId)
            .build()

        httpClient.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                Log.e(TAG, "Upload failed: ${e.message}")
                handleTransientFailure()
            }

            override fun onResponse(call: Call, response: Response) {
                response.use { resp ->
                    val code = resp.code
                    val responseBody = resp.body?.string()

                    when {
                        code == 200 -> {
                            Log.d(TAG, "Upload success: $responseBody")
                            prefs.resetFailureState()
                            parseSuccessResponse(responseBody)
                        }

                        code == 401 || code == 403 -> {
                            Log.w(TAG, "Auth failure ($code): $responseBody")
                            prefs.markAuthFailure()
                        }

                        code == 402 -> {
                            Log.w(TAG, "Subscription locked: $responseBody")
                            prefs.markAuthFailure()
                        }

                        code == 409 -> {
                            // Session off - not an error, just means no one is watching
                            Log.d(TAG, "Session off (409): $responseBody")
                            // Don't count as failure
                        }

                        code == 422 -> {
                            // Poor accuracy - discard, not a failure
                            Log.d(TAG, "Poor accuracy rejected (422): $responseBody")
                        }

                        code == 429 -> {
                            // Rate limited
                            Log.w(TAG, "Rate limited (429): $responseBody")
                            handleRateLimited(responseBody)
                        }

                        else -> {
                            Log.e(TAG, "Unexpected response ($code): $responseBody")
                            handleTransientFailure()
                        }
                    }
                }
            }
        })
    }

    /**
     * Parse success response for any server-side state changes.
     */
    private fun parseSuccessResponse(body: String?) {
        if (body == null) return

        try {
            val json = JSONObject(body)
            val data = json.optJSONObject("data") ?: return

            val accepted = data.optBoolean("accepted", true)
            val motionState = data.optString("motion_state", "")

            if (!accepted) {
                Log.d(TAG, "Location not accepted: ${data.optString("reason", "unknown")}")
            }

            // Log geofence events if any
            val geofenceEvents = data.optJSONArray("geofence_events")
            if (geofenceEvents != null && geofenceEvents.length() > 0) {
                Log.d(TAG, "Geofence events: ${geofenceEvents.length()}")
            }
        } catch (e: Exception) {
            Log.w(TAG, "Failed to parse success response", e)
        }
    }

    /**
     * Handle rate limiting response.
     */
    private fun handleRateLimited(body: String?) {
        try {
            val json = JSONObject(body ?: "{}")
            val retryAfter = json.optInt("retry_after", 60)
            // Use retry_after as backoff
            prefs.lastFailureTime = System.currentTimeMillis()
            prefs.consecutiveFailures = maxOf(prefs.consecutiveFailures, 3) // At least 60s backoff
        } catch (e: Exception) {
            handleTransientFailure()
        }
    }

    /**
     * Handle transient network failure.
     */
    private fun handleTransientFailure() {
        val backoff = prefs.recordFailure()
        Log.w(TAG, "Transient failure #${prefs.consecutiveFailures}, backoff=${backoff}ms")
    }
}
