package za.co.relatives.app.network

import android.util.Log
import com.google.gson.Gson
import okhttp3.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException

object ApiClient {
    private const val BASE_URL = "https://www.relatives.co.za/api/"
    private val client = OkHttpClient()
    private val gson = Gson()
    private val JSON = "application/json; charset=utf-8".toMediaType()

    data class SubscriptionStatus(
        val status: String,
        val trial_ends_at: String?,
        val current_period_end: String?,
        val provider: String?,
        val plan_code: String?
    )

    private data class StartSubscriptionRequest(
        val platform: String = "google_play",
        val family_id: String,
        val plan_code: String,
        val product_id: String,
        val purchase_token: String
    )

    fun getSubscriptionStatus(familyId: String, callback: (SubscriptionStatus?) -> Unit) {
        val url = "${BASE_URL}family/subscription-status.php?family_id=$familyId"
        
        val request = Request.Builder()
            .url(url)
            .get()
            .build()

        client.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                Log.e("ApiClient", "Failed to fetch subscription status", e)
                callback(null)
            }

            override fun onResponse(call: Call, response: Response) {
                response.use {
                    if (it.isSuccessful) {
                        try {
                            val body = it.body?.string()
                            val status = gson.fromJson(body, SubscriptionStatus::class.java)
                            callback(status)
                        } catch (e: Exception) {
                            Log.e("ApiClient", "Status JSON Parse Error", e)
                            callback(null)
                        }
                    } else {
                        Log.e("ApiClient", "Status request failed with code: ${it.code}")
                        callback(null)
                    }
                }
            }
        })
    }

    fun startGooglePlaySubscription(
        familyId: String,
        planCode: String,
        productId: String,
        purchaseToken: String,
        callback: (Boolean) -> Unit
    ) {
        val url = "${BASE_URL}subscription/start-from-native.php"
        
        val requestBody = StartSubscriptionRequest(
            family_id = familyId,
            plan_code = planCode,
            product_id = productId,
            purchase_token = purchaseToken
        )
        
        val json = gson.toJson(requestBody)
        val body = json.toRequestBody(JSON)
        
        val request = Request.Builder()
            .url(url)
            .post(body)
            .build()

        client.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                Log.e("ApiClient", "Start subscription network call failed", e)
                callback(false)
            }

            override fun onResponse(call: Call, response: Response) {
                response.use {
                    // Assuming any 2xx status code is a success
                    callback(it.isSuccessful)
                }
            }
        })
    }
}
