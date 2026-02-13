package za.co.relatives.app.network

import android.content.Context
import com.google.gson.Gson
import com.google.gson.JsonObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.FormBody
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
    private val gson = Gson()

    // ------------------------------------------------------------------ //
    //  Subscription
    // ------------------------------------------------------------------ //

    /**
     * Check the current family's subscription / trial status.
     *
     * @return Parsed JSON object with keys such as `status`, `plan`,
     *   `trial_remaining_days`, `is_active`, etc.
     */
    suspend fun getSubscriptionStatus(): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder()
            .url("${BASE_URL}subscription/status.php")
            .get()
            .build()
        executeJson(request)
    }

    /**
     * Verify a Google Play purchase with the backend.
     *
     * The server validates the purchase token with Google, activates the plan
     * for the family, and returns the new subscription state.
     */
    suspend fun verifyPurchase(
        familyId: String,
        planCode: String,
        purchaseToken: String
    ): JsonObject = withContext(Dispatchers.IO) {
        val body = JsonObject().apply {
            addProperty("family_id", familyId)
            addProperty("plan_code", planCode)
            addProperty("purchase_token", purchaseToken)
        }
        val request = Request.Builder()
            .url("${BASE_URL}subscription/verify_purchase.php")
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
     */
    suspend fun registerFcmToken(token: String): JsonObject = withContext(Dispatchers.IO) {
        val formBody = FormBody.Builder()
            .add("token", token)
            .add("platform", "android")
            .build()
        val request = Request.Builder()
            .url("${BASE_URL}notifications/register_token.php")
            .post(formBody)
            .build()
        executeJson(request)
    }

    // ------------------------------------------------------------------ //
    //  Internal helpers
    // ------------------------------------------------------------------ //

    /**
     * Execute [request] and parse the response body as a [JsonObject].
     *
     * @throws ApiException on HTTP errors or parse failures.
     */
    private fun executeJson(request: Request): JsonObject {
        val response = http.newCall(request).execute()
        val bodyString = response.body?.string().orEmpty()

        if (!response.isSuccessful) {
            throw ApiException(response.code, bodyString)
        }

        return try {
            gson.fromJson(bodyString, JsonObject::class.java)
                ?: throw ApiException(response.code, "Empty JSON response")
        } catch (e: Exception) {
            if (e is ApiException) throw e
            throw ApiException(response.code, "Failed to parse response: ${e.message}")
        }
    }
}

/** Simple exception carrying the HTTP status code and raw body. */
class ApiException(val httpCode: Int, message: String) : Exception(message)
