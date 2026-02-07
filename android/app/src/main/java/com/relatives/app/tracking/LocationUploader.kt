package com.relatives.app.tracking

import android.content.Context
import android.location.Location
import android.util.Log
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.IOException
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.concurrent.TimeUnit

/**
 * Handles uploading location data to the server.
 *
 * Features:
 * - Auth failure handling (401/403 blocks uploads for 30 min)
 * - Exponential backoff on transient failures
 * - Reset on success
 */
class LocationUploader(private val context: Context) {

    companion object {
        private const val TAG = "LocationUploader"
        private const val BASE_URL = "https://relatives.app/tracking/api"
        private val JSON_MEDIA_TYPE = "application/json; charset=utf-8".toMediaType()
    }

    private val prefs = PreferencesManager(context)

    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .writeTimeout(15, TimeUnit.SECONDS)
        .build()

    private val dateFormat = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply {
        timeZone = TimeZone.getTimeZone("UTC")
    }

    /**
     * Upload a location to the server.
     *
     * @return true if upload succeeded, false otherwise
     */
    fun upload(location: Location): Boolean {
        // Check auth block
        if (prefs.isAuthBlocked()) {
            Log.d(TAG, "Auth blocked, skipping upload")
            return false
        }

        // Check backoff
        if (prefs.isInBackoff()) {
            Log.d(TAG, "In backoff, skipping upload")
            return false
        }

        // Check auth credentials
        val userId = prefs.userId
        val sessionToken = prefs.sessionToken
        if (userId.isNullOrEmpty() || sessionToken.isNullOrEmpty()) {
            Log.w(TAG, "No auth credentials, skipping upload")
            return false
        }

        val json = JSONObject().apply {
            put("lat", location.latitude)
            put("lng", location.longitude)
            put("accuracy_m", location.accuracy.toInt())
            put("speed_mps", location.speed)
            put("bearing_deg", if (location.hasBearing()) location.bearing else JSONObject.NULL)
            put("altitude_m", if (location.hasAltitude()) location.altitude.toInt() else JSONObject.NULL)
            put("device_id", "android-$userId")
            put("platform", "android")
            put("app_version", "android-1.0")
            put("recorded_at", dateFormat.format(Date(location.time)))
        }

        val request = Request.Builder()
            .url("$BASE_URL/location.php")
            .addHeader("Cookie", "RELATIVES_SESSION=$sessionToken")
            .addHeader("Content-Type", "application/json")
            .post(json.toString().toRequestBody(JSON_MEDIA_TYPE))
            .build()

        return try {
            val response = client.newCall(request).execute()
            val code = response.code
            response.close()

            when {
                code in 200..299 -> {
                    Log.d(TAG, "Upload success: ${location.latitude}, ${location.longitude}")
                    prefs.resetFailureState()
                    true
                }
                code == 401 || code == 402 || code == 403 -> {
                    Log.w(TAG, "Auth failure ($code), blocking uploads for 30 min")
                    prefs.blockAuth()
                    false
                }
                code == 429 -> {
                    Log.w(TAG, "Rate limited (429)")
                    prefs.recordFailure()
                    false
                }
                else -> {
                    Log.w(TAG, "Upload failed: HTTP $code")
                    prefs.recordFailure()
                    false
                }
            }
        } catch (e: IOException) {
            Log.w(TAG, "Upload network error: ${e.message}")
            prefs.recordFailure()
            false
        }
    }
}
