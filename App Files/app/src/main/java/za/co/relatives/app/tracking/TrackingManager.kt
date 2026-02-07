package za.co.relatives.app.tracking

import android.content.Context
import android.location.Location
import za.co.relatives.app.data.PreferencesManager
import za.co.relatives.app.utils.BatteryUtils
import za.co.relatives.app.utils.NetworkUtils

/**
 * 3-mode state machine: IDLE -> MOVING -> BURST
 * IDLE: No GPS, uses activity recognition only
 * MOVING: GPS every 15-30s, distance gating (>10m)
 * BURST: GPS every 5-10s for driving
 */
class TrackingManager(
    private val context: Context,
    private val prefs: PreferencesManager
) {
    enum class State { IDLE, MOVING, BURST }

    private var currentState = State.MOVING
    private var lastUploadedLocation: Location? = null
    private var stationaryCount = 0
    private val uploader = LocationUploader(context, prefs)

    private companion object {
        const val MIN_DISTANCE_M = 10f
        const val BURST_SPEED_THRESHOLD = 8.33f // 30 km/h in m/s
        const val STATIONARY_THRESHOLD = 12 // ~2 min at 10s intervals
    }

    fun onLocationReceived(location: Location) {
        // State machine transitions
        when {
            location.speed > BURST_SPEED_THRESHOLD -> {
                currentState = State.BURST
                stationaryCount = 0
            }
            location.speed < 0.5f -> {
                stationaryCount++
                if (stationaryCount > STATIONARY_THRESHOLD) {
                    currentState = State.IDLE
                    prefs.setTrackingState("IDLE")
                }
            }
            else -> {
                currentState = State.MOVING
                stationaryCount = 0
                prefs.setTrackingState("MOVING")
            }
        }

        // Distance gating
        val last = lastUploadedLocation
        if (last != null && location.distanceTo(last) < MIN_DISTANCE_M) {
            return // Too close, skip
        }

        // Upload
        val battery = BatteryUtils.getBatteryLevel(context)
        uploader.upload(location, battery, currentState.name)
        lastUploadedLocation = location
    }
}
