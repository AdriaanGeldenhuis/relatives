package za.co.relatives.app.ui.tracking

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.google.gson.JsonObject
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import za.co.relatives.app.RelativesApplication
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.network.TrackingApiClient
import za.co.relatives.app.tracking.TrackingService

/**
 * Central ViewModel for all native tracking screens.
 *
 * Manages:
 *  - Family member locations (polled from TrackingStore cache)
 *  - Events list
 *  - Geofences list
 *  - Settings & alert rules
 *  - Tracking on/off state
 */
class TrackingViewModel(application: Application) : AndroidViewModel(application) {

    private val api = TrackingApiClient(application)
    val store = TrackingStore(application)
    private val prefs = (application as? RelativesApplication)?.preferencesManager
        ?: za.co.relatives.app.utils.PreferencesManager(application)

    // -- Family Members -----------------------------------------------------

    private val _members = MutableStateFlow(store.getFamilyLocations())
    val members: StateFlow<List<TrackingStore.MemberLocation>> = _members.asStateFlow()

    private var pollJob: Job? = null
    private var activePollInterval = 5_000L

    /**
     * The network polling is owned by TrackingActivity's FamilyPoller (the
     * same hardened poller MainActivity uses — null-safe parsing, single
     * flight, stops in background). It writes into the shared TrackingStore
     * cache; this loop just mirrors that cache into Compose state. Reading
     * the in-memory cache every few seconds costs nothing.
     */
    fun startPolling() {
        if (pollJob?.isActive == true) return
        pollJob = viewModelScope.launch {
            while (isActive) {
                refreshMembers()
                delay(activePollInterval)
            }
        }
    }

    fun stopPolling() {
        pollJob?.cancel()
        pollJob = null
    }

    private fun refreshMembers() {
        _members.value = store.getFamilyLocations()
    }

    /** Re-read the shared cache immediately (e.g. after the poller ran). */
    fun pollNow() {
        refreshMembers()
    }

    // -- Tracking toggle ----------------------------------------------------

    private val _trackingEnabled = MutableStateFlow(prefs.trackingEnabled)
    val trackingEnabled: StateFlow<Boolean> = _trackingEnabled.asStateFlow()

    fun enableTracking() {
        prefs.trackingEnabled = true
        TrackingService.start(getApplication())
        _trackingEnabled.value = true
    }

    fun disableTracking() {
        prefs.trackingEnabled = false
        TrackingService.stop(getApplication())
        _trackingEnabled.value = false
    }

    // -- Events -----------------------------------------------------------

    data class TrackingEvent(
        val id: Int,
        val eventType: String,
        val userName: String,
        val targetName: String,
        val occurredAt: String,
    )

    private val _events = MutableStateFlow<List<TrackingEvent>>(emptyList())
    val events: StateFlow<List<TrackingEvent>> = _events.asStateFlow()

    private val _eventsLoading = MutableStateFlow(false)
    val eventsLoading: StateFlow<Boolean> = _eventsLoading.asStateFlow()

    private var eventsOffset = 0
    private var eventsNoMore = false

    fun loadEvents(type: String? = null, reset: Boolean = false) {
        if (_eventsLoading.value) return
        if (!reset && eventsNoMore) return

        if (reset) {
            eventsOffset = 0
            eventsNoMore = false
            _events.value = emptyList()
        }

        _eventsLoading.value = true
        viewModelScope.launch {
            try {
                val result = api.getEvents(limit = 30, offset = eventsOffset, type = type)
                // Response::success wraps the payload under "data".
                val eventList = dataArray(result) ?: result.getAsJsonArray("events")
                if (eventList == null || eventList.size() == 0) {
                    eventsNoMore = true
                } else {
                    val parsed = mutableListOf<TrackingEvent>()
                    for (i in 0 until eventList.size()) {
                        val ev = eventList[i].takeIf { it.isJsonObject }?.asJsonObject ?: continue
                        val meta = try {
                            ev.strOrNull("meta_json")?.let {
                                com.google.gson.JsonParser.parseString(it).asJsonObject
                            }
                        } catch (_: Exception) { null }

                        parsed.add(
                            TrackingEvent(
                                id = ev.intOrNull("id") ?: i,
                                eventType = ev.strOrNull("event_type") ?: "unknown",
                                userName = meta?.strOrNull("user_name")
                                    ?: ev.strOrNull("user_name") ?: "Unknown",
                                targetName = meta?.strOrNull("geofence_name")
                                    ?: meta?.strOrNull("place_name")
                                    ?: meta?.strOrNull("name") ?: "Unknown",
                                occurredAt = ev.strOrNull("occurred_at")
                                    ?: ev.strOrNull("created_at") ?: "",
                            )
                        )
                    }
                    eventsOffset += parsed.size
                    _events.value = _events.value + parsed
                    if (parsed.size < 30) eventsNoMore = true
                }
            } catch (_: Exception) { }
            _eventsLoading.value = false
        }
    }

    // -- Geofences --------------------------------------------------------

    data class Geofence(
        val id: Int,
        val name: String,
        val type: String,
        val centerLat: Double,
        val centerLng: Double,
        val radiusM: Float,
        val polygonJson: String?,
        val active: Boolean,
        val createdAt: String,
    )

    private val _geofences = MutableStateFlow<List<Geofence>>(emptyList())
    val geofences: StateFlow<List<Geofence>> = _geofences.asStateFlow()

    private val _geofencesLoading = MutableStateFlow(false)
    val geofencesLoading: StateFlow<Boolean> = _geofencesLoading.asStateFlow()

