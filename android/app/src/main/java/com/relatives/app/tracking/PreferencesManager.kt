package com.relatives.app.tracking

import android.content.Context
import android.content.SharedPreferences
import androidx.core.content.edit

/**
 * Manages persisted tracking state via SharedPreferences.
 *
 * Stores:
 * - Auth credentials (userId, sessionToken)
 * - Tracking enabled/disabled state
 * - User requested stop flag (prevents zombie restarts)
 * - Tracking interval settings
 * - Network failure backoff state
 */
class PreferencesManager(context: Context) {

    companion object {
        private const val PREFS_NAME = "relatives_tracking"

        // Auth keys
        private const val KEY_USER_ID = "user_id"
        private const val KEY_SESSION_TOKEN = "session_token"

        // Tracking state keys
        private const val KEY_TRACKING_ENABLED = "tracking_enabled"
        private const val KEY_USER_REQUESTED_STOP = "user_requested_stop"

        // Settings keys
        private const val KEY_MOVING_INTERVAL_SECONDS = "moving_interval_seconds"
        private const val KEY_IDLE_INTERVAL_SECONDS = "idle_interval_seconds"
        private const val KEY_HIGH_ACCURACY_MODE = "high_accuracy_mode"
        private const val KEY_SPEED_THRESHOLD_MPS = "speed_threshold_mps"

        // Network failure keys
        private const val KEY_AUTH_FAILURE_UNTIL = "auth_failure_until"
        private const val KEY_CONSECUTIVE_FAILURES = "consecutive_failures"
        private const val KEY_LAST_FAILURE_TIME = "last_failure_time"

        // Device keys
        private const val KEY_DEVICE_ID = "device_id"
        private const val KEY_BASE_URL = "base_url"

        // Defaults
        const val DEFAULT_MOVING_INTERVAL = 60 // seconds
        const val DEFAULT_IDLE_INTERVAL = 600 // 10 minutes
        const val DEFAULT_SPEED_THRESHOLD = 1.0f // m/s
        const val DEFAULT_BASE_URL = "https://relatives.app"
        const val AUTH_BLOCK_DURATION_MS = 30L * 60 * 1000 // 30 minutes
    }

    private val prefs: SharedPreferences =
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    // ========== AUTH ==========

    var userId: String?
        get() = prefs.getString(KEY_USER_ID, null)
        set(value) = prefs.edit { putString(KEY_USER_ID, value) }

    var sessionToken: String?
        get() = prefs.getString(KEY_SESSION_TOKEN, null)
        set(value) = prefs.edit { putString(KEY_SESSION_TOKEN, value) }

    fun hasValidAuth(): Boolean {
        return !userId.isNullOrBlank() && !sessionToken.isNullOrBlank()
    }

    fun clearAuthBlock() {
        prefs.edit {
            putLong(KEY_AUTH_FAILURE_UNTIL, 0)
            putInt(KEY_CONSECUTIVE_FAILURES, 0)
            putLong(KEY_LAST_FAILURE_TIME, 0)
        }
    }

    // ========== TRACKING STATE ==========

    var isTrackingEnabled: Boolean
        get() = prefs.getBoolean(KEY_TRACKING_ENABLED, false)
        set(value) = prefs.edit { putBoolean(KEY_TRACKING_ENABLED, value) }

    var userRequestedStop: Boolean
        get() = prefs.getBoolean(KEY_USER_REQUESTED_STOP, false)
        set(value) = prefs.edit { putBoolean(KEY_USER_REQUESTED_STOP, value) }

    /**
     * Enable tracking - sets tracking_enabled=true and clears user stop flag.
     */
    fun enableTracking() {
        prefs.edit {
            putBoolean(KEY_TRACKING_ENABLED, true)
            putBoolean(KEY_USER_REQUESTED_STOP, false)
        }
    }

    /**
     * Disable tracking - sets tracking_enabled=false and marks user requested stop.
     */
    fun disableTracking() {
        prefs.edit {
            putBoolean(KEY_TRACKING_ENABLED, false)
            putBoolean(KEY_USER_REQUESTED_STOP, true)
        }
    }

    // ========== SETTINGS ==========

    var movingIntervalSeconds: Int
        get() = prefs.getInt(KEY_MOVING_INTERVAL_SECONDS, DEFAULT_MOVING_INTERVAL)
        set(value) = prefs.edit { putInt(KEY_MOVING_INTERVAL_SECONDS, value.coerceIn(10, 300)) }

