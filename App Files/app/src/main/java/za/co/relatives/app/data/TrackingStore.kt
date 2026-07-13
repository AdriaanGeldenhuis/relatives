package za.co.relatives.app.data

import android.content.Context
import android.location.Location
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject

/**
 * TrackingStore — single source of truth for all tracking data on the device.
 *
 * Acts as a local "memcache" layer:
 *  - Cached family member locations (in-memory map, avoids flicker / API spam)
 *  - Last-known device location (for dedup before upload)
 *  - Upload throttle state (last upload time)
 *  - Offline location queue (backed by Room)
 *
 * The store never calls the network directly. Modules write into it;
 * the WebViewBridge reads from it; MapboxController renders from it.
 */
class TrackingStore(context: Context) {

    private val appContext = context.applicationContext
    private val dao = TrackingDatabase.getInstance(appContext).locationDao()
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    // ── In-memory family location cache ─────────────────────────────────

    companion object {
        /**
         * Family cache shared across ALL TrackingStore instances.
         * MainActivity, TrackingBridge and the workers each construct their
         * own store; they must all see the same family snapshot or the map
         * page reads a permanently empty cache.
         */
        private val familyCache = mutableMapOf<String, MemberLocation>()

        @Volatile
        private var familyPollTime: Long = 0L
    }

    /** Timestamp of the last family poll that actually changed data. */
    val lastFamilyPollTime: Long
        get() = familyPollTime

    data class MemberLocation(
        val memberId: String,
        val name: String,
        val lat: Double?,
        val lng: Double?,
        val hasLocation: Boolean = lat != null && lng != null,
        val accuracy: Float? = null,
        val speed: Float? = null,
        val bearingDeg: Float? = null,
        val altitudeM: Double? = null,
        val motionState: String? = null,
        val recordedAt: String? = null,
        val updatedAt: String? = null,
        val avatarColor: String? = null,
        val hasAvatar: Boolean = false,
    )

    /**
     * Replace all family member locations atomically.
     * Only updates [lastFamilyPollTime] if the data actually changed.
     */
    fun putFamilyLocations(members: List<MemberLocation>) {
        synchronized(familyCache) {
            val changed = members.size != familyCache.size ||
                members.any { m ->
                    val cached = familyCache[m.memberId]
                    cached == null || cached.lat != m.lat || cached.lng != m.lng
                }
            familyCache.clear()
            members.forEach { familyCache[it.memberId] = it }
            if (changed) familyPollTime = System.currentTimeMillis()
        }
    }

    /** Get a snapshot of all cached family locations. */
    fun getFamilyLocations(): List<MemberLocation> =
        synchronized(familyCache) { familyCache.values.toList() }

    /**
     * Dump family locations as a JSON string for the WebView bridge.
     *
     * The shape mirrors /tracking/api/current.php exactly so the web page can
     * consume bridge data and API data interchangeably. Legacy keys
     * (id/latitude/longitude) are kept as aliases.
     */
    fun familyLocationsJson(): String = synchronized(familyCache) {
        val arr = JSONArray()
        familyCache.values.forEach { m ->
            arr.put(JSONObject().apply {
                put("user_id", m.memberId.toIntOrNull() ?: m.memberId)
                put("name", m.name)
                put("avatar_color", m.avatarColor ?: JSONObject.NULL)
                put("has_avatar", m.hasAvatar)
                put("has_location", m.hasLocation)
                put("lat", m.lat ?: JSONObject.NULL)
                put("lng", m.lng ?: JSONObject.NULL)
                put("accuracy_m", m.accuracy ?: JSONObject.NULL)
                put("speed_mps", m.speed ?: JSONObject.NULL)
                put("bearing_deg", m.bearingDeg ?: JSONObject.NULL)
                put("altitude_m", m.altitudeM ?: JSONObject.NULL)
                put("motion_state", m.motionState ?: JSONObject.NULL)
                put("recorded_at", m.recordedAt ?: JSONObject.NULL)
                put("updated_at", m.updatedAt ?: JSONObject.NULL)
                // Legacy aliases
                put("id", m.memberId)
                put("latitude", m.lat ?: JSONObject.NULL)
                put("longitude", m.lng ?: JSONObject.NULL)
            })
        }
        arr.toString()
    }

    // ── Device location dedup ───────────────────────────────────────────

    /** Last device location used for dedup. */
    @Volatile
    var lastDeviceLocation: Location? = null
        private set

    @Volatile
    var lastDeviceEnqueueTime: Long = 0L
        private set

    /** Minimum distance (metres) the device must move before we queue a new upload. */
    private val dedupDistanceMetres = 10f

    /** Minimum time (ms) between uploads even when moving. */
    private val dedupTimeMs = 5_000L

    /**
     * Check whether a new location should be queued or deduped away.
     * Returns true if the location is "new enough" to queue.
     */
    fun shouldEnqueue(location: Location): Boolean {
        val prev = lastDeviceLocation ?: return true
        val timeDelta = System.currentTimeMillis() - lastDeviceEnqueueTime
        if (timeDelta < dedupTimeMs) return false
        val distance = prev.distanceTo(location)
        return distance >= dedupDistanceMetres
    }

    /** Record that we just enqueued this location. */
    fun markEnqueued(location: Location) {
        lastDeviceLocation = location
        lastDeviceEnqueueTime = System.currentTimeMillis()
    }

    // ── Upload throttle ─────────────────────────────────────────────────

    @Volatile
    var lastUploadTime: Long = 0L

    // ── Offline queue (Room-backed) ─────────────────────────────────────

    fun enqueueLocation(entity: QueuedLocationEntity) {
        scope.launch {
            try {
                dao.insert(entity)
                dao.trimToMaxSize(300)
            } catch (e: Exception) {
                // A failed queue write (disk full, DB corruption) must never
                // take the process down; the point is simply lost.
                android.util.Log.w("TrackingStore", "Failed to queue location", e)
            }
        }
    }

    suspend fun getUnsentLocations(limit: Int = 100) = dao.getUnsent(limit)

    suspend fun markSent(id: String) = dao.markSent(id)

    suspend fun incrementRetry(id: String) = dao.incrementRetry(id)

    suspend fun cleanupSent() = dao.deleteSent()

    /** Drop queued points older than [cutoffMillis] (age-based expiry). */
    suspend fun expireOlderThan(cutoffMillis: Long) = dao.deleteOlderThan(cutoffMillis)

    suspend fun unsentCount() = dao.unsentCount()
}
