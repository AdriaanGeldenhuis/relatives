package za.co.relatives.app.network

import android.content.Context
import com.google.gson.Gson
import com.google.gson.JsonObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * Native API client for all tracking endpoints.
 *
 * Replaces the WebView JS calls to /tracking/api/* with direct OkHttp calls.
 * All methods run on [Dispatchers.IO] and return parsed results or throw.
 */
class TrackingApiClient(context: Context) {

    companion object {
        private const val BASE = "https://www.relatives.co.za/tracking/api"
        private val JSON_MEDIA = "application/json; charset=utf-8".toMediaType()
    }

    private val http = NetworkClient.getInstance(context.applicationContext)
    private val gson = Gson()

    // ── Family locations ────────────────────────────────────────────────

    suspend fun getCurrentLocations(): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$BASE/current.php").get().build()
        executeJson(request)
    }

    suspend fun getLocationHistory(
        userId: String? = null,
        hours: Int = 24
    ): JsonObject = withContext(Dispatchers.IO) {
        var url = "$BASE/history.php?hours=$hours"
        if (userId != null) url += "&user_id=$userId"
        val request = Request.Builder().url(url).get().build()
        executeJson(request)
    }

    // ── Session management ──────────────────────────────────────────────

    suspend fun keepAlive(): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$BASE/keepalive.php")
            .post("{}".toRequestBody(JSON_MEDIA)).build()
        executeJson(request)
    }

    suspend fun getSessionStatus(): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$BASE/session_status.php").get().build()
        executeJson(request)
    }

    // ── Events ──────────────────────────────────────────────────────────

    suspend fun getEvents(
        limit: Int = 30,
        offset: Int = 0,
        type: String? = null
    ): JsonObject = withContext(Dispatchers.IO) {
        var url = "$BASE/events_list.php?limit=$limit&offset=$offset"
        if (type != null) url += "&type=$type"
        val request = Request.Builder().url(url).get().build()
        executeJson(request)
    }

    // ── Geofences ───────────────────────────────────────────────────────

    suspend fun getGeofences(): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$BASE/geofences_list.php").get().build()
        executeJson(request)
    }

    suspend fun addGeofence(payload: JsonObject): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$BASE/geofences_add.php")
            .post(gson.toJson(payload).toRequestBody(JSON_MEDIA)).build()
        executeJson(request)
    }

    suspend fun updateGeofence(payload: JsonObject): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$BASE/geofences_update.php")
            .post(gson.toJson(payload).toRequestBody(JSON_MEDIA)).build()
        executeJson(request)
    }

    suspend fun deleteGeofence(id: Int): JsonObject = withContext(Dispatchers.IO) {
        val body = JsonObject().apply { addProperty("id", id) }
        val request = Request.Builder().url("$BASE/geofences_delete.php")
            .post(gson.toJson(body).toRequestBody(JSON_MEDIA)).build()
        executeJson(request)
    }

    // ── Places ──────────────────────────────────────────────────────────

    suspend fun getPlaces(): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$BASE/places_list.php").get().build()
        executeJson(request)
    }

    suspend fun addPlace(payload: JsonObject): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$BASE/places_add.php")
            .post(gson.toJson(payload).toRequestBody(JSON_MEDIA)).build()
        executeJson(request)
    }

    suspend fun deletePlace(id: Int): JsonObject = withContext(Dispatchers.IO) {
        val body = JsonObject().apply { addProperty("id", id) }
        val request = Request.Builder().url("$BASE/places_delete.php")
            .post(gson.toJson(body).toRequestBody(JSON_MEDIA)).build()
        executeJson(request)
    }

    // ── Settings ────────────────────────────────────────────────────────

    suspend fun getSettings(): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$BASE/settings_get.php").get().build()
        executeJson(request)
    }

    suspend fun saveSettings(payload: JsonObject): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$BASE/settings_save.php")
            .post(gson.toJson(payload).toRequestBody(JSON_MEDIA)).build()
        executeJson(request)
    }

    // ── Alert Rules ─────────────────────────────────────────────────────

    suspend fun getAlertRules(): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$BASE/alerts_rules_get.php").get().build()
        executeJson(request)
    }

    suspend fun saveAlertRules(payload: JsonObject): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$BASE/alerts_rules_save.php")
            .post(gson.toJson(payload).toRequestBody(JSON_MEDIA)).build()
        executeJson(request)
    }

    // ── Directions ──────────────────────────────────────────────────────

    suspend fun getDirections(
        fromLat: Double, fromLng: Double,
        toLat: Double, toLng: Double
    ): JsonObject = withContext(Dispatchers.IO) {
        val url = "$BASE/directions.php?from_lat=$fromLat&from_lng=$fromLng&to_lat=$toLat&to_lng=$toLng"
        val request = Request.Builder().url(url).get().build()
        executeJson(request)
    }

    // ── Wake devices ────────────────────────────────────────────────────

    suspend fun wakeDevices(): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url("$BASE/wake_devices.php")
            .post("{}".toRequestBody(JSON_MEDIA)).build()
        executeJson(request)
    }

    // ── Internal ────────────────────────────────────────────────────────

    private fun executeJson(request: Request): JsonObject {
        val response = http.newCall(request).execute()
        val bodyString = response.body?.string().orEmpty()

        if (!response.isSuccessful) {
            throw ApiException(response.code, bodyString)
        }

        return try {
            gson.fromJson(bodyString, JsonObject::class.java)
                ?: throw ApiException(response.code, "Empty JSON response")
        } catch (e: Exception) {
            if (e is ApiException) throw e
            throw ApiException(response.code, "Failed to parse response: ${e.message}")
        }
    }
}
