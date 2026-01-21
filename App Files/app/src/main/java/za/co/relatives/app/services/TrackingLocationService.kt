package za.co.relatives.app.services

import android.Manifest
import android.annotation.SuppressLint
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.location.Location
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.BatteryManager
import android.os.Build
import android.os.Handler
import android.os.HandlerThread
import android.os.IBinder
import android.os.Looper
import android.os.PowerManager
import android.util.Log
import android.webkit.CookieManager
import androidx.core.content.ContextCompat
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import okhttp3.Call
import okhttp3.Callback
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONArray
import org.json.JSONObject
import za.co.relatives.app.MainActivity
import za.co.relatives.app.network.NetworkClient
import za.co.relatives.app.utils.NotificationHelper
import za.co.relatives.app.utils.PreferencesManager
import java.io.IOException
import java.util.UUID
import java.util.concurrent.TimeUnit
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken

class TrackingLocationService : Service() {

    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private lateinit var locationCallback: LocationCallback
    private var isTracking = false
    private var isPaused = false

    // WakeLock to prevent service death
    private var wakeLock: PowerManager.WakeLock? = null
    private var serviceLooper: Looper? = null
    private var serviceHandler: Handler? = null
    private val mainHandler = Handler(Looper.getMainLooper())
    private var heartbeatRunnable: Runnable? = null

    // Notification Polling (existing - works 100%)
    private val notificationHandler = Handler(Looper.getMainLooper())
    private val notificationRunnable = object : Runnable {
        override fun run() {
            checkNotifications()
            notificationHandler.postDelayed(this, 30000) // Check every 30 seconds
        }
    }

    // Smart Tracking Modes
    private enum class TrackingMode { MOVING, IDLE }
    private var currentMode = TrackingMode.IDLE
    private var lastUploadTime: Long = 0
    private var consecutiveIdleCount = 0
    private var consecutiveIdleForStatus = 0
    private var lastReportedMovingStatus = false
    private var lastLocation: Location? = null
    private var lastLocationTime: Long = 0
    private val SPEED_THRESHOLD_KMH = 5.0f
    private val DISTANCE_THRESHOLD_M = 50.0f
    private val IDLE_COUNT_THRESHOLD = 3
    private val MOVING_STATUS_HOLD_COUNT = 5

    // Smart Battery Mode
    private val LOW_BATTERY_THRESHOLD = 20
    private val CRITICAL_BATTERY_THRESHOLD = 10
    private var isLowBatteryMode = false

    // Offline Queue - persisted to survive service restarts
    private val offlineQueue = mutableListOf<QueuedLocation>()
    private val MAX_QUEUE_SIZE = 50
    private val gson = Gson()

    // Exponential backoff for retries
    private var retryDelayMs = 2000L
    private val MAX_RETRY_DELAY_MS = 60000L
    private var consecutiveFailures = 0

    // Token refresh race condition prevention
    @Volatile
    private var isRefreshingToken = false
    private val tokenRefreshLock = Object()

    // Auth failure tracking
    private var consecutiveAuthFailures = 0
    private val MAX_AUTH_FAILURES = 5
    private var hasShownAuthFailureNotification = false

    // HTTP client with timeouts
    private val httpClient = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .build()
    private val jsonMediaType = "application/json; charset=utf-8".toMediaType()

    // Queued location for offline storage
    data class QueuedLocation(
        val latitude: Double,
        val longitude: Double,
        val accuracy: Float,
        val altitude: Double?,
        val bearing: Float?,
        val speed: Float?,
        val isMoving: Boolean,
        val speedKmh: Float,
        val timestamp: Long,
        val clientEventId: String = UUID.randomUUID().toString(),
        val retryCount: Int = 0
    ) {
        companion object {
            fun fromLocation(location: Location, isMoving: Boolean, speedKmh: Float): QueuedLocation {
                return QueuedLocation(
                    latitude = location.latitude,
                    longitude = location.longitude,
                    accuracy = location.accuracy,
                    altitude = if (location.hasAltitude()) location.altitude else null,
                    bearing = if (location.hasBearing()) location.bearing else null,
                    speed = if (location.hasSpeed()) location.speed else null,
                    isMoving = isMoving,
                    speedKmh = speedKmh,
                    timestamp = System.currentTimeMillis()
                )
            }
        }

        fun toLocation(): Location {
            return Location("queued").apply {
                latitude = this@QueuedLocation.latitude
                longitude = this@QueuedLocation.longitude
                accuracy = this@QueuedLocation.accuracy
                this@QueuedLocation.altitude?.let { altitude = it }
                this@QueuedLocation.bearing?.let { bearing = it }
                this@QueuedLocation.speed?.let { speed = it }
            }
        }
    }

