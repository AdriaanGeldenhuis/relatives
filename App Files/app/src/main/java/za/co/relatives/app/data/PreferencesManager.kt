package za.co.relatives.app.data

import android.content.Context
import android.content.SharedPreferences

class PreferencesManager(context: Context) {

    private val prefs: SharedPreferences =
        context.getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)

    companion object {
        private const val KEY_FCM_TOKEN = "fcm_token"
        private const val KEY_TRACKING_STATE = "tracking_state"
        private const val KEY_UPDATE_INTERVAL = "update_interval"
        private const val KEY_SESSION_COOKIE = "session_cookie"
    }

    fun getFCMToken(): String? = prefs.getString(KEY_FCM_TOKEN, null)
    fun setFCMToken(token: String) = prefs.edit().putString(KEY_FCM_TOKEN, token).apply()

    fun getTrackingState(): String = prefs.getString(KEY_TRACKING_STATE, "IDLE") ?: "IDLE"
    fun setTrackingState(state: String) = prefs.edit().putString(KEY_TRACKING_STATE, state).apply()

    fun getUpdateInterval(): Int = prefs.getInt(KEY_UPDATE_INTERVAL, 30)
    fun setUpdateInterval(seconds: Int) = prefs.edit().putInt(KEY_UPDATE_INTERVAL, seconds).apply()

    fun getSessionCookie(): String? = prefs.getString(KEY_SESSION_COOKIE, null)
    fun setSessionCookie(cookie: String) = prefs.edit().putString(KEY_SESSION_COOKIE, cookie).apply()
}
