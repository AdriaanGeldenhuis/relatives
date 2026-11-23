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
import android.location.LocationManager
import android.os.BatteryManager
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.PowerManager
import android.os.SystemClock
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
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
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import za.co.relatives.app.MainActivity
import za.co.relatives.app.R
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.utils.PreferencesManager

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
        private const val ACTION_WAKE = "za.co.relatives.app.tracking.WAKE"
        private const val ACTION_MOTION_PERMISSION = "za.co.relatives.app.tracking.MOTION_PERMISSION"
        const val ACTION_REVIVE = "za.co.relatives.app.tracking.REVIVE"

        private const val CHANNEL_ID = "tracking_channel"
        private const val NOTIFICATION_ID = 9001

        private const val GEOFENCE_RADIUS_M = 200f
        private const val GEOFENCE_ID = "idle_geofence"

        private const val MOVING_INTERVAL_MS = 30_000L
        private const val IDLE_INTERVAL_MS = 5 * 60_000L
        private const val FALLBACK_INTERVAL_MS = 2 * 60_000L

        private const val WAKE_COOLDOWN_MS = 30_000L
        private const val IDLE_ENQUEUE_INTERVAL_MS = 10L * 60 * 1000

        /** True while an instance is alive. Lets callers avoid start churn. */
        @Volatile
        var isRunning = false
            private set

        fun start(context: Context) {
            if (!hasLocationPermission(context) || !canShowTrackingNotification(context)) return
            if (PreferencesManager(context).enableTracking()) safeStart(context, ACTION_START)
        }

        fun requestWakeFix(context: Context, expedited: Boolean = false) {
            if (!isTrackingEnabled(context) || !hasLocationPermission(context) ||
                !canShowTrackingNotification(context)
            ) return
            if (!requestRunningWake(context) && hasBackgroundLocation(context)) {
                TrackingRestartWorker.enqueueWakeFix(context, expedited)
            }
        }

        fun onMotionPermissionGranted(context: Context) {
            if (isRunning) safeStart(context, ACTION_MOTION_PERMISSION)
        }

        fun showFamilyRefreshNotification(context: Context) {
            createTrackingChannel(context)
            val openTracking = PendingIntent.getActivity(
                context, 9002,
                Intent(context, MainActivity::class.java).putExtra("action_url", "/tracking/app/"),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
            )
            val stop = PendingIntent.getService(
                context, 0, Intent(context, TrackingService::class.java).setAction(ACTION_STOP),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
            )
            val notification = NotificationCompat.Builder(context, CHANNEL_ID)
                .setSmallIcon(R.drawable.ic_notification)
                .setContentTitle(context.getString(R.string.notif_title_tracking))
                .setContentText(context.getString(R.string.tracking_notif_refresh))
                .setContentIntent(openTracking)
                .setAutoCancel(true)
                .addAction(0, context.getString(R.string.tracking_notif_stop), stop)
                .setSilent(true)
                .setTimeoutAfter(60_000L)
                .build()
            context.getSystemService(NotificationManager::class.java)?.notify(9002, notification)
        }

        private fun createTrackingChannel(context: Context) {
            val channel = NotificationChannel(
                CHANNEL_ID, context.getString(R.string.channel_tracking_name), NotificationManager.IMPORTANCE_LOW,
            ).apply {
                description = context.getString(R.string.channel_tracking_desc)
                setShowBadge(false)
                setSound(null, null)
            }
            context.getSystemService(NotificationManager::class.java)?.createNotificationChannel(channel)
        }

        internal fun requestRunningWake(context: Context): Boolean =
            isRunning && safeStart(context, ACTION_WAKE)

        /**
         * Revive tracking WITHOUT re-persisting the enabled flag — for the
         * watchdog and FCM keepalive. Unlike [start] (a user action that
         * asserts "on"), a revive must never win a race against the user
         * tapping disable: onStartCommand re-checks the persisted flag and
         * shuts down if the user turned tracking off in the meantime.
         */
        fun revive(context: Context) {
            safeStart(context, ACTION_REVIVE)
        }

        /**
         * Stop tracking. Persists the disabled flag first so that even if the
         * service is already stopped, subsequent events respect the disabled flag.
         */
        fun stop(context: Context) {
            disableTracking(context)
            context.stopService(Intent(context, TrackingService::class.java))
        }

        private fun disableTracking(context: Context) {
            val prefs = PreferencesManager(context)
            val previous = prefs.trackingConsent()
            prefs.disableTracking()
            if (previous != null) TrackingRestartWorker.discard(context, previous.scope)
            TrackingRestartWorker.cancel(context)
            WatchdogAlarmReceiver.cancel(context)
            context.getSystemService(NotificationManager::class.java)?.cancel(9002)
            WorkManager.getInstance(context).cancelUniqueWork(LocationUploadWorker.WORK_NAME)
        }

        fun motionStarted(context: Context) {
            safeStart(context, ACTION_MOTION_STARTED)
        }

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
        private fun safeStart(context: Context, action: String): Boolean {
            if (!isTrackingEnabled(context) || !hasLocationPermission(context) ||
                !canShowTrackingNotification(context)
            ) return false
            val intent = Intent(context, TrackingService::class.java).apply { this.action = action }
            return try {
                context.startForegroundService(intent)
                true
            } catch (e: Exception) {
                Log.w(TAG, "Unable to start service for $action", e)
                false
            }
        }

        fun isTrackingEnabled(context: Context): Boolean =
            PreferencesManager(context).trackingConsent() != null

        fun canShowTrackingNotification(context: Context): Boolean {
            if (!NotificationManagerCompat.from(context).areNotificationsEnabled()) return false
            val manager = context.getSystemService(NotificationManager::class.java)
            return manager?.getNotificationChannel(CHANNEL_ID)?.importance != NotificationManager.IMPORTANCE_NONE
        }

        fun hasLocationPermission(context: Context): Boolean =
            ContextCompat.checkSelfPermission(
                context, Manifest.permission.ACCESS_FINE_LOCATION,
            ) == PackageManager.PERMISSION_GRANTED ||
                ContextCompat.checkSelfPermission(
                    context, Manifest.permission.ACCESS_COARSE_LOCATION,
                ) == PackageManager.PERMISSION_GRANTED

        fun hasBackgroundLocation(context: Context): Boolean =
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                ContextCompat.checkSelfPermission(
                    context, Manifest.permission.ACCESS_BACKGROUND_LOCATION,
                ) == PackageManager.PERMISSION_GRANTED
            } else {
                true
            }

        /**
         * Whether a broadcast receiver (running with the app in background)
         * should revive the service. If the service is already running its
         * while-in-use location grant stays valid, but a service *started*
         * from the background without ACCESS_BACKGROUND_LOCATION never
         * receives fixes — starting it would burn battery for nothing.
         */
        fun canReviveFromBackground(context: Context): Boolean =
            isRunning || hasBackgroundLocation(context)

    }

    private enum class BatteryBucket { NORMAL, LOW, CRITICAL }
    enum class Mode { IDLE, MOVING }

    private lateinit var fusedClient: FusedLocationProviderClient
    private lateinit var geofencingClient: GeofencingClient
    private lateinit var store: TrackingStore

    /**
     * False on devices without Google Play services (HMS-only Huawei etc.).
     * There every fused/geofence/activity-recognition call fails, so location
     * comes from the plain [LocationManager] instead and motion detection
     * relies solely on the speed/distance fallback in [onLocationReceived].
     */
    private var hasGms = true
    private var platformLocationManager: LocationManager? = null
    private var lmUpdatesRegistered = false
    private var providersChangedRegistered = false

    private var started = false
    private var isForeground = false
    private var initFailed = false
    private var currentMode = Mode.IDLE
    private var lastLocation: Location? = null
    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)
    private val handler = Handler(android.os.Looper.getMainLooper())
    private val idleTimeout = Runnable { if (started) switchToIdle() }
    private var lastMovementMs = 0L
    private var lastQueuedMs: Long? = null
    private var movementAnchor: Location? = null
    private var lastFixNanos = Long.MIN_VALUE
    private var lastQueuedFixNanos = Long.MIN_VALUE
    private var wakeFix: Job? = null
    private var lastWakeMs: Long? = null
    private var platformIdleFixes: Job? = null
    private var locationRequestGeneration = 0
    private var activityTransitionsRegistered = false
    private var lastBatteryBucket: BatteryBucket? = null
    private var batteryReceiverRegistered = false
    private var activityTransitionPi: PendingIntent? = null
    private var geofencePi: PendingIntent? = null

    private val locationCallback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            result.lastLocation?.let { onLocationReceived(it) }
        }
    }

    // Full object, not a SAM lambda: pre-API-30 platforms still invoke the
    // legacy callbacks, which a lambda compiled against a modern SDK does not
    // implement (AbstractMethodError).
    private val lmListener = object : android.location.LocationListener {
        override fun onLocationChanged(location: Location) = onLocationReceived(location)

        @Deprecated("Deprecated in Java")
        override fun onStatusChanged(provider: String?, status: Int, extras: android.os.Bundle?) {}
        override fun onProviderEnabled(provider: String) {}
        override fun onProviderDisabled(provider: String) {}
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

    // Non-GMS devices have no geofence/AR to re-arm updates, and the plain
    // LocationManager registration silently no-ops when GPS/network are both
    // off. Without this, toggling Location off then on left the user's dot
    // frozen until they reopened the app. Re-apply the mode whenever the
    // provider set changes so updates re-register once a provider returns.
    private val providersChangedReceiver = object : BroadcastReceiver() {
        override fun onReceive(ctx: Context?, intent: Intent?) {
            if (started) {
                Log.d(TAG, "Location providers changed; re-applying fallback updates.")
                applyMode(currentMode, force = true)
            }
        }
    }

    override fun onCreate() {
        super.onCreate()
        isRunning = true
        try {
            // Channel first: foreground promotion depends on it.
            createTrackingChannel(this)
            hasGms = GmsSupport.available(this)
            if (hasGms) {
                fusedClient = LocationServices.getFusedLocationProviderClient(this)
                geofencingClient = LocationServices.getGeofencingClient(this)
            }
            platformLocationManager = getSystemService(LOCATION_SERVICE) as? LocationManager
            store = TrackingStore(this)
        } catch (e: Exception) {
            // Play Services / Room can fail on broken devices — shut down
            // cleanly on the next command instead of crashing the app.
            Log.e(TAG, "Service init failed", e)
            initFailed = true
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val enabled = isTrackingEnabled(this) && canShowTrackingNotification(this)
        val hasPermission = hasLocationPermission(this)

        // Stop requests arrive via plain startService (companion stop() and
        // the notification action), which carries no foreground obligation —
        // handle them before promotion so a stop on a dead service doesn't
        // flash the tracking notification.
        if (intent?.action == ACTION_STOP) {
            doStop()
            return START_NOT_STICKY
        }

        // Rule 1: promote to foreground before anything else, on every path.
        if (!promoteToForeground(hasPermission)) {
            Log.w(TAG, "Cannot run as foreground service; shutting down.")
            shutdown()
            return START_NOT_STICKY
        }

        if (initFailed) {
            Log.e(TAG, "onCreate init failed; shutting down.")
            shutdown()
            return START_NOT_STICKY
        }

        if (!enabled || !hasPermission) {
            doStop()
            return START_NOT_STICKY
        }
        doStart()
        when (intent?.action) {
            ACTION_MOTION_STARTED -> recordMovement(SystemClock.elapsedRealtime())
            ACTION_MOTION_STOPPED -> {
                if (SystemClock.elapsedRealtime() - lastMovementMs >= TrackingMotion.STILL_DEBOUNCE_MS) {
                    switchToIdle()
                }
            }
            ACTION_WAKE -> captureWakeFix()
            ACTION_MOTION_PERMISSION -> registerActivityTransitions()
        }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        val enabled = isTrackingEnabled(this)

        handler.removeCallbacks(idleTimeout)
        serviceScope.cancel()
        stopLocationUpdates()
        unregisterBatteryReceiver()
        unregisterProvidersChangedReceiver()

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
            LocationUploadWorker.enqueue(this)
            TrackingRestartWorker.enqueue(this)
        }
        super.onTaskRemoved(rootIntent)
    }

    // ────────────────────────────────────────────────────────────────────
    //  Foreground promotion
    // ────────────────────────────────────────────────────────────────────

    private fun promoteToForeground(hasPermission: Boolean): Boolean {
        if (!hasPermission) return false
        if (isForeground) return true
        return try {
            val notification = buildNotification()
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
            } else {
                startForeground(NOTIFICATION_ID, notification)
            }
            isForeground = true
            true
        } catch (error: RuntimeException) {
            Log.w(TAG, "Location foreground service was not allowed", error)
            false
        }
    }

    /** Stop cleanly without touching the persisted enabled flag. */
    private fun shutdown() {
        started = false
        handler.removeCallbacks(idleTimeout)
        wakeFix?.cancel()
        stopLocationUpdates()
        unregisterBatteryReceiver()
        unregisterProvidersChangedReceiver()
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
        registerProvidersChangedReceiver()
        applyMode(Mode.IDLE, force = true)
        registerActivityTransitions()
        TrackingRestartWorker.enqueuePeriodic(this)
        WatchdogAlarmReceiver.cancel(this) // Cancel alarms left by an older app version.
    }

    private fun doStop() {
        disableTracking(this)
        shutdown()
    }

    private fun recordMovement(now: Long) {
        lastMovementMs = now
        applyMode(Mode.MOVING)
        handler.removeCallbacks(idleTimeout)
        handler.postDelayed(idleTimeout, TrackingMotion.IDLE_AFTER_MS)
    }

    private fun switchToIdle() {
        handler.removeCallbacks(idleTimeout)
        applyMode(Mode.IDLE)
    }

    private fun captureWakeFix() {
        if (wakeFix?.isActive == true) return
        val now = SystemClock.elapsedRealtime()
        if (lastWakeMs?.let { now - it < WAKE_COOLDOWN_MS } == true) return
        lastWakeMs = now
        wakeFix = serviceScope.launch {
            val fix = TrackingFix.current(this@TrackingService, highAccuracy = getBatteryBucket() == BatteryBucket.NORMAL)
            if (fix != null && acceptsFix(fix)) {
                // A manual refresh updates the map without granting a moving-mode window.
                enqueue(fix, SystemClock.elapsedRealtime(), urgent = true)
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────
    //  Motion triggers (activity recognition + geofence)
    // ────────────────────────────────────────────────────────────────────

    @SuppressLint("MissingPermission")
    private fun registerActivityTransitions() {
        if (activityTransitionPi != null) return
        if (!hasGms) {
            Log.i(TAG, "No Play services; skipping activity recognition (interval fallback active).")
            return
        }
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
        val transitions = activities.map { activity ->
            ActivityTransition.Builder()
                .setActivityType(activity)
                .setActivityTransition(ActivityTransition.ACTIVITY_TRANSITION_ENTER)
                .build()
        }
        val request = ActivityTransitionRequest(transitions)
        val intent = Intent(this, ActivityTransitionsReceiver::class.java)
        activityTransitionPi = PendingIntent.getBroadcast(
            this, 1, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE,
        )
        ActivityRecognition.getClient(this).requestActivityTransitionUpdates(request, activityTransitionPi!!)
            .addOnSuccessListener {
                if (started && currentMode == Mode.IDLE) {
                    activityTransitionsRegistered = true
                    applyMode(Mode.IDLE, force = true)
                } else if (started) {
                    activityTransitionsRegistered = true
                }
            }
            .addOnFailureListener { e ->
                activityTransitionPi = null
                Log.w(TAG, "Failed to register activity transitions", e)
            }
    }

    private fun unregisterActivityTransitions() {
        if (!hasGms) return
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACTIVITY_RECOGNITION) != PackageManager.PERMISSION_GRANTED
        ) {
            activityTransitionPi?.cancel()
            activityTransitionPi = null
            return
        }
        // After a process restart the field is null but the OLD registration
        // is still live in Play services. Rebuild the identical PendingIntent
        // (same request code + intent + flags → equal PI) so disabling
        // tracking actually removes it instead of leaking it forever.
        val pi = activityTransitionPi ?: PendingIntent.getBroadcast(
            this, 1, Intent(this, ActivityTransitionsReceiver::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE,
        )
        try {
            ActivityRecognition.getClient(this).removeActivityTransitionUpdates(pi)
                .addOnFailureListener { e -> Log.e(TAG, "Failed to unregister activity transitions", e) }
        } catch (error: SecurityException) {
            Log.w(TAG, "Activity recognition permission was revoked", error)
        } finally {
            pi.cancel()
            activityTransitionPi = null
        }
    }

    @SuppressLint("MissingPermission")
    private fun addIdleGeofence(location: Location) {
        if (!hasGms || geofencePi != null || !hasBackgroundLocation(this) ||
            !location.hasAccuracy() || location.accuracy > 100f ||
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) !=
            PackageManager.PERMISSION_GRANTED
        ) return
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
        val intent = Intent(this, GeofenceReceiver::class.java)
        geofencePi = PendingIntent.getBroadcast(
            this, 2, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE,
        )
        try {
            geofencingClient.addGeofences(request, geofencePi!!)
                .addOnFailureListener { e ->
                    Log.w(TAG, "Failed to add idle geofence", e)
                    // Interval updates remain the fallback for this idle window.
                }
        } catch (e: SecurityException) {
            Log.e(TAG, "Geofence add rejected: permission missing", e)
        }
    }

    private fun removeGeofence() {
        if (!hasGms) return
        // Rebuild the PendingIntent when the field is null (process restart)
        // so the previous incarnation's geofence is actually cleared rather
        // than left registered in Play services indefinitely.
        val pi = geofencePi ?: PendingIntent.getBroadcast(
            this, 2, Intent(this, GeofenceReceiver::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE,
        )
        try {
            geofencingClient.removeGeofences(pi)
                .addOnFailureListener { e -> Log.e(TAG, "Failed to remove geofence", e) }
        } catch (e: Exception) {
            Log.e(TAG, "Error removing geofence", e)
        }
        geofencePi = null
    }

    // ────────────────────────────────────────────────────────────────────
    //  Location modes
    // ────────────────────────────────────────────────────────────────────

    @SuppressLint("MissingPermission")
    private fun applyMode(mode: Mode, force: Boolean = false) {
        if (!started || (currentMode == mode && !force)) return
        val modeChanged = currentMode != mode
        currentMode = mode
        stopLocationUpdates()
        val generation = locationRequestGeneration

        try {
            if (hasGms && ::fusedClient.isInitialized) {
                fusedClient.requestLocationUpdates(buildLocationRequest(mode), locationCallback, mainLooper)
                    .addOnFailureListener { error ->
                        if (started && generation == locationRequestGeneration) {
                            Log.w(TAG, "Fused location unavailable; using platform location", error)
                            requestPlatformLocationUpdates(mode)
                        }
                    }
            } else {
                requestPlatformLocationUpdates(mode)
            }
        } catch (e: SecurityException) {
            Log.w(TAG, "Location permission missing", e)
            shutdown()
            return
        }

        if (mode == Mode.IDLE) lastLocation?.takeIf(TrackingFix::isFresh)?.let(::addIdleGeofence)
        if (modeChanged && mode == Mode.MOVING) removeGeofence()
        if (modeChanged) updateNotification()
    }

    @SuppressLint("MissingPermission")
    private fun requestPlatformLocationUpdates(mode: Mode) {
        val manager = platformLocationManager ?: return
        val interval = intervalFor(mode)
        val network = manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)
        val gps = manager.isProviderEnabled(LocationManager.GPS_PROVIDER)

        if (!network && gps && (mode == Mode.IDLE || getBatteryBucket() != BatteryBucket.NORMAL)) {
            // A GPS-only phone gets short, cancellable fixes while idle.
            platformIdleFixes = serviceScope.launch {
                while (isActive && started && isTrackingEnabled(this@TrackingService)) {
                    TrackingFix.current(this@TrackingService, highAccuracy = false, platformOnly = true)
                        ?.let(::onLocationReceived)
                    delay(interval)
                }
            }
            return
        }

        val provider = when {
            gps && mode == Mode.MOVING && getBatteryBucket() == BatteryBucket.NORMAL ->
                LocationManager.GPS_PROVIDER
            network -> LocationManager.NETWORK_PROVIDER
            gps -> LocationManager.GPS_PROVIDER
            else -> return
        }
        try {
            manager.requestLocationUpdates(provider, interval, 0f, lmListener, mainLooper)
            lmUpdatesRegistered = true
        } catch (error: SecurityException) {
            Log.w(TAG, "Platform location permission missing", error)
        }
    }

    private fun intervalFor(mode: Mode): Long = when {
        getBatteryBucket() == BatteryBucket.CRITICAL -> IDLE_INTERVAL_MS
        getBatteryBucket() == BatteryBucket.LOW -> 3 * 60_000L
        mode == Mode.MOVING -> MOVING_INTERVAL_MS
        activityTransitionsRegistered -> IDLE_INTERVAL_MS
        else -> FALLBACK_INTERVAL_MS
    }

    private fun buildLocationRequest(mode: Mode): LocationRequest {
        val interval = intervalFor(mode)
        val priority = if (getBatteryBucket() == BatteryBucket.CRITICAL ||
            (mode == Mode.IDLE && activityTransitionsRegistered)
        ) Priority.PRIORITY_LOW_POWER else Priority.PRIORITY_BALANCED_POWER_ACCURACY

        return LocationRequest.Builder(priority, interval)
            .setMinUpdateIntervalMillis(interval)
            .setMaxUpdateAgeMillis(0)
            .setMaxUpdateDelayMillis(if (mode == Mode.MOVING) 2 * interval else interval)
            .build()
    }

    private fun stopLocationUpdates() {
        locationRequestGeneration++
        platformIdleFixes?.cancel()
        platformIdleFixes = null
        if (::fusedClient.isInitialized) fusedClient.removeLocationUpdates(locationCallback)
        if (lmUpdatesRegistered) {
            platformLocationManager?.removeUpdates(lmListener)
            lmUpdatesRegistered = false
        }
    }

    private fun acceptsFix(location: Location): Boolean {
        if (!started) return false
        if (!isTrackingEnabled(this) || !canShowTrackingNotification(this)) {
            doStop()
            return false
        }
        return TrackingFix.isFresh(location)
    }

    private fun onLocationReceived(location: Location) {
        if (!acceptsFix(location) || location.elapsedRealtimeNanos <= lastFixNanos) return
        lastFixNanos = location.elapsedRealtimeNanos
        val now = SystemClock.elapsedRealtime()
        if (currentMode == Mode.MOVING && now - lastMovementMs >= TrackingMotion.IDLE_AFTER_MS) {
            switchToIdle()
        }
        val anchor = movementAnchor
        val accuracy = location.takeIf(Location::hasAccuracy)?.accuracy
        val moved = TrackingMotion.isMovement(
            anchor?.distanceTo(location),
            anchor?.accuracy,
            accuracy,
            location.takeIf(Location::hasSpeed)?.speed,
            location.takeIf(Location::hasSpeedAccuracy)?.speedAccuracyMetersPerSecond,
        )
        if (accuracy != null && accuracy <= 100f && (anchor == null || moved)) {
            movementAnchor = location
        }
        lastLocation = location
        if (moved) recordMovement(now)
        if (currentMode == Mode.IDLE) addIdleGeofence(location)

        val lastQueued = lastQueuedMs
        val heartbeatDue = lastQueued == null || now - lastQueued >= IDLE_ENQUEUE_INTERVAL_MS
        if (heartbeatDue || (currentMode == Mode.MOVING && store.shouldEnqueue(location))) {
            enqueue(location, now)
        }
    }

    private fun enqueue(location: Location, now: Long, urgent: Boolean = false) {
        val consent = PreferencesManager(this).trackingConsent() ?: return
        if (location.elapsedRealtimeNanos <= lastQueuedFixNanos) {
            if (urgent) LocationUploadWorker.enqueue(this)
            return
        }
        lastQueuedFixNanos = location.elapsedRealtimeNanos
        lastQueuedMs = now
        store.markEnqueued(location)
        val entity = TrackingFix.asQueueEntry(location, currentMode == Mode.MOVING, getBatteryLevel())
        serviceScope.launch {
            if (store.enqueueLocation(entity, consent.scope)) {
                LocationUploadWorker.enqueue(this@TrackingService, if (urgent) 0 else 15)
            } else if (lastQueuedFixNanos == location.elapsedRealtimeNanos) {
                lastQueuedMs = null
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────
    //  Battery
    // ────────────────────────────────────────────────────────────────────

    private fun registerBatteryReceiver() {
        if (batteryReceiverRegistered) return
        try {
            ContextCompat.registerReceiver(
                this, batteryReceiver, IntentFilter(Intent.ACTION_BATTERY_CHANGED).apply {
                    addAction(PowerManager.ACTION_POWER_SAVE_MODE_CHANGED)
                },
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

    private fun registerProvidersChangedReceiver() {
        if (providersChangedRegistered) return
        try {
            ContextCompat.registerReceiver(
                this, providersChangedReceiver,
                IntentFilter(LocationManager.PROVIDERS_CHANGED_ACTION),
                ContextCompat.RECEIVER_NOT_EXPORTED,
            )
            providersChangedRegistered = true
        } catch (e: Exception) {
            Log.w(TAG, "Could not register providers-changed receiver", e)
        }
    }

    private fun unregisterProvidersChangedReceiver() {
        if (!providersChangedRegistered) return
        try {
            unregisterReceiver(providersChangedReceiver)
        } catch (_: Exception) {
        }
        providersChangedRegistered = false
    }

    private fun getBatteryBucket(): BatteryBucket {
        val level = getBatteryLevel() ?: 100
        val powerSave = getSystemService(PowerManager::class.java)?.isPowerSaveMode == true
        return when {
            level < 10 -> BatteryBucket.CRITICAL
            level < 20 || powerSave -> BatteryBucket.LOW
            else -> BatteryBucket.NORMAL
        }
    }

    private fun getBatteryLevel(): Int? {
        return (getSystemService(BATTERY_SERVICE) as? BatteryManager)
            ?.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
            ?.takeIf { it in 0..100 }
    }

    // ────────────────────────────────────────────────────────────────────
    //  Notification
    // ────────────────────────────────────────────────────────────────────

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
            .setSmallIcon(R.drawable.ic_notification)
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
