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
 * LIVE   - 10s interval, HIGH_ACCURACY, wakelock (viewer watching / wake button)
 * MOVING - 30-60s interval (configurable), BALANCED, no wakelock
 * IDLE   - 10 min interval, LOW_POWER, heartbeat only (stationary)
 *
 * Movement detection uses speed from location updates (no Activity
 * Recognition permission needed). When stationary, GPS is essentially off.
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
        private const val STATIONARY_TIMEOUT_MS = 180_000L      // 3 min no movement → IDLE
        private const val MODE_CHECK_INTERVAL_MS = 60_000L      // Check mode every 1 min

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

        fun stopTracking(context: Context) {
            val intent = Intent(context, TrackingLocationService::class.java).apply {
                action = ACTION_STOP_TRACKING
            }
            context.startService(intent)
        }
    }

    enum class TrackingMode {
        LIVE,    // High-frequency, viewer watching
        MOVING,  // Medium-frequency, user in motion
        IDLE     // Low-frequency heartbeat, stationary
    }

    // Services
    private lateinit var fusedLocationClient: FusedLocationProviderClient
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

    // Location callback
    private var locationCallback: LocationCallback? = null

    // Periodic mode check
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

        return if (prefs.isTrackingEnabled) START_STICKY else START_NOT_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        isServiceRunning = false
        handler.removeCallbacks(modeCheckRunnable)
        removeLocationUpdates()
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
        prefs.enableTracking()

        val notification = buildNotification("Starting tracking...")
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }

        isServiceRunning = true
        lastMovementTime = System.currentTimeMillis()

        // Start in IDLE - switches to MOVING when speed detected
        switchMode(TrackingMode.IDLE)

        // Periodic mode checks
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
            handleStartTracking()
        }

        if (currentMode != TrackingMode.LIVE) {
            switchMode(TrackingMode.LIVE)
        }
    }

    private fun handleViewerHidden() {
        Log.d(TAG, "Viewer hidden - will drop from LIVE after timeout")
    }

    private fun handleUpdateSettings(intent: Intent) {
        val interval = intent.getIntExtra(EXTRA_INTERVAL_SECONDS, -1)
        val highAccuracy = intent.getBooleanExtra(EXTRA_HIGH_ACCURACY, true)

        if (interval > 0) {
            prefs.movingIntervalSeconds = interval
        }
        prefs.highAccuracyMode = highAccuracy

        Log.d(TAG, "Settings updated: interval=${interval}s, highAccuracy=$highAccuracy")

        if (isServiceRunning && currentMode == TrackingMode.MOVING) {
            switchMode(TrackingMode.MOVING)
        }
    }

    private fun handleWakeTracking() {
        Log.d(TAG, "Wake tracking requested")
        handleViewerVisible()
    }

    // ========== MODE MANAGEMENT ==========

    private fun checkAndUpdateMode() {
        if (!isServiceRunning) return

        val now = System.currentTimeMillis()

        when (currentMode) {
            TrackingMode.LIVE -> {
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
                val timeSinceMovement = now - lastMovementTime
                if (timeSinceMovement > STATIONARY_TIMEOUT_MS) {
                    Log.d(TAG, "No movement for ${timeSinceMovement / 1000}s - switching to IDLE")
                    switchMode(TrackingMode.IDLE)
                }
            }

            TrackingMode.IDLE -> {
                // Stays IDLE until speed-based detection or wake button
            }
        }
    }

    private fun switchMode(newMode: TrackingMode) {
        Log.d(TAG, "Switching mode: $currentMode -> $newMode")
        currentMode = newMode

        removeLocationUpdates()

        when (newMode) {
            TrackingMode.LIVE -> acquireTimeLimitedWakeLock()
            else -> releaseWakeLock()
        }

        requestLocationUpdates(newMode)
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
     * Uses speed to detect movement and switch between IDLE/MOVING.
     */
    private fun onLocationReceived(location: Location) {
        Log.d(TAG, "Location received: ${location.latitude}, ${location.longitude} " +
                "accuracy=${location.accuracy}m speed=${location.speed}m/s mode=$currentMode")

        // Speed-based movement detection
        if (location.hasSpeed() && location.speed > prefs.speedThresholdMps) {
            lastMovementTime = System.currentTimeMillis()

            if (currentMode == TrackingMode.IDLE) {
                Log.d(TAG, "Speed ${location.speed} > threshold ${prefs.speedThresholdMps} - switching to MOVING")
                switchMode(TrackingMode.MOVING)
            }
        }

        // Also detect movement by comparing to last known position
        lastLocation?.let { prev ->
            val distance = location.distanceTo(prev)
            val timeDiff = (location.time - prev.time) / 1000f
            if (timeDiff > 0 && distance / timeDiff > prefs.speedThresholdMps) {
                lastMovementTime = System.currentTimeMillis()
                if (currentMode == TrackingMode.IDLE) {
                    Log.d(TAG, "Calculated speed ${distance / timeDiff} m/s - switching to MOVING")
                    switchMode(TrackingMode.MOVING)
                }
            }
        }

        // Skip upload if auth blocked or in backoff
        if (prefs.isAuthBlocked()) {
            Log.d(TAG, "Auth blocked, skipping upload")
            updateNotificationText("Login required")
            lastLocation = location
            return
        }

        if (prefs.isInBackoff()) {
            Log.d(TAG, "In backoff, skipping upload")
            lastLocation = location
            return
        }

        lastLocation = location
        uploader.uploadLocation(location, currentMode.name.lowercase())
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
        releaseWakeLock()

        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }
}
