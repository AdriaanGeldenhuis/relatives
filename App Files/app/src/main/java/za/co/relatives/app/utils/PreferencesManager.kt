package za.co.relatives.app.utils

import android.content.Context
import android.content.SharedPreferences
import androidx.core.content.edit
import org.json.JSONObject
import java.util.UUID

object PreferencesManager {
    private const val PREF_NAME = "relatives_prefs"
    private const val KEY_DEVICE_UUID = "device_uuid"
    private const val KEY_TRACKING_ENABLED = "tracking_enabled"
    private const val KEY_UPDATE_INTERVAL = "update_interval"
    private const val KEY_LAST_NOTIF_COUNT = "last_notif_count"
    const val KEY_SESSION_TOKEN = "session_token"
    const val KEY_IDLE_HEARTBEAT_SECONDS = "idle_heartbeat_seconds"
    const val KEY_OFFLINE_THRESHOLD_SECONDS = "offline_threshold_seconds"
    const val KEY_STALE_THRESHOLD_SECONDS = "stale_threshold_seconds"
    private const val KEY_BATTERY_DIALOG_DISMISSED_AT = "battery_dialog_dismissed_at"
    private const val KEY_LAST_UPLOAD_TIME = "last_upload_time"
    private const val BATTERY_DIALOG_SNOOZE_MS = 7 * 24 * 60 * 60 * 1000L  // 7 days

    private lateinit var prefs: SharedPreferences

    fun init(context: Context) {
        prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
        // Ensure UUID exists
        if (!prefs.contains(KEY_DEVICE_UUID)) {
            val newUuid = UUID.randomUUID().toString()
            prefs.edit().putString(KEY_DEVICE_UUID, newUuid).apply()
        }
    }

    var sessionToken: String?
        get() = prefs.getString(KEY_SESSION_TOKEN, null)
        set(value) = prefs.edit { putString(KEY_SESSION_TOKEN, value) }

    var idleHeartbeatSeconds: Int
        get() = prefs.getInt(KEY_IDLE_HEARTBEAT_SECONDS, 300)  // Default 5 min
        set(value) = prefs.edit { putInt(KEY_IDLE_HEARTBEAT_SECONDS, value) }

    var offlineThresholdSeconds: Int
        get() = prefs.getInt(KEY_OFFLINE_THRESHOLD_SECONDS, 660)  // Default 11 min (2x heartbeat + buffer)
        set(value) = prefs.edit { putInt(KEY_OFFLINE_THRESHOLD_SECONDS, value) }

    var staleThresholdSeconds: Int
        get() = prefs.getInt(KEY_STALE_THRESHOLD_SECONDS, 3600)  // Default 60 min
        set(value) = prefs.edit { putInt(KEY_STALE_THRESHOLD_SECONDS, value) }


    fun getDeviceUuid(): String {
        return prefs.getString(KEY_DEVICE_UUID, "") ?: ""
    }

    fun setTrackingEnabled(enabled: Boolean) {
        prefs.edit().putBoolean(KEY_TRACKING_ENABLED, enabled).apply()
    }

    fun isTrackingEnabled(): Boolean {
        // Default to TRUE so tracking works out of the box once permissions are granted
        return prefs.getBoolean(KEY_TRACKING_ENABLED, true)
    }

    fun setUpdateInterval(seconds: Int) {
        prefs.edit().putInt(KEY_UPDATE_INTERVAL, seconds).apply()
    }

    fun getUpdateInterval(): Int {
        // Default to 30 seconds
        return prefs.getInt(KEY_UPDATE_INTERVAL, 30)
    }

    fun setLastNotificationCount(count: Int) {
        prefs.edit().putInt(KEY_LAST_NOTIF_COUNT, count).apply()
    }

    fun getLastNotificationCount(): Int {
        return prefs.getInt(KEY_LAST_NOTIF_COUNT, 0)
    }

    fun setBatteryDialogDismissed() {
        prefs.edit { putLong(KEY_BATTERY_DIALOG_DISMISSED_AT, System.currentTimeMillis()) }
    }

    fun shouldShowBatteryDialog(): Boolean {
        val dismissedAt = prefs.getLong(KEY_BATTERY_DIALOG_DISMISSED_AT, 0L)
        if (dismissedAt == 0L) return true  // Never dismissed
        val elapsed = System.currentTimeMillis() - dismissedAt
        return elapsed > BATTERY_DIALOG_SNOOZE_MS  // Show again after snooze period
    }

    var lastUploadTime: Long
        get() = prefs.getLong(KEY_LAST_UPLOAD_TIME, 0L)
        set(value) = prefs.edit { putLong(KEY_LAST_UPLOAD_TIME, value) }

    /**
     * Apply server settings from JSON response.
     * Returns true if update_interval_seconds changed (caller may need to react).
     */
    fun applyServerSettings(settings: JSONObject?): Boolean {
        settings ?: return false
        var intervalChanged = false
        settings.optInt("update_interval_seconds", 0).takeIf { it > 0 }?.let { newInterval ->
            if (newInterval != getUpdateInterval()) {
                intervalChanged = true
            }
            setUpdateInterval(newInterval)
        }
        settings.optInt("idle_heartbeat_seconds", 0).takeIf { it > 0 }?.let {
            idleHeartbeatSeconds = it
        }
        settings.optInt("offline_threshold_seconds", 0).takeIf { it > 0 }?.let {
            offlineThresholdSeconds = it
        }
        settings.optInt("stale_threshold_seconds", 0).takeIf { it > 0 }?.let {
            staleThresholdSeconds = it
        }
        return intervalChanged
    }
}
