package za.co.relatives.app.tracking

import android.content.Context
import android.util.Log
import com.google.gson.Gson
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import okhttp3.Request
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.network.NetworkClient

/**
 * FamilyPoller — periodically fetches family member locations from the server
 * and writes them into TrackingStore.
 *
 * Lifecycle: runs ONLY while the app is in the foreground (MainActivity
 * onResume starts it, onPause stops it). While the tracking map is visible it
 * polls fast; on other screens it polls slowly just to keep the cache warm.
 *
 * Parsing is defensive: the API returns JSON null for lat/lng/accuracy/speed
 * of members who have not shared a location yet, and Gson's JsonNull throws
 * on .asDouble/.asFloat — a single such member must not discard the poll.
 */
class FamilyPoller(context: Context, private val store: TrackingStore) {

    companion object {
        private const val TAG = "FamilyPoller"
        private const val CURRENT_URL = "https://www.relatives.co.za/tracking/api/current.php"
        private const val ACTIVE_INTERVAL_MS = 15_000L  // tracking map visible
        private const val PASSIVE_INTERVAL_MS = 60_000L // app foregrounded elsewhere
    }

    private val http = NetworkClient.getInstance(context.applicationContext)
    private val gson = Gson()
    private val fetchMutex = Mutex()

    private var scope: CoroutineScope? = null
    private var pollJob: Job? = null

    @Volatile
    private var intervalMs = PASSIVE_INTERVAL_MS

    /** Start polling. Safe to call multiple times (no-ops if already running). */
    @Synchronized
    fun start() {
        if (scope != null) return
        val newScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
        scope = newScope
        pollJob = newScope.launch { pollLoop() }
        Log.d(TAG, "Polling started (interval=${intervalMs}ms)")
    }

    /** Stop polling and cancel all coroutines. */
    @Synchronized
    fun stop() {
        scope?.cancel()
        scope = null
        pollJob = null
        Log.d(TAG, "Polling stopped")
    }

    /**
     * Switch between the fast (tracking map visible) and slow interval.
     * Restarts the loop so the new cadence applies immediately, with an
     * immediate poll when speeding up.
     */
    @Synchronized
    fun setActive(active: Boolean) {
        val newInterval = if (active) ACTIVE_INTERVAL_MS else PASSIVE_INTERVAL_MS
        if (newInterval == intervalMs) return
        intervalMs = newInterval
        val currentScope = scope ?: return
        pollJob?.cancel()
        pollJob = currentScope.launch {
            if (active) fetchAndCache()
            pollLoop()
        }
        Log.d(TAG, "Interval changed to ${intervalMs}ms (active=$active)")
    }

    /** Force an immediate poll (e.g. when the tracking screen becomes visible). */
    fun pollNow() {
        scope?.launch { fetchAndCache() }
    }

    // ── Internal ────────────────────────────────────────────────────────

    private suspend fun pollLoop() {
        val myScope = scope
        while (myScope?.isActive == true) {
            fetchAndCache()
            delay(intervalMs)
        }
    }

    private suspend fun fetchAndCache() {
        // Single flight: pollNow + loop must not stack requests.
        if (!fetchMutex.tryLock()) return
        try {
            val request = Request.Builder().url(CURRENT_URL).get().build()
            http.newCall(request).execute().use { response ->
                val body = response.body?.string() ?: return

                if (!response.isSuccessful) {
                    Log.w(TAG, "Poll failed: ${response.code}")
                    return
                }

                val json = try {
                    gson.fromJson(body, JsonObject::class.java)
                } catch (e: Exception) {
                    Log.w(TAG, "Poll returned non-JSON body")
                    null
                } ?: return
                val data = if (json.has("data") && json.get("data").isJsonArray) {
                    json.getAsJsonArray("data")
                } else {
                    return
                }

                val members = mutableListOf<TrackingStore.MemberLocation>()
                for (element in data) {
                    val member = try {
                        parseMember(element)
                    } catch (e: Exception) {
                        Log.w(TAG, "Skipping unparseable member", e)
                        null
                    }
                    if (member != null) members.add(member)
                }
                store.putFamilyLocations(members)
                Log.d(TAG, "Cached ${members.size} family members")
            }
        } catch (e: Exception) {
            Log.w(TAG, "Poll error", e)
        } finally {
            fetchMutex.unlock()
        }
    }

    private fun parseMember(element: JsonElement): TrackingStore.MemberLocation? {
        if (!element.isJsonObject) return null
        val obj = element.asJsonObject

        val memberId = obj.str("user_id") ?: obj.str("id") ?: return null
        val lat = obj.dbl("lat", "latitude")
        val lng = obj.dbl("lng", "longitude")

        return TrackingStore.MemberLocation(
            memberId = memberId,
            name = obj.str("name") ?: "Unknown",
            lat = lat,
            lng = lng,
            hasLocation = obj.bool("has_location") ?: (lat != null && lng != null),
            accuracy = obj.dbl("accuracy_m", "accuracy")?.toFloat(),
            speed = obj.dbl("speed_mps", "speed")?.toFloat(),
            bearingDeg = obj.dbl("bearing_deg", "bearing")?.toFloat(),
            altitudeM = obj.dbl("altitude_m", "altitude"),
            motionState = obj.str("motion_state"),
            recordedAt = obj.str("recorded_at"),
            updatedAt = obj.str("updated_at"),
            avatarColor = obj.str("avatar_color") ?: obj.str("color"),
            hasAvatar = obj.bool("has_avatar") ?: false,
        )
    }

    // ── Null-safe Gson accessors (JsonNull.asX throws, so filter it out) ──

    private fun JsonObject.prim(vararg keys: String): JsonElement? {
        for (k in keys) {
            val e = get(k)
            if (e != null && !e.isJsonNull && e.isJsonPrimitive) return e
        }
        return null
    }

    private fun JsonObject.str(vararg keys: String): String? =
        try {
            prim(*keys)?.asString
        } catch (_: Exception) {
            null
        }

    private fun JsonObject.dbl(vararg keys: String): Double? =
        try {
            prim(*keys)?.asDouble
        } catch (_: Exception) {
            null
        }

    private fun JsonObject.bool(vararg keys: String): Boolean? =
        try {
            prim(*keys)?.asBoolean
        } catch (_: Exception) {
            null
        }
}
