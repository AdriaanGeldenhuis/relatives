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
import org.json.JSONObject
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
    private lateinit var uploader: LocationUploader
    private var isTracking = false
    private var isPaused = false

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

    private enum class TrackingMode { MOVING, IDLE }
    private var currentMode = TrackingMode.IDLE
    private var lastUploadTime: Long = 0
    private var consecutiveIdleCount = 0
    private var consecutiveIdleForStatus = 0  // Separate counter for is_moving flag smoothing
    private var lastReportedMovingStatus = false  // Track what we last sent to server
    private var lastLocation: Location? = null
    private var lastLocationTime: Long = 0
    private val SPEED_THRESHOLD_KMH = 5.0f
    private val DISTANCE_THRESHOLD_M = 50.0f  // Consider moving if moved > 50m
    private val IDLE_COUNT_THRESHOLD = 3  // Need 3 consecutive slow readings to switch to idle
    private val MOVING_STATUS_HOLD_COUNT = 5  // Keep reporting "moving" for 5 readings after stopping (prevents traffic light flicker)
    private val MAX_REASONABLE_SPEED_KMH = 250.0f  // Cap speed at 250 km/h (faster = GPS glitch)

    // Smart Battery Mode
    private val LOW_BATTERY_THRESHOLD = 20
    private val CRITICAL_BATTERY_THRESHOLD = 10
    private var isLowBatteryMode = false

    // Offline Queue - store locations when network unavailable
    // Now persisted to SharedPreferences to survive service restarts
    private val offlineQueue = mutableListOf<QueuedLocation>()
    private val MAX_QUEUE_SIZE = 100  // Increased from 50 to match server batch limit
    private val gson = Gson()

    // Exponential backoff for retries
    private var retryDelayMs = 2000L  // Start at 2 seconds
    private val MAX_RETRY_DELAY_MS = 60000L  // Max 60 seconds
    private var consecutiveFailures = 0

    // Fix #5: Token refresh race condition prevention
    @Volatile
    private var isRefreshingToken = false
    private val tokenRefreshLock = Object()

    // Serializable version of QueuedLocation for persistence
    // Using lat/lng instead of Location object for JSON serialization
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
        val clientEventId: String = UUID.randomUUID().toString(),  // For idempotent uploads
        val retryCount: Int = 0  // Track retry attempts
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
        private const val NOTIF_COUNT_URL = "https://www.relatives.co.za/notifications/api/count.php"
        private const val BASE_URL = "https://www.relatives.co.za"

        // MOVING: Fast updates when traveling (30s like Life360)
        private const val DEFAULT_MOVING_INTERVAL_MS = 30 * 1000L
        // IDLE: Check every 60 seconds to quickly detect when movement starts
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
        uploader = LocationUploader(this)

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

        // Fix #6: Load persisted offline queue on service start
        loadPersistedQueue()

        // Start notification polling (existing functionality - works 100%)
        notificationHandler.post(notificationRunnable)
    }

    override fun onDestroy() {
        super.onDestroy()
        notificationHandler.removeCallbacks(notificationRunnable)
        serviceLooper?.quit()
    }

    // ========== Fix #6: Persist offline queue to survive service restarts ==========

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

    private fun restartTrackingWithNewInterval() {
        if (isTracking && !isPaused) {
            stopLocationUpdates()
            startLocationUpdates()
        }
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
        startLocationUpdates()
        NotificationHelper.updateTrackingNotification(this, isPaused)
        Log.d(TAG, "Tracking resumed")
    }


    private fun handleStartTracking() {
        if (isTracking) return
        isTracking = true
        isPaused = false
        consecutiveIdleCount = 0
        consecutiveIdleForStatus = 0
        consecutiveAuthFailures = 0
        hasShownAuthFailureNotification = false
        lastReportedMovingStatus = true  // Assume moving initially
        currentMode = TrackingMode.MOVING  // START IN MOVING MODE - detect idle later (like Life360)
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

        // Request immediate location to determine initial mode quickly
        requestImmediateLocation()

        // Schedule WorkManager backup checker for aggressive OEMs (Huawei, Honor, Xiaomi)
        // This will restart the service if it gets killed by battery optimization
        za.co.relatives.app.workers.TrackingServiceChecker.schedule(this)

        Log.d(TAG, "Tracking started (initial mode: MOVING, will switch to IDLE after 3 slow readings)")
    }

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

    private fun handleStopTracking() {
        isTracking = false
        isPaused = false
        PreferencesManager.setTrackingEnabled(false)
        stopLocationUpdates()
        stopHeartbeatTimer()
        releaseWakeLock()

        // Cancel WorkManager backup checker
        za.co.relatives.app.workers.TrackingServiceChecker.cancel(this)

        stopForeground(true)
        stopSelf()
        Log.d(TAG, "Tracking stopped")
    }

    @SuppressLint("MissingPermission")
    private fun startLocationUpdates() {
        if (!hasLocationPermission() || isPaused) {
            Log.w(TAG, "No location permission or tracking is paused, cannot start updates.")
            return
        }

        // Check battery level for smart battery mode
        val batteryLevel = getBatteryLevel() ?: 100
        isLowBatteryMode = batteryLevel < LOW_BATTERY_THRESHOLD
        val isCriticalBattery = batteryLevel < CRITICAL_BATTERY_THRESHOLD

        val request = when {
            isCriticalBattery -> {
                // Critical battery: Very infrequent updates (5 min) to preserve battery
                Log.d(TAG, "Starting CRITICAL BATTERY updates (battery: $batteryLevel%, interval: 5min)")
                LocationRequest.Builder(Priority.PRIORITY_PASSIVE, 5 * 60 * 1000L)
                    .setMinUpdateIntervalMillis(3 * 60 * 1000L)
                    .build()
            }
            isLowBatteryMode -> {
                // Low battery: Reduce frequency (3 min for moving, 5 min for idle)
                val intervalMs = if (currentMode == TrackingMode.MOVING) 3 * 60 * 1000L else 5 * 60 * 1000L
                Log.d(TAG, "Starting LOW BATTERY updates (battery: $batteryLevel%, mode: $currentMode, interval: ${intervalMs/1000}s)")
                LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, intervalMs)
                    .setMinUpdateIntervalMillis(intervalMs / 2)
                    .build()
            }
            currentMode == TrackingMode.MOVING -> {
                // Normal moving: Use server interval with high accuracy
                val intervalMs = PreferencesManager.getUpdateInterval() * 1000L
                Log.d(TAG, "Starting MOVING updates (interval: ${intervalMs/1000}s)")
                LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, intervalMs)
                    .setMinUpdateIntervalMillis(intervalMs / 2)
                    .build()
            }
            else -> {
                // Idle: Check for movement every 2 min
                Log.d(TAG, "Starting IDLE updates (interval: ${IDLE_INTERVAL_MS/1000}s)")
                LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, IDLE_INTERVAL_MS)
                    .setMinUpdateIntervalMillis(60 * 1000L)
                    .build()
            }
        }
        fusedLocationClient.requestLocationUpdates(request, locationCallback, serviceLooper)
    }

    private fun getBatteryLevel(): Int? {
        return try {
            val bm = getSystemService(BATTERY_SERVICE) as BatteryManager
            bm.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
        } catch (e: Exception) { null }
    }

    private fun stopLocationUpdates() {
        fusedLocationClient.removeLocationUpdates(locationCallback)
    }

    private fun uploadLocation(location: Location) {
        // Calculate speed - prefer GPS speed, but calculate from distance if unavailable
        var speedKmh = if (location.hasSpeed() && location.speed > 0) {
            location.speed * 3.6f
        } else {
            calculateSpeedFromDistance(location)
        }

        // SANITY CHECK: Cap speed at reasonable maximum (GPS glitches can cause 400+ km/h readings)
        // Also ignore high speeds when GPS accuracy is poor (> 100m)
        if (speedKmh > MAX_REASONABLE_SPEED_KMH || (speedKmh > 100 && location.accuracy > 100)) {
            Log.w(TAG, "Speed sanity check: ${speedKmh.toInt()} km/h capped (accuracy: ${location.accuracy}m)")
            speedKmh = 0f  // Reset to 0 - clearly a GPS glitch
        }

        // Also check if we've moved significant distance (for cell tower positioning)
        val distanceMoved = lastLocation?.distanceTo(location) ?: 0f
        val isMovingBySpeed = speedKmh > SPEED_THRESHOLD_KMH
        val isMovingByDistance = distanceMoved > DISTANCE_THRESHOLD_M
        val currentlyMoving = isMovingBySpeed || isMovingByDistance

        // SMOOTHED is_moving flag - prevents flickering at traffic lights
        // Switch TO moving: immediately
        // Switch TO idle: only after MOVING_STATUS_HOLD_COUNT consecutive non-moving readings
        val isMoving: Boolean
        if (currentlyMoving) {
            // Currently moving - report moving immediately, reset counter
            consecutiveIdleForStatus = 0
            isMoving = true
            lastReportedMovingStatus = true
        } else {
            // Currently not moving - but hold "moving" status for a while
            consecutiveIdleForStatus++
            if (consecutiveIdleForStatus >= MOVING_STATUS_HOLD_COUNT) {
                // Enough consecutive idle readings - now report as idle
                isMoving = false
                lastReportedMovingStatus = false
            } else {
                // Still in hold period - keep reporting as moving
                isMoving = lastReportedMovingStatus
                Log.d(TAG, "Holding moving status (${consecutiveIdleForStatus}/$MOVING_STATUS_HOLD_COUNT idle readings)")
            }
        }

        // If moving by distance but speed is 0, estimate speed
        if (isMovingByDistance && speedKmh < SPEED_THRESHOLD_KMH) {
            speedKmh = calculateSpeedFromDistance(location)
        }

        Log.d(TAG, "Location: speed=${speedKmh.toInt()} km/h, distance=${distanceMoved.toInt()}m, isMoving=$isMoving (raw=$currentlyMoving, holdCount=$consecutiveIdleForStatus)")

        // Check if network is available
        if (!isNetworkAvailable()) {
            queueLocationForLater(location, isMoving, speedKmh)
            // Still update local state
            lastLocation = location
            lastLocationTime = System.currentTimeMillis()
            updateTrackingMode(speedKmh, isMoving)
            return
        }

        // First, try to send any queued locations
        sendQueuedLocations()

        uploader.uploadLocation(
            location = location,
            isMoving = isMoving,
            speedKmh = speedKmh,
            onSuccess = { serverSettings ->
                lastUploadTime = System.currentTimeMillis()
                PreferencesManager.lastUploadTime = lastUploadTime  // Persist for WorkManager checker
                consecutiveAuthFailures = 0  // Reset auth failure counter on success
                // Store this location for next distance calculation
                lastLocation = location
                lastLocationTime = System.currentTimeMillis()
                applyServerSettings(serverSettings)
                updateTrackingMode(speedKmh, isMoving)
                Log.d(TAG, "Location uploaded successfully (speed: ${speedKmh.toInt()} km/h, mode: $currentMode)")
            },
            onAuthFailure = {
                Log.w(TAG, "Upload auth failure - queuing location and attempting to refresh token")
                // CRITICAL: Queue the location so it's not lost
                queueLocationForLater(location, isMoving, speedKmh)
                refreshSessionToken()
            },
            onTransientFailure = {
                // Queue for later if upload failed
                queueLocationForLater(location, isMoving, speedKmh)
            }
        )
    }

    private fun isNetworkAvailable(): Boolean {
        return try {
            val connectivityManager = getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
            val network = connectivityManager.activeNetwork ?: return false
            val capabilities = connectivityManager.getNetworkCapabilities(network) ?: return false
            capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
        } catch (e: Exception) {
            true // Assume available if we can't check
        }
    }

    private fun queueLocationForLater(location: Location, isMoving: Boolean, speedKmh: Float) {
        synchronized(offlineQueue) {
            // Add to queue using serializable format
            offlineQueue.add(QueuedLocation.fromLocation(location, isMoving, speedKmh))

            // Trim queue if too large (keep most recent)
            while (offlineQueue.size > MAX_QUEUE_SIZE) {
                offlineQueue.removeAt(0)
            }

            Log.d(TAG, "Location queued for later upload (queue size: ${offlineQueue.size})")

            // Persist queue to survive service restarts
            persistQueue()
        }
    }

    private fun sendQueuedLocations() {
        synchronized(offlineQueue) {
            if (offlineQueue.isEmpty()) return

            val queueSize = offlineQueue.size
            Log.d(TAG, "Sending $queueSize queued locations")

            // Fix #7: Use batch upload when there are multiple items (more efficient)
            if (queueSize > 3) {
                sendQueuedLocationsBatch()
            } else {
                sendQueuedLocationsIndividually()
            }
        }
    }

    // Fix #7: Batch upload for efficient queue flush
    private fun sendQueuedLocationsBatch() {
        synchronized(offlineQueue) {
            val toSend = offlineQueue.toList()
            offlineQueue.clear()
            persistQueue()

            Log.d(TAG, "Using batch upload for ${toSend.size} locations")

            uploader.uploadLocationBatch(
                locations = toSend,
                onSuccess = { serverSettings ->
                    consecutiveAuthFailures = 0
                    consecutiveFailures = 0
                    retryDelayMs = 2000L
                    PreferencesManager.lastUploadTime = System.currentTimeMillis()
                    applyServerSettings(serverSettings)
                    Log.d(TAG, "Batch upload successful (${toSend.size} locations)")
                },
                onPartialSuccess = { failedItems ->
                    // Some items failed - re-queue only the failed ones
                    Log.w(TAG, "Batch upload partial success - ${failedItems.size} items failed")
                    failedItems.forEach { reQueueLocationWithBackoff(it) }
                },
                onAuthFailure = {
                    Log.w(TAG, "Batch upload auth failure - re-queuing all")
                    toSend.forEach { reQueueLocationWithBackoff(it) }
                    refreshSessionToken()
                },
                onTransientFailure = {
                    Log.w(TAG, "Batch upload transient failure - re-queuing all with backoff")
                    toSend.forEach { reQueueLocationWithBackoff(it) }
                }
            )
        }
    }

    private fun sendQueuedLocationsIndividually() {
        synchronized(offlineQueue) {
            val toSend = offlineQueue.toList()
            offlineQueue.clear()
            persistQueue()

            toSend.forEach { queued ->
                uploader.uploadLocation(
                    location = queued.toLocation(),
                    isMoving = queued.isMoving,
                    speedKmh = queued.speedKmh,
                    clientEventId = queued.clientEventId,
                    onSuccess = {
                        consecutiveAuthFailures = 0
                        consecutiveFailures = 0
                        retryDelayMs = 2000L
                        Log.d(TAG, "Queued location uploaded successfully (id=${queued.clientEventId})")
                    },
                    onAuthFailure = {
                        Log.w(TAG, "Queued location auth failure - re-queuing (id=${queued.clientEventId})")
                        reQueueLocationWithBackoff(queued)
                    },
                    onTransientFailure = {
                        Log.w(TAG, "Queued location transient failure - re-queuing with backoff (id=${queued.clientEventId}, retry=${queued.retryCount})")
                        reQueueLocationWithBackoff(queued)
                    }
                )
            }
        }
    }

    private fun reQueueLocation(queued: QueuedLocation) {
        synchronized(offlineQueue) {
            offlineQueue.add(queued)  // Re-add with same clientEventId for idempotency
            while (offlineQueue.size > MAX_QUEUE_SIZE) {
                offlineQueue.removeAt(0)
            }
            persistQueue()  // Persist after re-queue
        }
    }

    private fun reQueueLocationWithBackoff(queued: QueuedLocation) {
        synchronized(offlineQueue) {
            consecutiveFailures++
            // Exponential backoff: 2s, 4s, 8s, 16s, 32s, max 60s
            retryDelayMs = (retryDelayMs * 2).coerceAtMost(MAX_RETRY_DELAY_MS)

            // Increment retry count for tracking
            val updatedQueued = queued.copy(retryCount = queued.retryCount + 1)
            offlineQueue.add(updatedQueued)

            while (offlineQueue.size > MAX_QUEUE_SIZE) {
                offlineQueue.removeAt(0)
            }

            persistQueue()  // Persist after re-queue

            Log.d(TAG, "Re-queued with backoff: ${retryDelayMs}ms delay, retry #${updatedQueued.retryCount}, queue size: ${offlineQueue.size}")

            // Schedule retry with backoff delay
            mainHandler.postDelayed({
                if (isNetworkAvailable()) {
                    sendQueuedLocations()
                }
            }, retryDelayMs)
        }
    }

    private fun calculateSpeedFromDistance(currentLocation: Location): Float {
        val prevLocation = lastLocation ?: return 0f
        val prevTime = lastLocationTime
        if (prevTime == 0L) return 0f

        val distanceM = prevLocation.distanceTo(currentLocation)
        val timeSeconds = (System.currentTimeMillis() - prevTime) / 1000f

        if (timeSeconds <= 0) return 0f

        // Speed in m/s, convert to km/h
        val speedMs = distanceM / timeSeconds
        return speedMs * 3.6f
    }

    private fun updateTrackingMode(speedKmh: Float, isMoving: Boolean) {
        val previousMode = currentMode

        if (isMoving || speedKmh > SPEED_THRESHOLD_KMH) {
            // Moving - switch to frequent updates immediately
            consecutiveIdleCount = 0
            if (currentMode != TrackingMode.MOVING) {
                currentMode = TrackingMode.MOVING
                Log.d(TAG, "Switched to MOVING mode (speed: ${speedKmh.toInt()} km/h, isMoving: $isMoving)")
            }
        } else {
            // Slow/stationary - need consecutive readings before switching to idle
            consecutiveIdleCount++
            if (consecutiveIdleCount >= IDLE_COUNT_THRESHOLD && currentMode != TrackingMode.IDLE) {
                currentMode = TrackingMode.IDLE
                Log.d(TAG, "Switched to IDLE mode after $consecutiveIdleCount slow readings")
            }
        }

        // Restart location updates if mode changed
        if (previousMode != currentMode && isTracking && !isPaused) {
            mainHandler.post {
                stopLocationUpdates()
                startLocationUpdates()
                // Also restart heartbeat with appropriate timing
                stopHeartbeatTimer()
                startHeartbeatTimer()
            }
        }
    }

    // Track consecutive auth failures to avoid infinite retry loops
    private var consecutiveAuthFailures = 0
    private val MAX_AUTH_FAILURES = 5
    private var hasShownAuthFailureNotification = false

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
        Log.w(TAG, "Showed auth failure notification to user")
    }

    private fun refreshSessionToken() {
        // Fix #5: Prevent race condition with synchronized lock
        synchronized(tokenRefreshLock) {
            if (isRefreshingToken) {
                Log.d(TAG, "Token refresh already in progress, skipping")
                return
            }
            isRefreshingToken = true
        }

        // Prevent infinite retry loops
        if (consecutiveAuthFailures >= MAX_AUTH_FAILURES) {
            Log.e(TAG, "Too many auth failures ($consecutiveAuthFailures) - user needs to re-login")
            showAuthFailureNotification()
            synchronized(tokenRefreshLock) { isRefreshingToken = false }
            return
        }
        consecutiveAuthFailures++

        // Fix #12: Get cookies on main thread first (some Android versions require this)
        mainHandler.post {
            try {
                val cookieManager = android.webkit.CookieManager.getInstance()
                val cookies = cookieManager.getCookie("https://www.relatives.co.za")

                if (cookies.isNullOrBlank()) {
                    Log.w(TAG, "No cookies available for token refresh - user needs to open app and login")
                    showAuthFailureNotification()
                    synchronized(tokenRefreshLock) { isRefreshingToken = false }
                    return@post
                }

                // Now do network call in background thread
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
                            val json = org.json.JSONObject(response)
                            if (json.optBoolean("success")) {
                                val token = json.optString("session_token")
                                if (token.isNotBlank()) {
                                    PreferencesManager.sessionToken = token
                                    Log.d(TAG, "Session token refreshed successfully - retrying queued locations")
                                    consecutiveAuthFailures = 0  // Reset on success
                                    hasShownAuthFailureNotification = false  // Reset notification flag
                                    // Now retry any queued locations with the fresh token
                                    mainHandler.post { sendQueuedLocations() }
                                }
                            }
                        } else if (connection.responseCode == 401) {
                            Log.w(TAG, "Session token refresh got 401 - session expired, user needs to re-login")
                            mainHandler.post { showAuthFailureNotification() }
                        }
                        connection.disconnect()
                    } catch (e: Exception) {
                        Log.e(TAG, "Failed to refresh session token", e)
                    } finally {
                        synchronized(tokenRefreshLock) { isRefreshingToken = false }
                    }
                }.start()
            } catch (e: Exception) {
                Log.e(TAG, "Failed to get cookies for token refresh", e)
                synchronized(tokenRefreshLock) { isRefreshingToken = false }
            }
        }
    }

    private fun requestHeartbeatLocation() {
        if (!hasLocationPermission()) {
            Log.w(TAG, "No location permission - sending state-only heartbeat")
            sendStateOnlyHeartbeat()
            return
        }
        if (!isLocationServicesEnabled()) {
            Log.w(TAG, "Location services disabled - sending state-only heartbeat")
            sendStateOnlyHeartbeat()
            return
        }

        try {
            fusedLocationClient.getCurrentLocation(Priority.PRIORITY_BALANCED_POWER_ACCURACY, null)
                .addOnSuccessListener { location ->
                    if (location != null) {
                        Log.d(TAG, "Heartbeat location received: lat=${location.latitude}")
                        uploadLocation(location)
                    } else {
                        Log.w(TAG, "Heartbeat location null - sending state-only")
                        sendStateOnlyHeartbeat()
                    }
                }
                .addOnFailureListener { e ->
                    Log.e(TAG, "Heartbeat location request failed - sending state-only", e)
                    sendStateOnlyHeartbeat()
                }
        } catch (e: SecurityException) {
            Log.e(TAG, "Security exception - sending state-only heartbeat", e)
            sendStateOnlyHeartbeat()
        }
    }

    @SuppressLint("MissingPermission")
    private fun sendStateOnlyHeartbeat() {
        // Try to get last known location first - better than no location at all
        if (hasLocationPermission()) {
            try {
                fusedLocationClient.lastLocation.addOnSuccessListener { lastKnownLocation ->
                    if (lastKnownLocation != null) {
                        // We have a cached location - use it instead of state-only
                        Log.d(TAG, "Using last known location for heartbeat")
                        uploadLocation(lastKnownLocation)
                    } else {
                        // No cached location - send state-only
                        sendPureHeartbeat()
                    }
                }.addOnFailureListener {
                    // Failed to get last location - send state-only
                    sendPureHeartbeat()
                }
            } catch (e: SecurityException) {
                sendPureHeartbeat()
            }
        } else {
            sendPureHeartbeat()
        }
    }

    private fun sendPureHeartbeat() {
        uploader.uploadHeartbeat(
            onSuccess = { serverSettings ->
                lastUploadTime = System.currentTimeMillis()
                PreferencesManager.lastUploadTime = lastUploadTime  // Persist for WorkManager checker
                consecutiveAuthFailures = 0  // Reset auth failure counter on success
                applyServerSettings(serverSettings)
                Log.d(TAG, "State-only heartbeat uploaded successfully")
            },
            onAuthFailure = {
                Log.w(TAG, "Heartbeat auth failure - attempting to refresh token")
                refreshSessionToken()
            },
            onTransientFailure = {
                Log.w(TAG, "Heartbeat transient failure")
            }
        )
    }

    private fun applyServerSettings(settings: JSONObject?) {
        settings ?: return
        try {
            settings.optInt("update_interval_seconds", 0).takeIf { it > 0 }?.let { newInterval ->
                val oldInterval = PreferencesManager.getUpdateInterval()
                PreferencesManager.setUpdateInterval(newInterval)
                // Restart location updates if interval changed and we're in MOVING mode
                if (newInterval != oldInterval && currentMode == TrackingMode.MOVING && isTracking && !isPaused) {
                    Log.d(TAG, "Server interval changed from ${oldInterval}s to ${newInterval}s, restarting updates")
                    mainHandler.post {
                        stopLocationUpdates()
                        startLocationUpdates()
                    }
                }
            }
            settings.optInt("idle_heartbeat_seconds", 0).takeIf { it > 0 }?.let {
                PreferencesManager.idleHeartbeatSeconds = it
                if (currentMode == TrackingMode.IDLE) {
                    stopHeartbeatTimer()
                    startHeartbeatTimer()
                }
            }
            settings.optInt("offline_threshold_seconds", 0).takeIf { it > 0 }?.let {
                PreferencesManager.offlineThresholdSeconds = it
            }
            settings.optInt("stale_threshold_seconds", 0).takeIf { it > 0 }?.let {
                PreferencesManager.staleThresholdSeconds = it
            }
            Log.d(TAG, "Applied server settings: heartbeat=${PreferencesManager.idleHeartbeatSeconds}s")
        } catch (e: Exception) {
            Log.w(TAG, "Error applying server settings", e)
        }
    }

    private fun startHeartbeatTimer() {
        stopHeartbeatTimer()
        heartbeatRunnable = Runnable {
            Log.d(TAG, "Heartbeat timer fired (mode: $currentMode)")
            requestHeartbeatLocation()
            startHeartbeatTimer()
        }
        // Heartbeat timing from server settings (idle_heartbeat_seconds)
        // Default 600s (10 min), configurable via tracking_settings table
        val heartbeatMs = PreferencesManager.idleHeartbeatSeconds * 1000L
        mainHandler.postDelayed(heartbeatRunnable!!, heartbeatMs)
        Log.d(TAG, "Heartbeat timer started (${heartbeatMs/1000}s interval from server settings)")
    }

    private fun stopHeartbeatTimer() {
        heartbeatRunnable?.let { mainHandler.removeCallbacks(it) }
        heartbeatRunnable = null
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

    override fun onTaskRemoved(rootIntent: Intent?) {
        super.onTaskRemoved(rootIntent)
        // If user swipes app from recents while tracking is enabled, restart the service
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

    override fun onBind(intent: Intent?): IBinder? = null

    // ========== NOTIFICATION POLLING (existing - works 100%) ==========

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

    private inner class LocationUploader(private val context: Context) {
        private val httpClient = OkHttpClient.Builder()
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(20, TimeUnit.SECONDS)
            .build()
        private val jsonMediaType = "application/json; charset=utf-8".toMediaType()
        private val UPLOAD_URL = "https://www.relatives.co.za/tracking/api/update_location.php"

        fun uploadLocation(
            location: Location,
            isMoving: Boolean,
            speedKmh: Float,
            clientEventId: String? = null,  // For idempotent uploads (prevents duplicates on retry)
            onSuccess: (serverSettings: JSONObject?) -> Unit,
            onAuthFailure: () -> Unit,
            onTransientFailure: () -> Unit
        ) {
            val deviceUuid = PreferencesManager.getDeviceUuid()
            val sessionToken = PreferencesManager.sessionToken
            if (sessionToken.isNullOrBlank()) {
                onAuthFailure()
                return
            }

            // Generate client_event_id if not provided (for fresh uploads)
            val eventId = clientEventId ?: UUID.randomUUID().toString()

            val body = JSONObject().apply {
                put("device_uuid", deviceUuid)
                put("platform", "android")
                put("device_name", Build.MODEL)
                put("network_status", getNetworkStatus())
                put("location_status", getLocationStatus())
                put("permission_status", getPermissionStatus())
                put("app_state", getAppState())
                put("battery_level", getBatteryLevel() ?: JSONObject.NULL)
                put("latitude", location.latitude)
                put("longitude", location.longitude)
                put("accuracy_m", location.accuracy.toInt())
                put("speed_kmh", speedKmh)  // Use calculated speed, not just GPS speed
                put("heading_deg", if (location.hasBearing()) location.bearing else 0)
                put("altitude_m", if (location.hasAltitude()) location.altitude else JSONObject.NULL)
                put("is_moving", isMoving)
                put("source", "native")
                put("session_token", sessionToken)
                put("client_event_id", eventId)  // For server-side idempotency check
                put("client_timestamp", System.currentTimeMillis())  // Original client timestamp
            }

            val request = Request.Builder()
                .url(UPLOAD_URL)
                .addHeader("Content-Type", "application/json")
                .addHeader("Authorization", "Bearer $sessionToken")
                .addHeader("User-Agent", "RelativesAndroid/1.0")
                .post(body.toString().toRequestBody(jsonMediaType))
                .build()

            httpClient.newCall(request).enqueue(createCallback(onSuccess, onAuthFailure, onTransientFailure))
        }

        fun uploadHeartbeat(
            onSuccess: (serverSettings: JSONObject?) -> Unit,
            onAuthFailure: () -> Unit,
            onTransientFailure: () -> Unit
        ) {
            val deviceUuid = PreferencesManager.getDeviceUuid()
            val sessionToken = PreferencesManager.sessionToken
            if (sessionToken.isNullOrBlank()) {
                onAuthFailure()
                return
            }

            val body = JSONObject().apply {
                put("device_uuid", deviceUuid)
                put("platform", "android")
                put("device_name", Build.MODEL)
                put("network_status", getNetworkStatus())
                put("location_status", getLocationStatus())
                put("permission_status", getPermissionStatus())
                put("app_state", getAppState())
                put("battery_level", getBatteryLevel() ?: JSONObject.NULL)
                put("source", "native")
                put("session_token", sessionToken)
            }

            val request = Request.Builder()
                .url(UPLOAD_URL)
                .addHeader("Content-Type", "application/json")
                .addHeader("Authorization", "Bearer $sessionToken")
                .addHeader("User-Agent", "RelativesAndroid/1.0")
                .post(body.toString().toRequestBody(jsonMediaType))
                .build()

            Log.d(TAG, "Uploading heartbeat (state-only)")
            httpClient.newCall(request).enqueue(createCallback(onSuccess, onAuthFailure, onTransientFailure))
        }

        // Fix #7: Batch upload for efficient queue flush
        private val BATCH_UPLOAD_URL = "https://www.relatives.co.za/tracking/api/update_location_batch.php"

        fun uploadLocationBatch(
            locations: List<QueuedLocation>,
            onSuccess: (serverSettings: JSONObject?) -> Unit,
            onPartialSuccess: (failedItems: List<QueuedLocation>) -> Unit,
            onAuthFailure: () -> Unit,
            onTransientFailure: () -> Unit
        ) {
            val deviceUuid = PreferencesManager.getDeviceUuid()
            val sessionToken = PreferencesManager.sessionToken
            if (sessionToken.isNullOrBlank()) {
                onAuthFailure()
                return
            }

            // Build locations array
            val locationsArray = org.json.JSONArray()
            val locationMap = mutableMapOf<String, QueuedLocation>()  // Map client_event_id to QueuedLocation

            locations.forEach { queued ->
                locationMap[queued.clientEventId] = queued
                locationsArray.put(JSONObject().apply {
                    put("latitude", queued.latitude)
                    put("longitude", queued.longitude)
                    put("accuracy_m", queued.accuracy.toInt())
                    put("speed_kmh", queued.speedKmh)
                    put("heading_deg", queued.bearing ?: 0)
                    put("altitude_m", queued.altitude ?: JSONObject.NULL)
                    put("is_moving", queued.isMoving)
                    put("battery_level", getBatteryLevel() ?: JSONObject.NULL)
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
                .url(BATCH_UPLOAD_URL)
                .addHeader("Content-Type", "application/json")
                .addHeader("Authorization", "Bearer $sessionToken")
                .addHeader("User-Agent", "RelativesAndroid/1.0")
                .post(body.toString().toRequestBody(jsonMediaType))
                .build()

            Log.d(TAG, "Uploading batch of ${locations.size} locations")

            httpClient.newCall(request).enqueue(object : Callback {
                override fun onFailure(call: Call, e: IOException) {
                    Log.e(TAG, "Batch upload network error: ${e.message}", e)
                    onTransientFailure()
                }

                override fun onResponse(call: Call, response: Response) {
                    response.use { resp ->
                        val responseBody = try { resp.body?.string() } catch (e: Exception) { null }

                        when {
                            resp.isSuccessful -> {
                                try {
                                    val json = JSONObject(responseBody ?: "{}")
                                    val serverSettings = json.optJSONObject("server_settings")

                                    // Check for partial failures
                                    val results = json.optJSONArray("results")
                                    val failedItems = mutableListOf<QueuedLocation>()

                                    if (results != null) {
                                        for (i in 0 until results.length()) {
                                            val result = results.getJSONObject(i)
                                            val clientEventId = result.optString("client_event_id")
                                            val success = result.optBoolean("success", false)

                                            if (!success && clientEventId.isNotEmpty()) {
                                                locationMap[clientEventId]?.let { failedItems.add(it) }
                                            }
                                        }
                                    }

                                    if (failedItems.isEmpty()) {
                                        onSuccess(serverSettings)
                                    } else {
                                        onPartialSuccess(failedItems)
                                    }
                                } catch (e: Exception) {
                                    Log.w(TAG, "Failed to parse batch response: ${e.message}")
                                    onSuccess(null)
                                }
                            }
                            resp.code in listOf(401, 402, 403) -> {
                                Log.e(TAG, "Batch upload auth failure (${resp.code}): $responseBody")
                                onAuthFailure()
                            }
                            else -> {
                                Log.e(TAG, "Batch upload failed (${resp.code}): $responseBody")
                                onTransientFailure()
                            }
                        }
                    }
                }
            })
        }

        private fun createCallback(
            onSuccess: (serverSettings: JSONObject?) -> Unit,
            onAuthFailure: () -> Unit,
            onTransientFailure: () -> Unit
        ) = object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                Log.e(TAG, "Upload failed with network error: ${e.message}", e)
                onTransientFailure()
            }

            override fun onResponse(call: Call, response: Response) {
                response.use { resp ->
                    val responseBody = try { resp.body?.string() } catch (e: Exception) { null }

                    when {
                        resp.isSuccessful -> {
                            val serverSettings = try {
                                responseBody?.let { JSONObject(it).optJSONObject("server_settings") }
                            } catch (e: Exception) {
                                Log.w(TAG, "Failed to parse server settings: ${e.message}")
                                null
                            }
                            onSuccess(serverSettings)
                        }
                        resp.code in listOf(401, 402, 403) -> {
                            // Log full response for auth failures
                            Log.e(TAG, "Upload auth failure (${resp.code}): $responseBody")
                            onAuthFailure()
                        }
                        else -> {
                            // Log full response for any other failures - NO SILENT FAILS
                            Log.e(TAG, "Upload failed (${resp.code}): $responseBody")
                            onTransientFailure()
                        }
                    }
                }
            }
        }

        private fun getNetworkStatus(): String {
            return try {
                val connectivityManager = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
                val network = connectivityManager.activeNetwork
                val capabilities = connectivityManager.getNetworkCapabilities(network)
                if (capabilities?.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED) == true) "online" else "offline"
            } catch (e: Exception) { "online" }
        }

        private fun getLocationStatus(): String {
            return if (isLocationServicesEnabled()) "enabled" else "disabled"
        }

        private fun getPermissionStatus(): String {
            return if (hasLocationPermission()) "granted" else "denied"
        }

        private fun getAppState(): String = "background"

        private fun getBatteryLevel(): Int? {
            return try {
                val bm = context.getSystemService(BATTERY_SERVICE) as BatteryManager
                bm.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
            } catch (e: Exception) { null }
        }
    }
}
