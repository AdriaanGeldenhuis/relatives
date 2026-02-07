package za.co.relatives.app.network

import android.content.Context
import okhttp3.Cookie
import okhttp3.CookieJar
import okhttp3.HttpUrl
import okhttp3.OkHttpClient
import java.util.concurrent.TimeUnit

/**
 * Process-wide OkHttp singleton with cookie persistence.
 *
 * Session cookies (PHPSESSID etc.) are kept in memory for the lifetime of the
 * process and replayed automatically on every request to the Relatives backend.
 * A [PreferencesManager]-backed cookie can optionally be injected at init time
 * so that the session survives process restarts.
 */
object NetworkClient {

    private const val TIMEOUT_SECONDS = 30L

    @Volatile
    private var client: OkHttpClient? = null

    /** Simple in-memory cookie store keyed by host. */
    private val cookieStore = mutableMapOf<String, MutableList<Cookie>>()

    /**
     * Return (or lazily create) the shared [OkHttpClient].
     *
     * @param context Only used on first call to seed the cookie jar from
     *   persisted session data. Safe to pass any context -- only
     *   applicationContext is retained.
     */
    fun getInstance(context: Context? = null): OkHttpClient {
        return client ?: synchronized(this) {
            client ?: buildClient(context).also { client = it }
        }
    }

    /**
     * Inject a session cookie so that background workers (which may start in a
     * fresh process) can authenticate with the backend.
     */
    fun setSessionCookie(domain: String, name: String, value: String) {
        val cookie = Cookie.Builder()
            .domain(domain)
            .name(name)
            .value(value)
            .path("/")
            .build()
        cookieStore.getOrPut(domain) { mutableListOf() }.let { list ->
            list.removeAll { it.name == name }
            list.add(cookie)
        }
    }

    /** Clear all cookies (e.g. on logout). */
    fun clearCookies() {
        cookieStore.clear()
    }

    // --------------------------------------------------------------------- //

    private fun buildClient(context: Context?): OkHttpClient {
        // Seed from preferences if available.
        if (context != null) {
            seedFromPreferences(context.applicationContext)
        }

        return OkHttpClient.Builder()
            .connectTimeout(TIMEOUT_SECONDS, TimeUnit.SECONDS)
            .readTimeout(TIMEOUT_SECONDS, TimeUnit.SECONDS)
            .writeTimeout(TIMEOUT_SECONDS, TimeUnit.SECONDS)
            .cookieJar(object : CookieJar {
                override fun saveFromResponse(url: HttpUrl, cookies: List<Cookie>) {
                    val host = url.host
                    val stored = cookieStore.getOrPut(host) { mutableListOf() }
                    for (cookie in cookies) {
                        stored.removeAll { it.name == cookie.name }
                        stored.add(cookie)
                    }
                }

                override fun loadForRequest(url: HttpUrl): List<Cookie> {
                    return cookieStore[url.host].orEmpty()
                }
            })
            .build()
    }

    /**
     * Attempt to restore a session cookie persisted by [PreferencesManager].
     */
    private fun seedFromPreferences(appContext: Context) {
        try {
            val prefs = appContext.getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)
            val sessionCookie = prefs.getString("session_cookie", null)
            if (!sessionCookie.isNullOrBlank()) {
                setSessionCookie("www.relatives.co.za", "PHPSESSID", sessionCookie)
            }
        } catch (_: Exception) {
            // Non-critical -- we just won't have a session until the user logs in.
        }
    }
}
