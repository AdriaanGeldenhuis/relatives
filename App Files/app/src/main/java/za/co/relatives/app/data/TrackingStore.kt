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

    /**
     * Cached family member locations keyed by member id.
     * Written by FamilyPoller, read by WebViewBridge / MapboxController.
     */
    private val familyCache = mutableMapOf<String, MemberLocation>()

    /** Timestamp of the last family poll that actually changed data. */
    @Volatile
    var lastFamilyPollTime: Long = 0L
        private set

    data class MemberLocation(
        val memberId: String,
        val name: String,
        val lat: Double,
        val lng: Double,
        val accuracy: Float?,
        val speed: Float?,
        val motionState: String?,
        val updatedAt: String?,
        val color: String? = null,
    )

    /**
     * Replace all family member locations atomically.
     * Only updates [lastFamilyPollTime] if the data actually changed.
     */
    @Synchronized
    fun putFamilyLocations(members: List<MemberLocation>) {
        val changed = members.size != familyCache.size ||
            members.any { m ->
                val cached = familyCache[m.memberId]
                cached == null || cached.lat != m.lat || cached.lng != m.lng
            }
        familyCache.clear()
        members.forEach { familyCache[it.memberId] = it }
        if (changed) lastFamilyPollTime = System.currentTimeMillis()
    }

    /** Get a snapshot of all cached family locations. */
    @Synchronized
    fun getFamilyLocations(): List<MemberLocation> = familyCache.values.toList()

    /**
     * Dump family locations as a JSON string for the WebView bridge.
     * Format: [{ id, name, lat, lng, accuracy, speed, motion_state, updated_at, color }]
     */
    @Synchronized
    fun familyLocationsJson(): String {
        val arr = JSONArray()
        familyCache.values.forEach { m ->
            arr.put(JSONObject().apply {
                put("id", m.memberId)
                put("name", m.name)
                put("latitude", m.lat)
                put("longitude", m.lng)
                put("accuracy", m.accuracy ?: JSONObject.NULL)
                put("speed", m.speed ?: JSONObject.NULL)
                put("motion_state", m.motionState ?: JSONObject.NULL)
                put("updated_at", m.updatedAt ?: JSONObject.NULL)
                put("color", m.color ?: JSONObject.NULL)
            })
        }
        return arr.toString()
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
            dao.insert(entity)
            dao.trimToMaxSize(300)
        }
    }

    suspend fun getUnsentLocations(limit: Int = 100) = dao.getUnsent(limit)

    suspend fun markSent(id: String) = dao.markSent(id)

    suspend fun incrementRetry(id: String) = dao.incrementRetry(id)

    suspend fun cleanupSent() = dao.deleteSent()

    suspend fun unsentCount() = dao.unsentCount()
}
