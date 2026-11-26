package za.co.relatives.app.tracking

import android.content.Context
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import kotlinx.coroutines.CancellationException
import okhttp3.CookieJar
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import za.co.relatives.app.network.NetworkClient
import za.co.relatives.app.network.readTextResponse
import java.util.concurrent.TimeUnit

internal class TrackingTransport(context: Context) {
    private val http = NetworkClient.getInstance(context).newBuilder()
        .cookieJar(CookieJar.NO_COOKIES)
        .followRedirects(false)
        .callTimeout(8, TimeUnit.SECONDS)
        .build()

    data class Reply(val code: Int, val body: JsonObject?) {
        val data: JsonObject? get() = body?.get("data")?.takeIf { it.isJsonObject }?.asJsonObject
        val error: String? get() = body?.text("error")
        val success: Boolean get() = code in 200..299 && body?.flag("success") == true
    }

    suspend fun call(path: String, cookie: String, body: JsonObject? = null): Reply {
        val request = Request.Builder().url("https://www.relatives.co.za/tracking/api/$path")
            .header("Cookie", "RELATIVES_SESSION=$cookie")
            .header("Accept", "application/json")
        if (body != null) request.post(body.toString().toRequestBody("application/json; charset=utf-8".toMediaType()))
        val (code, text) = http.newCall(request.build()).readTextResponse()
        val json = try {
            text?.let { JsonParser.parseString(it).takeIf { root -> root.isJsonObject }?.asJsonObject }
        } catch (_: RuntimeException) {
            null
        }
        return Reply(code, json)
    }

    suspend fun policy(cookie: String): Reply? = read("session_status.php", cookie)

    suspend fun monitorRegions(cookie: String): Reply? = read("monitor_regions.php", cookie)

    suspend fun pendingWake(cookie: String, deviceId: String): Reply? = read("wake_pending.php?device_id=$deviceId", cookie)

    private suspend fun read(path: String, cookie: String): Reply? = try {
        call(path, cookie)
    } catch (cancelled: CancellationException) {
        throw cancelled
    } catch (_: Exception) {
        null
    }
}

internal fun JsonObject.text(key: String): String? =
    get(key)?.takeIf { it.isJsonPrimitive }?.asString

internal fun JsonObject.number(key: String): Long? = text(key)?.toLongOrNull()

internal fun JsonObject.flag(key: String): Boolean? = text(key)?.toBooleanStrictOrNull()
