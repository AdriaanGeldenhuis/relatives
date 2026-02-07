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
import com.relatives.app.MainActivity
import com.relatives.app.R

/**
 * Foreground service for location tracking with 3 modes:
 *
 * LIVE   - 10s interval, HIGH_ACCURACY, wakelock (viewer watching)
 * MOVING - 30-60s interval (configurable), BALANCED, no wakelock
 * IDLE   - 10 min interval, LOW_POWER, heartbeat only (stationary)
 *
 * Uses Activity Recognition to detect movement and switch between
 * MOVING and IDLE automatically. Battery-efficient: when stationary,
 * GPS is essentially off.
 */
class TrackingLocationService : Service() {

    companion object {
        private const val TAG = "TrackingLocationService"

        // Actions
        const val ACTION_START_TRACKING = "com.relatives.app.START_TRACKING"
        const val ACTION_STOP_TRACKING = "com.relatives.app.STOP_TRACKING"
        const val ACTION_VIEWER_VISIBLE = "com.relatives.app.VIEWER_VISIBLE"
        const val ACTION_VIEWER_HIDDEN = "com.relatives.app.VIEWER_HIDDEN"
        const val ACTION_UPDATE_SETTINGS = "com.relatives.app.UPDATE_SETTINGS"
        const val ACTION_ACTIVITY_TRANSITION = "com.relatives.app.ACTIVITY_TRANSITION"
        const val ACTION_WAKE_TRACKING = "com.relatives.app.WAKE_TRACKING"

        // Extras
        const val EXTRA_INTERVAL_SECONDS = "interval_seconds"
        const val EXTRA_HIGH_ACCURACY = "high_accuracy"

        // Notification
        private const val NOTIFICATION_ID = 1001
        private const val CHANNEL_ID = "relatives_tracking"
        private const val CHANNEL_NAME = "Location Tracking"

        // Wakelock
        private const val WAKELOCK_TAG = "relatives:tracking_live"
        private const val WAKELOCK_TIMEOUT_MS = 2L * 60 * 1000 // 2 minutes

        // Timing
        private const val LIVE_INTERVAL_MS = 10_000L           // 10s
        private const val LIVE_MIN_DISTANCE_M = 10f             // 10m
        private const val IDLE_INTERVAL_MS = 600_000L           // 10 min
        private const val IDLE_MIN_DISTANCE_M = 100f            // 100m
        private const val VIEWER_LIVE_DURATION_MS = 600_000L    // 10 min
        private const val STATIONARY_TIMEOUT_MS = 180_000L      // 3 min to go idle
        private const val MODE_CHECK_INTERVAL_MS = 60_000L      // Check mode every 1 min
        private const val ACTIVITY_DETECTION_INTERVAL_MS = 30_000L // 30s

        /**
         * Start tracking from outside the service.
         */
        fun startTracking(context: Context) {
            val intent = Intent(context, TrackingLocationService::class.java).apply {
                action = ACTION_START_TRACKING
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }

        /**
         * Stop tracking from outside the service.
         */
        fun stopTracking(context: Context) {
            val intent = Intent(context, TrackingLocationService::class.java).apply {
                action = ACTION_STOP_TRACKING
            }
            context.startService(intent)
        }
    }

    // Tracking modes
    enum class TrackingMode {
        LIVE,    // High-frequency, viewer watching
        MOVING,  // Medium-frequency, user in motion
        IDLE     // Low-frequency heartbeat, stationary
    }

    // Services
    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private lateinit var activityRecognitionClient: ActivityRecognitionClient
    private lateinit var prefs: PreferencesManager
    private lateinit var uploader: LocationUploader
    private lateinit var notificationManager: NotificationManager
    private lateinit var handler: Handler

    // State
    private var currentMode = TrackingMode.IDLE
    private var viewerLiveUntil = 0L
    private var lastMovementTime = 0L
    private var lastLocation: Location? = null
    private var isServiceRunning = false
    private var wakeLock: PowerManager.WakeLock? = null

    // Callbacks
    private var locationCallback: LocationCallback? = null
    private var activityTransitionPendingIntent: PendingIntent? = null

    // Mode check runnable
    private val modeCheckRunnable = object : Runnable {
        override fun run() {
            checkAndUpdateMode()
            if (isServiceRunning) {
                handler.postDelayed(this, MODE_CHECK_INTERVAL_MS)
            }
        }
    }

    // ========== SERVICE LIFECYCLE ==========

    override fun onCreate() {
        super.onCreate()
        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)
        activityRecognitionClient = ActivityRecognition.getClient(this)
        prefs = PreferencesManager(this)
        uploader = LocationUploader(this, prefs)
        notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        handler = Handler(Looper.getMainLooper())

        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START_TRACKING -> handleStartTracking()
            ACTION_STOP_TRACKING -> handleStopTracking()
            ACTION_VIEWER_VISIBLE -> handleViewerVisible()
            ACTION_VIEWER_HIDDEN -> handleViewerHidden()
            ACTION_UPDATE_SETTINGS -> handleUpdateSettings(intent)
            ACTION_ACTIVITY_TRANSITION -> handleActivityTransition(intent)
            ACTION_WAKE_TRACKING -> handleWakeTracking()
            else -> {
                // Service restarted by system - check if should continue
                if (prefs.isTrackingEnabled && !prefs.userRequestedStop) {
                    handleStartTracking()
                } else {
                    stopSelfCleanly()
                }
            }
        }

