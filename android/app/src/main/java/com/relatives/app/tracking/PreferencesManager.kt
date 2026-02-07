package com.relatives.app.tracking

import android.content.Context
import android.content.SharedPreferences
import android.util.Log

/**
 * Persisted state management for tracking service.
 *
 * Stores:
 * - tracking_enabled: Master switch for tracking
 * - user_requested_stop: Prevents restart after explicit stop
 * - auth credentials: userId + sessionToken
 * - auth_failure_until: Blocks uploads after 401/403
 * - consecutive_failures: Tracks failures for exponential backoff
 */
class PreferencesManager(context: Context) {

    companion object {
        private const val TAG = "PreferencesManager"
        private const val PREFS_NAME = "relatives_tracking"

        private const val KEY_TRACKING_ENABLED = "tracking_enabled"
        private const val KEY_USER_REQUESTED_STOP = "user_requested_stop"
        private const val KEY_USER_ID = "user_id"
        private const val KEY_SESSION_TOKEN = "session_token"
        private const val KEY_AUTH_FAILURE_UNTIL = "auth_failure_until"
        private const val KEY_CONSECUTIVE_FAILURES = "consecutive_failures"
        private const val KEY_LAST_FAILURE_TIME = "last_failure_time"
        private const val KEY_MOVING_INTERVAL_SECONDS = "moving_interval_seconds"
        private const val KEY_HIGH_ACCURACY_MODE = "high_accuracy_mode"

        private const val AUTH_BLOCK_DURATION_MS = 30 * 60 * 1000L // 30 minutes
    }

    private val prefs: SharedPreferences =
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    // ============ TRACKING STATE ============

    var isTrackingEnabled: Boolean
        get() = prefs.getBoolean(KEY_TRACKING_ENABLED, false)
        set(value) = prefs.edit().putBoolean(KEY_TRACKING_ENABLED, value).apply()

    var userRequestedStop: Boolean
        get() = prefs.getBoolean(KEY_USER_REQUESTED_STOP, false)
        set(value) = prefs.edit().putBoolean(KEY_USER_REQUESTED_STOP, value).apply()

    fun enableTracking() {
        Log.d(TAG, "enableTracking")
        prefs.edit()
            .putBoolean(KEY_TRACKING_ENABLED, true)
            .putBoolean(KEY_USER_REQUESTED_STOP, false)
            .apply()
    }

    fun disableTracking() {
        Log.d(TAG, "disableTracking")
        prefs.edit()
            .putBoolean(KEY_TRACKING_ENABLED, false)
            .putBoolean(KEY_USER_REQUESTED_STOP, true)
            .apply()
    }

    // ============ AUTH ============

    var userId: String?
        get() = prefs.getString(KEY_USER_ID, null)
        set(value) = prefs.edit().putString(KEY_USER_ID, value).apply()

    var sessionToken: String?
        get() = prefs.getString(KEY_SESSION_TOKEN, null)
        set(value) = prefs.edit().putString(KEY_SESSION_TOKEN, value).apply()

    fun hasValidAuth(): Boolean {
        return !userId.isNullOrEmpty() && !sessionToken.isNullOrEmpty()
    }

    // ============ AUTH FAILURE HANDLING ============

    var authFailureUntil: Long
        get() = prefs.getLong(KEY_AUTH_FAILURE_UNTIL, 0)
        set(value) = prefs.edit().putLong(KEY_AUTH_FAILURE_UNTIL, value).apply()

    fun isAuthBlocked(): Boolean {
        return System.currentTimeMillis() < authFailureUntil
    }

    fun blockAuth() {
        authFailureUntil = System.currentTimeMillis() + AUTH_BLOCK_DURATION_MS
        Log.d(TAG, "Auth blocked for 30 minutes")
    }

    fun clearAuthBlock() {
        authFailureUntil = 0
        Log.d(TAG, "Auth block cleared")
    }

    // ============ NETWORK BACKOFF ============

    var consecutiveFailures: Int
        get() = prefs.getInt(KEY_CONSECUTIVE_FAILURES, 0)
        set(value) = prefs.edit().putInt(KEY_CONSECUTIVE_FAILURES, value).apply()

    var lastFailureTime: Long
        get() = prefs.getLong(KEY_LAST_FAILURE_TIME, 0)
        set(value) = prefs.edit().putLong(KEY_LAST_FAILURE_TIME, value).apply()

    /**
     * Get backoff delay in milliseconds based on consecutive failures.
     * 10s -> 30s -> 60s -> 2min -> 5min cap
     */
    fun getBackoffDelayMs(): Long {
        return when (consecutiveFailures) {
            0 -> 0L
            1 -> 10_000L
            2 -> 30_000L
            3 -> 60_000L
            4 -> 120_000L
            else -> 300_000L // 5 minute cap
        }
    }

    fun isInBackoff(): Boolean {
        val delay = getBackoffDelayMs()
        if (delay == 0L) return false
        return System.currentTimeMillis() - lastFailureTime < delay
    }

    fun recordFailure() {
        consecutiveFailures++
        lastFailureTime = System.currentTimeMillis()
        Log.d(TAG, "Failure recorded: count=$consecutiveFailures, backoff=${getBackoffDelayMs()}ms")
    }

    fun resetFailureState() {
        prefs.edit()
            .putInt(KEY_CONSECUTIVE_FAILURES, 0)
            .putLong(KEY_LAST_FAILURE_TIME, 0)
            .apply()
    }

    // ============ TRACKING SETTINGS ============

    var movingIntervalSeconds: Int
        get() = prefs.getInt(KEY_MOVING_INTERVAL_SECONDS, 60)
        set(value) = prefs.edit().putInt(KEY_MOVING_INTERVAL_SECONDS, value).apply()

    var highAccuracyMode: Boolean
        get() = prefs.getBoolean(KEY_HIGH_ACCURACY_MODE, true)
        set(value) = prefs.edit().putBoolean(KEY_HIGH_ACCURACY_MODE, value).apply()
}
