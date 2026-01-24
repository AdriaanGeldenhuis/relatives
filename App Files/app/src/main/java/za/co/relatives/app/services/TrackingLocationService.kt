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
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import okhttp3.Call
import okhttp3.Callback
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONObject
import za.co.relatives.app.data.QueuedLocationEntity
import za.co.relatives.app.data.TrackingDatabase
import za.co.relatives.app.network.NetworkClient
import za.co.relatives.app.utils.NotificationHelper
import za.co.relatives.app.utils.PreferencesManager
import za.co.relatives.app.workers.LocationUploadWorker
import java.io.IOException
import java.util.UUID
import java.util.concurrent.TimeUnit

/**
 * ============================================
 * TRACKING LOCATION SERVICE v3.0
 * ============================================
 *
 * 3-mode tracking engine:
 * - IDLE: Low power, balanced accuracy, 2-5 min interval, 100-250m distance gate
 * - MOVING: High accuracy, 10-30s interval, 10-30m distance gate
 * - BURST: High accuracy for 20-30s to get good fix, then settle
 *
 * Key changes from v2:
 * - Distance gating via setMinUpdateDistanceMeters (reduces spam + battery)
 * - Room database queue (replaces SharedPreferences JSON)
 * - WorkManager-based upload (decoupled from collection, survives death)
 * - WebView visibility only boosts interval, does NOT control core tracking
 * - BURST mode for movement start / bad accuracy recovery
 */
class TrackingLocationService : Service() {

    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private lateinit var locationCallback: LocationCallback
    private var isTracking = false
    private var isPaused = false

    private var wakeLock: PowerManager.WakeLock? = null
    private var serviceLooper: Looper? = null
    private var serviceHandler: Handler? = null
    private val mainHandler = Handler(Looper.getMainLooper())
    private var heartbeatRunnable: Runnable? = null

    // Coroutine scope for Room operations
    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    // Notification Polling
    private val notificationHandler = Handler(Looper.getMainLooper())
    private val notificationRunnable = object : Runnable {
        override fun run() {
            checkNotifications()
            notificationHandler.postDelayed(this, 30000)
        }
    }

    // ========== 3-MODE ENGINE ==========
    enum class TrackingMode {
        IDLE,    // Low power: balanced accuracy, 2-5 min, 100-250m distance
        MOVING,  // High fidelity: high accuracy, 10-30s, 10-30m distance
        BURST    // Confirm + lock: high accuracy for 20-30s, then settle
    }

    private var currentMode = TrackingMode.IDLE
    private var lastLocation: Location? = null
    private var lastLocationTime: Long = 0
    private var lastUploadTime: Long = 0

    // Mode transition state
    private var consecutiveIdleCount = 0
    private var consecutiveMovingCount = 0
    private var burstStartTime: Long = 0
    private var burstFixCount = 0

    // Movement detection
    private val SPEED_THRESHOLD_KMH = 5.0f
    private val DISTANCE_THRESHOLD_M = 50.0f
    private val IDLE_COUNT_THRESHOLD = 3    // 3 consecutive slow readings -> IDLE
    private val BURST_DURATION_MS = 25000L  // 25 seconds of burst
    private val BURST_MAX_FIXES = 3         // Max fixes to collect in burst

    // Smoothed is_moving flag
    private var consecutiveIdleForStatus = 0
    private var lastReportedMovingStatus = false
    private val MOVING_STATUS_HOLD_COUNT = 5

    // Battery management
    private val LOW_BATTERY_THRESHOLD = 20
    private val CRITICAL_BATTERY_THRESHOLD = 10
    private var isLowBatteryMode = false

    // Screen visibility boost (from WebView JS interface)
    // This is an EXTRA on top of core tracking, not a dependency
    @Volatile
    var isScreenVisible = false
        private set

    // Auth failure tracking
    private var consecutiveAuthFailures = 0
    private val MAX_AUTH_FAILURES = 5
    private var hasShownAuthFailureNotification = false

    // Token refresh lock
    @Volatile
    private var isRefreshingToken = false
    private val tokenRefreshLock = Object()

