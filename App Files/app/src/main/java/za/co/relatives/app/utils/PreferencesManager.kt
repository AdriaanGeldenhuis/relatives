package za.co.relatives.app.utils

import android.content.Context
import android.content.SharedPreferences
import org.json.JSONObject
import java.util.UUID

/**
 * Centralised SharedPreferences wrapper for all persistent app settings.
 *
 * Thread-safety note: every write uses [SharedPreferences.Editor.apply] which is
 * asynchronous but safe to call from any thread.
 */
class PreferencesManager(context: Context) {

    private val prefs: SharedPreferences =
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    // ── Companion constants ────────────────────────────────────────────────

    companion object {
        private const val PREFS_NAME = "relatives_prefs"

        // Keys
        private const val KEY_DEVICE_UUID = "device_uuid"
        private const val KEY_TRACKING_ENABLED = "tracking_enabled"
        private const val KEY_UPDATE_INTERVAL = "update_interval"
        private const val KEY_IDLE_HEARTBEAT_SECONDS = "idle_heartbeat_seconds"
        private const val KEY_BURST_INTERVAL = "burst_interval"
        private const val KEY_SESSION_TOKEN = "session_token"
        private const val KEY_NOTIFICATION_COUNT = "notification_count"
        private const val KEY_LAST_UPLOAD_TIME = "last_upload_time"
        private const val KEY_FCM_TOKEN = "fcm_token"

        // Defaults
        private const val DEFAULT_UPDATE_INTERVAL = 30        // seconds (MOVING mode)
        private const val DEFAULT_IDLE_HEARTBEAT = 300         // seconds (IDLE mode)
        private const val DEFAULT_BURST_INTERVAL = 5           // seconds (BURST mode)
    }

    // ── Device identity ────────────────────────────────────────────────────

    /** Stable UUID generated once on first access and persisted forever. */
    val deviceUuid: String
        get() {
            val existing = prefs.getString(KEY_DEVICE_UUID, null)
            if (existing != null) return existing
            val generated = UUID.randomUUID().toString()
            prefs.edit().putString(KEY_DEVICE_UUID, generated).apply()
            return generated
        }

    // ── Tracking configuration ─────────────────────────────────────────────

    var trackingEnabled: Boolean
        get() = prefs.getBoolean(KEY_TRACKING_ENABLED, true)
        set(value) = prefs.edit().putBoolean(KEY_TRACKING_ENABLED, value).apply()

    /** Interval (in seconds) for the MOVING tracking mode. */
    var updateInterval: Int
        get() = prefs.getInt(KEY_UPDATE_INTERVAL, DEFAULT_UPDATE_INTERVAL)
        set(value) = prefs.edit().putInt(KEY_UPDATE_INTERVAL, value).apply()

    /** Heartbeat interval (in seconds) for the IDLE tracking mode. */
    var idleHeartbeatSeconds: Int
        get() = prefs.getInt(KEY_IDLE_HEARTBEAT_SECONDS, DEFAULT_IDLE_HEARTBEAT)
        set(value) = prefs.edit().putInt(KEY_IDLE_HEARTBEAT_SECONDS, value).apply()

    /** Interval (in seconds) for the BURST tracking mode. */
    var burstInterval: Int
        get() = prefs.getInt(KEY_BURST_INTERVAL, DEFAULT_BURST_INTERVAL)
        set(value) = prefs.edit().putInt(KEY_BURST_INTERVAL, value).apply()

    // ── Session / auth ─────────────────────────────────────────────────────

    /** Session token extracted from WebView cookies, used for native API calls. */
    var sessionToken: String?
        get() = prefs.getString(KEY_SESSION_TOKEN, null)
        set(value) = prefs.edit().putString(KEY_SESSION_TOKEN, value).apply()

    // ── Notification bookkeeping ───────────────────────────────────────────

    var notificationCount: Int
        get() = prefs.getInt(KEY_NOTIFICATION_COUNT, 0)
        set(value) = prefs.edit().putInt(KEY_NOTIFICATION_COUNT, value).apply()

    fun incrementNotificationCount() {
        notificationCount += 1
    }

    // ── Upload bookkeeping ─────────────────────────────────────────────────

    /** Epoch millis of the last successful location batch upload. */
    var lastUploadTime: Long
        get() = prefs.getLong(KEY_LAST_UPLOAD_TIME, 0L)
        set(value) = prefs.edit().putLong(KEY_LAST_UPLOAD_TIME, value).apply()

    // ── Firebase Cloud Messaging ───────────────────────────────────────────

    var fcmToken: String?
        get() = prefs.getString(KEY_FCM_TOKEN, null)
        set(value) = prefs.edit().putString(KEY_FCM_TOKEN, value).apply()

    // ── Server-driven configuration ────────────────────────────────────────

    /**
     * Apply interval overrides received from the server.
     *
     * Expected JSON keys (all optional):
     * - `update_interval`        -> MOVING interval in seconds
     * - `idle_heartbeat_seconds` -> IDLE heartbeat in seconds
     * - `burst_interval`         -> BURST interval in seconds
     */
    fun applyServerSettings(json: JSONObject) {
        val editor = prefs.edit()
        if (json.has("update_interval")) {
            editor.putInt(KEY_UPDATE_INTERVAL, json.getInt("update_interval"))
        }
        if (json.has("idle_heartbeat_seconds")) {
            editor.putInt(KEY_IDLE_HEARTBEAT_SECONDS, json.getInt("idle_heartbeat_seconds"))
        }
        if (json.has("burst_interval")) {
            editor.putInt(KEY_BURST_INTERVAL, json.getInt("burst_interval"))
        }
        editor.apply()
    }

    // ── Cleanup ────────────────────────────────────────────────────────────

    fun clearSession() {
        prefs.edit().remove(KEY_SESSION_TOKEN).apply()
    }
}
