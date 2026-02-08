package za.co.relatives.app.tracking

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
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import za.co.relatives.app.R
import za.co.relatives.app.data.QueuedLocationEntity
import za.co.relatives.app.data.TrackingStore

/**
 * TrackingService — foreground service that collects device location.
 *
 * Responsibilities (and nothing else):
 *  - Get device location at adaptive intervals
 *  - Detect motion (IDLE vs MOVING) and adjust accordingly
 *  - Write locations into TrackingStore (offline queue)
 *  - Trigger LocationUploadWorker to push to server
 *  - Never calls the network directly
 *
 * Only starts when the user explicitly enables tracking via the toggle.
 */
class TrackingService : Service() {

    companion object {
        private const val TAG = "TrackingService"

        const val ACTION_START = "za.co.relatives.app.tracking.START"
        const val ACTION_STOP = "za.co.relatives.app.tracking.STOP"
        const val ACTION_WAKE = "za.co.relatives.app.tracking.WAKE"

        private const val CHANNEL_ID = "tracking_channel"
        private const val NOTIFICATION_ID = 9001

        private const val WAKELOCK_TAG = "Relatives::TrackingWakeLock"
        private const val WAKELOCK_TIMEOUT_MS = 10L * 60 * 1000

        // Motion thresholds
        private const val SPEED_MOVING_THRESHOLD = 1.0f   // m/s (~3.6 km/h)
        private const val DISTANCE_MOVING_THRESHOLD = 50f  // metres
        private const val IDLE_TIMEOUT_MS = 2L * 60 * 1000 // 2 min of no movement -> IDLE

        // Intervals
        private const val MOVING_INTERVAL_MS = 15_000L     // 15s when moving
        private const val IDLE_INTERVAL_MS = 120_000L      // 2 min when idle
        private const val WAKE_INTERVAL_MS = 5_000L        // 5s burst for wake
        private const val WAKE_DURATION_MS = 30_000L       // 30s burst duration

        private const val HEARTBEAT_INTERVAL_MS = 5L * 60 * 1000 // 5 min heartbeat even when idle

        fun start(context: Context) {
            val intent = Intent(context, TrackingService::class.java).apply {
                action = ACTION_START
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }

        fun stop(context: Context) {
            context.startService(
                Intent(context, TrackingService::class.java).apply { action = ACTION_STOP }
            )
        }

        fun wake(context: Context) {
            context.startService(
                Intent(context, TrackingService::class.java).apply { action = ACTION_WAKE }
            )
        }
    }

    // ── State ───────────────────────────────────────────────────────────

    enum class Mode { IDLE, MOVING, WAKE }

    private lateinit var fusedClient: FusedLocationProviderClient
    private lateinit var store: TrackingStore

    private var currentMode = Mode.IDLE
    private var lastLocation: Location? = null
    private var lastEnqueueTime = 0L
    private var lastHeartbeatTime = 0L
    private var wakeStartTime = 0L
    private var wakeLock: PowerManager.WakeLock? = null

    private val locationCallback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            result.lastLocation?.let { onLocationReceived(it) }
        }
    }

    private val batteryReceiver = object : BroadcastReceiver() {
        override fun onReceive(ctx: Context?, intent: Intent?) {
            applyMode(currentMode)
        }
    }

    // ── Lifecycle ───────────────────────────────────────────────────────

    override fun onCreate() {
        super.onCreate()
        fusedClient = LocationServices.getFusedLocationProviderClient(this)
        store = TrackingStore(this)
        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> doStart()
            ACTION_STOP -> doStop()
            ACTION_WAKE -> doWake()
            else -> doStart()
        }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        stopLocationUpdates()
        releaseWakeLock()
        try { unregisterReceiver(batteryReceiver) } catch (_: Exception) {}
        super.onDestroy()
    }

    // ── Start / Stop / Wake ─────────────────────────────────────────────

    private fun doStart() {
        Log.i(TAG, "Starting tracking")
        persistEnabled(true)
        startForeground(NOTIFICATION_ID, buildNotification())
        acquireWakeLock()
        registerReceiver(batteryReceiver, IntentFilter(Intent.ACTION_BATTERY_CHANGED))

        // Start in IDLE. Will transition to MOVING when motion detected.
        applyMode(Mode.IDLE)
        lastHeartbeatTime = System.currentTimeMillis()
    }

    private fun doStop() {
        Log.i(TAG, "Stopping tracking")
        persistEnabled(false)
        stopLocationUpdates()
        releaseWakeLock()
        try { unregisterReceiver(batteryReceiver) } catch (_: Exception) {}
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    private fun doWake() {
        Log.d(TAG, "Wake burst triggered")
        wakeStartTime = SystemClock.elapsedRealtime()
        applyMode(Mode.WAKE)
    }

    // ── Mode application ────────────────────────────────────────────────

    private fun applyMode(mode: Mode) {
        currentMode = mode
        stopLocationUpdates()

        val request = buildLocationRequest(mode)
        try {
            fusedClient.requestLocationUpdates(request, locationCallback, mainLooper)
            Log.d(TAG, "Mode=$mode interval=${request.intervalMillis}ms")
        } catch (e: SecurityException) {
            Log.e(TAG, "Location permission missing", e)
        }
        updateNotification()
    }

    private fun buildLocationRequest(mode: Mode): LocationRequest {
        val battery = batteryBucket()

        // Critical battery: passive only
        if (battery == Battery.CRITICAL) {
            return LocationRequest.Builder(Priority.PRIORITY_PASSIVE, 5 * 60 * 1000L)
                .setMinUpdateDistanceMeters(250f)
                .build()
        }

        // Low battery: lenient
        if (battery == Battery.LOW) {
            return LocationRequest.Builder(
                Priority.PRIORITY_BALANCED_POWER_ACCURACY, 3 * 60 * 1000L
            ).setMinUpdateDistanceMeters(150f).build()
        }

        return when (mode) {
            Mode.WAKE -> LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, WAKE_INTERVAL_MS)
                .setMinUpdateDistanceMeters(0f)
                .build()
            Mode.MOVING -> LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, MOVING_INTERVAL_MS)
                .setMinUpdateDistanceMeters(15f)
                .setMaxUpdateDelayMillis(30_000L)
                .build()
            Mode.IDLE -> LocationRequest.Builder(
                Priority.PRIORITY_BALANCED_POWER_ACCURACY, IDLE_INTERVAL_MS
            )
                .setMinUpdateDistanceMeters(80f)
                .setMaxUpdateDelayMillis(5 * 60 * 1000L)
                .build()
        }
    }

    @Suppress("MissingPermission")
    private fun stopLocationUpdates() {
        try { fusedClient.removeLocationUpdates(locationCallback) } catch (_: Exception) {}
    }

    // ── Location handling ───────────────────────────────────────────────

    private fun onLocationReceived(location: Location) {
        // WAKE settle check
        if (currentMode == Mode.WAKE) {
            val elapsed = SystemClock.elapsedRealtime() - wakeStartTime
            if (elapsed >= WAKE_DURATION_MS) {
                Log.d(TAG, "Wake burst done, returning to MOVING")
                applyMode(Mode.MOVING)
            }
        }

        val prev = lastLocation

        // Motion detection: IDLE -> MOVING
        if (currentMode == Mode.IDLE && prev != null) {
            val distance = prev.distanceTo(location)
            val speed = location.speed
            if (speed > SPEED_MOVING_THRESHOLD || distance > DISTANCE_MOVING_THRESHOLD) {
                Log.d(TAG, "Motion detected (speed=${speed}m/s dist=${distance}m)")
                lastLocation = location
                applyMode(Mode.MOVING)
            }
        }

        // Stillness detection: MOVING -> IDLE
        if (currentMode == Mode.MOVING && prev != null) {
            val distance = prev.distanceTo(location)
            if (distance < 15f) {
                val timeSinceLast = System.currentTimeMillis() - lastEnqueueTime
                if (timeSinceLast > IDLE_TIMEOUT_MS) {
                    Log.d(TAG, "Appears stationary, reverting to IDLE")
                    applyMode(Mode.IDLE)
                }
            }
        }

        lastLocation = location

        // Dedup: only enqueue if moved enough
        if (store.shouldEnqueue(location) || currentMode == Mode.WAKE) {
            enqueue(location)
        } else {
            maybeHeartbeat(location)
        }
    }

    private fun maybeHeartbeat(location: Location) {
        val now = System.currentTimeMillis()
        if (now - lastHeartbeatTime >= HEARTBEAT_INTERVAL_MS) {
            Log.d(TAG, "Heartbeat enqueue")
            enqueue(location)
        }
    }

    private fun enqueue(location: Location) {
        val now = System.currentTimeMillis()
        lastEnqueueTime = now
        lastHeartbeatTime = now
        store.markEnqueued(location)

        val entity = QueuedLocationEntity(
            lat = location.latitude,
            lng = location.longitude,
            accuracy = if (location.hasAccuracy()) location.accuracy else null,
            altitude = if (location.hasAltitude()) location.altitude else null,
            bearing = if (location.hasBearing()) location.bearing else null,
            speed = if (location.hasSpeed()) location.speed else null,
            speedKmh = if (location.hasSpeed()) location.speed * 3.6f else null,
            isMoving = currentMode == Mode.MOVING || currentMode == Mode.WAKE,
            batteryLevel = getBatteryLevel(),
            timestamp = now,
        )
        store.enqueueLocation(entity)
        scheduleUpload()
    }

    // ── Upload scheduling ───────────────────────────────────────────────

    private fun scheduleUpload() {
        val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()
        val request = OneTimeWorkRequestBuilder<LocationUploadWorker>()
            .setConstraints(constraints)
            .build()
        WorkManager.getInstance(this)
            .enqueueUniqueWork(LocationUploadWorker.WORK_NAME, ExistingWorkPolicy.KEEP, request)
    }

    // ── Battery ─────────────────────────────────────────────────────────

    private enum class Battery { NORMAL, LOW, CRITICAL }

    private fun batteryBucket(): Battery {
        val level = getBatteryLevel() ?: return Battery.NORMAL
        return when {
            level < 10 -> Battery.CRITICAL
            level < 20 -> Battery.LOW
            else -> Battery.NORMAL
        }
    }

    private fun getBatteryLevel(): Int? {
        val bm = getSystemService(BATTERY_SERVICE) as? BatteryManager
        return bm?.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
    }

    // ── Notification ────────────────────────────────────────────────────

    private fun createNotificationChannel() {
        val channel = NotificationChannel(
            CHANNEL_ID,
            getString(R.string.channel_tracking_name),
            NotificationManager.IMPORTANCE_LOW,
        ).apply {
            description = getString(R.string.channel_tracking_desc)
            setShowBadge(false)
            setSound(null, null)
        }
        getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
    }

    private fun buildNotification(): Notification {
        val stopIntent = Intent(this, TrackingService::class.java).apply {
            action = ACTION_STOP
        }
        val stopPending = PendingIntent.getService(
            this, 0, stopIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val text = when (currentMode) {
            Mode.IDLE -> getString(R.string.tracking_notif_idle)
            Mode.MOVING -> getString(R.string.tracking_notif_moving)
            Mode.WAKE -> getString(R.string.tracking_notif_wake)
        }

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(R.string.app_name))
            .setContentText(text)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setOngoing(true)
            .setSilent(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .addAction(0, getString(R.string.tracking_notif_stop), stopPending)
            .build()
    }

    private fun updateNotification() {
        try {
            getSystemService(NotificationManager::class.java)
                .notify(NOTIFICATION_ID, buildNotification())
        } catch (_: Exception) {}
    }

    // ── Wake lock ───────────────────────────────────────────────────────

    private fun acquireWakeLock() {
        if (wakeLock == null) {
            val pm = getSystemService(POWER_SERVICE) as PowerManager
            wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, WAKELOCK_TAG).apply {
                acquire(WAKELOCK_TIMEOUT_MS)
            }
        }
    }

    private fun releaseWakeLock() {
        wakeLock?.let { if (it.isHeld) it.release(); wakeLock = null }
    }

    // ── Preferences ─────────────────────────────────────────────────────

    private fun persistEnabled(enabled: Boolean) {
        getSharedPreferences("relatives_prefs", MODE_PRIVATE)
            .edit().putBoolean("tracking_enabled", enabled).apply()
    }
}
