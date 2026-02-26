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
    private val prefs = (application as RelativesApplication).preferencesManager

    // -- Family Members -----------------------------------------------------

    private val _members = MutableStateFlow<List<TrackingStore.MemberLocation>>(emptyList())
    val members: StateFlow<List<TrackingStore.MemberLocation>> = _members.asStateFlow()

    private var pollJob: Job? = null
    private var activePollInterval = 5_000L

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

    /** Force-refresh from server, then update cache. */
    fun pollNow() {
        viewModelScope.launch {
            try {
                val result = api.getCurrentLocations()
                val data = result.getAsJsonArray("data") ?: return@launch
                val parsed = mutableListOf<TrackingStore.MemberLocation>()
                for (i in 0 until data.size()) {
                    val obj = data[i].asJsonObject ?: continue
                    val lat = obj.get("latitude")?.asDouble
                        ?: obj.get("lat")?.asDouble ?: continue
                    val lng = obj.get("longitude")?.asDouble
                        ?: obj.get("lng")?.asDouble ?: continue
                    parsed.add(
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
                store.putFamilyLocations(parsed)
                _members.value = parsed
            } catch (e: Exception) {
                // Fall back to cached data
                refreshMembers()
            }
        }
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
                val eventList = result.getAsJsonArray("events")
                if (eventList == null || eventList.size() == 0) {
                    eventsNoMore = true
                } else {
                    val parsed = mutableListOf<TrackingEvent>()
                    for (i in 0 until eventList.size()) {
                        val ev = eventList[i].asJsonObject ?: continue
                        val meta = try {
                            ev.get("meta_json")?.asString?.let {
                                com.google.gson.JsonParser.parseString(it).asJsonObject
                            }
                        } catch (_: Exception) { null }

                        parsed.add(
                            TrackingEvent(
                                id = ev.get("id")?.asInt ?: i,
                                eventType = ev.get("event_type")?.asString ?: "unknown",
                                userName = meta?.get("user_name")?.asString
                                    ?: ev.get("user_name")?.asString ?: "Unknown",
                                targetName = meta?.get("geofence_name")?.asString
                                    ?: meta?.get("place_name")?.asString
                                    ?: meta?.get("name")?.asString ?: "Unknown",
                                occurredAt = ev.get("occurred_at")?.asString
                                    ?: ev.get("created_at")?.asString ?: "",
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
                val list = result.getAsJsonArray("geofences") ?: result.getAsJsonArray("data")
                if (list != null) {
                    val parsed = mutableListOf<Geofence>()
                    for (i in 0 until list.size()) {
                        val gf = list[i].asJsonObject ?: continue
                        parsed.add(
                            Geofence(
                                id = gf.get("id")?.asInt ?: continue,
                                name = gf.get("name")?.asString ?: "Unnamed",
                                type = gf.get("type")?.asString ?: "circle",
                                centerLat = gf.get("center_lat")?.asDouble ?: 0.0,
                                centerLng = gf.get("center_lng")?.asDouble ?: 0.0,
                                radiusM = gf.get("radius_m")?.asFloat ?: 200f,
                                polygonJson = gf.get("polygon_json")?.asString,
                                active = gf.get("active")?.let { it.asInt == 1 || it.asBoolean } ?: true,
                                createdAt = gf.get("created_at")?.asString ?: "",
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
                _settings.value = settingsResult.getAsJsonObject("settings") ?: settingsResult

                val alertsResult = api.getAlertRules()
                _alertRules.value = alertsResult.getAsJsonObject("rules") ?: alertsResult
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

    override fun onCleared() {
        stopPolling()
        super.onCleared()
    }
}
