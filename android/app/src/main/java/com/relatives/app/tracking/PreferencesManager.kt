package com.relatives.app.tracking

import android.content.Context
import android.content.SharedPreferences
import androidx.core.content.edit

/**
 * Manages persisted preferences for tracking service.
 * Critical: tracking_enabled controls whether service should run/restart.
 */
class PreferencesManager(context: Context) {

    private val prefs: SharedPreferences = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    companion object {
        private const val PREFS_NAME = "relatives_tracking_prefs"

        // Core tracking state
        const val KEY_TRACKING_ENABLED = "tracking_enabled"
        const val KEY_USER_REQUESTED_STOP = "user_requested_stop"

        // Settings from web
        const val KEY_UPDATE_INTERVAL_SECONDS = "update_interval_seconds"
        const val KEY_HIGH_ACCURACY_MODE = "high_accuracy_mode"

        // Auth data
        const val KEY_USER_ID = "user_id"
        const val KEY_SESSION_TOKEN = "session_token"
        const val KEY_DEVICE_UUID = "device_uuid"

        // Backoff state
        const val KEY_AUTH_FAILURE_UNTIL = "auth_failure_until"
        const val KEY_CONSECUTIVE_FAILURES = "consecutive_failures"
        const val KEY_LAST_FAILURE_TIME = "last_failure_time"

        // Defaults
        const val DEFAULT_UPDATE_INTERVAL = 60 // seconds
        const val DEFAULT_HIGH_ACCURACY = false
    }

    // ============ TRACKING STATE ============

    /**
     * Whether tracking is enabled. This is the master switch.
     * - Set to true when user starts tracking
     * - Set to false when user explicitly stops tracking
     * - BootReceiver and restart logic MUST check this before starting service
     */
    var isTrackingEnabled: Boolean
        get() = prefs.getBoolean(KEY_TRACKING_ENABLED, false)
        set(value) = prefs.edit { putBoolean(KEY_TRACKING_ENABLED, value) }

    /**
     * Whether the last stop was user-requested (vs crash/system kill).
     * If true, do NOT auto-restart.
     */
    var userRequestedStop: Boolean
        get() = prefs.getBoolean(KEY_USER_REQUESTED_STOP, false)
        set(value) = prefs.edit { putBoolean(KEY_USER_REQUESTED_STOP, value) }

    // ============ SETTINGS ============

    /**
     * Update interval in seconds (from web settings).
     * Used for MOVING mode. Range: 10-300, default 60.
     */
    var updateIntervalSeconds: Int
        get() = prefs.getInt(KEY_UPDATE_INTERVAL_SECONDS, DEFAULT_UPDATE_INTERVAL)
        set(value) = prefs.edit { putInt(KEY_UPDATE_INTERVAL_SECONDS, value.coerceIn(10, 300)) }

    /**
     * Whether to use high accuracy (GPS) even in MOVING mode.
     */
    var highAccuracyMode: Boolean
        get() = prefs.getBoolean(KEY_HIGH_ACCURACY_MODE, DEFAULT_HIGH_ACCURACY)
        set(value) = prefs.edit { putBoolean(KEY_HIGH_ACCURACY_MODE, value) }

    // ============ AUTH DATA ============

    var userId: String?
        get() = prefs.getString(KEY_USER_ID, null)
        set(value) = prefs.edit { putString(KEY_USER_ID, value) }

    var sessionToken: String?
        get() = prefs.getString(KEY_SESSION_TOKEN, null)
        set(value) = prefs.edit { putString(KEY_SESSION_TOKEN, value) }

    var deviceUuid: String?
        get() = prefs.getString(KEY_DEVICE_UUID, null)
        set(value) = prefs.edit { putString(KEY_DEVICE_UUID, value) }

    fun hasValidAuth(): Boolean {
        return !userId.isNullOrBlank() && !sessionToken.isNullOrBlank()
    }

    // ============ BACKOFF STATE ============

    /**
     * Timestamp until which we should not attempt uploads due to auth failure (401/403).
     * Set to System.currentTimeMillis() + 30 minutes on auth failure.
     */
    var authFailureUntil: Long
        get() = prefs.getLong(KEY_AUTH_FAILURE_UNTIL, 0L)
        set(value) = prefs.edit { putLong(KEY_AUTH_FAILURE_UNTIL, value) }

    /**
     * Count of consecutive transient failures for exponential backoff.
     */
    var consecutiveFailures: Int
        get() = prefs.getInt(KEY_CONSECUTIVE_FAILURES, 0)
        set(value) = prefs.edit { putInt(KEY_CONSECUTIVE_FAILURES, value) }

    var lastFailureTime: Long
        get() = prefs.getLong(KEY_LAST_FAILURE_TIME, 0L)
        set(value) = prefs.edit { putLong(KEY_LAST_FAILURE_TIME, value) }

    fun isAuthBlocked(): Boolean {
        return authFailureUntil > System.currentTimeMillis()
    }

    fun clearAuthBlock() {
        authFailureUntil = 0L
    }

    fun resetFailureState() {
        consecutiveFailures = 0
        lastFailureTime = 0L
    }

    /**
     * Calculate backoff delay based on consecutive failures.
     * Sequence: 10s, 30s, 60s, 2min, 5min (cap)
     */
    fun getBackoffDelayMs(): Long {
        return when (consecutiveFailures) {
            0 -> 0L
            1 -> 10_000L
            2 -> 30_000L
            3 -> 60_000L
            4 -> 120_000L
            else -> 300_000L // 5 min cap
        }
    }

    // ============ UTILITY ============

    fun clearAll() {
        prefs.edit { clear() }
    }

    /**
     * Enable tracking - call when user starts tracking.
     */
    fun enableTracking() {
        isTrackingEnabled = true
        userRequestedStop = false
        resetFailureState()
    }

    /**
     * Disable tracking - call when user explicitly stops.
     */
    fun disableTracking() {
        isTrackingEnabled = false
        userRequestedStop = true
    }
}