    companion object {
        const val ACTION_START_TRACKING = "ACTION_START_TRACKING"
        const val ACTION_STOP_TRACKING = "ACTION_STOP_TRACKING"
        const val ACTION_PAUSE_TRACKING = "ACTION_PAUSE_TRACKING"
        const val ACTION_RESUME_TRACKING = "ACTION_RESUME_TRACKING"
        const val ACTION_UPDATE_INTERVAL = "ACTION_UPDATE_INTERVAL"
        private const val TAG = "TrackingService"
        private const val API_URL = "https://www.relatives.co.za/tracking/api/update_location.php"
        private const val BATCH_API_URL = "https://www.relatives.co.za/tracking/api/update_location_batch.php"
        private const val NOTIF_COUNT_URL = "https://www.relatives.co.za/notifications/api/count.php"
        private const val BASE_URL = "https://www.relatives.co.za"
        private const val IDLE_INTERVAL_MS = 60 * 1000L

        fun startTracking(context: Context) {
            val intent = Intent(context, TrackingLocationService::class.java).apply {
                action = ACTION_START_TRACKING
            }
            ContextCompat.startForegroundService(context, intent)
        }
    }

    override fun onCreate() {
        super.onCreate()
        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)

        val handlerThread = HandlerThread(TAG)
        handlerThread.start()
        serviceLooper = handlerThread.looper
        serviceHandler = Handler(serviceLooper!!)

        locationCallback = object : LocationCallback() {
            override fun onLocationResult(locationResult: LocationResult) {
                locationResult.lastLocation?.let { location ->
                    uploadLocation(location)
                }
            }
        }

        // Load persisted offline queue
        loadPersistedQueue()

