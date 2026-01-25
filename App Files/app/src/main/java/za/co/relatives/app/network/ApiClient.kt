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
        val subscription_expires_at: String?,
        val max_members: Int,
        val members_count: Int
    )

    data class VerifyRequest(
        val family_id: String,
        val plan_code: String,
        val purchase_token: String
    )

    fun getSubscriptionStatus(familyId: String, callback: (SubscriptionStatus?) -> Unit) {
        val url = "${BASE_URL}subscription.php?action=status&family_id=$familyId"
        
        val request = Request.Builder()
            .url(url)
            .get()
            .build()

        client.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                Log.e("ApiClient", "Failed to fetch status", e)
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
                            Log.e("ApiClient", "JSON Parse Error", e)
                            callback(null)
                        }
                    } else {
                        callback(null)
                    }
                }
            }
        })
    }

    fun verifyPurchase(familyId: String, planCode: String, purchaseToken: String, callback: (Boolean) -> Unit) {
        val url = "${BASE_URL}subscription.php?action=verify_google"
        
        val json = gson.toJson(VerifyRequest(familyId, planCode, purchaseToken))
        val body = json.toRequestBody(JSON)
        
        val request = Request.Builder()
            .url(url)
            .post(body)
            .build()

        client.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                callback(false)
            }

            override fun onResponse(call: Call, response: Response) {
                response.use {
                    callback(it.isSuccessful)
                }
            }
        })
    }
}
