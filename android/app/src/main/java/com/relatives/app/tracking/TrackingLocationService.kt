package com.relatives.app.tracking

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.location.Location
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.PowerManager
import android.util.Log
import androidx.core.app.NotificationCompat
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.relatives.app.MainActivity
import com.relatives.app.R
import java.util.concurrent.Executors

/**
 * Foreground location tracking service with LIVE/MOVING/IDLE modes.
 *
 * Mode behavior:
 * - LIVE: 10s interval, HIGH_ACCURACY, 10m min distance (when viewer is watching)
 * - MOVING: Settings-based interval, BALANCED/HIGH accuracy (when device is moving)
 * - IDLE: 10 min interval, LOW_POWER, 100m min distance (when stationary)
 *
 * WakeLock: Time-limited (2 min max) only in LIVE mode.
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

        // Extras
        const val EXTRA_INTERVAL_SECONDS = "interval_seconds"
        const val EXTRA_HIGH_ACCURACY = "high_accuracy"

        // Mode intervals
        private const val LIVE_INTERVAL_MS = 10_000L       // 10 seconds
        private const val LIVE_MIN_DISTANCE_M = 10f
        private const val IDLE_INTERVAL_MS = 600_000L      // 10 minutes
        private const val IDLE_MIN_DISTANCE_M = 100f
        private const val DEFAULT_MOVING_INTERVAL_MS = 60_000L // 1 minute

        // Viewer keepalive
        private const val VIEWER_LIVE_DURATION_MS = 600_000L // 10 minutes

        // WakeLock
        private const val WAKELOCK_TAG = "relatives:tracking"
        private const val WAKELOCK_TIMEOUT_MS = 120_000L    // 2 minutes

        // Idle detection
        private const val IDLE_THRESHOLD_MS = 180_000L      // 3 min without movement
        private const val MOVEMENT_SPEED_THRESHOLD = 0.5f   // m/s

        // Mode check interval
        private const val MODE_CHECK_INTERVAL_MS = 60_000L  // 1 minute

        // Notification
        private const val NOTIFICATION_CHANNEL_ID = "tracking_channel"
        private const val NOTIFICATION_ID = 1001

        /**
         * Start tracking from outside the service.
         */
        fun startTracking(context: Context) {
            Log.d(TAG, "startTracking called")
            val prefs = PreferencesManager(context)
            prefs.enableTracking()

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
            Log.d(TAG, "stopTracking called")
            val prefs = PreferencesManager(context)
            prefs.disableTracking()

            val intent = Intent(context, TrackingLocationService::class.java).apply {
                action = ACTION_STOP_TRACKING
            }
            context.startService(intent)
        }
    }

    enum class TrackingMode { LIVE, MOVING, IDLE }

    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private lateinit var prefs: PreferencesManager
    private lateinit var uploader: LocationUploader
    private lateinit var powerManager: PowerManager

    private var currentMode = TrackingMode.MOVING
    private var viewerLiveUntil = 0L
    private var lastMovementTime = 0L
    private var wakeLock: PowerManager.WakeLock? = null

    private val handler = Handler(Looper.getMainLooper())
    private val uploadExecutor = Executors.newSingleThreadExecutor()

    private val locationCallback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            val location = result.lastLocation ?: return
            Log.d(TAG, "Location received: ${location.latitude}, ${location.longitude} " +
                    "(accuracy=${location.accuracy}m, speed=${location.speed}m/s)")

            // Update movement tracking
            if (location.speed > MOVEMENT_SPEED_THRESHOLD) {
                lastMovementTime = System.currentTimeMillis()
            }

            // Upload on background thread
            uploadExecutor.execute {
                uploader.upload(location)
            }
        }
    }

    private val modeCheckRunnable = object : Runnable {
        override fun run() {
            checkAndUpdateMode()
            handler.postDelayed(this, MODE_CHECK_INTERVAL_MS)
        }
    }

    override fun onCreate() {
        super.onCreate()
        Log.d(TAG, "Service created")

        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)
        prefs = PreferencesManager(this)
        uploader = LocationUploader(this)
        powerManager = getSystemService(Context.POWER_SERVICE) as PowerManager

        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        Log.d(TAG, "onStartCommand: action=${intent?.action}")

        when (intent?.action) {
            ACTION_START_TRACKING -> handleStartTracking()
            ACTION_STOP_TRACKING -> handleStopTracking()
            ACTION_VIEWER_VISIBLE -> handleViewerVisible()
            ACTION_VIEWER_HIDDEN -> handleViewerHidden()
            ACTION_UPDATE_SETTINGS -> {
                val interval = intent.getIntExtra(EXTRA_INTERVAL_SECONDS, 60)
                val highAccuracy = intent.getBooleanExtra(EXTRA_HIGH_ACCURACY, true)
                handleUpdateSettings(interval, highAccuracy)
            }
            else -> {
                // Unknown action or null - if tracking is enabled, start; otherwise stop
                if (prefs.isTrackingEnabled) {
                    handleStartTracking()
                } else {
                    stopSelfCleanly()
                }
            }
        }

        // NOT_STICKY: Don't restart if killed, BootReceiver handles restarts
        return START_NOT_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        Log.d(TAG, "Service destroyed")
        handler.removeCallbacks(modeCheckRunnable)
        releaseWakeLock()
        try {
            fusedLocationClient.removeLocationUpdates(locationCallback)
        } catch (e: Exception) {
            Log.w(TAG, "Error removing location updates: ${e.message}")
        }
        super.onDestroy()
    }

    // ============ ACTION HANDLERS ============

    private fun handleStartTracking() {
        Log.d(TAG, "handleStartTracking")

        // Start as foreground service
        val notification = buildNotification("Starting tracking...")
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }

        // Initialize movement tracking
        lastMovementTime = System.currentTimeMillis()

        // Start in MOVING mode
        switchMode(TrackingMode.MOVING)

        // Start periodic mode checker
        handler.removeCallbacks(modeCheckRunnable)
        handler.postDelayed(modeCheckRunnable, MODE_CHECK_INTERVAL_MS)

        Log.d(TAG, "Tracking service started successfully")
    }

    private fun handleStopTracking() {
        Log.d(TAG, "handleStopTracking")
        prefs.disableTracking()
        stopSelfCleanly()
    }

    private fun handleViewerVisible() {
        Log.d(TAG, "handleViewerVisible")
        viewerLiveUntil = System.currentTimeMillis() + VIEWER_LIVE_DURATION_MS
        if (currentMode != TrackingMode.LIVE) {
            switchMode(TrackingMode.LIVE)
        }
    }

    private fun handleViewerHidden() {
        Log.d(TAG, "handleViewerHidden")
        // Don't immediately drop - let viewerLiveUntil expire naturally
        // This prevents flickering when user switches tabs briefly
    }

    private fun handleUpdateSettings(intervalSeconds: Int, highAccuracy: Boolean) {
        Log.d(TAG, "handleUpdateSettings: interval=$intervalSeconds, highAccuracy=$highAccuracy")
        prefs.movingIntervalSeconds = intervalSeconds
        prefs.highAccuracyMode = highAccuracy

        // Re-apply if currently in MOVING mode
        if (currentMode == TrackingMode.MOVING) {
            switchMode(TrackingMode.MOVING)
        }
    }

    // ============ MODE MANAGEMENT ============

    private fun checkAndUpdateMode() {
        val now = System.currentTimeMillis()

        when {
            // LIVE mode: check if viewer keepalive has expired
            currentMode == TrackingMode.LIVE && now > viewerLiveUntil -> {
                Log.d(TAG, "Viewer keepalive expired, dropping from LIVE")
                val timeSinceMovement = now - lastMovementTime
                if (timeSinceMovement > IDLE_THRESHOLD_MS) {
                    switchMode(TrackingMode.IDLE)
                } else {
                    switchMode(TrackingMode.MOVING)
                }
            }
            // MOVING mode: check if should go IDLE
            currentMode == TrackingMode.MOVING -> {
                val timeSinceMovement = now - lastMovementTime
                if (timeSinceMovement > IDLE_THRESHOLD_MS) {
                    switchMode(TrackingMode.IDLE)
                }
            }
            // IDLE mode: check if movement detected
            currentMode == TrackingMode.IDLE -> {
                val timeSinceMovement = now - lastMovementTime
                if (timeSinceMovement < IDLE_THRESHOLD_MS) {
                    switchMode(TrackingMode.MOVING)
                }
            }
        }
    }

    @Suppress("MissingPermission")
    private fun switchMode(newMode: TrackingMode) {
        Log.d(TAG, "switchMode: $currentMode -> $newMode")
        currentMode = newMode

        // Remove existing location updates
        try {
            fusedLocationClient.removeLocationUpdates(locationCallback)
        } catch (e: Exception) {
            Log.w(TAG, "Error removing updates during mode switch: ${e.message}")
        }

        // Configure location request based on mode
        val locationRequest = when (newMode) {
            TrackingMode.LIVE -> {
                acquireTimeLimitedWakeLock()
                LocationRequest.Builder(
                    Priority.PRIORITY_HIGH_ACCURACY,
                    LIVE_INTERVAL_MS
                ).setMinUpdateDistanceMeters(LIVE_MIN_DISTANCE_M).build()
            }
            TrackingMode.MOVING -> {
                releaseWakeLock()
                val interval = prefs.movingIntervalSeconds * 1000L
                val priority = if (prefs.highAccuracyMode) {
                    Priority.PRIORITY_HIGH_ACCURACY
                } else {
                    Priority.PRIORITY_BALANCED_POWER_ACCURACY
                }
                LocationRequest.Builder(priority, interval).build()
            }
            TrackingMode.IDLE -> {
                releaseWakeLock()
                LocationRequest.Builder(
                    Priority.PRIORITY_LOW_POWER,
                    IDLE_INTERVAL_MS
                ).setMinUpdateDistanceMeters(IDLE_MIN_DISTANCE_M).build()
            }
        }

        // Request new location updates
        try {
            fusedLocationClient.requestLocationUpdates(
                locationRequest,
                locationCallback,
                Looper.getMainLooper()
            )
        } catch (e: SecurityException) {
            Log.e(TAG, "Location permission not granted: ${e.message}")
            stopSelfCleanly()
            return
        }

        // Update notification
        updateNotification(newMode)
    }

    // ============ WAKELOCK ============

    private fun acquireTimeLimitedWakeLock() {
        releaseWakeLock()
        wakeLock = powerManager.newWakeLock(
            PowerManager.PARTIAL_WAKE_LOCK,
            WAKELOCK_TAG
        ).apply {
            acquire(WAKELOCK_TIMEOUT_MS)
        }
        Log.d(TAG, "WakeLock acquired (${WAKELOCK_TIMEOUT_MS}ms timeout)")
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

    // ============ CLEANUP ============

    private fun stopSelfCleanly() {
        Log.d(TAG, "stopSelfCleanly")
        handler.removeCallbacks(modeCheckRunnable)
        releaseWakeLock()
        try {
            fusedLocationClient.removeLocationUpdates(locationCallback)
        } catch (e: Exception) {
            Log.w(TAG, "Error during cleanup: ${e.message}")
        }
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    // ============ NOTIFICATIONS ============

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
            val manager = getSystemService(NotificationManager::class.java)
            manager.createNotificationChannel(channel)
        }
    }

    private fun buildNotification(text: String): Notification {
        val pendingIntent = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        return NotificationCompat.Builder(this, NOTIFICATION_CHANNEL_ID)
            .setContentTitle("Relatives Tracking")
            .setContentText(text)
            .setSmallIcon(R.drawable.ic_launcher_foreground)
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .setSilent(true)
            .build()
    }

    private fun updateNotification(mode: TrackingMode) {
        val text = when (mode) {
            TrackingMode.LIVE -> "LIVE - Sharing location in real-time"
            TrackingMode.MOVING -> "Moving - Tracking active"
            TrackingMode.IDLE -> "Idle - Monitoring for movement"
        }

        val notification = buildNotification(text)
        val manager = getSystemService(NotificationManager::class.java)
        manager.notify(NOTIFICATION_ID, notification)
    }
}
