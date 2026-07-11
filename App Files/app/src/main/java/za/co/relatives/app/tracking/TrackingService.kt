package za.co.relatives.app.tracking

import android.Manifest
import android.annotation.SuppressLint
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.location.Location
import android.os.BatteryManager
import android.os.Build
import android.os.IBinder
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import com.google.android.gms.location.ActivityRecognition
import com.google.android.gms.location.ActivityTransition
import com.google.android.gms.location.ActivityTransitionRequest
import com.google.android.gms.location.DetectedActivity
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.Geofence
import com.google.android.gms.location.GeofencingClient
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
 * Foreground location service.
 *
 * Contract rules this class must never break:
 *  1. Every path through [onStartCommand] reaches [promoteToForeground] first,
 *     because any caller may have used startForegroundService(). Skipping
 *     startForeground() kills the whole app with
 *     ForegroundServiceDidNotStartInTimeException.
 *  2. On Android 14+ a location-type foreground service may only be promoted
 *     while location permission is granted; otherwise startForeground throws
 *     SecurityException. We detect that case and shut down cleanly.
 *  3. Callers (receivers, FCM, workers) may be in the background where
 *     starting a foreground service throws on Android 12+. The companion
 *     helpers swallow those exceptions — a missed wake beats a dead app.
 *
 * Battery model: no wakelock. The fused location provider wakes us via
 * callbacks; between fixes the CPU is allowed to sleep. IDLE mode uses a
 * geofence + activity recognition to detect movement instead of tight GPS
 * polling.
 */
class TrackingService : Service() {

    companion object {
        private const val TAG = "TrackingService"

        const val ACTION_START = "za.co.relatives.app.tracking.START"
        const val ACTION_STOP = "za.co.relatives.app.tracking.STOP"
        const val ACTION_MOTION_STARTED = "za.co.relatives.app.tracking.MOTION_STARTED"
        const val ACTION_MOTION_STOPPED = "za.co.relatives.app.tracking.MOTION_STOPPED"

        private const val CHANNEL_ID = "tracking_channel"
        private const val NOTIFICATION_ID = 9001

        private const val GEOFENCE_RADIUS_M = 200f
        private const val GEOFENCE_ID = "idle_geofence"

        private const val SPEED_MOVING_THRESHOLD = 1.0f
        private const val DISTANCE_MOVING_THRESHOLD = 50f
        private const val IDLE_TIMEOUT_MS = 2L * 60 * 1000

        private const val MOTION_STOP_DEBOUNCE_MS = 30_000

        private const val MOVING_INTERVAL_MS = 15_000L
        private const val IDLE_INTERVAL_MS = 120_000L

        private const val IDLE_ENQUEUE_INTERVAL_MS = 10L * 60 * 1000

        /** True while an instance is alive. Lets callers avoid start churn. */
        @Volatile
        var isRunning = false
            private set

        fun start(context: Context) = safeStart(context, ACTION_START)

        /**
         * Stop tracking. Persists the disabled flag first so that even if the
         * stop intent cannot be delivered (background restrictions), every
         * receiver and the service itself will see tracking as off and shut
         * down on the next event.
         */
        fun stop(context: Context) {
            persistEnabled(context, false)
            try {
                context.startService(
                    Intent(context, TrackingService::class.java).apply { action = ACTION_STOP },
                )
            } catch (e: Exception) {
                Log.w(TAG, "Could not deliver stop intent (service will stop on next event)", e)
            }
        }

        fun motionStarted(context: Context) = safeStart(context, ACTION_MOTION_STARTED)

        fun motionStopped(context: Context) {
            // Only meaningful if the service is already running; never worth
            // spinning up a foreground service just to say "go idle".
            if (isRunning) safeStart(context, ACTION_MOTION_STOPPED)
        }

        /**
         * Start the service, tolerating every framework refusal:
         * ForegroundServiceStartNotAllowedException (Android 12+ background
         * start), IllegalStateException (pre-12 background start) and
         * SecurityException. Tracking resumes the next time an allowed
         * trigger fires (app open, geofence/AR transition, FCM wake).
         */
        private fun safeStart(context: Context, action: String) {
            val intent = Intent(context, TrackingService::class.java).apply { this.action = action }
            try {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    context.startForegroundService(intent)
                } else {
                    context.startService(intent)
                }
            } catch (e: Exception) {
                Log.w(TAG, "Unable to start service for $action", e)
            }
        }

