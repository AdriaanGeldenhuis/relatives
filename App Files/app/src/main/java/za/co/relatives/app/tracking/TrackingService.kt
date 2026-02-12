package za.co.relatives.app.tracking

import android.annotation.SuppressLint
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
import android.content.pm.ServiceInfo
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
import com.google.android.gms.location.ActivityRecognition
import com.google.android.gms.location.ActivityTransition
import com.google.android.gms.location.ActivityTransitionRequest
import com.google.android.gms.location.ActivityTransitionResult
import com.google.android.gms.location.DetectedActivity
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.Geofence
import com.google.android.gms.location.GeofencingClient
import com.google.android.gms.location.GeofencingEvent
import com.google.android.gms.location.GeofencingRequest
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
 *  - Detect motion via Activity Recognition + Geofencing (battery-efficient)
 *  - Write locations into TrackingStore (offline queue)
 *  - Trigger LocationUploadWorker to push to server
 *  - Never calls the network directly
 *
 * Motion detection uses three complementary layers:
 *  1. Activity Recognition Transition API (detects STILL → IN_VEHICLE/WALKING etc.)
 *  2. Geofence EXIT around idle position (100m radius)
 *  3. Location callback speed/distance check (fallback)
 *  4. Safety alarm every 15 min (absolute fallback if all else fails)
 *
 * Only starts when the user explicitly enables tracking via the toggle.
 */
class TrackingService : Service() {

