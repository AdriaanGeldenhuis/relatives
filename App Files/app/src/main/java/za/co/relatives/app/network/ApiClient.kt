package za.co.relatives.app.network

import android.content.Context
import com.google.gson.Gson
import com.google.gson.JsonObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.CookieJar
import okhttp3.OkHttpClient
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * Lightweight API client for the Relatives backend.
 *
 * All network calls run on [Dispatchers.IO] and return parsed results or throw.
 * The shared [NetworkClient] instance handles session cookies transparently.
 */
class ApiClient(private val context: Context) {

    companion object {
        private const val BASE_URL = "https://www.relatives.co.za/api/"
        private val JSON_MEDIA = "application/json; charset=utf-8".toMediaType()
    }

    private val http by lazy { NetworkClient.getInstance(context) }
    private val pinnedSessionHttp by lazy {
        http.newBuilder().cookieJar(CookieJar.NO_COOKIES).followRedirects(false).build()
    }
    private val gson = Gson()

    // ------------------------------------------------------------------ //
    //  Subscription
    // ------------------------------------------------------------------ //

    suspend fun getSubscriptionStatus(): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder()
            .url("${BASE_URL}subscription/details.php")
            .get()
            .build()
        executeJson(request)
    }

    suspend fun verifyPurchase(
        familyId: Long,
        productId: String,
        purchaseToken: String,
    ): JsonObject = withContext(Dispatchers.IO) {
        val body = JsonObject().apply {
            addProperty("platform", "google_play")
            addProperty("family_id", familyId)
            addProperty("product_id", productId)
            addProperty("purchase_token", purchaseToken)
        }
        val request = Request.Builder()
            .url("${BASE_URL}subscription/start-from-native.php")
            .post(gson.toJson(body).toRequestBody(JSON_MEDIA))
            .build()
        executeJson(request)
    }

    // ------------------------------------------------------------------ //
    //  FCM token registration
    // ------------------------------------------------------------------ //

    /**
     * Register (or update) the device FCM token with the backend so it can
     * send push notifications to this device.
     *
     * Endpoint contract (api/fcm/register.php): POST JSON
     * { "token": ..., "device_type": "android" } with the session cookie.
     * (The previous implementation posted form data to a nonexistent
     * /api/notifications/register_token.php — tokens were never registered,
     * so no pushes or tracking wakes ever reached Android devices.)
     */
    suspend fun registerFcmToken(token: String, sessionCookie: String, installationId: String, capability: String): JsonObject = withContext(Dispatchers.IO) {
        val body = JsonObject().apply {
            addProperty("token", token)
            addProperty("device_type", "android")
            addProperty("installation_id", installationId)
            addProperty("wake_capability", capability)
        }
        val request = Request.Builder()
            .url("${BASE_URL}fcm/register.php")
            .header("Cookie", "RELATIVES_SESSION=$sessionCookie")
            .post(gson.toJson(body).toRequestBody(JSON_MEDIA))
            .build()
        executeJson(request, pinnedSessionHttp)
    }

    // ------------------------------------------------------------------ //
    //  Internal helpers
    // ------------------------------------------------------------------ //

    /**
     * Execute [request] and parse the response body as a [JsonObject].
     *
     * @throws ApiException on HTTP errors or parse failures.
     */
    private suspend fun executeJson(request: Request, client: OkHttpClient = http): JsonObject {
        val (status, bodyString) = client.newCall(request).readTextResponse()
        if (status !in 200..299) {
            throw ApiException(status, "Relatives API returned HTTP $status")
        }
        return gson.fromJson(bodyString, JsonObject::class.java)
            ?: throw ApiException(status, "Empty JSON response")
    }
}

/** Simple exception carrying the HTTP status code and raw body. */
class ApiException(val httpCode: Int, message: String) : Exception(message)
