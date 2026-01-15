package com.relatives.app.tracking

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.location.Location
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.PowerManager
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.google.android.gms.location.ActivityRecognition
import com.google.android.gms.location.ActivityRecognitionClient
import com.google.android.gms.location.ActivityTransition
import com.google.android.gms.location.ActivityTransitionRequest
import com.google.android.gms.location.ActivityTransitionResult
import com.google.android.gms.location.DetectedActivity
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority

/**
 * Foreground location tracking service with three modes:
 * - LIVE: High accuracy, 10s interval (viewer watching)
 * - MOVING: User-configured interval (default 60s)
 * - IDLE: Low power, 10-minute heartbeat
 *
 * Key behaviors:
 * - NO permanent wakelocks
 * - Stops completely when user requests stop (no zombie restarts)
 * - Exponential backoff on network failures
 * - Auth failure blocks uploads for 30 minutes
 */
class TrackingLocationService : Service() {

    companion object {
        private const val TAG = "TrackingLocationService"

        // Notification
        const val NOTIFICATION_CHANNEL_ID = "relatives_tracking_channel"
        const val NOTIFICATION_ID = 1001

        // Actions
        const val ACTION_START_TRACKING = "com.relatives.app.START_TRACKING"
        const val ACTION_STOP_TRACKING = "com.relatives.app.STOP_TRACKING"
        const val ACTION_VIEWER_VISIBLE = "com.relatives.app.VIEWER_VISIBLE"
        const val ACTION_VIEWER_HIDDEN = "com.relatives.app.VIEWER_HIDDEN"
        const val ACTION_UPDATE_SETTINGS = "com.relatives.app.UPDATE_SETTINGS"
        const val ACTION_ACTIVITY_TRANSITION = "com.relatives.app.ACTIVITY_TRANSITION"

        // Extras
        const val EXTRA_INTERVAL_SECONDS = "interval_seconds"
        const val EXTRA_HIGH_ACCURACY = "high_accuracy"

        // Mode intervals
        const val LIVE_INTERVAL_MS = 10_000L       // 10 seconds
        const val LIVE_FASTEST_MS = 5_000L         // 5 seconds
        const val LIVE_MIN_DISTANCE_M = 10f        // 10 meters

        const val IDLE_INTERVAL_MS = 600_000L     // 10 minutes
        const val IDLE_MIN_DISTANCE_M = 100f      // 100 meters

        // Viewer timeout
        const val VIEWER_LIVE_DURATION_MS = 600_000L  // 10 minutes

        // Movement detection
        const val MOVEMENT_IDLE_TIMEOUT_MS = 180_000L // 3 minutes without movement -> IDLE

        // Wakelock (only for LIVE mode, time-limited)
        const val WAKELOCK_TAG = "relatives:tracking_live"
        const val WAKELOCK_TIMEOUT_MS = 120_000L  // 2 minutes max

        fun startTracking(context: Context) {
            val intent = Intent(context, TrackingLocationService::class.java).apply {
                action = ACTION_START_TRACKING
            }
            ContextCompat.startForegroundService(context, intent)
        }

        fun stopTracking(context: Context) {
            val intent = Intent(context, TrackingLocationService::class.java).apply {
                action = ACTION_STOP_TRACKING
            }
            context.startService(intent)
        }
    }

    enum class TrackingMode {
        LIVE,    // Viewer watching - high frequency
        MOVING,  // User in motion - medium frequency
        IDLE     // Stationary - heartbeat only
    }

    // Dependencies
    private lateinit var prefs: PreferencesManager
    private lateinit var uploader: LocationUploader
    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private lateinit var activityRecognitionClient: ActivityRecognitionClient
    private lateinit var notificationManager: NotificationManager

    // State
    private var currentMode: TrackingMode = TrackingMode.IDLE
    private var viewerLiveUntil: Long = 0L
    private var lastMovementTime: Long = 0L
    private var lastLocation: Location? = null
    private var lastUploadTime: Long = 0L
    private var isServiceRunning = false

