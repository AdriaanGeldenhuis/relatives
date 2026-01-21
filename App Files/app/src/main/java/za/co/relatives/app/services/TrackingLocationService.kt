package za.co.relatives.app.services

import android.annotation.SuppressLint
import android.app.Service
import android.content.Intent
import android.content.pm.ServiceInfo
import android.location.Location
import android.os.BatteryManager
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.util.Log
import android.webkit.CookieManager
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import okhttp3.Call
import okhttp3.Callback
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONObject
import za.co.relatives.app.network.NetworkClient
import za.co.relatives.app.utils.NotificationHelper
import za.co.relatives.app.utils.PreferencesManager
import java.io.IOException

class TrackingLocationService : Service() {

    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private lateinit var locationCallback: LocationCallback
    private var isTracking = false

    // Notification Polling
    private val notificationHandler = Handler(Looper.getMainLooper())
    private val notificationRunnable = object : Runnable {
        override fun run() {
            checkNotifications()
            notificationHandler.postDelayed(this, 30000) // Check every 30 seconds
        }
    }

    companion object {
        const val ACTION_START_TRACKING = "ACTION_START_TRACKING"
        const val ACTION_STOP_TRACKING = "ACTION_STOP_TRACKING"
        const val ACTION_UPDATE_INTERVAL = "ACTION_UPDATE_INTERVAL"
        private const val TAG = "TrackingService"
        private const val API_URL = "https://www.relatives.co.za/tracking/api/update_location.php"
        private const val NOTIF_COUNT_URL = "https://www.relatives.co.za/notifications/api/count.php"
        private const val BASE_URL = "https://www.relatives.co.za"
    }

    override fun onCreate() {
        super.onCreate()
        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)
        
        locationCallback = object : LocationCallback() {
            override fun onLocationResult(locationResult: LocationResult) {
                for (location in locationResult.locations) {
                    sendLocationToServer(location)
                }
            }
        }

