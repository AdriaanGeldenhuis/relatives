package za.co.relatives.app.utils

import android.content.Context
import android.content.SharedPreferences
import java.util.UUID
import za.co.relatives.app.tracking.TrackingPolicy

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
        private const val KEY_SESSION_TOKEN = "session_token"
        private const val KEY_TRACKING_COOKIE_HASH = "tracking_cookie_hash"
        private const val KEY_TRACKING_SCOPE = "tracking_scope"
        private const val KEY_NOTIFICATION_COUNT = "notification_count"
        private const val KEY_LAST_UPLOAD_TIME = "last_upload_time"
        private const val KEY_FCM_TOKEN = "fcm_token"
        private const val KEY_PUSH_RECIPIENT = "push_recipient"
        private const val KEY_TRACKING_POLICY = "tracking_policy"
        private const val KEY_POLICY_COOKIE = "tracking_policy_cookie"
        private const val KEY_LAST_WAKE = "last_wake_request"
        private const val KEY_WAKE_NOTICE = "last_wake_notice"
        private const val KEY_FCM_REGISTRATION = "fcm_registration"
        private const val KEY_FCM_REGISTERED_AT = "fcm_registered_at"
        private val wakeLock = Any()
        private const val KEY_PUSH_COOKIE_HASH = "push_cookie_hash"

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

    internal class TrackingConsent(val scope: String, val sessionCookie: String)

    internal fun trackingConsent(): TrackingConsent? {
        // Take one snapshot so a cookie change cannot combine two sessions.
        val values = prefs.all
        if (values[KEY_TRACKING_ENABLED] != true) return null
        val cookie = values[KEY_SESSION_TOKEN] as? String ?: return null
        val scope = values[KEY_TRACKING_SCOPE] as? String ?: return null
        if (values[KEY_TRACKING_COOKIE_HASH] != fingerprint(cookie)) return null
        return TrackingConsent(scope, cookie)
    }

    internal fun enableTracking(): Boolean {
        val cookie = sessionToken?.takeIf(String::isNotBlank) ?: return false
        if (trackingConsent() == null) {
            prefs.edit()
                .putBoolean(KEY_TRACKING_ENABLED, true)
                .putString(KEY_TRACKING_COOKIE_HASH, fingerprint(cookie))
                .putString(KEY_TRACKING_SCOPE, UUID.randomUUID().toString())
                .apply()
        }
        return true
    }

    internal fun disableTracking() {
        prefs.edit().putBoolean(KEY_TRACKING_ENABLED, false)
            .remove(KEY_TRACKING_COOKIE_HASH).remove(KEY_TRACKING_SCOPE).apply()
    }

    internal fun saveTrackingPolicy(cookie: String, policyJson: com.google.gson.JsonObject) {
        if (sessionToken != cookie) return
        prefs.edit().putString(KEY_TRACKING_POLICY, policyJson.toString())
            .putString(KEY_POLICY_COOKIE, fingerprint(cookie)).apply()
    }

    internal fun trackingPolicy(): TrackingPolicy {
        val values = prefs.all
        val cookie = values[KEY_SESSION_TOKEN] as? String ?: return TrackingPolicy()
        if (values[KEY_POLICY_COOKIE] != fingerprint(cookie)) return TrackingPolicy()
        val json = values[KEY_TRACKING_POLICY] as? String ?: return TrackingPolicy()
        return runCatching { TrackingPolicy.from(com.google.gson.JsonParser.parseString(json).asJsonObject) }
            .getOrDefault(TrackingPolicy())
    }

    internal fun claimWake(scope: String, id: String): Boolean = synchronized(wakeLock) {
        if (trackingConsent()?.scope != scope) return@synchronized false
        val key = "$scope:$id"
        if (prefs.getString(KEY_LAST_WAKE, null) == key) return@synchronized false
        prefs.edit().putString(KEY_LAST_WAKE, key).commit()
    }

    internal fun claimWakeNotice(id: String): Boolean = synchronized(wakeLock) {
        if (prefs.getString(KEY_WAKE_NOTICE, null) == id) return@synchronized false
        prefs.edit().putString(KEY_WAKE_NOTICE, id).apply()
        true
    }

    internal fun sessionBinding(): String? = sessionToken?.let(::fingerprint)

    internal fun registrationMatches(cookie: String, token: String, capability: String): Boolean =
        prefs.getString(KEY_FCM_REGISTRATION, null) == fingerprint("$cookie:$token:$capability") &&
            System.currentTimeMillis() - prefs.getLong(KEY_FCM_REGISTERED_AT, 0) in 0L..86_400_000L

    internal fun recordRegistration(cookie: String, token: String, capability: String) {
        if (sessionToken == cookie && fcmToken == token) {
            prefs.edit().putString(KEY_FCM_REGISTRATION, fingerprint("$cookie:$token:$capability"))
                .putLong(KEY_FCM_REGISTERED_AT, System.currentTimeMillis()).apply()
        }
    }

    private fun fingerprint(cookie: String): String = java.security.MessageDigest.getInstance("SHA-256")
        .digest(cookie.toByteArray(Charsets.UTF_8))
        .let { java.util.Base64.getEncoder().encodeToString(it) }

    // ── Session / auth ─────────────────────────────────────────────────────

    /** Session token extracted from WebView cookies, used for native API calls. */
    var sessionToken: String?
        get() = prefs.getString(KEY_SESSION_TOKEN, null)
        set(value) = prefs.edit().putString(KEY_SESSION_TOKEN, value).apply()

    internal fun bindPushRecipient(cookie: String, recipient: String) {
        if (sessionToken != cookie || !recipient.all(Char::isDigit) || recipient.isEmpty()) return
        prefs.edit()
            .putString(KEY_PUSH_COOKIE_HASH, fingerprint(cookie))
            .putString(KEY_PUSH_RECIPIENT, recipient)
            .apply()
    }

    internal fun isPushRecipient(recipient: String?): Boolean {
        val values = prefs.all
        val cookie = values[KEY_SESSION_TOKEN] as? String ?: return false
        return recipient != null && values[KEY_PUSH_RECIPIENT] == recipient &&
            values[KEY_PUSH_COOKIE_HASH] == fingerprint(cookie)
    }

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

    // ── Cleanup ────────────────────────────────────────────────────────────

    fun clearSession() {
        prefs.edit()
            .remove(KEY_SESSION_TOKEN)
            .remove(KEY_PUSH_RECIPIENT)
            .remove(KEY_PUSH_COOKIE_HASH)
            .remove(KEY_TRACKING_POLICY)
            .remove(KEY_POLICY_COOKIE)
            .remove(KEY_FCM_REGISTRATION)
            .remove(KEY_FCM_REGISTERED_AT)
            .remove(KEY_LAST_WAKE)
            .remove(KEY_WAKE_NOTICE)
            .apply()
    }
}