    // Wakelock - only for LIVE mode, time-limited
    private var wakeLock: PowerManager.WakeLock? = null

    // Handlers
    private val mainHandler = Handler(Looper.getMainLooper())
    private var modeCheckRunnable: Runnable? = null
    private var activityTransitionPendingIntent: PendingIntent? = null

    // Location callback
    private val locationCallback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            result.lastLocation?.let { location ->
                handleLocationUpdate(location)
            }
        }
    }

    override fun onCreate() {
        super.onCreate()
        Log.d(TAG, "Service onCreate")

        prefs = PreferencesManager(this)
        uploader = LocationUploader(this, prefs)
        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)
        activityRecognitionClient = ActivityRecognition.getClient(this)
        notificationManager = getSystemService(NotificationManager::class.java)

        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        Log.d(TAG, "onStartCommand: action=${intent?.action}")

        when (intent?.action) {
            ACTION_START_TRACKING -> handleStartTracking()
            ACTION_STOP_TRACKING -> handleStopTracking()
            ACTION_VIEWER_VISIBLE -> handleViewerVisible()
            ACTION_VIEWER_HIDDEN -> handleViewerHidden()
            ACTION_UPDATE_SETTINGS -> handleUpdateSettings(intent)
            ACTION_ACTIVITY_TRANSITION -> handleActivityTransition(intent)
            else -> {
                // Service restarted by system - check if we should actually be running
                if (prefs.isTrackingEnabled && !prefs.userRequestedStop) {
                    handleStartTracking()
                } else {
                    Log.d(TAG, "Service restarted but tracking not enabled, stopping")
                    stopSelfCleanly()
                    return START_NOT_STICKY
                }
            }
        }

        // Only restart if tracking is explicitly enabled
        return if (prefs.isTrackingEnabled && !prefs.userRequestedStop) {
            START_STICKY
        } else {
            START_NOT_STICKY
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        Log.d(TAG, "Service onDestroy")
        cleanupResources()
        super.onDestroy()
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        Log.d(TAG, "onTaskRemoved: trackingEnabled=${prefs.isTrackingEnabled}, userRequestedStop=${prefs.userRequestedStop}")

        // Only schedule restart if tracking is enabled AND user didn't explicitly stop
        if (prefs.isTrackingEnabled && !prefs.userRequestedStop) {
            Log.d(TAG, "Task removed but tracking enabled - service will restart via START_STICKY")
        } else {
            Log.d(TAG, "Task removed and tracking disabled - stopping completely")
            stopSelfCleanly()
        }

        super.onTaskRemoved(rootIntent)
    }

    // ============ ACTION HANDLERS ============

    private fun handleStartTracking() {
        Log.d(TAG, "handleStartTracking")

        if (!hasLocationPermission()) {
            Log.e(TAG, "No location permission, cannot start tracking")
            updateNotification(TrackingMode.IDLE, "Location permission required")
            return
        }

        // Update preferences
        prefs.enableTracking()

        // Start foreground
        startForegroundService()
        isServiceRunning = true

        // Initialize state
        lastMovementTime = System.currentTimeMillis()

        // Start activity recognition
        registerActivityTransitions()

        // Start in MOVING mode, will adjust based on activity
        switchMode(TrackingMode.MOVING)

        // Start periodic mode checker
        startModeChecker()
    }

    private fun handleStopTracking() {
        Log.d(TAG, "handleStopTracking - USER REQUESTED STOP")

        // Critical: Mark as user-requested stop to prevent zombie restarts
        prefs.disableTracking()

        // Stop everything
        stopSelfCleanly()
    }

    private fun handleViewerVisible() {
        Log.d(TAG, "handleViewerVisible - switching to LIVE mode")

        // Extend viewer live timeout
        viewerLiveUntil = System.currentTimeMillis() + VIEWER_LIVE_DURATION_MS

        // Switch to LIVE mode
        if (currentMode != TrackingMode.LIVE) {
            switchMode(TrackingMode.LIVE)
        }
    }

    private fun handleViewerHidden() {
        Log.d(TAG, "handleViewerHidden - viewer no longer watching")

        // Don't immediately switch - let the mode checker handle timeout
        // This prevents rapid mode switches if user quickly shows/hides
    }

    private fun handleUpdateSettings(intent: Intent) {
        val intervalSeconds = intent.getIntExtra(EXTRA_INTERVAL_SECONDS, prefs.updateIntervalSeconds)
        val highAccuracy = intent.getBooleanExtra(EXTRA_HIGH_ACCURACY, prefs.highAccuracyMode)

        Log.d(TAG, "handleUpdateSettings: interval=$intervalSeconds, highAccuracy=$highAccuracy")

        prefs.updateIntervalSeconds = intervalSeconds
        prefs.highAccuracyMode = highAccuracy

        // Re-apply current mode with new settings
        if (currentMode == TrackingMode.MOVING) {
            switchMode(TrackingMode.MOVING)
        }
    }

    private fun handleActivityTransition(intent: Intent) {
        if (ActivityTransitionResult.hasResult(intent)) {
            val result = ActivityTransitionResult.extractResult(intent)
            result?.transitionEvents?.forEach { event ->
                Log.d(TAG, "Activity transition: ${event.activityType} -> ${event.transitionType}")

                val isMoving = when (event.activityType) {
                    DetectedActivity.WALKING,
                    DetectedActivity.RUNNING,
                    DetectedActivity.ON_BICYCLE,
                    DetectedActivity.IN_VEHICLE -> event.transitionType == ActivityTransition.ACTIVITY_TRANSITION_ENTER
                    DetectedActivity.STILL -> event.transitionType == ActivityTransition.ACTIVITY_TRANSITION_EXIT
                    else -> null
                }

                isMoving?.let { moving ->
                    if (moving) {
                        onMovementDetected()
                    }
                }
            }
        }
    }

    // ============ MODE MANAGEMENT ============

    private fun switchMode(newMode: TrackingMode) {
        Log.d(TAG, "switchMode: $currentMode -> $newMode")

        val oldMode = currentMode
        currentMode = newMode

        // Handle wakelock
        if (newMode == TrackingMode.LIVE) {
            acquireTimeLimitedWakeLock()
        } else if (oldMode == TrackingMode.LIVE) {
            releaseWakeLock()
        }

        // Update location request
        requestLocationUpdates()

        // Update notification
        updateNotification(newMode)
    }

    private fun requestLocationUpdates() {
        if (!hasLocationPermission()) {
            Log.e(TAG, "No location permission for updates")
            return
        }

        // Remove existing updates
        try {
            fusedLocationClient.removeLocationUpdates(locationCallback)
        } catch (e: Exception) {
            Log.w(TAG, "Error removing location updates", e)
        }

        val request = when (currentMode) {
            TrackingMode.LIVE -> LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, LIVE_INTERVAL_MS)
                .setMinUpdateIntervalMillis(LIVE_FASTEST_MS)
                .setMinUpdateDistanceMeters(LIVE_MIN_DISTANCE_M)
                .build()

            TrackingMode.MOVING -> {
                val intervalMs = prefs.updateIntervalSeconds * 1000L
                val priority = if (prefs.highAccuracyMode) {
                    Priority.PRIORITY_HIGH_ACCURACY
                } else {
                    Priority.PRIORITY_BALANCED_POWER_ACCURACY
                }
                LocationRequest.Builder(priority, intervalMs)
                    .setMinUpdateIntervalMillis(intervalMs / 2)
                    .build()
            }

            TrackingMode.IDLE -> LocationRequest.Builder(Priority.PRIORITY_LOW_POWER, IDLE_INTERVAL_MS)
                .setMinUpdateDistanceMeters(IDLE_MIN_DISTANCE_M)
                .build()
        }

        try {
            fusedLocationClient.requestLocationUpdates(request, locationCallback, Looper.getMainLooper())
            Log.d(TAG, "Location updates requested for mode: $currentMode")
        } catch (e: SecurityException) {
            Log.e(TAG, "Security exception requesting location updates", e)
        }
    }

    // ============ LOCATION HANDLING ============

    private fun handleLocationUpdate(location: Location) {
        Log.d(TAG, "Location update: lat=${location.latitude}, lng=${location.longitude}, accuracy=${location.accuracy}")

        // Check for movement
        val moved = lastLocation?.let { last ->
            val distance = location.distanceTo(last)
            distance > 20 // More than 20 meters
        } ?: true

        if (moved || location.speed > 1.0f) { // Speed > 1 m/s (~3.6 km/h)
            onMovementDetected()
        }

        lastLocation = location

        // Upload based on mode and backoff state
        val shouldUpload = when {
            prefs.isAuthBlocked() -> {
                Log.d(TAG, "Auth blocked, skipping upload")
                false
            }
            isInBackoff() -> {
                Log.d(TAG, "In backoff, skipping upload")
                false
            }
            currentMode == TrackingMode.LIVE -> true
            currentMode == TrackingMode.MOVING -> true
            currentMode == TrackingMode.IDLE -> {
                // Only upload heartbeat every 10 minutes
                val timeSinceLastUpload = System.currentTimeMillis() - lastUploadTime
                timeSinceLastUpload >= IDLE_INTERVAL_MS
            }
            else -> false
        }

        if (shouldUpload) {
            uploadLocation(location)
        }
    }

    private fun uploadLocation(location: Location) {
        uploader.uploadLocation(
            location = location,
            isMoving = currentMode != TrackingMode.IDLE,
            onSuccess = {
                lastUploadTime = System.currentTimeMillis()
                prefs.resetFailureState()
                Log.d(TAG, "Location uploaded successfully")
            },
            onAuthFailure = {
                Log.w(TAG, "Auth failure - blocking uploads for 30 minutes")
                prefs.authFailureUntil = System.currentTimeMillis() + 30 * 60 * 1000
                updateNotification(currentMode, "Login required")
            },
            onTransientFailure = {
                prefs.consecutiveFailures++
                prefs.lastFailureTime = System.currentTimeMillis()
                Log.w(TAG, "Transient failure, consecutive count: ${prefs.consecutiveFailures}")

                // If too many failures, drop to lower frequency temporarily
                if (prefs.consecutiveFailures >= 3 && currentMode == TrackingMode.LIVE) {
                    Log.d(TAG, "Too many failures in LIVE mode, dropping to MOVING")
                    switchMode(TrackingMode.MOVING)
                }
            }
        )
    }

    private fun isInBackoff(): Boolean {
        val backoffDelay = prefs.getBackoffDelayMs()
        if (backoffDelay == 0L) return false

        val timeSinceFailure = System.currentTimeMillis() - prefs.lastFailureTime
        return timeSinceFailure < backoffDelay
    }

    private fun onMovementDetected() {
        lastMovementTime = System.currentTimeMillis()

        // If in IDLE mode, switch to MOVING
        if (currentMode == TrackingMode.IDLE) {
            Log.d(TAG, "Movement detected - switching to MOVING mode")
            switchMode(TrackingMode.MOVING)

            // Immediately request current location for upload
            requestImmediateLocation()
        }
    }

    private fun requestImmediateLocation() {
        if (!hasLocationPermission()) return

        try {
            fusedLocationClient.getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, null)
                .addOnSuccessListener { location ->
                    location?.let { handleLocationUpdate(it) }
                }
        } catch (e: SecurityException) {
            Log.e(TAG, "Security exception getting current location", e)
        }
    }

    // ============ MODE CHECKER ============

    private fun startModeChecker() {
        modeCheckRunnable?.let { mainHandler.removeCallbacks(it) }

        modeCheckRunnable = object : Runnable {
            override fun run() {
                checkAndUpdateMode()
                mainHandler.postDelayed(this, 30_000) // Check every 30 seconds
            }
        }
        mainHandler.postDelayed(modeCheckRunnable!!, 30_000)
    }

    private fun stopModeChecker() {
        modeCheckRunnable?.let { mainHandler.removeCallbacks(it) }
        modeCheckRunnable = null
    }

    private fun checkAndUpdateMode() {
        val now = System.currentTimeMillis()

        // Check viewer timeout
        if (currentMode == TrackingMode.LIVE && now > viewerLiveUntil) {
            Log.d(TAG, "Viewer timeout - dropping from LIVE mode")
            // Fall back to MOVING or IDLE based on movement
            val shouldBeIdle = (now - lastMovementTime) > MOVEMENT_IDLE_TIMEOUT_MS
            switchMode(if (shouldBeIdle) TrackingMode.IDLE else TrackingMode.MOVING)
            return
        }

        // Check movement timeout (only if not in LIVE)
        if (currentMode == TrackingMode.MOVING) {
            val timeSinceMovement = now - lastMovementTime
            if (timeSinceMovement > MOVEMENT_IDLE_TIMEOUT_MS) {
                Log.d(TAG, "No movement for ${timeSinceMovement}ms - switching to IDLE")
                switchMode(TrackingMode.IDLE)
            }
        }

        // Check auth block timeout
        if (prefs.isAuthBlocked() && now > prefs.authFailureUntil) {
            Log.d(TAG, "Auth block expired, clearing")
            prefs.clearAuthBlock()
            updateNotification(currentMode)
        }
    }

    // ============ ACTIVITY RECOGNITION ============

    private fun registerActivityTransitions() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACTIVITY_RECOGNITION)
            != PackageManager.PERMISSION_GRANTED) {
            Log.w(TAG, "No activity recognition permission")
            return
        }

        val transitions = listOf(
            ActivityTransition.Builder()
                .setActivityType(DetectedActivity.STILL)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                .build(),
            ActivityTransition.Builder()
                .setActivityType(DetectedActivity.STILL)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_EXIT)
                .build(),
            ActivityTransition.Builder()
                .setActivityType(DetectedActivity.WALKING)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                .build(),
            ActivityTransition.Builder()
                .setActivityType(DetectedActivity.RUNNING)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                .build(),
            ActivityTransition.Builder()
                .setActivityType(DetectedActivity.IN_VEHICLE)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                .build(),
            ActivityTransition.Builder()
                .setActivityType(DetectedActivity.ON_BICYCLE)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                .build()
        )

        val request = ActivityTransitionRequest(transitions)

        val intent = Intent(this, TrackingLocationService::class.java).apply {
            action = ACTION_ACTIVITY_TRANSITION
        }
        activityTransitionPendingIntent = PendingIntent.getService(
            this, 0, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
        )

        try {
            activityRecognitionClient.requestActivityTransitionUpdates(request, activityTransitionPendingIntent!!)
                .addOnSuccessListener { Log.d(TAG, "Activity transitions registered") }
                .addOnFailureListener { Log.e(TAG, "Failed to register activity transitions", it) }
        } catch (e: SecurityException) {
            Log.e(TAG, "Security exception registering activity transitions", e)
        }
    }

    private fun unregisterActivityTransitions() {
        activityTransitionPendingIntent?.let { pendingIntent ->
            try {
                activityRecognitionClient.removeActivityTransitionUpdates(pendingIntent)
                    .addOnSuccessListener { Log.d(TAG, "Activity transitions unregistered") }
            } catch (e: Exception) {
                Log.w(TAG, "Error unregistering activity transitions", e)
            }
        }
        activityTransitionPendingIntent = null
    }

    // ============ WAKELOCK (LIMITED) ============

    /**
     * Acquire a time-limited wakelock for LIVE mode only.
     * The wakelock will auto-release after WAKELOCK_TIMEOUT_MS.
     */
    private fun acquireTimeLimitedWakeLock() {
        if (wakeLock?.isHeld == true) return

        val powerManager = getSystemService(Context.POWER_SERVICE) as PowerManager
        wakeLock = powerManager.newWakeLock(
            PowerManager.PARTIAL_WAKE_LOCK,
            WAKELOCK_TAG
        ).apply {
            // CRITICAL: Time-limited acquire - will auto-release
            acquire(WAKELOCK_TIMEOUT_MS)
        }
        Log.d(TAG, "Acquired time-limited wakelock for ${WAKELOCK_TIMEOUT_MS}ms")
    }

    private fun releaseWakeLock() {
        wakeLock?.let { lock ->
            if (lock.isHeld) {
                lock.release()
                Log.d(TAG, "Released wakelock")
            }
        }
        wakeLock = null
    }

    // ============ NOTIFICATION ============

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                NOTIFICATION_CHANNEL_ID,
                "Location Tracking",
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "Shows when location tracking is active"
                setShowBadge(false)
            }
            notificationManager.createNotificationChannel(channel)
        }
    }

    private fun startForegroundService() {
        val notification = buildNotification(TrackingMode.MOVING)

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(
                NOTIFICATION_ID,
                notification,
                ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION
            )
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    private fun buildNotification(mode: TrackingMode, extraText: String? = null): Notification {
        val modeText = when (mode) {
            TrackingMode.LIVE -> "LIVE"
            TrackingMode.MOVING -> "Moving"
            TrackingMode.IDLE -> "Idle"
        }

        val contentText = extraText ?: when (mode) {
            TrackingMode.LIVE -> "Live tracking active"
            TrackingMode.MOVING -> "Tracking your location"
            TrackingMode.IDLE -> "Standby mode"
        }

        // Intent to open app
        val openIntent = packageManager.getLaunchIntentForPackage(packageName)?.let { intent ->
            PendingIntent.getActivity(
                this, 0, intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
        }

        // Stop action
        val stopIntent = Intent(this, TrackingLocationService::class.java).apply {
            action = ACTION_STOP_TRACKING
        }
        val stopPendingIntent = PendingIntent.getService(
            this, 1, stopIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        return NotificationCompat.Builder(this, NOTIFICATION_CHANNEL_ID)
            .setContentTitle("Relatives • $modeText")
            .setContentText(contentText)
            .setSmallIcon(android.R.drawable.ic_menu_mylocation)
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setContentIntent(openIntent)
            .addAction(android.R.drawable.ic_menu_close_clear_cancel, "Stop", stopPendingIntent)
            .build()
    }

    private fun updateNotification(mode: TrackingMode, extraText: String? = null) {
        if (!isServiceRunning) return
        val notification = buildNotification(mode, extraText)
        notificationManager.notify(NOTIFICATION_ID, notification)
    }

    // ============ CLEANUP ============

    private fun stopSelfCleanly() {
        Log.d(TAG, "stopSelfCleanly - cleaning up all resources")

        isServiceRunning = false
        cleanupResources()

        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    private fun cleanupResources() {
        // Stop mode checker
        stopModeChecker()

        // Remove location updates
        try {
            fusedLocationClient.removeLocationUpdates(locationCallback)
        } catch (e: Exception) {
            Log.w(TAG, "Error removing location updates during cleanup", e)
        }

        // Unregister activity recognition
        unregisterActivityTransitions()

        // Release wakelock
        releaseWakeLock()
    }

    // ============ PERMISSION CHECK ============

    private fun hasLocationPermission(): Boolean {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) ==
                PackageManager.PERMISSION_GRANTED ||
                ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) ==
                PackageManager.PERMISSION_GRANTED
    }
}
