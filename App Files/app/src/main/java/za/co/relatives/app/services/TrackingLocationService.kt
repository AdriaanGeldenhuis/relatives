package za.co.relatives.app.services

import android.app.AlarmManager
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.location.Location
import android.os.BatteryManager
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import android.os.SystemClock
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
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
import za.co.relatives.app.R
import za.co.relatives.app.data.QueuedLocationEntity
import za.co.relatives.app.data.TrackingDatabase
import za.co.relatives.app.workers.LocationUploadWorker
import za.co.relatives.app.workers.TrackingServiceChecker
import java.util.concurrent.TimeUnit

/**
 * Foreground service that tracks the device location using three adaptive
 * modes: [TrackingMode.IDLE], [TrackingMode.MOVING], and [TrackingMode.BURST].
 *
 * Locations are written to a Room-backed offline queue and periodically
 * flushed to the backend by [LocationUploadWorker].
 */
class TrackingLocationService : Service() {

    // ------------------------------------------------------------------ //
    //  Constants
    // ------------------------------------------------------------------ //

    companion object {
        private const val TAG = "TrackingLocationSvc"

        const val ACTION_START = "za.co.relatives.app.action.START_TRACKING"
        const val ACTION_STOP = "za.co.relatives.app.action.STOP_TRACKING"
        const val ACTION_BURST = "za.co.relatives.app.action.TRIGGER_BURST"
        const val ACTION_BOOST = "za.co.relatives.app.action.BOOST_FOR_SCREEN"
        const val ACTION_KEEPALIVE = "za.co.relatives.app.action.KEEPALIVE"

        private const val NOTIFICATION_ID = 9001
        private const val CHANNEL_ID = "tracking_channel"

        private const val WAKELOCK_TAG = "Relatives::TrackingWakeLock"
        private const val WAKELOCK_TIMEOUT_MS = 10L * 60 * 1000 // 10 minutes

        private const val KEEPALIVE_INTERVAL_MS = 5L * 60 * 1000 // 5 minutes
        private const val HEARTBEAT_INTERVAL_MS = 5L * 60 * 1000 // 5 minutes
        private const val BURST_SETTLE_DELAY_MS = 30_000L          // 30 seconds

        // Distance gates (metres)
        private const val DISTANCE_GATE_MOVING = 20f
        private const val DISTANCE_GATE_IDLE = 100f

        // Motion detection thresholds
        private const val MOTION_SPEED_THRESHOLD = 1.0f   // m/s
        private const val MOTION_DISTANCE_THRESHOLD = 50f  // metres

        private const val PREF_NAME = "relatives_prefs"
        private const val PREF_TRACKING_ENABLED = "tracking_enabled"
        private const val PREF_LAST_UPLOAD = "last_upload_time"

        /** Convenience helper used by other components to start the service. */
        fun start(context: Context) {
            val intent = Intent(context, TrackingLocationService::class.java).apply {
                action = ACTION_START
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }

        /** Convenience helper to stop the service. */
        fun stop(context: Context) {
            val intent = Intent(context, TrackingLocationService::class.java).apply {
                action = ACTION_STOP
            }
            context.startService(intent)
        }

        /** Check whether tracking is enabled in preferences. */
        fun isTrackingEnabled(context: Context): Boolean {
            return context.getSharedPreferences(PREF_NAME, MODE_PRIVATE)
                .getBoolean(PREF_TRACKING_ENABLED, false)
        }
    }

    // ------------------------------------------------------------------ //
    //  Tracking modes
    // ------------------------------------------------------------------ //

    enum class TrackingMode {
        /** Low-power: balanced accuracy, long intervals. */
        IDLE,
        /** Active movement: high accuracy, short intervals. */
        MOVING,
        /** Immediate high-accuracy fix, settles to MOVING after 30 s. */
        BURST
    }

    // ------------------------------------------------------------------ //
    //  Battery level buckets
    // ------------------------------------------------------------------ //

    private enum class BatteryBucket { NORMAL, LOW, CRITICAL }

    // ------------------------------------------------------------------ //
    //  State
    // ------------------------------------------------------------------ //

    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    private lateinit var fusedClient: FusedLocationProviderClient
    private lateinit var dao: za.co.relatives.app.data.QueuedLocationDao

    private var currentMode: TrackingMode = TrackingMode.IDLE
    private var lastLocation: Location? = null
    private var lastEnqueueTime: Long = 0L
    private var lastHeartbeatTime: Long = 0L
    private var burstStartTime: Long = 0L

    private var wakeLock: PowerManager.WakeLock? = null

    // Server-provided overrides (applied when an upload succeeds).
    private var serverUpdateIntervalMs: Long? = null

    // ------------------------------------------------------------------ //
    //  Location callback
    // ------------------------------------------------------------------ //

    private val locationCallback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            result.lastLocation?.let { handleNewLocation(it) }
        }
    }

    // ------------------------------------------------------------------ //
    //  Battery receiver
    // ------------------------------------------------------------------ //

    private val batteryReceiver = object : BroadcastReceiver() {
        override fun onReceive(ctx: Context?, intent: Intent?) {
            // Battery level changed -- re-evaluate tracking parameters.
            applyTrackingMode(currentMode)
        }
    }

    // ------------------------------------------------------------------ //
    //  Lifecycle
    // ------------------------------------------------------------------ //

    override fun onCreate() {
        super.onCreate()
        fusedClient = LocationServices.getFusedLocationProviderClient(this)
        dao = TrackingDatabase.getInstance(this).queuedLocationDao()
        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> startTracking()
            ACTION_STOP -> stopTracking()
            ACTION_BURST -> triggerBurst()
            ACTION_BOOST -> boostForScreen()
            ACTION_KEEPALIVE -> onKeepalive()
            else -> startTracking() // Default to start.
        }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        stopLocationUpdates()
        releaseWakeLock()
        cancelKeepaliveAlarm()
        try { unregisterReceiver(batteryReceiver) } catch (_: Exception) {}
        serviceScope.cancel()
        super.onDestroy()
    }

    // ------------------------------------------------------------------ //
    //  Start / Stop
    // ------------------------------------------------------------------ //

    private fun startTracking() {
        Log.i(TAG, "Starting tracking service")

        // Persist preference so boot receiver knows to restart.
        getSharedPreferences(PREF_NAME, MODE_PRIVATE)
            .edit().putBoolean(PREF_TRACKING_ENABLED, true).apply()

        startForeground(NOTIFICATION_ID, buildNotification())
        acquireWakeLock()

        // Listen for battery changes.
        registerReceiver(batteryReceiver, IntentFilter(Intent.ACTION_BATTERY_CHANGED))

        // Start with IDLE, will transition to MOVING if motion detected.
        applyTrackingMode(TrackingMode.IDLE)
        scheduleKeepaliveAlarm()
        scheduleServiceChecker()
        scheduleUploadWorker()

        lastHeartbeatTime = System.currentTimeMillis()
    }

    private fun stopTracking() {
        Log.i(TAG, "Stopping tracking service")
        getSharedPreferences(PREF_NAME, MODE_PRIVATE)
            .edit().putBoolean(PREF_TRACKING_ENABLED, false).apply()

        stopLocationUpdates()
        releaseWakeLock()
        cancelKeepaliveAlarm()
        try { unregisterReceiver(batteryReceiver) } catch (_: Exception) {}

        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    // ------------------------------------------------------------------ //
    //  Public mode triggers
    // ------------------------------------------------------------------ //

    /**
     * Temporarily boost to MOVING accuracy while the tracking screen is
     * visible. The mode will naturally settle back to IDLE when the device
     * stops moving.
     */
    fun boostForScreen() {
        if (currentMode == TrackingMode.IDLE) {
            Log.d(TAG, "Boosting to MOVING for screen visibility")
            applyTrackingMode(TrackingMode.MOVING)
        }
    }

    /**
     * Switch to BURST mode for an immediate high-accuracy fix (e.g. when
     * another family member taps "wake device"). After [BURST_SETTLE_DELAY_MS]
     * the mode reverts to MOVING.
     */
    fun triggerBurst() {
        Log.d(TAG, "Triggering BURST mode")
        burstStartTime = SystemClock.elapsedRealtime()
        applyTrackingMode(TrackingMode.BURST)
    }

    // ------------------------------------------------------------------ //
    //  Tracking mode application
    // ------------------------------------------------------------------ //

    private fun applyTrackingMode(mode: TrackingMode) {
        currentMode = mode
        stopLocationUpdates()

        val battery = currentBatteryBucket()
        val request = buildLocationRequest(mode, battery)

        try {
            fusedClient.requestLocationUpdates(
                request,
                locationCallback,
                mainLooper
            )
            Log.d(TAG, "Applied mode=$mode battery=$battery interval=${request.intervalMillis}ms")
        } catch (e: SecurityException) {
            Log.e(TAG, "Location permission missing", e)
        }

        updateNotification()
    }

    @Suppress("MissingPermission")
    private fun stopLocationUpdates() {
        try {
            fusedClient.removeLocationUpdates(locationCallback)
        } catch (_: Exception) {}
    }

    /**
     * Build a [LocationRequest] appropriate for the given [mode] and
     * [battery] level, honouring server-provided overrides when set.
     */
    private fun buildLocationRequest(
        mode: TrackingMode,
        battery: BatteryBucket
    ): LocationRequest {
        return when {
            // Critical battery: passive only.
            battery == BatteryBucket.CRITICAL -> {
                LocationRequest.Builder(Priority.PRIORITY_PASSIVE, 5 * 60 * 1000L)
                    .setMinUpdateDistanceMeters(250f)
                    .setMinUpdateIntervalMillis(3 * 60 * 1000L)
                    .build()
            }
            // Low battery: lenient intervals.
            battery == BatteryBucket.LOW -> {
                LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, 3 * 60 * 1000L)
                    .setMinUpdateDistanceMeters(150f)
                    .setMinUpdateIntervalMillis(2 * 60 * 1000L)
                    .build()
            }
            // BURST mode.
            mode == TrackingMode.BURST -> {
                LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, 5_000L)
                    .setMinUpdateIntervalMillis(3_000L)
                    .setMinUpdateDistanceMeters(0f)
                    .build()
            }
            // MOVING mode.
            mode == TrackingMode.MOVING -> {
                val interval = serverUpdateIntervalMs ?: 10_000L
                LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, interval)
                    .setMinUpdateDistanceMeters(20f)
                    .setMinUpdateIntervalMillis(interval)
                    .setMaxUpdateDelayMillis(30_000L)
                    .build()
            }
            // IDLE mode (default).
            else -> {
                LocationRequest.Builder(
                    Priority.PRIORITY_BALANCED_POWER_ACCURACY,
                    serverUpdateIntervalMs ?: 120_000L
                )
                    .setMinUpdateDistanceMeters(100f)
                    .setMinUpdateIntervalMillis(120_000L)
                    .setMaxUpdateDelayMillis(300_000L)
                    .build()
            }
        }
    }

    // ------------------------------------------------------------------ //
    //  Location handling
    // ------------------------------------------------------------------ //

    private fun handleNewLocation(location: Location) {
        // --- BURST settle check ---------------------------------------- //
        if (currentMode == TrackingMode.BURST) {
            val elapsed = SystemClock.elapsedRealtime() - burstStartTime
            if (elapsed >= BURST_SETTLE_DELAY_MS) {
                Log.d(TAG, "BURST settle period elapsed, transitioning to MOVING")
                applyTrackingMode(TrackingMode.MOVING)
            }
        }

        // --- Distance gate --------------------------------------------- //
        val prev = lastLocation
        if (prev != null) {
            val distance = prev.distanceTo(location)
            val gate = when (currentMode) {
                TrackingMode.IDLE -> DISTANCE_GATE_IDLE
                TrackingMode.MOVING -> DISTANCE_GATE_MOVING
                TrackingMode.BURST -> 0f // Accept all in burst.
            }
            if (distance < gate && currentMode != TrackingMode.BURST) {
                // Still within the gate -- check for heartbeat instead.
                maybeHeartbeat(location)
                return
            }

            // --- Motion detection (IDLE -> MOVING) --------------------- //
            if (currentMode == TrackingMode.IDLE) {
                val speed = location.speed
                if (speed > MOTION_SPEED_THRESHOLD || distance > MOTION_DISTANCE_THRESHOLD) {
                    Log.d(TAG, "Motion detected (speed=${speed} m/s, dist=${distance} m), switching to MOVING")
                    lastLocation = location
                    applyTrackingMode(TrackingMode.MOVING)
                }
            }

            // --- Stillness detection (MOVING -> IDLE) ------------------ //
            if (currentMode == TrackingMode.MOVING && distance < DISTANCE_GATE_MOVING) {
                val timeSinceLast = System.currentTimeMillis() - lastEnqueueTime
                if (timeSinceLast > 2 * 60 * 1000) {
                    Log.d(TAG, "Appears stationary, reverting to IDLE")
                    applyTrackingMode(TrackingMode.IDLE)
                }
            }
        }

        lastLocation = location
        enqueueLocation(location)
    }

    /**
     * If nothing has been enqueued for [HEARTBEAT_INTERVAL_MS], force a
     * heartbeat location so the server knows the device is alive.
     */
    private fun maybeHeartbeat(location: Location) {
        val now = System.currentTimeMillis()
        if (now - lastHeartbeatTime >= HEARTBEAT_INTERVAL_MS) {
            Log.d(TAG, "Heartbeat: enqueuing idle location")
            enqueueLocation(location)
            lastHeartbeatTime = now
        }
    }

    private fun enqueueLocation(location: Location) {
        val now = System.currentTimeMillis()
        lastEnqueueTime = now
        lastHeartbeatTime = now

        val speedKmh = if (location.hasSpeed()) (location.speed * 3.6) else null

        val entity = QueuedLocationEntity(
            lat = location.latitude,
            lng = location.longitude,
            accuracy = if (location.hasAccuracy()) location.accuracy.toDouble() else null,
            altitude = if (location.hasAltitude()) location.altitude else null,
            bearing = if (location.hasBearing()) location.bearing.toDouble() else null,
            speed = if (location.hasSpeed()) location.speed.toDouble() else null,
            speedKmh = speedKmh,
            isMoving = currentMode == TrackingMode.MOVING || currentMode == TrackingMode.BURST,
            batteryLevel = getBatteryLevel(),
            timestamp = now
        )

        serviceScope.launch {
            try {
                dao.insert(entity)
                dao.trimToMaxSize(300)
                Log.d(TAG, "Enqueued location (${entity.lat}, ${entity.lng}) mode=$currentMode")
            } catch (e: Exception) {
                Log.e(TAG, "Failed to enqueue location", e)
            }
        }

        // Nudge the upload worker.
        scheduleUploadWorker()
    }

    // ------------------------------------------------------------------ //
    //  Battery helpers
    // ------------------------------------------------------------------ //

    private fun currentBatteryBucket(): BatteryBucket {
        val level = getBatteryLevel() ?: return BatteryBucket.NORMAL
        return when {
            level < 10 -> BatteryBucket.CRITICAL
            level < 20 -> BatteryBucket.LOW
            else -> BatteryBucket.NORMAL
        }
    }

    private fun getBatteryLevel(): Int? {
        val bm = getSystemService(BATTERY_SERVICE) as? BatteryManager
        return bm?.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
    }

    // ------------------------------------------------------------------ //
    //  Server settings
    // ------------------------------------------------------------------ //

    /**
     * Called by [LocationUploadWorker] after a successful batch upload so the
     * service can apply any server-pushed settings.
     */
    fun applyServerSettings(settings: Map<String, Any?>) {
        val interval = (settings["update_interval"] as? Number)?.toLong()
        if (interval != null && interval > 0) {
            serverUpdateIntervalMs = interval * 1000 // Server sends seconds.
            Log.d(TAG, "Server update_interval applied: ${interval}s")
            // Re-apply current mode with new interval.
            applyTrackingMode(currentMode)
        }
    }

    // ------------------------------------------------------------------ //
    //  Foreground notification
    // ------------------------------------------------------------------ //

    private fun createNotificationChannel() {
        val channel = NotificationChannel(
            CHANNEL_ID,
            "Location Tracking",
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = "Keeps location tracking running in the background"
            setShowBadge(false)
            setSound(null, null)
        }
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(channel)
    }

    private fun buildNotification(): Notification {
        val stopIntent = Intent(this, TrackingLocationService::class.java).apply {
            action = ACTION_STOP
        }
        val stopPending = PendingIntent.getService(
            this, 0, stopIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val contentText = when (currentMode) {
            TrackingMode.IDLE -> "Monitoring location"
            TrackingMode.MOVING -> "Tracking movement"
            TrackingMode.BURST -> "Getting precise location..."
        }

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("Relatives")
            .setContentText(contentText)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setOngoing(true)
            .setSilent(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .addAction(0, "Stop", stopPending)
            .build()
    }

    private fun updateNotification() {
        try {
            val nm = getSystemService(NotificationManager::class.java)
            nm.notify(NOTIFICATION_ID, buildNotification())
        } catch (_: Exception) {}
    }

    // ------------------------------------------------------------------ //
    //  Wake lock
    // ------------------------------------------------------------------ //

    private fun acquireWakeLock() {
        if (wakeLock == null) {
            val pm = getSystemService(POWER_SERVICE) as PowerManager
            wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, WAKELOCK_TAG).apply {
                acquire(WAKELOCK_TIMEOUT_MS)
            }
        }
    }

    private fun releaseWakeLock() {
        wakeLock?.let {
            if (it.isHeld) it.release()
            wakeLock = null
        }
    }

    // ------------------------------------------------------------------ //
    //  Keep-alive alarm (safety net)
    // ------------------------------------------------------------------ //

    private fun scheduleKeepaliveAlarm() {
        val am = getSystemService(ALARM_SERVICE) as AlarmManager
        val intent = Intent(this, TrackingLocationService::class.java).apply {
            action = ACTION_KEEPALIVE
        }
        val pi = PendingIntent.getService(
            this, 1, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        am.setRepeating(
            AlarmManager.ELAPSED_REALTIME_WAKEUP,
            SystemClock.elapsedRealtime() + KEEPALIVE_INTERVAL_MS,
            KEEPALIVE_INTERVAL_MS,
            pi
        )
    }

    private fun cancelKeepaliveAlarm() {
        val am = getSystemService(ALARM_SERVICE) as AlarmManager
        val intent = Intent(this, TrackingLocationService::class.java).apply {
            action = ACTION_KEEPALIVE
        }
        val pi = PendingIntent.getService(
            this, 1, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        am.cancel(pi)
    }

    /**
     * Fires every 5 minutes as a safety net. Re-acquires wake lock and
     * refreshes location request in case the fused provider silently stopped.
     */
    private fun onKeepalive() {
        Log.d(TAG, "Keepalive ping")
        acquireWakeLock()
        applyTrackingMode(currentMode)
        scheduleUploadWorker()
    }

    // ------------------------------------------------------------------ //
    //  WorkManager scheduling
    // ------------------------------------------------------------------ //

    private fun scheduleUploadWorker() {
        val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()
        val request = OneTimeWorkRequestBuilder<LocationUploadWorker>()
            .setConstraints(constraints)
            .build()
        WorkManager.getInstance(this)
            .enqueueUniqueWork(
                LocationUploadWorker.WORK_NAME,
                ExistingWorkPolicy.KEEP,
                request
            )
    }

    private fun scheduleServiceChecker() {
        val request = PeriodicWorkRequestBuilder<TrackingServiceChecker>(
            15, TimeUnit.MINUTES
        ).build()
        WorkManager.getInstance(this)
            .enqueueUniquePeriodicWork(
                TrackingServiceChecker.WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                request
            )
    }
}