    companion object {
        const val ACTION_START_TRACKING = "ACTION_START_TRACKING"
        const val ACTION_STOP_TRACKING = "ACTION_STOP_TRACKING"
        const val ACTION_PAUSE_TRACKING = "ACTION_PAUSE_TRACKING"
        const val ACTION_RESUME_TRACKING = "ACTION_RESUME_TRACKING"
        const val ACTION_SCREEN_VISIBLE = "ACTION_SCREEN_VISIBLE"
        const val ACTION_SCREEN_HIDDEN = "ACTION_SCREEN_HIDDEN"
        private const val TAG = "TrackingService"
        private const val NOTIF_COUNT_URL = "https://www.relatives.co.za/notifications/api/count.php"
        private const val BASE_URL = "https://www.relatives.co.za"
        private const val UPLOAD_URL = "https://www.relatives.co.za/tracking/api/update_location.php"

        // Mode intervals
        // Note: IDLE fires every 2min but with 100m gate, so stationary users only get heartbeat fixes
        private const val IDLE_INTERVAL_MS = 2 * 60 * 1000L       // 2 minutes (+ distance gate for stationary)
        private const val IDLE_MIN_DISTANCE_M = 100f               // 100m distance gate
        // MOVING interval comes from server via PreferencesManager.getUpdateInterval()
        private const val MOVING_MIN_DISTANCE_M = 20f              // 20m distance gate
        private const val BURST_INTERVAL_MS = 5 * 1000L            // 5 seconds (fast sampling)
        private const val SCREEN_BOOST_INTERVAL_MS = 15 * 1000L    // 15s when screen visible

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
                    onLocationReceived(location)
                }
            }
        }

        notificationHandler.post(notificationRunnable)
    }

    override fun onDestroy() {
        super.onDestroy()
        notificationHandler.removeCallbacks(notificationRunnable)
        serviceScope.cancel()
        serviceLooper?.quit()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START_TRACKING -> handleStartTracking()
            ACTION_STOP_TRACKING -> handleStopTracking()
            ACTION_PAUSE_TRACKING -> pauseTracking()
            ACTION_RESUME_TRACKING -> resumeTracking()
            ACTION_SCREEN_VISIBLE -> onScreenVisible()
            ACTION_SCREEN_HIDDEN -> onScreenHidden()
        }
        return START_STICKY
    }

    // ========== LIFECYCLE ==========

    private fun handleStartTracking() {
        if (isTracking) return
        isTracking = true
        isPaused = false
        consecutiveIdleCount = 0
        consecutiveMovingCount = 0
        consecutiveAuthFailures = 0
        hasShownAuthFailureNotification = false
        lastReportedMovingStatus = true
        currentMode = TrackingMode.BURST  // Start with BURST to get initial good fix
        burstStartTime = System.currentTimeMillis()
        burstFixCount = 0
        PreferencesManager.setTrackingEnabled(true)

        val notification = NotificationHelper.buildTrackingNotification(this, false)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NotificationHelper.NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        } else {
            startForeground(NotificationHelper.NOTIFICATION_ID, notification)
        }

        acquireWakeLock()
        startLocationUpdates()
        startHeartbeatTimer()

        // Schedule WorkManager for pending uploads
        LocationUploadWorker.enqueue(this)

        // Schedule service checker for aggressive OEMs
        za.co.relatives.app.workers.TrackingServiceChecker.schedule(this)

        // Schedule AlarmManager keepalive (every 5 min - more reliable than WorkManager)
        scheduleKeepAlive()

        Log.d(TAG, "Tracking started (initial mode: BURST -> will settle to IDLE/MOVING)")
    }

    private fun handleStopTracking() {
        isTracking = false
        isPaused = false
        PreferencesManager.setTrackingEnabled(false)
        stopLocationUpdates()
        stopHeartbeatTimer()
        releaseWakeLock()
        cancelKeepAlive()

        za.co.relatives.app.workers.TrackingServiceChecker.cancel(this)

        // Flush any remaining queued locations
        LocationUploadWorker.enqueue(this)

        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
        Log.d(TAG, "Tracking stopped")
    }

    private fun pauseTracking() {
        if (!isTracking || isPaused) return
        isPaused = true
        stopLocationUpdates()
        releaseWakeLock()
        NotificationHelper.updateTrackingNotification(this, isPaused)
        Log.d(TAG, "Tracking paused")
    }

    private fun resumeTracking() {
        if (!isTracking || !isPaused) return
        isPaused = false
        acquireWakeLock()
        // Resume with BURST to get a fresh fix
        enterBurstMode("resume from pause")
        NotificationHelper.updateTrackingNotification(this, isPaused)
        Log.d(TAG, "Tracking resumed")
    }

    // ========== SCREEN VISIBILITY (boost, not dependency) ==========

    private fun onScreenVisible() {
        isScreenVisible = true
        if (isTracking && !isPaused) {
            // Boost: shorter interval while user is watching the map
            Log.d(TAG, "Screen visible - boosting update rate")
            restartLocationUpdates()
        }
    }

    private fun onScreenHidden() {
        isScreenVisible = false
        if (isTracking && !isPaused) {
            // Revert to normal mode-based interval
            Log.d(TAG, "Screen hidden - reverting to mode-based interval")
            restartLocationUpdates()
        }
    }

    // ========== LOCATION UPDATES (3-mode engine) ==========

    @SuppressLint("MissingPermission")
    private fun startLocationUpdates() {
        if (!hasLocationPermission() || isPaused) return

        val batteryLevel = getBatteryLevel() ?: 100
        isLowBatteryMode = batteryLevel < LOW_BATTERY_THRESHOLD
        val isCriticalBattery = batteryLevel < CRITICAL_BATTERY_THRESHOLD

        val request = when {
            isCriticalBattery -> {
                // Critical battery: passive only
                Log.d(TAG, "CRITICAL BATTERY mode (${batteryLevel}%): passive, 5min")
                LocationRequest.Builder(Priority.PRIORITY_PASSIVE, 5 * 60 * 1000L)
                    .setMinUpdateIntervalMillis(3 * 60 * 1000L)
                    .setMinUpdateDistanceMeters(500f)
                    .build()
            }
            isLowBatteryMode -> {
                // Low battery: balanced, 3min
                Log.d(TAG, "LOW BATTERY mode (${batteryLevel}%): balanced, 3min")
                LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, 3 * 60 * 1000L)
                    .setMinUpdateIntervalMillis(60 * 1000L)
                    .setMinUpdateDistanceMeters(200f)
                    .build()
            }
            currentMode == TrackingMode.BURST -> {
                // BURST: high accuracy, fast sampling, no distance gate
                Log.d(TAG, "BURST mode: high accuracy, 5s, no distance gate")
                LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, BURST_INTERVAL_MS)
                    .setMinUpdateIntervalMillis(3 * 1000L)
                    .build()
            }
            currentMode == TrackingMode.MOVING -> {
                // MOVING: high accuracy with distance gate
                val intervalMs = if (isScreenVisible) SCREEN_BOOST_INTERVAL_MS else {
                    PreferencesManager.getUpdateInterval() * 1000L
                }
                Log.d(TAG, "MOVING mode: high accuracy, ${intervalMs/1000}s, ${MOVING_MIN_DISTANCE_M}m gate")
                LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, intervalMs)
                    .setMinUpdateIntervalMillis(intervalMs / 2)
                    .setMinUpdateDistanceMeters(MOVING_MIN_DISTANCE_M)
                    .build()
            }
            else -> {
                // IDLE: balanced power, large distance gate
                val intervalMs = if (isScreenVisible) SCREEN_BOOST_INTERVAL_MS else IDLE_INTERVAL_MS
                Log.d(TAG, "IDLE mode: balanced, ${intervalMs/1000}s, ${IDLE_MIN_DISTANCE_M}m gate")
                LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, intervalMs)
                    .setMinUpdateIntervalMillis(60 * 1000L)
                    .setMinUpdateDistanceMeters(IDLE_MIN_DISTANCE_M)
                    .build()
            }
        }

        fusedLocationClient.requestLocationUpdates(request, locationCallback, serviceLooper)
    }

    private fun stopLocationUpdates() {
        fusedLocationClient.removeLocationUpdates(locationCallback)
    }

    private fun restartLocationUpdates() {
        stopLocationUpdates()
        startLocationUpdates()
    }

    // ========== LOCATION RECEIVED (core logic) ==========

    private fun onLocationReceived(location: Location) {
        val accuracyM = location.accuracy

        // Calculate raw speed
        var speedKmh = if (location.hasSpeed() && location.speed > 0) {
            location.speed * 3.6f
        } else {
            calculateSpeedFromDistance(location)
        }

        // Movement detection - account for GPS accuracy in distance check
        val distanceMoved = lastLocation?.distanceTo(location) ?: 0f
        // Only count distance as movement if it exceeds accuracy circle
        // GPS drift within accuracy is NOT real movement
        val effectiveDistance = if (distanceMoved > accuracyM) distanceMoved else 0f
        val isMovingBySpeed = speedKmh > SPEED_THRESHOLD_KMH
        val isMovingByDistance = effectiveDistance > DISTANCE_THRESHOLD_M
        val currentlyMoving = isMovingBySpeed || isMovingByDistance

        // Smoothed is_moving flag
        val isMoving: Boolean
        if (currentlyMoving) {
            consecutiveIdleForStatus = 0
            isMoving = true
            lastReportedMovingStatus = true
        } else {
            consecutiveIdleForStatus++
            isMoving = if (consecutiveIdleForStatus >= MOVING_STATUS_HOLD_COUNT) {
                lastReportedMovingStatus = false
                false
            } else {
                lastReportedMovingStatus
            }
        }

        // CLAMP SPEED TO 0 when not actually moving
        // GPS drift causes 3-5 km/h "phantom speed" - don't report it
        if (!isMoving) {
            speedKmh = 0f
        }

        Log.d(TAG, "Fix: acc=${accuracyM.toInt()}m speed=${speedKmh.toInt()}km/h dist=${distanceMoved.toInt()}m effective=${effectiveDistance.toInt()}m moving=$isMoving mode=$currentMode")

        // ========== QUEUE TO ROOM (always - collection is decoupled from upload) ==========
        queueLocation(location, isMoving, speedKmh)

        // ========== ALSO TRY IMMEDIATE UPLOAD (if network available) ==========
        if (isNetworkAvailable()) {
            uploadLocationDirect(location, isMoving, speedKmh)
        } else {
            // Enqueue WorkManager to flush when network comes back
            LocationUploadWorker.enqueue(this)
        }

        // Update local state
        lastLocation = location
        lastLocationTime = System.currentTimeMillis()

        // ========== MODE TRANSITION LOGIC ==========
        updateTrackingMode(speedKmh, isMoving, accuracyM)
    }

    // ========== ROOM QUEUE (replaces SharedPreferences) ==========

    private fun queueLocation(location: Location, isMoving: Boolean, speedKmh: Float) {
        val entity = QueuedLocationEntity(
            latitude = location.latitude,
            longitude = location.longitude,
            accuracy = location.accuracy,
            altitude = if (location.hasAltitude()) location.altitude else null,
            bearing = if (location.hasBearing()) location.bearing else null,
            speed = if (location.hasSpeed()) location.speed else null,
            isMoving = isMoving,
            speedKmh = speedKmh,
            batteryLevel = getBatteryLevel(),
            timestamp = System.currentTimeMillis()
        )

        serviceScope.launch {
            try {
                val dao = TrackingDatabase.getInstance(applicationContext).queuedLocationDao()
                dao.insert(entity)
                dao.trimToMaxSize()  // Keep max 300
            } catch (e: Exception) {
                Log.e(TAG, "Failed to queue location to Room", e)
            }
        }
    }

    // ========== DIRECT UPLOAD (for immediate feedback when online) ==========

    private val httpClient = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .build()
    private val jsonMediaType = "application/json; charset=utf-8".toMediaType()

    private fun uploadLocationDirect(location: Location, isMoving: Boolean, speedKmh: Float) {
        val sessionToken = PreferencesManager.sessionToken
        if (sessionToken.isNullOrBlank()) {
            refreshSessionToken()
            return
        }

        val deviceUuid = PreferencesManager.getDeviceUuid()
        val clientEventId = UUID.randomUUID().toString()

        val body = JSONObject().apply {
            put("device_uuid", deviceUuid)
            put("platform", "android")
            put("device_name", Build.MODEL)
            put("network_status", getNetworkStatus())
            put("location_status", getLocationStatus())
            put("permission_status", getPermissionStatus())
            put("app_state", if (isScreenVisible) "foreground" else "background")
            put("battery_level", getBatteryLevel() ?: JSONObject.NULL)
            put("latitude", location.latitude)
            put("longitude", location.longitude)
            put("accuracy_m", location.accuracy.toInt())
            put("speed_kmh", speedKmh)
            put("heading_deg", if (location.hasBearing()) location.bearing else 0)
            put("altitude_m", if (location.hasAltitude()) location.altitude else JSONObject.NULL)
            put("is_moving", isMoving)
            put("tracking_mode", currentMode.name.lowercase())
            put("source", "native")
            put("session_token", sessionToken)
            put("client_event_id", clientEventId)
            put("client_timestamp", System.currentTimeMillis())
        }

        val request = Request.Builder()
            .url(UPLOAD_URL)
            .addHeader("Content-Type", "application/json")
            .addHeader("Authorization", "Bearer $sessionToken")
            .addHeader("User-Agent", "RelativesAndroid/1.0")
            .post(body.toString().toRequestBody(jsonMediaType))
            .build()

        httpClient.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                Log.w(TAG, "Direct upload failed (network) - WorkManager will retry")
            }

            override fun onResponse(call: Call, response: Response) {
                response.use { resp ->
                    val responseBody = try { resp.body?.string() } catch (e: Exception) { null }
                    when {
                        resp.isSuccessful -> {
                            lastUploadTime = System.currentTimeMillis()
                            PreferencesManager.lastUploadTime = lastUploadTime
                            consecutiveAuthFailures = 0

                            // Parse response
                            val json = try { JSONObject(responseBody ?: "{}") } catch (e: Exception) { JSONObject() }

                            // Apply server settings
                            try {
                                applyServerSettings(json.optJSONObject("server_settings"))
                            } catch (e: Exception) { /* non-fatal */ }

                            // Only mark as sent if NOT rate-limited (otherwise data is lost)
                            val wasRateLimited = json.optBoolean("rate_limited", false)
                            if (!wasRateLimited) {
                                serviceScope.launch {
                                    try {
                                        val dao = TrackingDatabase.getInstance(applicationContext).queuedLocationDao()
                                        dao.markSent(listOf(clientEventId))
                                        dao.deleteSent()
                                    } catch (e: Exception) { /* non-fatal */ }
                                }
                            }
                        }
                        resp.code in listOf(401, 403) -> {
                            Log.w(TAG, "Direct upload auth failure (${resp.code})")
                            refreshSessionToken()
                        }
                        // Other failures: WorkManager will retry from Room queue
                    }
                }
            }
        })
    }

    // ========== MODE TRANSITION LOGIC ==========

    private fun updateTrackingMode(speedKmh: Float, isMoving: Boolean, accuracyM: Float) {
        val previousMode = currentMode

        when (currentMode) {
            TrackingMode.BURST -> {
                burstFixCount++
                val burstElapsed = System.currentTimeMillis() - burstStartTime

                // Exit BURST when we have enough fixes or time elapsed
                if (burstFixCount >= BURST_MAX_FIXES || burstElapsed >= BURST_DURATION_MS) {
                    // Decide: settle into MOVING or IDLE
                    currentMode = if (isMoving || speedKmh > SPEED_THRESHOLD_KMH) {
                        TrackingMode.MOVING
                    } else {
                        TrackingMode.IDLE
                    }
                    Log.d(TAG, "BURST complete (${burstFixCount} fixes, ${burstElapsed}ms) -> $currentMode")
                }
            }
            TrackingMode.IDLE -> {
                if (isMoving || speedKmh > SPEED_THRESHOLD_KMH) {
                    consecutiveMovingCount++
                    if (consecutiveMovingCount >= 1) {
                        // Movement detected - enter BURST to confirm
                        enterBurstMode("movement detected from IDLE")
                    }
                } else {
                    consecutiveMovingCount = 0
                }

                // If accuracy is bad (>100m), enter BURST to try for better fix
                if (accuracyM > 100 && !isLowBatteryMode) {
                    enterBurstMode("bad accuracy in IDLE (${accuracyM.toInt()}m)")
                }
            }
            TrackingMode.MOVING -> {
                if (!isMoving && speedKmh < SPEED_THRESHOLD_KMH) {
                    consecutiveIdleCount++
                    if (consecutiveIdleCount >= IDLE_COUNT_THRESHOLD) {
                        currentMode = TrackingMode.IDLE
                        consecutiveIdleCount = 0
                        Log.d(TAG, "Switched to IDLE after $IDLE_COUNT_THRESHOLD slow readings")
                    }
                } else {
                    consecutiveIdleCount = 0
                }
            }
        }

        // Restart location updates if mode changed
        if (previousMode != currentMode && isTracking && !isPaused) {
            mainHandler.post {
                restartLocationUpdates()
                stopHeartbeatTimer()
                startHeartbeatTimer()
            }
        }
    }

    private fun enterBurstMode(reason: String) {
        currentMode = TrackingMode.BURST
        burstStartTime = System.currentTimeMillis()
        burstFixCount = 0
        consecutiveIdleCount = 0
        consecutiveMovingCount = 0
        Log.d(TAG, "Entering BURST mode: $reason")

        if (isTracking && !isPaused) {
            mainHandler.post { restartLocationUpdates() }
        }
    }

    // ========== HEARTBEAT ==========

    private fun startHeartbeatTimer() {
        stopHeartbeatTimer()
        heartbeatRunnable = Runnable {
            Log.d(TAG, "Heartbeat timer fired (mode: $currentMode)")
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
        if (!hasLocationPermission()) return

        try {
            fusedLocationClient.getCurrentLocation(Priority.PRIORITY_BALANCED_POWER_ACCURACY, null)
                .addOnSuccessListener { location ->
                    location?.let { onLocationReceived(it) }
                        ?: Log.w(TAG, "Heartbeat location null")
                }
                .addOnFailureListener { e ->
                    Log.w(TAG, "Heartbeat location failed", e)
                }
        } catch (e: SecurityException) {
            Log.e(TAG, "Heartbeat security exception", e)
        }
    }

    // ========== SERVER SETTINGS ==========

    private fun applyServerSettings(settings: JSONObject?) {
        try {
            val intervalChanged = PreferencesManager.applyServerSettings(settings)
            if (intervalChanged && currentMode == TrackingMode.MOVING && isTracking && !isPaused) {
                mainHandler.post { restartLocationUpdates() }
            }
        } catch (e: Exception) {
            Log.w(TAG, "Error applying server settings", e)
        }
    }

    // ========== AUTH ==========

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
                val cookies = cookieManager.getCookie("https://www.relatives.co.za")

                if (cookies.isNullOrBlank()) {
                    showAuthFailureNotification()
                    synchronized(tokenRefreshLock) { isRefreshingToken = false }
                    return@post
                }

                Thread {
                    try {
                        val url = java.net.URL("https://www.relatives.co.za/api/session-token.php")
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
                                    consecutiveAuthFailures = 0
                                    hasShownAuthFailureNotification = false
                                    Log.d(TAG, "Session token refreshed")
                                    LocationUploadWorker.enqueue(applicationContext)
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
                Log.e(TAG, "Token refresh cookie error", e)
                synchronized(tokenRefreshLock) { isRefreshingToken = false }
            }
        }
    }

    private fun showAuthFailureNotification() {
        if (hasShownAuthFailureNotification) return
        hasShownAuthFailureNotification = true

        val intent = Intent(this, za.co.relatives.app.MainActivity::class.java).apply {
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

    // ========== UTILITY ==========

    private fun calculateSpeedFromDistance(currentLocation: Location): Float {
        val prevLocation = lastLocation ?: return 0f
        val prevTime = lastLocationTime
        if (prevTime == 0L) return 0f
        val distanceM = prevLocation.distanceTo(currentLocation)
        val timeSeconds = (System.currentTimeMillis() - prevTime) / 1000f
        if (timeSeconds <= 0) return 0f
        return (distanceM / timeSeconds) * 3.6f
    }

    private fun getBatteryLevel(): Int? {
        return try {
            val bm = getSystemService(BATTERY_SERVICE) as BatteryManager
            bm.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
        } catch (e: Exception) { null }
    }

    private fun isNetworkAvailable(): Boolean {
        return try {
            val cm = getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
            val network = cm.activeNetwork ?: return false
            val caps = cm.getNetworkCapabilities(network) ?: return false
            caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
        } catch (e: Exception) { true }
    }

    private fun hasLocationPermission(): Boolean {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
               ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
    }

    private fun getNetworkStatus(): String {
        return try {
            val cm = getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
            val caps = cm.getNetworkCapabilities(cm.activeNetwork)
            if (caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED) == true) "online" else "offline"
        } catch (e: Exception) { "online" }
    }

    private fun getLocationStatus(): String {
        val lm = getSystemService(Context.LOCATION_SERVICE) as android.location.LocationManager
        return if (lm.isProviderEnabled(android.location.LocationManager.GPS_PROVIDER) ||
                   lm.isProviderEnabled(android.location.LocationManager.NETWORK_PROVIDER)) "enabled" else "disabled"
    }

    private fun getPermissionStatus(): String {
        return if (hasLocationPermission()) "granted" else "denied"
    }

    @SuppressLint("WakelockTimeout")
    private fun acquireWakeLock() {
        if (wakeLock == null) {
            val pm = getSystemService(Context.POWER_SERVICE) as PowerManager
            wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "$TAG:WakeLock")
        }
        if (wakeLock?.isHeld == false) {
            wakeLock?.acquire()
        }
    }

    private fun releaseWakeLock() {
        if (wakeLock?.isHeld == true) {
            wakeLock?.release()
        }
    }

    // ========== KEEPALIVE ALARM (safety net for OEM kills) ==========

    private fun scheduleKeepAlive() {
        val alarmManager = getSystemService(Context.ALARM_SERVICE) as android.app.AlarmManager
        val intent = Intent(this, TrackingLocationService::class.java).apply {
            action = ACTION_START_TRACKING
        }
        val pendingIntent = PendingIntent.getService(
            this, 2, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        // Fire every 5 minutes - if service is alive, handleStartTracking returns immediately
        // If service was killed, this restarts it
        alarmManager.setRepeating(
            android.app.AlarmManager.ELAPSED_REALTIME_WAKEUP,
            android.os.SystemClock.elapsedRealtime() + 5 * 60 * 1000L,
            5 * 60 * 1000L,
            pendingIntent
        )
        Log.d(TAG, "Keepalive alarm scheduled (every 5 min)")
    }

    private fun cancelKeepAlive() {
        val alarmManager = getSystemService(Context.ALARM_SERVICE) as android.app.AlarmManager
        val intent = Intent(this, TrackingLocationService::class.java).apply {
            action = ACTION_START_TRACKING
        }
        val pendingIntent = PendingIntent.getService(
            this, 2, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        alarmManager.cancel(pendingIntent)
        Log.d(TAG, "Keepalive alarm cancelled")
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        super.onTaskRemoved(rootIntent)
        if (PreferencesManager.isTrackingEnabled()) {
            val restartIntent = Intent(applicationContext, TrackingLocationService::class.java).apply {
                action = ACTION_START_TRACKING
            }
            val pendingIntent = PendingIntent.getService(
                applicationContext, 1, restartIntent,
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

    override fun onBind(intent: Intent?): IBinder? = null

    // ========== NOTIFICATION POLLING ==========

    private fun checkNotifications() {
        var cookie = CookieManager.getInstance().getCookie(NOTIF_COUNT_URL)
        if (cookie.isNullOrEmpty()) {
            cookie = CookieManager.getInstance().getCookie(BASE_URL)
        }
        if (cookie.isNullOrEmpty()) return

        val request = Request.Builder()
            .url(NOTIF_COUNT_URL)
            .addHeader("Cookie", cookie)
            .get()
            .build()

        NetworkClient.client.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                Log.e(TAG, "Notification check failed", e)
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
                                        this@TrackingLocationService, count, latestTitle, latestMessage
                                    )
                                }
                                PreferencesManager.setLastNotificationCount(count)
                            }
                        } catch (e: Exception) {
                            Log.e(TAG, "Notification parse error", e)
                        }
                    }
                }
            }
        })
    }
}