    fun loadGeofences() {
        _geofencesLoading.value = true
        viewModelScope.launch {
            try {
                val result = api.getGeofences()
                val list = dataArray(result) ?: result.getAsJsonArray("geofences")
                if (list != null) {
                    // Null-safe field reads: center_lat/center_lng are SQL NULL
                    // for polygon zones and polygon_json is NULL for circles —
                    // Gson's JsonNull.asX throws, which used to wipe the list.
                    val parsed = mutableListOf<Geofence>()
                    for (i in 0 until list.size()) {
                        val gf = list[i].takeIf { it.isJsonObject }?.asJsonObject ?: continue
                        parsed.add(
                            Geofence(
                                id = gf.intOrNull("id") ?: continue,
                                name = gf.strOrNull("name") ?: "Unnamed",
                                type = gf.strOrNull("type") ?: "circle",
                                centerLat = gf.dblOrNull("center_lat") ?: 0.0,
                                centerLng = gf.dblOrNull("center_lng") ?: 0.0,
                                radiusM = gf.dblOrNull("radius_m")?.toFloat() ?: 200f,
                                polygonJson = gf.strOrNull("polygon_json"),
                                active = gf.boolOrNull("active") ?: true,
                                createdAt = gf.strOrNull("created_at") ?: "",
                            )
                        )
                    }
                    _geofences.value = parsed
                }
            } catch (_: Exception) { }
            _geofencesLoading.value = false
        }
    }

    fun deleteGeofence(id: Int) {
        viewModelScope.launch {
            try {
                api.deleteGeofence(id)
                _geofences.value = _geofences.value.filter { it.id != id }
            } catch (_: Exception) { }
        }
    }

    fun addGeofence(
        name: String, type: String,
        lat: Double, lng: Double,
        radiusM: Float, polygonJson: String?
    ) {
        viewModelScope.launch {
            try {
                val payload = JsonObject().apply {
                    addProperty("name", name)
                    addProperty("type", type)
                    addProperty("center_lat", lat)
                    addProperty("center_lng", lng)
                    addProperty("radius_m", radiusM)
                    if (polygonJson != null) addProperty("polygon_json", polygonJson)
                }
                api.addGeofence(payload)
                loadGeofences()
            } catch (_: Exception) { }
        }
    }

    // -- Settings ---------------------------------------------------------

    private val _settings = MutableStateFlow<JsonObject?>(null)
    val settings: StateFlow<JsonObject?> = _settings.asStateFlow()

    private val _alertRules = MutableStateFlow<JsonObject?>(null)
    val alertRules: StateFlow<JsonObject?> = _alertRules.asStateFlow()

    private val _settingsLoading = MutableStateFlow(false)
    val settingsLoading: StateFlow<Boolean> = _settingsLoading.asStateFlow()

    fun loadSettings() {
        _settingsLoading.value = true
        viewModelScope.launch {
            try {
                val settingsResult = api.getSettings()
                _settings.value = dataObject(settingsResult)
                    ?: settingsResult.getAsJsonObject("settings")

                val alertsResult = api.getAlertRules()
                _alertRules.value = dataObject(alertsResult)
                    ?: alertsResult.getAsJsonObject("rules")
            } catch (_: Exception) { }
            _settingsLoading.value = false
        }
    }

    fun saveSettings(settingsPayload: JsonObject, alertsPayload: JsonObject) {
        viewModelScope.launch {
            try {
                api.saveSettings(settingsPayload)
                api.saveAlertRules(alertsPayload)
                _saveSuccess.value = true
            } catch (_: Exception) {
                _saveSuccess.value = false
            }
        }
    }

    private val _saveSuccess = MutableStateFlow<Boolean?>(null)
    val saveSuccess: StateFlow<Boolean?> = _saveSuccess.asStateFlow()

    fun clearSaveStatus() { _saveSuccess.value = null }

    // -- Wake -------------------------------------------------------------

    fun wakeAllDevices() {
        viewModelScope.launch {
            try {
                api.wakeDevices()
            } catch (_: Exception) { }
        }
    }

    // -- Response helpers ---------------------------------------------------
    // Response::success wraps every payload as {success, message, data}.

    private fun dataArray(result: JsonObject) =
        result.get("data")?.takeIf { it.isJsonArray }?.asJsonArray

    private fun dataObject(result: JsonObject) =
        result.get("data")?.takeIf { it.isJsonObject }?.asJsonObject

    // Null-safe Gson accessors: JsonNull.asX throws, so filter it out.

    private fun JsonObject.primOrNull(key: String) =
        get(key)?.takeIf { !it.isJsonNull && it.isJsonPrimitive }

    private fun JsonObject.strOrNull(key: String): String? =
        try { primOrNull(key)?.asString } catch (_: Exception) { null }

    private fun JsonObject.intOrNull(key: String): Int? =
        try { primOrNull(key)?.asInt } catch (_: Exception) { null }

    private fun JsonObject.dblOrNull(key: String): Double? =
        try { primOrNull(key)?.asDouble } catch (_: Exception) { null }

    private fun JsonObject.boolOrNull(key: String): Boolean? =
        try {
            val e = primOrNull(key) ?: return null
            when {
                e.asJsonPrimitive.isBoolean -> e.asBoolean
                else -> e.asInt == 1 // MySQL tinyint
            }
        } catch (_: Exception) { null }

    override fun onCleared() {
        stopPolling()
        super.onCleared()
    }
}
