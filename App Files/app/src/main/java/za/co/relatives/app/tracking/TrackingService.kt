package za.co.relatives.app.tracking

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
import android.content.pm.ServiceInfo
import android.location.Location
import android.os.BatteryManager
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
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

class TrackingService : Service() {

    companion object {
        private const val TAG = "TrackingService"

        const val ACTION_START = "za.co.relatives.app.tracking.START"
        const val ACTION_STOP = "za.co.relatives.app.tracking.STOP"
        const val ACTION_MOTION_STARTED = "za.co.relatives.app.tracking.MOTION_STARTED"
        const val ACTION_MOTION_STOPPED = "za.co.relatives.app.tracking.MOTION_STOPPED"

        private const val CHANNEL_ID = "tracking_channel"
        private const val NOTIFICATION_ID = 9001

        private const val WAKELOCK_TAG = "Relatives::TrackingWakeLock"
        private const val WAKELOCK_TIMEOUT_MS = 30L * 60 * 1000

        private const val GEOFENCE_RADIUS_M = 200f
        private const val GEOFENCE_ID = "idle_geofence"

        private const val SPEED_MOVING_THRESHOLD = 1.0f
        private const val DISTANCE_MOVING_THRESHOLD = 50f
        private const val IDLE_TIMEOUT_MS = 2L * 60 * 1000
        
        private const val MOTION_STOP_DEBOUNCE_MS = 30_000 

        private const val MOVING_INTERVAL_MS = 15_000L
        private const val IDLE_INTERVAL_MS = 120_000L

        private const val IDLE_ENQUEUE_INTERVAL_MS = 10L * 60 * 1000

        fun start(context: Context) {
            val intent = Intent(context, TrackingService::class.java).apply { action = ACTION_START }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }

        fun stop(context: Context) {
            context.startService(Intent(context, TrackingService::class.java).apply { action = ACTION_STOP })
        }

        fun motionStarted(context: Context) {
            val intent = Intent(context, TrackingService::class.java).apply { action = ACTION_MOTION_STARTED }
             if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }
        
        fun motionStopped(context: Context) {
            val intent = Intent(context, TrackingService::class.java).apply { action = ACTION_MOTION_STOPPED }
             if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }
    }

    private enum class BatteryBucket { NORMAL, LOW, CRITICAL }
    enum class Mode { IDLE, MOVING }

    private lateinit var fusedClient: FusedLocationProviderClient
    private lateinit var geofencingClient: GeofencingClient
    private lateinit var store: TrackingStore

    private var started = false
    private var currentMode = Mode.IDLE
    private var lastLocation: Location? = null
    private var lastEnqueueTime = 0L
    private var lastIdleEnqueueTime = 0L
    private var lastBatteryBucket: BatteryBucket? = null
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
        try {
            fusedClient = LocationServices.getFusedLocationProviderClient(this)
            geofencingClient = LocationServices.getGeofencingClient(this)
            store = TrackingStore(this)
            createNotificationChannel()
        } catch (e: Exception) {
            Log.e(TAG, "Service init failed", e)
            stopSelf()
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> doStart()
            ACTION_STOP -> doStop()
            ACTION_MOTION_STARTED -> doMotionStarted()
            ACTION_MOTION_STOPPED -> doMotionStopped()
            else -> {
                if (getSharedPreferences("relatives_prefs", MODE_PRIVATE).getBoolean("tracking_enabled", false)) {
                    doStart()
                } else {
                    stopSelf()
                }
            }
        }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        val prefs = getSharedPreferences("relatives_prefs", MODE_PRIVATE)
        val enabled = prefs.getBoolean("tracking_enabled", false)

        // Always stop active location callbacks (service is dying)
        stopLocationUpdates()
        releaseWakeLock()
        try { unregisterReceiver(batteryReceiver) } catch (_: Exception) {}

        // CRITICAL: only remove triggers when user intentionally disabled tracking
        if (!enabled) {
            unregisterActivityTransitions()
            removeGeofence()
        } else {
            Log.w(TAG, "Service destroyed but tracking still enabled; keeping AR/geofence registered.")
        }

        started = false
        super.onDestroy()
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        val enabled = getSharedPreferences("relatives_prefs", MODE_PRIVATE)
            .getBoolean("tracking_enabled", false)

