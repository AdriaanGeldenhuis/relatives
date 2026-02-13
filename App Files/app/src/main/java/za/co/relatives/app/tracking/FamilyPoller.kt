package za.co.relatives.app.tracking

import android.content.Context
import android.util.Log
import com.google.gson.Gson
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import okhttp3.Request
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.network.NetworkClient

/**
 * FamilyPoller — periodically fetches family member locations from the server
 * and writes them into TrackingStore.
 *
 * Responsibilities (and nothing else):
 *  - Poll /tracking/api/current.php every N seconds
 *  - Parse the response into MemberLocation objects
 *  - Write into TrackingStore.putFamilyLocations()
 *
 * Does NOT touch UI, map, or WebView. The WebViewBridge and MapboxController
 * read from TrackingStore independently.
 */
class FamilyPoller(context: Context, private val store: TrackingStore) {

    companion object {
        private const val TAG = "FamilyPoller"
        private const val CURRENT_URL = "https://www.relatives.co.za/tracking/api/current.php"
        private const val ACTIVE_INTERVAL_MS = 10_000L   // 10s when active
        private const val BACKGROUND_INTERVAL_MS = 30_000L // 30s when backgrounded
    }

    private val http = NetworkClient.getInstance(context.applicationContext)
    private val gson = Gson()

    private var scope: CoroutineScope? = null
    private var pollJob: Job? = null
    private var intervalMs = ACTIVE_INTERVAL_MS

    /**
     * Start polling. Safe to call multiple times (no-ops if already running).
     */
    fun start() {
        if (scope != null) return
        val newScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
        scope = newScope
        pollJob = newScope.launch { pollLoop() }
        Log.d(TAG, "Polling started (interval=${intervalMs}ms)")
    }

    /**
     * Stop polling and cancel all coroutines.
     */
    fun stop() {
        scope?.cancel()
        scope = null
        pollJob = null
        Log.d(TAG, "Polling stopped")
    }

    /**
     * Switch to a faster or slower interval depending on visibility.
     */
    fun setActive(active: Boolean) {
        intervalMs = if (active) ACTIVE_INTERVAL_MS else BACKGROUND_INTERVAL_MS
        Log.d(TAG, "Interval changed to ${intervalMs}ms (active=$active)")
    }

    /**
     * Force an immediate poll (e.g. when the tracking screen becomes visible).
     */
    fun pollNow() {
        scope?.launch { fetchAndCache() }
    }

    // ── Internal ────────────────────────────────────────────────────────

    private suspend fun pollLoop() {
        while (scope?.isActive == true) {
            fetchAndCache()
            delay(intervalMs)
        }
    }

    private fun fetchAndCache() {
        try {
            val request = Request.Builder().url(CURRENT_URL).get().build()
            val response = http.newCall(request).execute()
            val body = response.body?.string() ?: return

            if (!response.isSuccessful) {
                Log.w(TAG, "Poll failed: ${response.code}")
                return
            }

            val json = gson.fromJson(body, JsonObject::class.java) ?: return
            val data = json.getAsJsonArray("data") ?: return

            val members = parseMemberLocations(data)
            store.putFamilyLocations(members)
            Log.d(TAG, "Cached ${members.size} family locations")
        } catch (e: Exception) {
            Log.w(TAG, "Poll error", e)
        }
    }

    private fun parseMemberLocations(data: JsonArray): List<TrackingStore.MemberLocation> {
        val result = mutableListOf<TrackingStore.MemberLocation>()
        for (i in 0 until data.size()) {
            val obj = data[i].asJsonObject ?: continue
            val lat = obj.get("latitude")?.asDouble
                ?: obj.get("lat")?.asDouble
                ?: continue
            val lng = obj.get("longitude")?.asDouble
                ?: obj.get("lng")?.asDouble
                ?: continue

            result.add(
                TrackingStore.MemberLocation(
                    memberId = (obj.get("user_id") ?: obj.get("id"))?.asString ?: continue,
                    name = obj.get("name")?.asString ?: "Unknown",
                    lat = lat,
                    lng = lng,
                    accuracy = obj.get("accuracy_m")?.asFloat ?: obj.get("accuracy")?.asFloat,
                    speed = obj.get("speed_mps")?.asFloat ?: obj.get("speed")?.asFloat,
                    motionState = obj.get("motion_state")?.asString,
                    updatedAt = obj.get("recorded_at")?.asString ?: obj.get("updated_at")?.asString,
                    color = obj.get("color")?.asString,
                )
            )
        }
        return result
    }
}