    companion object {
        private const val TAG = "TrackingService"

        const val ACTION_START = "za.co.relatives.app.tracking.START"
        const val ACTION_STOP = "za.co.relatives.app.tracking.STOP"
        const val ACTION_WAKE = "za.co.relatives.app.tracking.WAKE"
        const val ACTION_SAFETY_ALARM = "za.co.relatives.app.tracking.SAFETY_ALARM"
        const val ACTION_ACTIVITY_TRANSITION = "za.co.relatives.app.tracking.ACTIVITY_TRANSITION"
        const val ACTION_GEOFENCE_EVENT = "za.co.relatives.app.tracking.GEOFENCE_EVENT"

        private const val CHANNEL_ID = "tracking_channel"
        private const val NOTIFICATION_ID = 9001

        private const val WAKELOCK_TAG = "Relatives::TrackingWakeLock"
        private const val WAKELOCK_TIMEOUT_MS = 30L * 60 * 1000       // 30 min safety net
        private const val WAKELOCK_HEARTBEAT_MS = 60_000L             // 60s for alarm processing

        // Safety alarm: absolute fallback if activity recognition + geofencing miss
        private const val SAFETY_ALARM_INTERVAL_MS = 15L * 60 * 1000  // 15 min

        // Geofence around idle position — triggers MOVING when user leaves this radius
        private const val GEOFENCE_RADIUS_M = 100f
        private const val GEOFENCE_ID = "idle_geofence"

        // Motion thresholds
        private const val SPEED_MOVING_THRESHOLD = 1.0f   // m/s (~3.6 km/h)
        private const val DISTANCE_MOVING_THRESHOLD = 50f  // metres
        private const val IDLE_TIMEOUT_MS = 2L * 60 * 1000 // 2 min of no movement -> IDLE

        // Intervals
        private const val MOVING_INTERVAL_MS = 15_000L     // 15s when moving
        private const val IDLE_INTERVAL_MS = 120_000L      // 2 min when idle
        private const val WAKE_INTERVAL_MS = 5_000L        // 5s burst for wake
        private const val WAKE_DURATION_MS = 30_000L       // 30s burst duration

        // Periodic "last seen" enqueue even when idle (runs in location callback, zero battery cost)
        private const val IDLE_ENQUEUE_INTERVAL_MS = 10L * 60 * 1000 // 10 min

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
    private lateinit var geofencingClient: GeofencingClient
    private lateinit var store: TrackingStore

    private var currentMode = Mode.IDLE
    private var lastLocation: Location? = null
    private var lastEnqueueTime = 0L
    private var lastIdleEnqueueTime = 0L
    private var wakeStartTime = 0L
    private var wakeLock: PowerManager.WakeLock? = null
    private var activityTransitionPi: PendingIntent? = null
    private var geofencePi: PendingIntent? = null

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
        geofencingClient = LocationServices.getGeofencingClient(this)
        store = TrackingStore(this)
        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> doStart()
            ACTION_STOP -> doStop()
            ACTION_WAKE -> doWake()
            ACTION_SAFETY_ALARM -> doSafetyAlarm()
            ACTION_ACTIVITY_TRANSITION -> handleActivityTransition(intent)
            ACTION_GEOFENCE_EVENT -> handleGeofenceEvent(intent)
            else -> doStart()
        }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        cancelSafetyAlarm()
        unregisterActivityTransitions()
        removeGeofence()
        stopLocationUpdates()
        releaseWakeLock()
        try { unregisterReceiver(batteryReceiver) } catch (_: Exception) {}
        super.onDestroy()
    }

    // ── Start / Stop / Wake ─────────────────────────────────────────────

    private fun doStart() {
        Log.i(TAG, "Starting tracking")
        persistEnabled(true)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            startForeground(
                NOTIFICATION_ID,
                buildNotification(),
                ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION
            )
        } else {
            startForeground(NOTIFICATION_ID, buildNotification())
        }
        acquireWakeLock(WAKELOCK_TIMEOUT_MS)
        registerReceiver(batteryReceiver, IntentFilter(Intent.ACTION_BATTERY_CHANGED))

        // Start in IDLE. Will transition to MOVING via activity recognition,
        // geofence exit, or location callback speed/distance check.
        applyMode(Mode.IDLE)
        lastIdleEnqueueTime = System.currentTimeMillis()
        registerActivityTransitions()
        scheduleSafetyAlarm()
    }

    private fun doStop() {
        Log.i(TAG, "Stopping tracking")
        persistEnabled(false)
        cancelSafetyAlarm()
        unregisterActivityTransitions()
        removeGeofence()
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

    // ── Safety alarm (absolute fallback) ─────────────────────────────────

    @SuppressLint("MissingPermission")
    private fun doSafetyAlarm() {
        Log.d(TAG, "Safety alarm fired")
        acquireWakeLock(WAKELOCK_HEARTBEAT_MS)

        // Force a single high-accuracy location fix
        fusedClient.getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, null)
            .addOnSuccessListener { location ->
                val loc = location ?: lastLocation
                if (loc != null) {
                    Log.d(TAG, "Safety alarm location: ${loc.latitude},${loc.longitude}")
                    lastLocation = loc
                    enqueue(loc)
                } else {
                    Log.w(TAG, "Safety alarm: no location available")
                }
            }
            .addOnFailureListener { e ->
                Log.e(TAG, "Safety alarm getCurrentLocation failed", e)
                lastLocation?.let { enqueue(it) }
            }

        scheduleSafetyAlarm()
    }

    private fun scheduleSafetyAlarm() {
        val am = getSystemService(ALARM_SERVICE) as AlarmManager
        val intent = Intent(this, TrackingService::class.java).apply {
            action = ACTION_SAFETY_ALARM
        }
        val pi = PendingIntent.getService(
            this, 0, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val triggerAt = SystemClock.elapsedRealtime() + SAFETY_ALARM_INTERVAL_MS
        am.setAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, triggerAt, pi)
        Log.d(TAG, "Safety alarm scheduled in ${SAFETY_ALARM_INTERVAL_MS / 1000}s")
    }

    private fun cancelSafetyAlarm() {
        val am = getSystemService(ALARM_SERVICE) as AlarmManager
        val intent = Intent(this, TrackingService::class.java).apply {
            action = ACTION_SAFETY_ALARM
        }
        val pi = PendingIntent.getService(
            this, 0, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        am.cancel(pi)
        Log.d(TAG, "Safety alarm cancelled")
    }

    // ── Activity Recognition ─────────────────────────────────────────────

    @SuppressLint("MissingPermission")
    private fun registerActivityTransitions() {
        val transitions = listOf(
            DetectedActivity.IN_VEHICLE,
            DetectedActivity.WALKING,
            DetectedActivity.RUNNING,
            DetectedActivity.ON_BICYCLE,
            DetectedActivity.STILL,
        ).map { activity ->
            ActivityTransition.Builder()
                .setActivityType(activity)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                .build()
        }

        val request = ActivityTransitionRequest(transitions)
        val intent = Intent(this, TrackingService::class.java).apply {
            action = ACTION_ACTIVITY_TRANSITION
        }
        activityTransitionPi = PendingIntent.getService(
            this, 1, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
        )

        ActivityRecognition.getClient(this)
            .requestActivityTransitionUpdates(request, activityTransitionPi!!)
            .addOnSuccessListener { Log.i(TAG, "Activity transitions registered") }
            .addOnFailureListener { e -> Log.e(TAG, "Failed to register activity transitions", e) }
    }

    private fun unregisterActivityTransitions() {
        activityTransitionPi?.let { pi ->
            ActivityRecognition.getClient(this)
                .removeActivityTransitionUpdates(pi)
                .addOnSuccessListener { Log.d(TAG, "Activity transitions unregistered") }
                .addOnFailureListener { e -> Log.e(TAG, "Failed to unregister activity transitions", e) }
        }
        activityTransitionPi = null
    }

    private fun handleActivityTransition(intent: Intent?) {
        if (intent == null || !ActivityTransitionResult.hasResult(intent)) return

        val result = ActivityTransitionResult.extractResult(intent) ?: return
        for (event in result.transitionEvents) {
            Log.d(TAG, "Activity transition: type=${event.activityType} transition=${event.transitionType}")

            when (event.activityType) {
                DetectedActivity.IN_VEHICLE,
                DetectedActivity.WALKING,
                DetectedActivity.RUNNING,
                DetectedActivity.ON_BICYCLE -> {
                    if (currentMode == Mode.IDLE) {
                        Log.i(TAG, "Activity Recognition: movement detected, switching to MOVING")
                        applyMode(Mode.MOVING)
                    }
                }
                DetectedActivity.STILL -> {
                    Log.d(TAG, "Activity Recognition: STILL detected")
                }
            }
        }
    }

    // ── Geofencing ───────────────────────────────────────────────────────

    @SuppressLint("MissingPermission")
    private fun addIdleGeofence(location: Location) {
        removeGeofence()

        val geofence = Geofence.Builder()
            .setRequestId(GEOFENCE_ID)
            .setCircularRegion(location.latitude, location.longitude, GEOFENCE_RADIUS_M)
            .setExpirationDuration(Geofence.NEVER_EXPIRE)
            .setTransitionTypes(Geofence.GEOFENCE_TRANSITION_EXIT)
            .build()

        val request = GeofencingRequest.Builder()
            .setInitialTrigger(0)
            .addGeofence(geofence)
            .build()

        val intent = Intent(this, TrackingService::class.java).apply {
            action = ACTION_GEOFENCE_EVENT
        }
        geofencePi = PendingIntent.getService(
            this, 2, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
        )

        geofencingClient.addGeofences(request, geofencePi!!)
            .addOnSuccessListener {
                Log.i(TAG, "Geofence set at ${location.latitude},${location.longitude} r=${GEOFENCE_RADIUS_M}m")
            }
            .addOnFailureListener { e ->
                Log.e(TAG, "Failed to add geofence", e)
            }
    }

    private fun removeGeofence() {
        geofencePi?.let { pi ->
            geofencingClient.removeGeofences(pi)
                .addOnSuccessListener { Log.d(TAG, "Geofence removed") }
                .addOnFailureListener { e -> Log.e(TAG, "Failed to remove geofence", e) }
        }
        geofencePi = null
    }

    private fun handleGeofenceEvent(intent: Intent?) {
        if (intent == null) return
        val event = GeofencingEvent.fromIntent(intent) ?: return

        if (event.hasError()) {
            Log.e(TAG, "Geofence error: ${event.errorCode}")
            return
        }

        if (event.geofenceTransition == Geofence.GEOFENCE_TRANSITION_EXIT) {
            Log.i(TAG, "Geofence EXIT — switching to MOVING")
            if (currentMode == Mode.IDLE) {
                applyMode(Mode.MOVING)
            }
        }
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

        // Manage geofence based on mode
        when (mode) {
            Mode.IDLE -> lastLocation?.let { addIdleGeofence(it) }
            Mode.MOVING, Mode.WAKE -> removeGeofence()
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
                .setMinUpdateDistanceMeters(30f)
                .setMaxUpdateDelayMillis(2 * 60 * 1000L)
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

        // Motion detection: IDLE -> MOVING (fallback for activity recognition + geofencing)
        if (currentMode == Mode.IDLE && prev != null) {
            val distance = prev.distanceTo(location)
            val speed = location.speed
            if (speed > SPEED_MOVING_THRESHOLD || distance > DISTANCE_MOVING_THRESHOLD) {
                Log.d(TAG, "Motion detected via location (speed=${speed}m/s dist=${distance}m)")
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

        // Set up geofence if in IDLE and none set yet (e.g. first location after start)
        if (currentMode == Mode.IDLE && geofencePi == null) {
            addIdleGeofence(location)
        }

        lastLocation = location

        // Dedup: only enqueue if moved enough
        if (store.shouldEnqueue(location) || currentMode == Mode.WAKE) {
            enqueue(location)
        } else {
            maybeIdleEnqueue(location)
        }
    }

    private fun maybeIdleEnqueue(location: Location) {
        val now = System.currentTimeMillis()
        if (now - lastIdleEnqueueTime >= IDLE_ENQUEUE_INTERVAL_MS) {
            Log.d(TAG, "Periodic idle enqueue (last seen update)")
            enqueue(location)
        }
    }

    private fun enqueue(location: Location) {
        val now = System.currentTimeMillis()
        lastEnqueueTime = now
        lastIdleEnqueueTime = now
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

    private fun acquireWakeLock(timeoutMs: Long = WAKELOCK_TIMEOUT_MS) {
        val pm = getSystemService(POWER_SERVICE) as PowerManager
        if (wakeLock == null) {
            wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, WAKELOCK_TAG)
        }
        wakeLock?.let {
            if (!it.isHeld) {
                it.acquire(timeoutMs)
                Log.d(TAG, "WakeLock acquired (timeout=${timeoutMs / 1000}s)")
            }
        }
    }

    private fun releaseWakeLock() {
        wakeLock?.let {
            if (it.isHeld) it.release()
            wakeLock = null
        }
    }

    // ── Preferences ─────────────────────────────────────────────────────

    private fun persistEnabled(enabled: Boolean) {
        getSharedPreferences("relatives_prefs", MODE_PRIVATE)
            .edit().putBoolean("tracking_enabled", enabled).apply()
    }
}