        // Start notification polling (existing functionality)
        notificationHandler.post(notificationRunnable)
    }

    override fun onDestroy() {
        super.onDestroy()
        notificationHandler.removeCallbacks(notificationRunnable)
        serviceLooper?.quit()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START_TRACKING -> handleStartTracking()
            ACTION_STOP_TRACKING -> handleStopTracking()
            ACTION_PAUSE_TRACKING -> pauseTracking()
            ACTION_RESUME_TRACKING -> resumeTracking()
            ACTION_UPDATE_INTERVAL -> restartTrackingWithNewInterval()
        }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    // ========== QUEUE PERSISTENCE ==========

    private fun loadPersistedQueue() {
        try {
            val prefs = getSharedPreferences("tracking_queue", Context.MODE_PRIVATE)
            val json = prefs.getString("offline_queue", null) ?: return
            val type = object : TypeToken<List<QueuedLocation>>() {}.type
            val loaded: List<QueuedLocation> = gson.fromJson(json, type) ?: emptyList()
            synchronized(offlineQueue) {
                offlineQueue.clear()
                offlineQueue.addAll(loaded)
            }
            Log.d(TAG, "Loaded ${loaded.size} queued locations from persistence")
        } catch (e: Exception) {
            Log.e(TAG, "Failed to load persisted queue", e)
        }
    }

    private fun persistQueue() {
        try {
            val prefs = getSharedPreferences("tracking_queue", Context.MODE_PRIVATE)
            synchronized(offlineQueue) {
                if (offlineQueue.isEmpty()) {
                    prefs.edit().remove("offline_queue").apply()
                } else {
                    val json = gson.toJson(offlineQueue.toList())
                    prefs.edit().putString("offline_queue", json).apply()
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to persist queue", e)
        }
    }

    // ========== START/STOP TRACKING ==========

    private fun handleStartTracking() {
        if (isTracking) return
        isTracking = true
        isPaused = false
        consecutiveIdleCount = 0
        consecutiveIdleForStatus = 0
        consecutiveAuthFailures = 0
        hasShownAuthFailureNotification = false
        lastReportedMovingStatus = true
        currentMode = TrackingMode.MOVING
        PreferencesManager.setTrackingEnabled(true)

        val notification = NotificationHelper.buildTrackingNotification(this)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NotificationHelper.NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        } else {
            startForeground(NotificationHelper.NOTIFICATION_ID, notification)
        }

        acquireWakeLock()
        startLocationUpdates()
        startHeartbeatTimer()
        requestImmediateLocation()

        // Schedule WorkManager backup checker
        za.co.relatives.app.workers.TrackingServiceChecker.schedule(this)

        Log.d(TAG, "Tracking started (initial mode: MOVING)")
    }

    private fun handleStopTracking() {
        isTracking = false
        isPaused = false
        PreferencesManager.setTrackingEnabled(false)
        stopLocationUpdates()
        stopHeartbeatTimer()
        releaseWakeLock()

        // Cancel WorkManager backup checker
        za.co.relatives.app.workers.TrackingServiceChecker.cancel(this)

        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
        Log.d(TAG, "Tracking stopped")
    }

    private fun pauseTracking() {
        if (!isTracking || isPaused) return
        isPaused = true
        stopLocationUpdates()
        releaseWakeLock()
        Log.d(TAG, "Tracking paused")
    }

    private fun resumeTracking() {
        if (!isTracking || !isPaused) return
        isPaused = false
        acquireWakeLock()
        startLocationUpdates()
        Log.d(TAG, "Tracking resumed")
    }

    private fun restartTrackingWithNewInterval() {
        if (isTracking && !isPaused) {
            stopLocationUpdates()
            startLocationUpdates()
        }
    }

    // ========== LOCATION UPDATES ==========

    @SuppressLint("MissingPermission")
    private fun requestImmediateLocation() {
        if (!hasLocationPermission()) return

        try {
            fusedLocationClient.getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, null)
                .addOnSuccessListener { location ->
                    location?.let {
                        Log.d(TAG, "Immediate location received")
                        uploadLocation(it)
                    }
                }
                .addOnFailureListener { e ->
                    Log.w(TAG, "Immediate location request failed", e)
                }
        } catch (e: SecurityException) {
            Log.e(TAG, "Security exception on immediate location", e)
        }
    }

    @SuppressLint("MissingPermission")
    private fun startLocationUpdates() {
        if (!hasLocationPermission() || isPaused) {
            Log.w(TAG, "No location permission or tracking is paused")
            return
        }

        val batteryLevel = getBatteryLevel()
        isLowBatteryMode = batteryLevel < LOW_BATTERY_THRESHOLD
        val isCriticalBattery = batteryLevel < CRITICAL_BATTERY_THRESHOLD

        val request = when {
            isCriticalBattery -> {
                Log.d(TAG, "Starting CRITICAL BATTERY updates (battery: $batteryLevel%, interval: 5min)")
                LocationRequest.Builder(Priority.PRIORITY_PASSIVE, 5 * 60 * 1000L)
                    .setMinUpdateIntervalMillis(3 * 60 * 1000L)
                    .build()
            }
            isLowBatteryMode -> {
                val intervalMs = if (currentMode == TrackingMode.MOVING) 3 * 60 * 1000L else 5 * 60 * 1000L
                Log.d(TAG, "Starting LOW BATTERY updates (battery: $batteryLevel%, mode: $currentMode, interval: ${intervalMs/1000}s)")
                LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, intervalMs)
                    .setMinUpdateIntervalMillis(intervalMs / 2)
                    .build()
            }
            currentMode == TrackingMode.MOVING -> {
                val intervalMs = PreferencesManager.getUpdateInterval() * 1000L
                Log.d(TAG, "Starting MOVING updates (interval: ${intervalMs/1000}s)")
                LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, intervalMs)
                    .setMinUpdateIntervalMillis(intervalMs / 2)
                    .build()
            }
            else -> {
                Log.d(TAG, "Starting IDLE updates (interval: ${IDLE_INTERVAL_MS/1000}s)")
                LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, IDLE_INTERVAL_MS)
                    .setMinUpdateIntervalMillis(60 * 1000L)
                    .build()
            }
        }
        fusedLocationClient.requestLocationUpdates(request, locationCallback, serviceLooper)
    }

    private fun stopLocationUpdates() {
        try {
            fusedLocationClient.removeLocationUpdates(locationCallback)
        } catch (e: Exception) {
            Log.e(TAG, "Error removing updates", e)
        }
    }

    // ========== LOCATION UPLOAD ==========

    private fun uploadLocation(location: Location) {
        var speedKmh = if (location.hasSpeed() && location.speed > 0) {
            location.speed * 3.6f
        } else {
            calculateSpeedFromDistance(location)
        }

        val distanceMoved = lastLocation?.distanceTo(location) ?: 0f
        val isMovingBySpeed = speedKmh > SPEED_THRESHOLD_KMH
        val isMovingByDistance = distanceMoved > DISTANCE_THRESHOLD_M
        val currentlyMoving = isMovingBySpeed || isMovingByDistance

        // Smoothed is_moving flag
        val isMoving: Boolean
        if (currentlyMoving) {
            consecutiveIdleForStatus = 0
            isMoving = true
            lastReportedMovingStatus = true
        } else {
            consecutiveIdleForStatus++
            if (consecutiveIdleForStatus >= MOVING_STATUS_HOLD_COUNT) {
                isMoving = false
                lastReportedMovingStatus = false
            } else {
                isMoving = lastReportedMovingStatus
            }
        }

        if (isMovingByDistance && speedKmh < SPEED_THRESHOLD_KMH) {
            speedKmh = calculateSpeedFromDistance(location)
        }

        Log.d(TAG, "Location: speed=${speedKmh.toInt()} km/h, distance=${distanceMoved.toInt()}m, isMoving=$isMoving")

        // Check network
        if (!isNetworkAvailable()) {
            queueLocationForLater(location, isMoving, speedKmh)
            lastLocation = location
            lastLocationTime = System.currentTimeMillis()
            updateTrackingMode(speedKmh, isMoving)
            return
        }

        // Send queued locations first
        sendQueuedLocations()

        // Send current location
        sendLocationToServer(location, isMoving, speedKmh, null,
            onSuccess = { serverSettings ->
                lastUploadTime = System.currentTimeMillis()
                PreferencesManager.lastUploadTime = lastUploadTime
                consecutiveAuthFailures = 0
                consecutiveFailures = 0
                retryDelayMs = 2000L
                lastLocation = location
                lastLocationTime = System.currentTimeMillis()
                applyServerSettings(serverSettings)
                updateTrackingMode(speedKmh, isMoving)
                Log.d(TAG, "Location uploaded successfully (speed: ${speedKmh.toInt()} km/h, mode: $currentMode)")
            },
            onAuthFailure = {
                Log.w(TAG, "Upload auth failure - queuing location")
                queueLocationForLater(location, isMoving, speedKmh)
                refreshSessionToken()
            },
            onTransientFailure = {
                queueLocationForLater(location, isMoving, speedKmh)
            }
        )
    }

    private fun sendLocationToServer(
        location: Location,
        isMoving: Boolean,
        speedKmh: Float,
        clientEventId: String?,
        onSuccess: (JSONObject?) -> Unit,
        onAuthFailure: () -> Unit,
        onTransientFailure: () -> Unit
    ) {
        val deviceUuid = PreferencesManager.getDeviceUuid()
        val sessionToken = PreferencesManager.sessionToken
        val batteryLevel = getBatteryLevel()
        val eventId = clientEventId ?: UUID.randomUUID().toString()

        val json = JSONObject().apply {
            put("device_uuid", deviceUuid)
            put("platform", "android")
            put("device_name", Build.MODEL)
            put("latitude", location.latitude)
            put("longitude", location.longitude)
            put("accuracy_m", location.accuracy.toInt())
            put("speed_kmh", speedKmh)
            put("heading_deg", if (location.hasBearing()) location.bearing else 0)
            put("altitude_m", if (location.hasAltitude()) location.altitude else JSONObject.NULL)
            put("is_moving", isMoving)
            put("battery_level", batteryLevel)
            put("source", "android_native")
            put("client_event_id", eventId)
            put("client_timestamp", System.currentTimeMillis())
            put("network_status", if (isNetworkAvailable()) "online" else "offline")
            put("location_status", if (isLocationServicesEnabled()) "enabled" else "disabled")
            put("permission_status", if (hasLocationPermission()) "granted" else "denied")
            put("app_state", "background")
            if (!sessionToken.isNullOrBlank()) {
                put("session_token", sessionToken)
            }
        }

        val body = json.toString().toRequestBody(jsonMediaType)

        val requestBuilder = Request.Builder()
            .url(API_URL)
            .post(body)
            .addHeader("Content-Type", "application/json")
            .addHeader("User-Agent", "RelativesAndroid/1.0")

        // Prefer Bearer token, fall back to cookies
        if (!sessionToken.isNullOrBlank()) {
            requestBuilder.addHeader("Authorization", "Bearer $sessionToken")
        } else {
            // Fallback to cookies (existing behavior)
            var cookie = CookieManager.getInstance().getCookie(API_URL)
            if (cookie.isNullOrEmpty()) {
                cookie = CookieManager.getInstance().getCookie(BASE_URL)
            }
            if (!cookie.isNullOrEmpty()) {
                requestBuilder.addHeader("Cookie", cookie)
            }
        }

        httpClient.newCall(requestBuilder.build()).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                Log.e(TAG, "Network error sending location", e)
                onTransientFailure()
            }

            override fun onResponse(call: Call, response: Response) {
                response.use {
                    val responseBody = try { it.body?.string() } catch (e: Exception) { null }

                    when {
                        it.isSuccessful -> {
                            val serverSettings = try {
                                responseBody?.let { body -> JSONObject(body).optJSONObject("server_settings") }
                            } catch (e: Exception) { null }
                            onSuccess(serverSettings)
                        }
                        it.code in listOf(401, 402, 403) -> {
                            Log.e(TAG, "Auth failure (${it.code}): $responseBody")
                            onAuthFailure()
                        }
                        else -> {
                            Log.e(TAG, "Upload failed (${it.code}): $responseBody")
                            onTransientFailure()
                        }
                    }
                }
            }
        })
    }

    // ========== OFFLINE QUEUE ==========

    private fun isNetworkAvailable(): Boolean {
        return try {
            val connectivityManager = getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
            val network = connectivityManager.activeNetwork ?: return false
            val capabilities = connectivityManager.getNetworkCapabilities(network) ?: return false
            capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
        } catch (e: Exception) {
            true
        }
    }

    private fun queueLocationForLater(location: Location, isMoving: Boolean, speedKmh: Float) {
        synchronized(offlineQueue) {
            offlineQueue.add(QueuedLocation.fromLocation(location, isMoving, speedKmh))
            while (offlineQueue.size > MAX_QUEUE_SIZE) {
                offlineQueue.removeAt(0)
            }
            Log.d(TAG, "Location queued (queue size: ${offlineQueue.size})")
            persistQueue()
        }
    }

    private fun sendQueuedLocations() {
        synchronized(offlineQueue) {
            if (offlineQueue.isEmpty()) return
            val queueSize = offlineQueue.size
            Log.d(TAG, "Sending $queueSize queued locations")

            if (queueSize > 3) {
                sendQueuedLocationsBatch()
            } else {
                sendQueuedLocationsIndividually()
            }
        }
    }

    private fun sendQueuedLocationsBatch() {
        synchronized(offlineQueue) {
            val toSend = offlineQueue.toList()
            offlineQueue.clear()
            persistQueue()

            val deviceUuid = PreferencesManager.getDeviceUuid()
            val sessionToken = PreferencesManager.sessionToken

            if (sessionToken.isNullOrBlank()) {
                toSend.forEach { reQueueLocationWithBackoff(it) }
                refreshSessionToken()
                return
            }

            val locationsArray = JSONArray()
            toSend.forEach { queued ->
                locationsArray.put(JSONObject().apply {
                    put("latitude", queued.latitude)
                    put("longitude", queued.longitude)
                    put("accuracy_m", queued.accuracy.toInt())
                    put("speed_kmh", queued.speedKmh)
                    put("heading_deg", queued.bearing ?: 0)
                    put("altitude_m", queued.altitude ?: JSONObject.NULL)
                    put("is_moving", queued.isMoving)
                    put("battery_level", getBatteryLevel())
                    put("client_event_id", queued.clientEventId)
                    put("client_timestamp", queued.timestamp)
                })
            }

            val body = JSONObject().apply {
                put("device_uuid", deviceUuid)
                put("platform", "android")
                put("device_name", Build.MODEL)
                put("source", "native")
                put("session_token", sessionToken)
                put("locations", locationsArray)
            }

            val request = Request.Builder()
                .url(BATCH_API_URL)
                .addHeader("Content-Type", "application/json")
                .addHeader("Authorization", "Bearer $sessionToken")
                .post(body.toString().toRequestBody(jsonMediaType))
                .build()

            httpClient.newCall(request).enqueue(object : Callback {
                override fun onFailure(call: Call, e: IOException) {
                    Log.e(TAG, "Batch upload failed", e)
                    toSend.forEach { reQueueLocationWithBackoff(it) }
                }

                override fun onResponse(call: Call, response: Response) {
                    response.use { resp ->
                        if (resp.isSuccessful) {
                            consecutiveFailures = 0
                            retryDelayMs = 2000L
                            PreferencesManager.lastUploadTime = System.currentTimeMillis()
                            Log.d(TAG, "Batch upload successful (${toSend.size} locations)")
                        } else if (resp.code in listOf(401, 402, 403)) {
                            toSend.forEach { reQueueLocationWithBackoff(it) }
                            refreshSessionToken()
                        } else {
                            toSend.forEach { reQueueLocationWithBackoff(it) }
                        }
                    }
                }
            })
        }
    }

    private fun sendQueuedLocationsIndividually() {
        synchronized(offlineQueue) {
            val toSend = offlineQueue.toList()
            offlineQueue.clear()
            persistQueue()

            toSend.forEach { queued ->
                sendLocationToServer(
                    location = queued.toLocation(),
                    isMoving = queued.isMoving,
                    speedKmh = queued.speedKmh,
                    clientEventId = queued.clientEventId,
                    onSuccess = {
                        consecutiveFailures = 0
                        retryDelayMs = 2000L
                        Log.d(TAG, "Queued location uploaded (id=${queued.clientEventId})")
                    },
                    onAuthFailure = {
                        reQueueLocationWithBackoff(queued)
                    },
                    onTransientFailure = {
                        reQueueLocationWithBackoff(queued)
                    }
                )
            }
        }
    }

    private fun reQueueLocationWithBackoff(queued: QueuedLocation) {
        synchronized(offlineQueue) {
            consecutiveFailures++
            retryDelayMs = (retryDelayMs * 2).coerceAtMost(MAX_RETRY_DELAY_MS)
            val updatedQueued = queued.copy(retryCount = queued.retryCount + 1)
            offlineQueue.add(updatedQueued)
            while (offlineQueue.size > MAX_QUEUE_SIZE) {
                offlineQueue.removeAt(0)
            }
            persistQueue()

            mainHandler.postDelayed({
                if (isNetworkAvailable()) {
                    sendQueuedLocations()
                }
            }, retryDelayMs)
        }
    }

    // ========== TOKEN REFRESH ==========

    private fun refreshSessionToken() {
        synchronized(tokenRefreshLock) {
            if (isRefreshingToken) return
            isRefreshingToken = true
        }

        if (consecutiveAuthFailures >= MAX_AUTH_FAILURES) {
            Log.e(TAG, "Too many auth failures - user needs to re-login")
            showAuthFailureNotification()
            synchronized(tokenRefreshLock) { isRefreshingToken = false }
            return
        }
        consecutiveAuthFailures++

        mainHandler.post {
            try {
                val cookieManager = CookieManager.getInstance()
                val cookies = cookieManager.getCookie(BASE_URL)

                if (cookies.isNullOrBlank()) {
                    Log.w(TAG, "No cookies for token refresh")
                    showAuthFailureNotification()
                    synchronized(tokenRefreshLock) { isRefreshingToken = false }
                    return@post
                }

                Thread {
                    try {
                        val url = java.net.URL("$BASE_URL/api/session-token.php")
                        val connection = url.openConnection() as java.net.HttpURLConnection
                        connection.requestMethod = "GET"
                        connection.setRequestProperty("Cookie", cookies)
                        connection.connectTimeout = 10000
                        connection.readTimeout = 10000

                        if (connection.responseCode == 200) {
                            val response = connection.inputStream.bufferedReader().readText()
                            val json = JSONObject(response)
                            if (json.optBoolean("success")) {
                                val token = json.optString("session_token")
                                if (token.isNotBlank()) {
                                    PreferencesManager.sessionToken = token
                                    Log.d(TAG, "Session token refreshed")
                                    consecutiveAuthFailures = 0
                                    hasShownAuthFailureNotification = false
                                    mainHandler.post { sendQueuedLocations() }
                                }
                            }
                        } else if (connection.responseCode == 401) {
                            mainHandler.post { showAuthFailureNotification() }
                        }
                        connection.disconnect()
                    } catch (e: Exception) {
                        Log.e(TAG, "Token refresh failed", e)
                    } finally {
                        synchronized(tokenRefreshLock) { isRefreshingToken = false }
                    }
                }.start()
            } catch (e: Exception) {
                Log.e(TAG, "Cookie access failed", e)
                synchronized(tokenRefreshLock) { isRefreshingToken = false }
            }
        }
    }

    private fun showAuthFailureNotification() {
        if (hasShownAuthFailureNotification) return
        hasShownAuthFailureNotification = true

        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
        }
        val pendingIntent = PendingIntent.getActivity(
            this, 0, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = androidx.core.app.NotificationCompat.Builder(this, NotificationHelper.CHANNEL_ALERTS)
            .setSmallIcon(android.R.drawable.ic_dialog_alert)
            .setContentTitle("Location sharing paused")
            .setContentText("Please open the app to reconnect")
            .setPriority(androidx.core.app.NotificationCompat.PRIORITY_HIGH)
            .setContentIntent(pendingIntent)
            .setAutoCancel(true)
            .build()

        val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager
        notificationManager.notify(NotificationHelper.ALERT_NOTIFICATION_ID + 1, notification)
    }

    // ========== HEARTBEAT TIMER ==========

    private fun startHeartbeatTimer() {
        stopHeartbeatTimer()
        heartbeatRunnable = Runnable {
            Log.d(TAG, "Heartbeat timer fired")
            requestHeartbeatLocation()
            startHeartbeatTimer()
        }
        val heartbeatMs = PreferencesManager.idleHeartbeatSeconds * 1000L
        mainHandler.postDelayed(heartbeatRunnable!!, heartbeatMs)
    }

    private fun stopHeartbeatTimer() {
        heartbeatRunnable?.let { mainHandler.removeCallbacks(it) }
        heartbeatRunnable = null
    }

    @SuppressLint("MissingPermission")
    private fun requestHeartbeatLocation() {
        if (!hasLocationPermission() || !isLocationServicesEnabled()) return

        try {
            fusedLocationClient.getCurrentLocation(Priority.PRIORITY_BALANCED_POWER_ACCURACY, null)
                .addOnSuccessListener { location ->
                    location?.let { uploadLocation(it) }
                }
        } catch (e: SecurityException) {
            Log.e(TAG, "Heartbeat location error", e)
        }
    }

    // ========== TRACKING MODE ==========

    private fun updateTrackingMode(speedKmh: Float, isMoving: Boolean) {
        val previousMode = currentMode

        if (isMoving || speedKmh > SPEED_THRESHOLD_KMH) {
            consecutiveIdleCount = 0
            if (currentMode != TrackingMode.MOVING) {
                currentMode = TrackingMode.MOVING
                Log.d(TAG, "Switched to MOVING mode")
            }
        } else {
            consecutiveIdleCount++
            if (consecutiveIdleCount >= IDLE_COUNT_THRESHOLD && currentMode != TrackingMode.IDLE) {
                currentMode = TrackingMode.IDLE
                Log.d(TAG, "Switched to IDLE mode")
            }
        }

        if (previousMode != currentMode && isTracking && !isPaused) {
            mainHandler.post {
                stopLocationUpdates()
                startLocationUpdates()
                stopHeartbeatTimer()
                startHeartbeatTimer()
            }
        }
    }

    private fun applyServerSettings(settings: JSONObject?) {
        settings ?: return
        try {
            settings.optInt("update_interval_seconds", 0).takeIf { it > 0 }?.let { newInterval ->
                val oldInterval = PreferencesManager.getUpdateInterval()
                PreferencesManager.setUpdateInterval(newInterval)
                if (newInterval != oldInterval && currentMode == TrackingMode.MOVING && isTracking && !isPaused) {
                    mainHandler.post {
                        stopLocationUpdates()
                        startLocationUpdates()
                    }
                }
            }
            settings.optInt("idle_heartbeat_seconds", 0).takeIf { it > 0 }?.let {
                PreferencesManager.idleHeartbeatSeconds = it
            }
            settings.optInt("offline_threshold_seconds", 0).takeIf { it > 0 }?.let {
                PreferencesManager.offlineThresholdSeconds = it
            }
            settings.optInt("stale_threshold_seconds", 0).takeIf { it > 0 }?.let {
                PreferencesManager.staleThresholdSeconds = it
            }
        } catch (e: Exception) {
            Log.w(TAG, "Error applying server settings", e)
        }
    }

    // ========== HELPER METHODS ==========

    private fun calculateSpeedFromDistance(currentLocation: Location): Float {
        val prevLocation = lastLocation ?: return 0f
        if (lastLocationTime == 0L) return 0f

        val distanceM = prevLocation.distanceTo(currentLocation)
        val timeSeconds = (System.currentTimeMillis() - lastLocationTime) / 1000f
        if (timeSeconds <= 0) return 0f

        return (distanceM / timeSeconds) * 3.6f
    }

    private fun getBatteryLevel(): Int {
        return try {
            val bm = getSystemService(BATTERY_SERVICE) as BatteryManager
            bm.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
        } catch (e: Exception) { 100 }
    }

    private fun hasLocationPermission(): Boolean {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
               ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
    }

    private fun isLocationServicesEnabled(): Boolean {
        val locationManager = getSystemService(Context.LOCATION_SERVICE) as android.location.LocationManager
        return locationManager.isProviderEnabled(android.location.LocationManager.GPS_PROVIDER) ||
               locationManager.isProviderEnabled(android.location.LocationManager.NETWORK_PROVIDER)
    }

    @SuppressLint("WakelockTimeout")
    private fun acquireWakeLock() {
        if (wakeLock == null) {
            val powerManager = getSystemService(Context.POWER_SERVICE) as PowerManager
            wakeLock = powerManager.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "$TAG:WakeLock")
        }
        if (wakeLock?.isHeld == false) {
            wakeLock?.acquire()
            Log.d(TAG, "WakeLock acquired")
        }
    }

    private fun releaseWakeLock() {
        if (wakeLock?.isHeld == true) {
            wakeLock?.release()
            Log.d(TAG, "WakeLock released")
        }
    }

    // ========== NOTIFICATION POLLING (existing - unchanged) ==========

    private fun checkNotifications() {
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
                                val latestTitle = if (json.has("latest_title")) json.getString("latest_title") else null
                                val latestMessage = if (json.has("latest_message")) json.getString("latest_message") else null

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
                            Log.e(TAG, "Error parsing notification count", e)
                        }
                    }
                }
            }
        })
    }

    // ========== SERVICE RESTART ON TASK REMOVAL ==========

    override fun onTaskRemoved(rootIntent: Intent?) {
        super.onTaskRemoved(rootIntent)
        if (PreferencesManager.isTrackingEnabled()) {
            Log.d(TAG, "Task removed but tracking enabled - scheduling restart")
            val restartIntent = Intent(applicationContext, TrackingLocationService::class.java).apply {
                action = ACTION_START_TRACKING
            }
            val pendingIntent = PendingIntent.getService(
                applicationContext,
                1,
                restartIntent,
                PendingIntent.FLAG_ONE_SHOT or PendingIntent.FLAG_IMMUTABLE
            )
            val alarmManager = getSystemService(Context.ALARM_SERVICE) as android.app.AlarmManager
            alarmManager.set(
                android.app.AlarmManager.ELAPSED_REALTIME_WAKEUP,
                android.os.SystemClock.elapsedRealtime() + 1000,
                pendingIntent
            )
        }
    }
}
