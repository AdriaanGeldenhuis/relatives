package za.co.relatives.app.tracking

import android.content.Context
import android.location.Location
import za.co.relatives.app.data.PreferencesManager
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread
import org.json.JSONObject

class LocationUploader(
    private val context: Context,
    private val prefs: PreferencesManager
) {
    companion object {
        private const val UPLOAD_URL = "https://relatives.co.za/tracking/api/update.php"
    }

    fun upload(location: Location, battery: Int, state: String) {
        thread {
            try {
                val json = JSONObject().apply {
                    put("lat", location.latitude)
                    put("lng", location.longitude)
                    put("accuracy", location.accuracy.toDouble())
                    put("altitude", if (location.hasAltitude()) location.altitude else JSONObject.NULL)
                    put("speed", location.speed.toDouble())
                    put("heading", if (location.hasBearing()) location.bearing.toDouble() else JSONObject.NULL)
                    put("battery", battery)
                    put("is_moving", state != "IDLE")
                    put("source", "fused")
                }

                val sessionCookie = prefs.getSessionCookie()
                if (sessionCookie.isNullOrEmpty()) return@thread

                val conn = (URL(UPLOAD_URL).openConnection() as HttpURLConnection).apply {
                    requestMethod = "POST"
                    setRequestProperty("Content-Type", "application/json")
                    setRequestProperty("Cookie", sessionCookie)
                    connectTimeout = 10000
                    readTimeout = 10000
                    doOutput = true
                }

                OutputStreamWriter(conn.outputStream).use { it.write(json.toString()) }

                val responseCode = conn.responseCode
                if (responseCode != 200) {
                    android.util.Log.w("LocationUploader", "Upload failed: HTTP $responseCode")
                }

                conn.disconnect()
            } catch (e: Exception) {
                android.util.Log.e("LocationUploader", "Upload error", e)
                // TODO: Queue for later upload via Room DB
            }
        }
    }
}