        // Start polling for notifications regardless of tracking state (if service is alive)
        notificationHandler.post(notificationRunnable)
    }

    override fun onDestroy() {
        super.onDestroy()
        notificationHandler.removeCallbacks(notificationRunnable)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START_TRACKING -> startTracking()
            ACTION_STOP_TRACKING -> stopTracking()
            ACTION_UPDATE_INTERVAL -> restartTrackingWithNewInterval()
        }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun startTracking() {
        if (isTracking) return
        isTracking = true
        PreferencesManager.setTrackingEnabled(true)

        // Start Foreground
        val notification = NotificationHelper.buildTrackingNotification(this)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(
                NotificationHelper.NOTIFICATION_ID, 
                notification,
                ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION
            )
        } else {
            startForeground(NotificationHelper.NOTIFICATION_ID, notification)
        }

        requestLocationUpdates()
    }

    private fun stopTracking() {
        isTracking = false
        PreferencesManager.setTrackingEnabled(false)
        try {
            fusedLocationClient.removeLocationUpdates(locationCallback)
        } catch (e: Exception) {
            Log.e(TAG, "Error removing updates", e)
        }
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    private fun restartTrackingWithNewInterval() {
        if (isTracking) {
            fusedLocationClient.removeLocationUpdates(locationCallback)
            requestLocationUpdates()
        }
    }

    @SuppressLint("MissingPermission") // Checked in Activity
    private fun requestLocationUpdates() {
        val intervalSeconds = PreferencesManager.getUpdateInterval()
        val intervalMillis = intervalSeconds * 1000L

        val locationRequest = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, intervalMillis)
            .setMinUpdateIntervalMillis(intervalMillis)
            .build()

        try {
            fusedLocationClient.requestLocationUpdates(
                locationRequest,
                locationCallback,
                Looper.getMainLooper()
            )
            Log.d(TAG, "Location updates started with interval: ${intervalSeconds}s")
        } catch (e: SecurityException) {
            Log.e(TAG, "Location permission lost", e)
            stopTracking()
        }
    }

    private fun checkNotifications() {
        // Get cookies from WebView store
        var cookie = CookieManager.getInstance().getCookie(NOTIF_COUNT_URL)
        if (cookie.isNullOrEmpty()) {
            cookie = CookieManager.getInstance().getCookie(BASE_URL)
        }

        if (cookie.isNullOrEmpty()) {
            Log.w(TAG, "Skipping notification check - no cookie")
            return
        }

        val request = Request.Builder()
            .url(NOTIF_COUNT_URL)
            .addHeader("Cookie", cookie)
            .get()
            .build()

        NetworkClient.client.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                Log.e(TAG, "Failed to check notifications", e)
            }

            override fun onResponse(call: Call, response: Response) {
                response.use {
                    val body = it.body?.string()
                    if (it.isSuccessful && !body.isNullOrEmpty()) {
                        try {
                            val json = JSONObject(body)
                            if (json.has("count")) {
                                val count = json.getInt("count")
                                val lastCount = PreferencesManager.getLastNotificationCount()
                                
                                // Look for optional latest message details
                                val latestTitle = if (json.has("latest_title")) json.getString("latest_title") else null
                                val latestMessage = if (json.has("latest_message")) json.getString("latest_message") else null

                                // Alert if count > 0 and either:
                                // 1. It's the first run (lastCount == 0)
                                // 2. The count has increased
                                if (count > 0 && count > lastCount) {
                                    NotificationHelper.showNewMessageNotification(
                                        this@TrackingLocationService, 
                                        count,
                                        latestTitle,
                                        latestMessage
                                    )
                                }
                                
                                PreferencesManager.setLastNotificationCount(count)
                            }
                        } catch (e: Exception) {
                            Log.e(TAG, "Error parsing notification count: $body", e)
                        }
                    } else {
                        Log.w(TAG, "Notification check failed: ${it.code} - $body")
                    }
                }
            }
        })
    }

    private fun sendLocationToServer(location: Location) {
        val deviceUuid = PreferencesManager.getDeviceUuid()
        val batteryLevel = getBatteryLevel()
        val isMoving = if (location.hasSpeed() && location.speed > 0.5) 1 else 0
        val speedKmh = (location.speed * 3.6)

        val json = JSONObject()
        try {
            json.put("device_uuid", deviceUuid)
            json.put("latitude", location.latitude)
            json.put("longitude", location.longitude)
            json.put("accuracy_m", location.accuracy.toInt())
            json.put("speed_kmh", speedKmh)
            json.put("heading_deg", location.bearing)
            json.put("is_moving", isMoving)
            json.put("battery_level", batteryLevel)
            json.put("source", "android_native")
        } catch (e: Exception) {
            Log.e(TAG, "Error building JSON", e)
            return
        }

        val mediaType = "application/json; charset=utf-8".toMediaType()
        val body = json.toString().toRequestBody(mediaType)
        
        var cookie = CookieManager.getInstance().getCookie(API_URL)
        if (cookie.isNullOrEmpty()) {
            cookie = CookieManager.getInstance().getCookie(BASE_URL)
        }
        
        val requestBuilder = Request.Builder()
            .url(API_URL)
            .post(body)
        
        if (!cookie.isNullOrEmpty()) {
            requestBuilder.addHeader("Cookie", cookie)
        }

        val request = requestBuilder.build()

        NetworkClient.client.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                Log.e(TAG, "Network error sending location", e)
            }

            override fun onResponse(call: Call, response: Response) {
                response.use {
                    val responseBody = it.body?.string()
                    if (!it.isSuccessful) {
                        Log.e(TAG, "Server rejected update (Code ${it.code}): $responseBody")
                    } else {
                        Log.d(TAG, "Location sent successfully. Server: $responseBody")
                    }
                }
            }
        })
    }

    private fun getBatteryLevel(): Int {
        val bm = getSystemService(BATTERY_SERVICE) as BatteryManager
        return bm.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
    }
}