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
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import okhttp3.CookieJar
import okhttp3.Request
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.network.NetworkClient
import za.co.relatives.app.network.readTextResponse
import za.co.relatives.app.utils.PreferencesManager

/**
 * FamilyPoller — periodically fetches family member locations from the server
 * and writes them into TrackingStore.
 *
 * Polls only when the tracking map is visible and MainActivity is resumed.
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
    }

    private val preferences = PreferencesManager(context)
    private val http = NetworkClient.getInstance(context.applicationContext).newBuilder()
        .cookieJar(CookieJar.NO_COOKIES).followRedirects(false).build()
    private val gson = Gson()
    private val fetchMutex = Mutex()

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var pollJob: Job? = null
    private var resumed = false
    private var mapVisible = false

    // Called on the activity's main thread.
    fun start() {
        resumed = true
        updatePolling()
    }

    fun stop() {
        resumed = false
        updatePolling()
    }

    fun setActive(active: Boolean) {
        mapVisible = active
        updatePolling()
    }

    private fun updatePolling() {
        if (!resumed || !mapVisible) {
            pollJob?.cancel()
            pollJob = null
        } else if (pollJob?.isActive != true) {
            pollJob = scope.launch {
                while (isActive) {
                    fetchAndCache()
                    delay(ACTIVE_INTERVAL_MS)
                }
            }
        }
    }

    private suspend fun fetchAndCache() {
        // Serialize a rapid map reopen with cancellation of the previous poll.
        fetchMutex.lock()
        try {
            val cookie = preferences.sessionToken ?: return
            val request = Request.Builder().url(CURRENT_URL).get()
                .header("Cookie", "RELATIVES_SESSION=$cookie").build()
            val (status, responseBody) = http.newCall(request).readTextResponse()
            if (status !in 200..299) {
                if ((status == 401 || status == 403) && preferences.sessionToken == cookie) store.clearFamilyLocations()
                Log.w(TAG, "Poll failed: $status")
                return
            }
            val body = responseBody ?: return

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
            currentCoroutineContext().ensureActive()
            if (preferences.sessionToken == cookie) store.putFamilyLocations(members)
            Log.d(TAG, "Cached ${members.size} family members")
        } catch (cancelled: CancellationException) {
            throw cancelled
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