        if (enabled) {
            Log.i(TAG, "Task removed with tracking on; scheduling restart via WorkManager.")
            scheduleUpload() // Attempt to flush queue before restarting
            TrackingRestartWorker.enqueue(this)
        }
        super.onTaskRemoved(rootIntent)
    }

    private fun doStart() {
        if (started) {
            Log.w(TAG, "Start called on already started service")
            return
        }
        started = true

        Log.i(TAG, "Starting tracking")
        persistEnabled(true)
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
                startForeground(NOTIFICATION_ID, buildNotification(), ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
            } else {
                startForeground(NOTIFICATION_ID, buildNotification())
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to start foreground service", e)
            started = false
            stopSelf()
            return
        }
        acquireWakeLock(WAKELOCK_TIMEOUT_MS)
        lastBatteryBucket = getBatteryBucket()
        registerReceiver(batteryReceiver, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
        applyMode(Mode.IDLE, force = true)
        lastIdleEnqueueTime = System.currentTimeMillis()
        registerActivityTransitions()
    }

    private fun doStop() {
        Log.i(TAG, "Stopping tracking")
        started = false
        persistEnabled(false)
        unregisterActivityTransitions()
        removeGeofence()
        stopLocationUpdates()
        releaseWakeLock()
        try { unregisterReceiver(batteryReceiver) } catch (_: Exception) {}
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
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

    @SuppressLint("MissingPermission")
    private fun registerActivityTransitions() {
        val activities = listOf(
            DetectedActivity.IN_VEHICLE, 
            DetectedActivity.ON_BICYCLE,
            DetectedActivity.ON_FOOT, 
            DetectedActivity.RUNNING, 
            DetectedActivity.STILL,
            DetectedActivity.WALKING
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
                    .build()
            )
        }
        val request = ActivityTransitionRequest(transitions)
        val intent = Intent(this, ActivityTransitionsReceiver::class.java)
        activityTransitionPi = PendingIntent.getBroadcast(
            this, 1, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        ActivityRecognition.getClient(this).requestActivityTransitionUpdates(request, activityTransitionPi!!)
            .addOnSuccessListener { Log.i(TAG, "Activity transitions registered for: $activities") }
            .addOnFailureListener { e -> Log.e(TAG, "Failed to register activity transitions", e) }
    }

    private fun unregisterActivityTransitions() {
        activityTransitionPi?.let { pi ->
            ActivityRecognition.getClient(this).removeActivityTransitionUpdates(pi)
                .addOnSuccessListener { Log.d(TAG, "Activity transitions unregistered") }
                .addOnFailureListener { e -> Log.e(TAG, "Failed to unregister activity transitions", e) }
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
            this, 2, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        geofencingClient.addGeofences(request, geofencePi!!)
            .addOnSuccessListener { Log.i(TAG, "Idle geofence (EXIT only, r=${GEOFENCE_RADIUS_M}m) set at ${location.latitude},${location.longitude}") }
            .addOnFailureListener { e -> Log.e(TAG, "Failed to add geofence", e) }
    }

    private fun removeGeofence() {
        geofencePi?.let { pi ->
            geofencingClient.removeGeofences(pi)
                .addOnSuccessListener { Log.d(TAG, "Geofence removed") }
                .addOnFailureListener { e -> Log.e(TAG, "Failed to remove geofence", e) }
        }
        geofencePi = null
    }
    
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
            return LocationRequest.Builder(Priority.PRIORITY_PASSIVE, 5 * 60 * 1000L).setMinUpdateDistanceMeters(250f).build()
        }
        if (battery == BatteryBucket.LOW) {
            return LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, 3 * 60 * 1000L).setMinUpdateDistanceMeters(150f).build()
        }
        return when (mode) {
            Mode.MOVING -> LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, MOVING_INTERVAL_MS).setMinUpdateDistanceMeters(15f).setMaxUpdateDelayMillis(30_000L).build()
            Mode.IDLE -> LocationRequest.Builder(Priority.PRIORITY_BALANCED_POWER_ACCURACY, IDLE_INTERVAL_MS).setMinUpdateDistanceMeters(30f).setMaxUpdateDelayMillis(2 * 60 * 1000L).build()
        }
    }

    @Suppress("MissingPermission")
    private fun stopLocationUpdates() {
        try { fusedClient.removeLocationUpdates(locationCallback) } catch (_: Exception) {}
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
        // This is now redundant since the caller (maybeIdleEnqueue) sets it, but leaving it here
        // for other callers of enqueue() is safe.
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

    private fun getBatteryBucket(): BatteryBucket {
        val level = getBatteryLevel() ?: return BatteryBucket.NORMAL
        return when {
            level < 10 -> BatteryBucket.CRITICAL
            level < 20 -> BatteryBucket.LOW
            else -> BatteryBucket.NORMAL
        }
    }

    private fun getBatteryLevel(): Int? {
        return (getSystemService(BATTERY_SERVICE) as? BatteryManager)?.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(CHANNEL_ID, getString(R.string.channel_tracking_name), NotificationManager.IMPORTANCE_LOW).apply {
                description = getString(R.string.channel_tracking_desc)
                setShowBadge(false)
                setSound(null, null)
            }
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
    }
    
    private fun buildNotification(): Notification {
        val stopIntent = Intent(this, TrackingService::class.java).apply { action = ACTION_STOP }
        val stopPending = PendingIntent.getService(this, 0, stopIntent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
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
        } catch (_: Exception) {}
    }

    private fun acquireWakeLock(timeoutMs: Long = WAKELOCK_TIMEOUT_MS) {
        if (wakeLock == null) {
            val pm = getSystemService(POWER_SERVICE) as PowerManager
            wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, WAKELOCK_TAG)
        }
        wakeLock?.let {
            if (!it.isHeld) it.acquire(timeoutMs)
        }
    }

    private fun releaseWakeLock() {
        wakeLock?.takeIf { it.isHeld }?.release()
        wakeLock = null
    }

    private fun persistEnabled(enabled: Boolean) {
        getSharedPreferences("relatives_prefs", MODE_PRIVATE).edit().putBoolean("tracking_enabled", enabled).apply()
    }
}
