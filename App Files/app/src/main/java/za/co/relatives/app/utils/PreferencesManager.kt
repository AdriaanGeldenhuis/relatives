package za.co.relatives.app.utils

import android.content.Context
import android.content.SharedPreferences
import java.util.UUID

object PreferencesManager {
    private const val PREF_NAME = "relatives_prefs"
    private const val KEY_DEVICE_UUID = "device_uuid"
    private const val KEY_TRACKING_ENABLED = "tracking_enabled"
    private const val KEY_UPDATE_INTERVAL = "update_interval"
    private const val KEY_LAST_NOTIF_COUNT = "last_notif_count"

    private lateinit var prefs: SharedPreferences

    fun init(context: Context) {
        prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
        // Ensure UUID exists
        if (!prefs.contains(KEY_DEVICE_UUID)) {
            val newUuid = UUID.randomUUID().toString()
            prefs.edit().putString(KEY_DEVICE_UUID, newUuid).apply()
        }
    }

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