        // Don't auto-restart unless tracking is enabled
        return if (prefs.isTrackingEnabled) START_STICKY else START_NOT_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        isServiceRunning = false
        handler.removeCallbacks(modeCheckRunnable)
        removeLocationUpdates()
        unregisterActivityTransitions()
        releaseWakeLock()
        super.onDestroy()
    }

    // ========== ACTION HANDLERS ==========

    private fun handleStartTracking() {
        if (isServiceRunning) {
            Log.d(TAG, "Service already running")
            return
        }

        Log.d(TAG, "Starting tracking service")

        // Enable tracking in prefs
        prefs.enableTracking()

        // Start as foreground service
        val notification = buildNotification("Starting tracking...")
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }

        isServiceRunning = true
        lastMovementTime = System.currentTimeMillis()

        // Start in IDLE mode - will switch to MOVING when movement detected
        switchMode(TrackingMode.IDLE)

        // Register activity recognition
        registerActivityTransitions()

        // Start periodic mode checks
        handler.postDelayed(modeCheckRunnable, MODE_CHECK_INTERVAL_MS)
    }

    private fun handleStopTracking() {
        Log.d(TAG, "Stopping tracking service")
        prefs.disableTracking()
        stopSelfCleanly()
    }

    private fun handleViewerVisible() {
        Log.d(TAG, "Viewer visible - switching to LIVE mode")
        viewerLiveUntil = System.currentTimeMillis() + VIEWER_LIVE_DURATION_MS

        if (!isServiceRunning) {
            // Start the service if not running
            handleStartTracking()
        }

        if (currentMode != TrackingMode.LIVE) {
            switchMode(TrackingMode.LIVE)
        }
    }

    private fun handleViewerHidden() {
        Log.d(TAG, "Viewer hidden - will drop from LIVE after timeout")
        // Don't immediately drop - let viewerLiveUntil expire naturally
        // The modeCheckRunnable will handle the transition
    }

    private fun handleUpdateSettings(intent: Intent) {
        val interval = intent.getIntExtra(EXTRA_INTERVAL_SECONDS, -1)
        val highAccuracy = intent.getBooleanExtra(EXTRA_HIGH_ACCURACY, true)

        if (interval > 0) {
            prefs.movingIntervalSeconds = interval
        }
        prefs.highAccuracyMode = highAccuracy

        Log.d(TAG, "Settings updated: interval=${interval}s, highAccuracy=$highAccuracy")

        // Reapply current mode with new settings
        if (isServiceRunning && currentMode == TrackingMode.MOVING) {
            switchMode(TrackingMode.MOVING)
        }
    }

    private fun handleActivityTransition(intent: Intent) {
        if (!ActivityTransitionResult.hasResult(intent)) return

        val result = ActivityTransitionResult.extractResult(intent) ?: return

        for (event in result.transitionEvents) {
            val activity = event.activityType
            val transition = event.transitionType

            Log.d(TAG, "Activity transition: activity=$activity, transition=$transition")

            when {
                // User started moving
                transition == ActivityTransition.ACTIVITY_TRANSITION_ENTER &&
                activity in listOf(
                    DetectedActivity.IN_VEHICLE,
                    DetectedActivity.ON_BICYCLE,
                    DetectedActivity.ON_FOOT,
                    DetectedActivity.RUNNING,
                    DetectedActivity.WALKING
                ) -> {
                    lastMovementTime = System.currentTimeMillis()
                    if (currentMode == TrackingMode.IDLE) {
                        Log.d(TAG, "Movement detected - switching to MOVING")
                        switchMode(TrackingMode.MOVING)
                    }
                }

                // User became still
                transition == ActivityTransition.ACTIVITY_TRANSITION_ENTER &&
                activity == DetectedActivity.STILL -> {
                    // Don't immediately go idle - wait for stationary timeout
                    // The modeCheckRunnable handles this
                    Log.d(TAG, "User became still - will go IDLE after timeout")
                }
            }
        }
    }

    private fun handleWakeTracking() {
        Log.d(TAG, "Wake tracking requested")
        // Same as viewer visible - activates LIVE mode
        handleViewerVisible()
    }

    // ========== MODE MANAGEMENT ==========

    /**
     * Periodically check if mode should change.
     */
    private fun checkAndUpdateMode() {
        if (!isServiceRunning) return

        val now = System.currentTimeMillis()

        when (currentMode) {
            TrackingMode.LIVE -> {
                // Check if LIVE should expire
                if (now >= viewerLiveUntil) {
                    Log.d(TAG, "LIVE mode expired, checking movement")
                    val timeSinceMovement = now - lastMovementTime
                    if (timeSinceMovement > STATIONARY_TIMEOUT_MS) {
                        switchMode(TrackingMode.IDLE)
                    } else {
                        switchMode(TrackingMode.MOVING)
                    }
                }
            }

            TrackingMode.MOVING -> {
                // Check if should go IDLE (no movement for 3 min)
                val timeSinceMovement = now - lastMovementTime
                if (timeSinceMovement > STATIONARY_TIMEOUT_MS) {
                    Log.d(TAG, "No movement for ${timeSinceMovement / 1000}s - switching to IDLE")
                    switchMode(TrackingMode.IDLE)
                }
            }

            TrackingMode.IDLE -> {
                // Idle mode stays until activity recognition detects movement
                // or viewer becomes visible
            }
        }
    }

    /**
     * Switch to a new tracking mode.
     */
    private fun switchMode(newMode: TrackingMode) {
        Log.d(TAG, "Switching mode: $currentMode -> $newMode")
        currentMode = newMode

        // Remove existing location updates
        removeLocationUpdates()

        // Handle wakelock
        when (newMode) {
            TrackingMode.LIVE -> acquireTimeLimitedWakeLock()
            else -> releaseWakeLock()
        }

        // Request new location updates
        requestLocationUpdates(newMode)

        // Update notification
        updateNotification(newMode)
    }

    // ========== LOCATION UPDATES ==========

    private fun requestLocationUpdates(mode: TrackingMode) {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
            != PackageManager.PERMISSION_GRANTED) {
            Log.w(TAG, "Location permission not granted")
            updateNotificationText("Location permission required")
            return
        }

        val request = when (mode) {
            TrackingMode.LIVE -> LocationRequest.Builder(
                Priority.PRIORITY_HIGH_ACCURACY,
                LIVE_INTERVAL_MS
            ).setMinUpdateDistanceMeters(LIVE_MIN_DISTANCE_M)
                .setMinUpdateIntervalMillis(LIVE_INTERVAL_MS / 2)
                .build()

            TrackingMode.MOVING -> {
                val intervalMs = prefs.movingIntervalSeconds * 1000L
                val priority = if (prefs.highAccuracyMode)
                    Priority.PRIORITY_HIGH_ACCURACY
                else
                    Priority.PRIORITY_BALANCED_POWER_ACCURACY

                LocationRequest.Builder(priority, intervalMs)
                    .setMinUpdateIntervalMillis(intervalMs / 2)
                    .build()
            }

            TrackingMode.IDLE -> LocationRequest.Builder(
                Priority.PRIORITY_LOW_POWER,
                IDLE_INTERVAL_MS
            ).setMinUpdateDistanceMeters(IDLE_MIN_DISTANCE_M)
                .setMinUpdateIntervalMillis(IDLE_INTERVAL_MS / 2)
                .build()
        }

        locationCallback = object : LocationCallback() {
            override fun onLocationResult(result: LocationResult) {
                val location = result.lastLocation ?: return
                onLocationReceived(location)
            }
        }

        try {
            fusedLocationClient.requestLocationUpdates(
                request,
                locationCallback!!,
                Looper.getMainLooper()
            )
            Log.d(TAG, "Location updates requested: mode=$mode")
        } catch (e: SecurityException) {
            Log.e(TAG, "SecurityException requesting location updates", e)
        }
    }

    private fun removeLocationUpdates() {
        locationCallback?.let {
            fusedLocationClient.removeLocationUpdates(it)
            locationCallback = null
        }
    }

    /**
     * Called when a new location is received.
     */
    private fun onLocationReceived(location: Location) {
        Log.d(TAG, "Location received: ${location.latitude}, ${location.longitude} " +
                "accuracy=${location.accuracy}m speed=${location.speed}m/s mode=$currentMode")

        // Update movement detection based on speed
        if (location.hasSpeed() && location.speed > prefs.speedThresholdMps) {
            lastMovementTime = System.currentTimeMillis()

            // If we're IDLE and detect movement from location speed, switch to MOVING
            if (currentMode == TrackingMode.IDLE) {
                Log.d(TAG, "Speed ${location.speed} > threshold ${prefs.speedThresholdMps} - switching to MOVING")
                switchMode(TrackingMode.MOVING)
            }
        }

        // Check if we should skip upload (backoff/auth blocked)
        if (prefs.isAuthBlocked()) {
            Log.d(TAG, "Auth blocked, skipping upload")
            updateNotificationText("Login required")
            return
        }

        if (prefs.isInBackoff()) {
            Log.d(TAG, "In backoff, skipping upload")
            return
        }

        // Upload location
        lastLocation = location
        uploader.uploadLocation(location, currentMode.name.lowercase())
    }

    // ========== ACTIVITY RECOGNITION ==========

    private fun registerActivityTransitions() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACTIVITY_RECOGNITION)
            != PackageManager.PERMISSION_GRANTED) {
            Log.w(TAG, "Activity recognition permission not granted")
            return
        }

        val transitions = listOf(
            // Detect when user starts moving
            ActivityTransition.Builder()
                .setActivityType(DetectedActivity.IN_VEHICLE)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                .build(),
            ActivityTransition.Builder()
                .setActivityType(DetectedActivity.ON_BICYCLE)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                .build(),
            ActivityTransition.Builder()
                .setActivityType(DetectedActivity.ON_FOOT)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                .build(),
            ActivityTransition.Builder()
                .setActivityType(DetectedActivity.WALKING)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                .build(),
            ActivityTransition.Builder()
                .setActivityType(DetectedActivity.RUNNING)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                .build(),
            // Detect when user becomes still
            ActivityTransition.Builder()
                .setActivityType(DetectedActivity.STILL)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                .build()
        )

        val request = ActivityTransitionRequest(transitions)

        val intent = Intent(this, TrackingLocationService::class.java).apply {
            action = ACTION_ACTIVITY_TRANSITION
        }
        val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
        } else {
            PendingIntent.FLAG_UPDATE_CURRENT
        }
        activityTransitionPendingIntent = PendingIntent.getService(this, 0, intent, flags)

        try {
            activityRecognitionClient.requestActivityTransitionUpdates(
                request,
                activityTransitionPendingIntent!!
            ).addOnSuccessListener {
                Log.d(TAG, "Activity transition updates registered")
            }.addOnFailureListener { e ->
                Log.e(TAG, "Failed to register activity transitions", e)
            }
        } catch (e: SecurityException) {
            Log.e(TAG, "SecurityException registering activity transitions", e)
        }
    }

    private fun unregisterActivityTransitions() {
        activityTransitionPendingIntent?.let {
            try {
                activityRecognitionClient.removeActivityTransitionUpdates(it)
            } catch (e: Exception) {
                Log.w(TAG, "Failed to unregister activity transitions", e)
            }
        }
    }

    // ========== WAKELOCK ==========

    private fun acquireTimeLimitedWakeLock() {
        releaseWakeLock()
        val pm = getSystemService(Context.POWER_SERVICE) as PowerManager
        wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, WAKELOCK_TAG).apply {
            acquire(WAKELOCK_TIMEOUT_MS)
        }
        Log.d(TAG, "WakeLock acquired (${WAKELOCK_TIMEOUT_MS / 1000}s limit)")
    }

    private fun releaseWakeLock() {
        wakeLock?.let {
            if (it.isHeld) {
                it.release()
                Log.d(TAG, "WakeLock released")
            }
        }
        wakeLock = null
    }

    // ========== NOTIFICATION ==========

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                CHANNEL_NAME,
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "Shows when location tracking is active"
                setShowBadge(false)
            }
            notificationManager.createNotificationChannel(channel)
        }
    }

    private fun buildNotification(text: String): Notification {
        val contentIntent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP
        }
        val pendingIntent = PendingIntent.getActivity(
            this, 0, contentIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val stopIntent = Intent(this, TrackingLocationService::class.java).apply {
            action = ACTION_STOP_TRACKING
        }
        val stopPendingIntent = PendingIntent.getService(
            this, 1, stopIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("Relatives Tracking")
            .setContentText(text)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentIntent(pendingIntent)
            .addAction(R.drawable.ic_stop, "Stop", stopPendingIntent)
            .setOngoing(true)
            .setSilent(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .build()
    }

    private fun updateNotification(mode: TrackingMode) {
        val text = when (mode) {
            TrackingMode.LIVE -> "Live tracking active"
            TrackingMode.MOVING -> "Tracking movement"
            TrackingMode.IDLE -> "Standby - waiting for movement"
        }
        updateNotificationText(text)
    }

    private fun updateNotificationText(text: String) {
        if (!isServiceRunning) return
        val notification = buildNotification(text)
        notificationManager.notify(NOTIFICATION_ID, notification)
    }

    // ========== CLEANUP ==========

    private fun stopSelfCleanly() {
        Log.d(TAG, "Stopping service cleanly")
        isServiceRunning = false

        handler.removeCallbacks(modeCheckRunnable)
        removeLocationUpdates()
        unregisterActivityTransitions()
        releaseWakeLock()

        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }
}
