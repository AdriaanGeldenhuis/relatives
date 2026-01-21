package za.co.relatives.app.utils

import android.content.Context
import android.content.SharedPreferences
import androidx.core.content.edit
import java.util.UUID

object PreferencesManager {
    private const val PREF_NAME = "relatives_prefs"
    private const val KEY_DEVICE_UUID = "device_uuid"
    private const val KEY_TRACKING_ENABLED = "tracking_enabled"
    private const val KEY_UPDATE_INTERVAL = "update_interval"
    private const val KEY_LAST_NOTIF_COUNT = "last_notif_count"
    private const val KEY_SESSION_TOKEN = "session_token"
    private const val KEY_IDLE_HEARTBEAT_SECONDS = "idle_heartbeat_seconds"
    private const val KEY_OFFLINE_THRESHOLD_SECONDS = "offline_threshold_seconds"
    private const val KEY_STALE_THRESHOLD_SECONDS = "stale_threshold_seconds"
    private const val KEY_LAST_UPLOAD_TIME = "last_upload_time"

    private lateinit var prefs: SharedPreferences

    fun init(context: Context) {
        prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
        // Ensure UUID exists
        if (!prefs.contains(KEY_DEVICE_UUID)) {
            val newUuid = UUID.randomUUID().toString()
            prefs.edit().putString(KEY_DEVICE_UUID, newUuid).apply()
        }
    }

    // Session token for Bearer auth (more reliable than cookies)
    var sessionToken: String?
        get() = prefs.getString(KEY_SESSION_TOKEN, null)
        set(value) = prefs.edit { putString(KEY_SESSION_TOKEN, value) }

    // Server-configurable thresholds
    var idleHeartbeatSeconds: Int
        get() = prefs.getInt(KEY_IDLE_HEARTBEAT_SECONDS, 600)  // Default 10 min
        set(value) = prefs.edit { putInt(KEY_IDLE_HEARTBEAT_SECONDS, value) }

    var offlineThresholdSeconds: Int
        get() = prefs.getInt(KEY_OFFLINE_THRESHOLD_SECONDS, 720)  // Default 12 min
        set(value) = prefs.edit { putInt(KEY_OFFLINE_THRESHOLD_SECONDS, value) }

    var staleThresholdSeconds: Int
        get() = prefs.getInt(KEY_STALE_THRESHOLD_SECONDS, 3600)  // Default 60 min
        set(value) = prefs.edit { putInt(KEY_STALE_THRESHOLD_SECONDS, value) }

    // Last successful upload time (for WorkManager service checker)
    var lastUploadTime: Long
        get() = prefs.getLong(KEY_LAST_UPLOAD_TIME, 0L)
        set(value) = prefs.edit { putLong(KEY_LAST_UPLOAD_TIME, value) }

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
}