    var idleIntervalSeconds: Int
        get() = prefs.getInt(KEY_IDLE_INTERVAL_SECONDS, DEFAULT_IDLE_INTERVAL)
        set(value) = prefs.edit { putInt(KEY_IDLE_INTERVAL_SECONDS, value.coerceIn(60, 1800)) }

    var highAccuracyMode: Boolean
        get() = prefs.getBoolean(KEY_HIGH_ACCURACY_MODE, true)
        set(value) = prefs.edit { putBoolean(KEY_HIGH_ACCURACY_MODE, value) }

    var speedThresholdMps: Float
        get() = prefs.getFloat(KEY_SPEED_THRESHOLD_MPS, DEFAULT_SPEED_THRESHOLD)
        set(value) = prefs.edit { putFloat(KEY_SPEED_THRESHOLD_MPS, value) }

    // ========== NETWORK FAILURE STATE ==========

    var authFailureUntil: Long
        get() = prefs.getLong(KEY_AUTH_FAILURE_UNTIL, 0)
        set(value) = prefs.edit { putLong(KEY_AUTH_FAILURE_UNTIL, value) }

    var consecutiveFailures: Int
        get() = prefs.getInt(KEY_CONSECUTIVE_FAILURES, 0)
        set(value) = prefs.edit { putInt(KEY_CONSECUTIVE_FAILURES, value) }

    var lastFailureTime: Long
        get() = prefs.getLong(KEY_LAST_FAILURE_TIME, 0)
        set(value) = prefs.edit { putLong(KEY_LAST_FAILURE_TIME, value) }

    /**
     * Check if uploads are blocked due to auth failure.
     */
    fun isAuthBlocked(): Boolean {
        val until = authFailureUntil
        if (until == 0L) return false
        if (System.currentTimeMillis() >= until) {
            // Block expired, clear it
            clearAuthBlock()
            return false
        }
        return true
    }

    /**
     * Mark an auth failure - blocks uploads for 30 minutes.
     */
    fun markAuthFailure() {
        prefs.edit {
            putLong(KEY_AUTH_FAILURE_UNTIL, System.currentTimeMillis() + AUTH_BLOCK_DURATION_MS)
        }
    }

    /**
     * Record a transient upload failure and return the backoff delay in ms.
     */
    fun recordFailure(): Long {
        val failures = consecutiveFailures + 1
        consecutiveFailures = failures
        lastFailureTime = System.currentTimeMillis()

        return getBackoffDelay(failures)
    }

    /**
     * Calculate exponential backoff delay.
     */
    fun getBackoffDelay(failures: Int = consecutiveFailures): Long {
        return when {
            failures <= 1 -> 10_000L       // 10s
            failures == 2 -> 30_000L       // 30s
            failures == 3 -> 60_000L       // 1 min
            failures == 4 -> 120_000L      // 2 min
            else -> 300_000L               // 5 min cap
        }
    }

    /**
     * Check if within backoff window.
     */
    fun isInBackoff(): Boolean {
        val failures = consecutiveFailures
        if (failures == 0) return false

        val lastFailure = lastFailureTime
        if (lastFailure == 0L) return false

        val backoff = getBackoffDelay(failures)
        return System.currentTimeMillis() < lastFailure + backoff
    }

    /**
     * Reset all failure state (call on successful upload).
     */
    fun resetFailureState() {
        prefs.edit {
            putInt(KEY_CONSECUTIVE_FAILURES, 0)
            putLong(KEY_LAST_FAILURE_TIME, 0)
        }
    }

    // ========== DEVICE ==========

    var deviceId: String
        get() {
            var id = prefs.getString(KEY_DEVICE_ID, null)
            if (id == null) {
                id = java.util.UUID.randomUUID().toString()
                prefs.edit { putString(KEY_DEVICE_ID, id) }
            }
            return id
        }
        set(value) = prefs.edit { putString(KEY_DEVICE_ID, value) }

    var baseUrl: String
        get() = prefs.getString(KEY_BASE_URL, DEFAULT_BASE_URL) ?: DEFAULT_BASE_URL
        set(value) = prefs.edit { putString(KEY_BASE_URL, value) }
}