        fun isTrackingEnabled(context: Context): Boolean =
            context.getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)
                .getBoolean("tracking_enabled", false)

        fun hasLocationPermission(context: Context): Boolean =
            ContextCompat.checkSelfPermission(
                context, Manifest.permission.ACCESS_FINE_LOCATION,
            ) == PackageManager.PERMISSION_GRANTED ||
                ContextCompat.checkSelfPermission(
                    context, Manifest.permission.ACCESS_COARSE_LOCATION,
                ) == PackageManager.PERMISSION_GRANTED

        private fun persistEnabled(context: Context, enabled: Boolean) {
            context.getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)
                .edit().putBoolean("tracking_enabled", enabled).apply()
        }
    }

    private enum class BatteryBucket { NORMAL, LOW, CRITICAL }
    enum class Mode { IDLE, MOVING }

    private lateinit var fusedClient: FusedLocationProviderClient
    private lateinit var geofencingClient: GeofencingClient
    private lateinit var store: TrackingStore

    private var started = false
    private var isForeground = false
    private var currentMode = Mode.IDLE
    private var lastLocation: Location? = null
    private var lastEnqueueTime = 0L
    private var lastIdleEnqueueTime = 0L
    private var lastBatteryBucket: BatteryBucket? = null
    private var batteryReceiverRegistered = false
    private var activityTransitionPi: PendingIntent? = null
    private var geofencePi: PendingIntent? = null

    private val locationCallback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            result.lastLocation?.let { onLocationReceived(it) }
        }
    }

    private val batteryReceiver = object : BroadcastReceiver() {
        override fun onReceive(ctx: Context?, intent: Intent?) {
            val newBucket = getBatteryBucket()
            if (newBucket != lastBatteryBucket) {
                Log.d(TAG, "Battery bucket changed from $lastBatteryBucket to $newBucket, re-applying mode.")
                lastBatteryBucket = newBucket
                applyMode(currentMode, force = true)
            }
        }
    }

    override fun onCreate() {
        super.onCreate()
        isRunning = true
        fusedClient = LocationServices.getFusedLocationProviderClient(this)
        geofencingClient = LocationServices.getGeofencingClient(this)
        store = TrackingStore(this)
        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val enabled = isTrackingEnabled(this)
        val hasPermission = hasLocationPermission(this)

        // Rule 1: promote to foreground before anything else, on every path.
        if (!promoteToForeground(hasPermission)) {
            Log.w(TAG, "Cannot run as foreground service; shutting down.")
            shutdown()
            return START_NOT_STICKY
        }

        when (intent?.action) {
            ACTION_STOP -> {
                doStop()
                return START_NOT_STICKY
            }
            ACTION_START -> {
                if (hasPermission) {
                    persistEnabled(this, true)
                    doStart()
                } else {
                    Log.w(TAG, "Start requested without location permission.")
                    doStop()
                    return START_NOT_STICKY
                }
            }
            ACTION_MOTION_STARTED -> {
                if (enabled && hasPermission) {
                    doStart() // no-op when already started
                    doMotionStarted()
                } else {
                    doStop()
                    return START_NOT_STICKY
                }
            }
            ACTION_MOTION_STOPPED -> {
                if (enabled && hasPermission) {
                    doStart()
                    doMotionStopped()
                } else {
                    doStop()
                    return START_NOT_STICKY
                }
            }
            else -> {
                // Null intent = START_STICKY restart after process death.
                if (enabled && hasPermission) {
                    doStart()
                } else {
                    doStop()
                    return START_NOT_STICKY
                }
            }
        }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        val enabled = isTrackingEnabled(this)

        // Always stop active location callbacks (service is dying)
        stopLocationUpdates()
        unregisterBatteryReceiver()

        // Only remove passive triggers when the user intentionally disabled
        // tracking. If the system killed us, geofence + activity recognition
        // stay registered and will restart the service on the next movement
        // (an allowed background-start exemption on Android 12+).
        if (!enabled) {
            unregisterActivityTransitions()
            removeGeofence()
        } else {
            Log.w(TAG, "Service destroyed but tracking still enabled; keeping AR/geofence registered.")
        }

        started = false
        isForeground = false
        isRunning = false
        super.onDestroy()
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        if (isTrackingEnabled(this)) {
            Log.i(TAG, "Task removed with tracking on; flushing queue and scheduling restart check.")
            scheduleUpload()
            TrackingRestartWorker.enqueue(this)
        }
        super.onTaskRemoved(rootIntent)
    }

    // ────────────────────────────────────────────────────────────────────
    //  Foreground promotion
    // ────────────────────────────────────────────────────────────────────

    /**
     * Fulfil the startForegroundService() contract. Returns false when the
     * service could not be promoted (the caller must then stop the service).
     *
     * On Android 14+ promoting a location-type service without location
     * permission throws SecurityException, so in that case we promote as a
     * SHORT_SERVICE (needs no permission or manifest type) purely to satisfy
     * the contract before an immediate shutdown.
     */
    private fun promoteToForeground(hasPermission: Boolean): Boolean {
        if (isForeground) return true
        return try {
            val notification = buildNotification()
            when {
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE && !hasPermission ->
                    startForeground(
                        NOTIFICATION_ID, notification,
                        ServiceInfo.FOREGROUND_SERVICE_TYPE_SHORT_SERVICE,
                    )
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q ->
                    startForeground(
                        NOTIFICATION_ID, notification,
                        ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION,
                    )
                else -> startForeground(NOTIFICATION_ID, notification)
            }
            isForeground = true
            // A SHORT_SERVICE promotion only exists to satisfy the contract;
            // report failure so the caller shuts down before its timeout.
            hasPermission || Build.VERSION.SDK_INT < Build.VERSION_CODES.UPSIDE_DOWN_CAKE
        } catch (e: Exception) {
            Log.e(TAG, "startForeground failed", e)
            false
        }
    }

    /** Stop cleanly without touching the persisted enabled flag. */
    private fun shutdown() {
        stopLocationUpdates()
        unregisterBatteryReceiver()
        try {
            stopForeground(STOP_FOREGROUND_REMOVE)
        } catch (_: Exception) {
        }
        isForeground = false
        started = false
        stopSelf()
    }

    // ────────────────────────────────────────────────────────────────────
    //  Actions
    // ────────────────────────────────────────────────────────────────────

    private fun doStart() {
        if (started) return
        started = true

        Log.i(TAG, "Starting tracking")
        lastBatteryBucket = getBatteryBucket()
        registerBatteryReceiver()
        applyMode(Mode.IDLE, force = true)
        lastIdleEnqueueTime = System.currentTimeMillis()
        registerActivityTransitions()
    }

    private fun doStop() {
        Log.i(TAG, "Stopping tracking")
        started = false
        persistEnabled(this, false)
        unregisterActivityTransitions()
        removeGeofence()
        shutdown()
    }

    @SuppressLint("MissingPermission")
    private fun doMotionStarted() {
        Log.d(TAG, "Motion started event received")
        applyMode(Mode.MOVING)

        // Force an immediate point upload on movement
        try {
            fusedClient.getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, null)
                .addOnSuccessListener { loc -> if (loc != null) enqueue(loc) }
        } catch (_: SecurityException) {
            Log.e(TAG, "Could not get current location on motion started: permission denied")
        }
    }

    private fun doMotionStopped() {
        val timeSinceLastMovement = System.currentTimeMillis() - lastEnqueueTime
        if (currentMode != Mode.IDLE && timeSinceLastMovement < MOTION_STOP_DEBOUNCE_MS) {
            Log.w(TAG, "Motion stopped event ignored (debounce). Still moving recently (${timeSinceLastMovement}ms ago).")
            return
        }
        Log.i(TAG, "Motion stopped event received, switching to IDLE.")
        applyMode(Mode.IDLE)
    }

    // ────────────────────────────────────────────────────────────────────
    //  Motion triggers (activity recognition + geofence)
    // ────────────────────────────────────────────────────────────────────

    @SuppressLint("MissingPermission")
    private fun registerActivityTransitions() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACTIVITY_RECOGNITION)
            != PackageManager.PERMISSION_GRANTED && Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q
        ) {
            Log.w(TAG, "ACTIVITY_RECOGNITION not granted; relying on geofence + location fallback.")
            return
        }
        val activities = listOf(
            DetectedActivity.IN_VEHICLE,
            DetectedActivity.ON_BICYCLE,
            DetectedActivity.ON_FOOT,
            DetectedActivity.RUNNING,
            DetectedActivity.STILL,
            DetectedActivity.WALKING,
        )
        val transitions = activities.flatMap { activity ->
            listOf(
                ActivityTransition.Builder()
                    .setActivityType(activity)
                    .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                    .build(),
                ActivityTransition.Builder()
                    .setActivityType(activity)
                    .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_EXIT)
                    .build(),
            )
        }
        val request = ActivityTransitionRequest(transitions)
        val intent = Intent(this, ActivityTransitionsReceiver::class.java)
        activityTransitionPi = PendingIntent.getBroadcast(
            this, 1, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE,
        )
        ActivityRecognition.getClient(this).requestActivityTransitionUpdates(request, activityTransitionPi!!)
            .addOnSuccessListener { Log.i(TAG, "Activity transitions registered") }
            .addOnFailureListener { e -> Log.e(TAG, "Failed to register activity transitions", e) }
    }

    private fun unregisterActivityTransitions() {
        activityTransitionPi?.let { pi ->
            try {
                ActivityRecognition.getClient(this).removeActivityTransitionUpdates(pi)
                    .addOnFailureListener { e -> Log.e(TAG, "Failed to unregister activity transitions", e) }
            } catch (e: Exception) {
                Log.e(TAG, "Error unregistering activity transitions", e)
            }
        }
        activityTransitionPi = null
    }

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
            .addGeofence(geofence)
            .build()
        val intent = Intent(this, GeofenceReceiver::class.java)
        geofencePi = PendingIntent.getBroadcast(
            this, 2, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE,
        )
        try {
            geofencingClient.addGeofences(request, geofencePi!!)
                .addOnSuccessListener { Log.i(TAG, "Idle geofence set at ${location.latitude},${location.longitude}") }
                .addOnFailureListener { e -> Log.e(TAG, "Failed to add geofence", e) }
        } catch (e: SecurityException) {
            Log.e(TAG, "Geofence add rejected: permission missing", e)
        }
    }

    private fun removeGeofence() {
        geofencePi?.let { pi ->
            try {
                geofencingClient.removeGeofences(pi)
                    .addOnFailureListener { e -> Log.e(TAG, "Failed to remove geofence", e) }
            } catch (e: Exception) {
                Log.e(TAG, "Error removing geofence", e)
            }
        }
        geofencePi = null
    }

    // ────────────────────────────────────────────────────────────────────
    //  Location modes
    // ────────────────────────────────────────────────────────────────────

    private fun applyMode(mode: Mode, force: Boolean = false) {
        if (currentMode == mode && !force) return

        if (mode == Mode.MOVING) {
            lastEnqueueTime = System.currentTimeMillis()
        }

        currentMode = mode
        stopLocationUpdates()

        val request = buildLocationRequest(mode)
        try {
            fusedClient.requestLocationUpdates(request, locationCallback, mainLooper)
            Log.i(TAG, "Mode applied: $mode (interval=${request.intervalMillis}ms, force=$force)")
        } catch (e: SecurityException) {
            Log.e(TAG, "Location permission missing, stopping service.", e)
            doStop()
            return
        }

        when (mode) {
            Mode.IDLE -> lastLocation?.let { addIdleGeofence(it) }
            Mode.MOVING -> removeGeofence()
        }
        updateNotification()
    }

    private fun buildLocationRequest(mode: Mode): LocationRequest {
        val battery = getBatteryBucket()
        if (battery == BatteryBucket.CRITICAL) {
            return LocationRequest.Builder(Priority.PRIORITY_PASSIVE, 5 * 60 * 1000L)
                .setMinUpdateDistanceMeters(250f).build()
        }
        if (battery == BatteryBucket.LOW) {
            return LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, 3 * 60 * 1000L)
                .setMinUpdateDistanceMeters(150f).build()
        }
        return when (mode) {
            Mode.MOVING -> LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, MOVING_INTERVAL_MS)
                .setMinUpdateDistanceMeters(15f)
                .setMaxUpdateDelayMillis(30_000L)
                .build()
            Mode.IDLE -> LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, IDLE_INTERVAL_MS)
                .setMinUpdateDistanceMeters(30f)
                .setMaxUpdateDelayMillis(2 * 60 * 1000L)
                .build()
        }
    }

    private fun stopLocationUpdates() {
        try {
            fusedClient.removeLocationUpdates(locationCallback)
        } catch (_: Exception) {
        }
    }

    private fun onLocationReceived(location: Location) {
        val prev = lastLocation
        if (currentMode == Mode.IDLE && prev != null) {
            if (location.speed > SPEED_MOVING_THRESHOLD || prev.distanceTo(location) > DISTANCE_MOVING_THRESHOLD) {
                Log.i(TAG, "Fallback motion detected, switching to MOVING")
                applyMode(Mode.MOVING)
            }
        }

        if (currentMode == Mode.MOVING && prev != null) {
            if (prev.distanceTo(location) < 15f) {
                if (System.currentTimeMillis() - lastEnqueueTime > IDLE_TIMEOUT_MS) {
                    Log.i(TAG, "Appears stationary, reverting to IDLE")
                    applyMode(Mode.IDLE)
                }
            } else {
                lastEnqueueTime = System.currentTimeMillis()
            }
        }

        if (currentMode == Mode.IDLE && geofencePi == null) {
            addIdleGeofence(location)
        }

        lastLocation = location

        if (store.shouldEnqueue(location)) {
            enqueue(location)
        } else {
            maybeIdleEnqueue(location)
        }
    }

    private fun maybeIdleEnqueue(location: Location) {
        val now = System.currentTimeMillis()
        if (now - lastIdleEnqueueTime >= IDLE_ENQUEUE_INTERVAL_MS) {
            Log.d(TAG, "Periodic idle enqueue")
            enqueue(location)
            lastIdleEnqueueTime = now
        }
    }

    private fun enqueue(location: Location) {
        val now = System.currentTimeMillis()
        lastEnqueueTime = now
        if (currentMode == Mode.IDLE) lastIdleEnqueueTime = now
        store.markEnqueued(location)
        val entity = QueuedLocationEntity(
            lat = location.latitude, lng = location.longitude,
            accuracy = if (location.hasAccuracy()) location.accuracy else null,
            altitude = if (location.hasAltitude()) location.altitude else null,
            bearing = if (location.hasBearing()) location.bearing else null,
            speed = if (location.hasSpeed()) location.speed else null,
            speedKmh = if (location.hasSpeed()) location.speed * 3.6f else null,
            isMoving = currentMode == Mode.MOVING,
            batteryLevel = getBatteryLevel(),
            timestamp = now,
        )
        store.enqueueLocation(entity)
        scheduleUpload()
    }

    private fun scheduleUpload() {
        val constraints = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()
        val request = OneTimeWorkRequestBuilder<LocationUploadWorker>().setConstraints(constraints).build()
        WorkManager.getInstance(this).enqueueUniqueWork(LocationUploadWorker.WORK_NAME, ExistingWorkPolicy.KEEP, request)
    }

    // ────────────────────────────────────────────────────────────────────
    //  Battery
    // ────────────────────────────────────────────────────────────────────

    private fun registerBatteryReceiver() {
        if (batteryReceiverRegistered) return
        try {
            ContextCompat.registerReceiver(
                this, batteryReceiver, IntentFilter(Intent.ACTION_BATTERY_CHANGED),
                ContextCompat.RECEIVER_NOT_EXPORTED,
            )
            batteryReceiverRegistered = true
        } catch (e: Exception) {
            Log.w(TAG, "Could not register battery receiver", e)
        }
    }

    private fun unregisterBatteryReceiver() {
        if (!batteryReceiverRegistered) return
        try {
            unregisterReceiver(batteryReceiver)
        } catch (_: Exception) {
        }
        batteryReceiverRegistered = false
    }

    private fun getBatteryBucket(): BatteryBucket {
        val level = getBatteryLevel() ?: return BatteryBucket.NORMAL
        return when {
            level < 10 -> BatteryBucket.CRITICAL
            level < 20 -> BatteryBucket.LOW
            else -> BatteryBucket.NORMAL
        }
    }

    private fun getBatteryLevel(): Int? {
        return (getSystemService(BATTERY_SERVICE) as? BatteryManager)
            ?.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
    }

    // ────────────────────────────────────────────────────────────────────
    //  Notification
    // ────────────────────────────────────────────────────────────────────

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
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
    }

    private fun buildNotification(): Notification {
        val stopIntent = Intent(this, TrackingService::class.java).apply { action = ACTION_STOP }
        val stopPending = PendingIntent.getService(
            this, 0, stopIntent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val text = when (currentMode) {
            Mode.IDLE -> getString(R.string.tracking_notif_idle)
            Mode.MOVING -> getString(R.string.tracking_notif_moving)
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
            getSystemService(NotificationManager::class.java).notify(NOTIFICATION_ID, buildNotification())
        } catch (_: Exception) {
        }
    }
}
