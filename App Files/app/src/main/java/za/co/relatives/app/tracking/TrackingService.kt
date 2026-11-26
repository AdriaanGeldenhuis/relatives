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
import android.hardware.Sensor
import android.hardware.SensorManager
import android.hardware.TriggerEvent
import android.hardware.TriggerEventListener
import android.location.Location
import android.location.LocationManager
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
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
import androidx.core.location.LocationManagerCompat
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
import kotlinx.coroutines.withTimeoutOrNull
import kotlinx.coroutines.channels.Channel
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import za.co.relatives.app.R
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.network.NetworkClient
import za.co.relatives.app.services.FcmRegistrationWorker
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
        private const val ACTION_FAMILY_WAKE = "za.co.relatives.app.tracking.FAMILY_WAKE"
        private const val ACTION_BOUNDARY = "za.co.relatives.app.tracking.BOUNDARY"
        private const val ACTION_DRIVING = "za.co.relatives.app.tracking.DRIVING"
        private const val ACTION_MOTION_PERMISSION = "za.co.relatives.app.tracking.MOTION_PERMISSION"
        const val ACTION_REVIVE = "za.co.relatives.app.tracking.REVIVE"

        private const val CHANNEL_ID = "tracking_channel"
        private const val NOTIFICATION_ID = 9001

        private const val GEOFENCE_RADIUS_M = 200f
        private const val GEOFENCE_ID = "idle_geofence"

        private const val WAKE_COOLDOWN_MS = 18_000L
        @Volatile
        private var activeService: TrackingService? = null

        /** True while an instance is alive. Lets callers avoid start churn. */
        @Volatile
        var isRunning = false
            private set

        @Volatile internal var appVisible = false

        fun start(context: Context) {
            if (!hasLocationPermission(context) || !canShowTrackingNotification(context)) return
            if (PreferencesManager(context).enableTracking()) safeStart(context, ACTION_START)
        }

        fun requestWakeFix(context: Context) {
            if (wakeCapability(context) != "silent_ready") return
            val service = activeService
            if (isRunning && service != null) {
                service.handler.post {
                    if (activeService === service && isRunning) service.captureWakeFix()
                }
            } else if (appVisible) {
                safeStart(context, ACTION_WAKE)
            }
        }

        internal fun startFamilyWake(context: Context, wake: FamilyWake): Boolean {
            if (PreferencesManager(context).trackingConsent()?.scope != wake.scope) return false
            val service = activeService
            if (isRunning && service != null) {
                return service.handler.post {
                    if (activeService === service && isRunning) service.captureFamilyWake(wake)
                }
            }
            return appVisible && safeStart(context, ACTION_FAMILY_WAKE, wake)
        }

        fun wakeCapability(context: Context): String = when {
            !isTrackingEnabled(context) || !PreferencesManager(context).trackingPolicy().canCollect -> "paused"
            !hasLocationPermission(context) || !providersEnabled(context) -> "permission"
            !canShowTrackingNotification(context) -> "notifications"
            else -> "silent_ready"
        }

        fun providersEnabled(context: Context): Boolean =
            context.getSystemService(LocationManager::class.java)?.let(LocationManagerCompat::isLocationEnabled) == true

        fun batterySaver(context: Context): Boolean =
            context.getSystemService(PowerManager::class.java)?.isPowerSaveMode == true ||
                context.getSystemService(BatteryManager::class.java)
                    ?.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)?.let { it in 0..19 } == true

        internal fun signInRequired(context: Context) {
            stop(context)
            PreferencesManager(context).clearSession()
            NetworkClient.clearCookies()
        }

        internal fun accountSharingDisabled(context: Context, cookie: String) {
            val prefs = PreferencesManager(context)
            if (prefs.sessionToken != cookie) return
            prefs.saveTrackingPolicy(cookie, JsonObject().apply { addProperty("location_sharing", false) })
            stop(context)
        }

        internal fun applyServerPolicy(context: Context, cookie: String, json: JsonObject) {
            val prefs = PreferencesManager(context)
            if (prefs.sessionToken != cookie) return
            json.number("server_time_ms")?.let { TrackingClock.sync(it) }
            val previous = prefs.trackingPolicy()
            prefs.saveTrackingPolicy(cookie, json)
            val current = prefs.trackingPolicy()
            if (!current.accountAllowsSharing) {
                accountSharingDisabled(context, cookie)
                return
            }
            if (previous != current) {
                FcmRegistrationWorker.enqueue(context)
                activeService?.let { service ->
                    service.handler.post { service.policyChanged(current) }
                }
            }
        }

        internal suspend fun refreshServerPolicy(context: Context, cookie: String) {
            val reply = TrackingTransport(context).policy(cookie) ?: return
            val prefs = PreferencesManager(context)
            if (prefs.sessionToken != cookie) return
            when {
                reply.code == 401 -> signInRequired(context)
                reply.success -> {
                    val data = reply.data ?: return
                    val policy = data.getAsJsonObject("tracking_policy") ?: data
                    applyServerPolicy(context, cookie, policy)
                }
            }
        }

        fun onMotionPermissionGranted(context: Context) {
            if (isRunning) safeStart(context, ACTION_MOTION_PERMISSION)
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
            if (activeService == null) {
                // The pending intents can outlive an OS-killed service.
                listOf(1 to ActivityTransitionsReceiver::class.java, 2 to GeofenceReceiver::class.java).forEach { (code, receiver) ->
                    PendingIntent.getBroadcast(context, code, Intent(context, receiver),
                        PendingIntent.FLAG_NO_CREATE or PendingIntent.FLAG_MUTABLE)?.cancel()
                }
            }
            context.stopService(Intent(context, TrackingService::class.java))
        }

        private fun disableTracking(context: Context) {
            val prefs = PreferencesManager(context)
            val previous = prefs.trackingConsent()
            prefs.disableTracking()
            if (previous != null) TrackingRestartWorker.discard(context, previous.scope)
            TrackingRestartWorker.cancel(context)
            WatchdogAlarmReceiver.cancel(context)
            TrackingHeartbeatReceiver.cancel(context)
            FamilyBoundaryMonitor.removeRegistrations(context)
            FcmRegistrationWorker.enqueue(context)
            WorkManager.getInstance(context).cancelUniqueWork(LocationUploadWorker.WORK_NAME)
        }

        fun motionStarted(context: Context, driving: Boolean = false) {
            safeStart(context, if (driving) ACTION_DRIVING else ACTION_MOTION_STARTED)
        }

        internal fun boundaryTriggered(context: Context, location: Location?) {
            if (isRunning) {
                activeService?.let { service -> service.handler.post { service.captureBoundary(location) } }
            } else if (hasBackgroundLocation(context)) {
                safeStart(context, ACTION_BOUNDARY)
            }
        }

        internal fun boundaryUnavailable() {
            activeService?.let { service -> service.handler.post { service.boundaries.invalidate() } }
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
        private fun safeStart(context: Context, action: String, wake: FamilyWake? = null): Boolean {
            if (!isTrackingEnabled(context) || !hasLocationPermission(context) ||
                !canShowTrackingNotification(context)
            ) return false
            val intent = Intent(context, TrackingService::class.java).apply { this.action = action }
            if (wake != null) wake.putInto(intent)
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

        fun hasPreciseLocation(context: Context): Boolean =
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED

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
    private var providersChangedRegistered = false

    private var started = false
    private var sampling = false
    private var requireFirstFix = false
    private var networkCallback: ConnectivityManager.NetworkCallback? = null
    private var networkRecovery: Job? = null
    private val uploadSignals = Channel<Unit>(Channel.CONFLATED)
    private var policy = TrackingPolicy()
    private var captureSequence = 0
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
    private var lastFamilyWakeId: String? = null
    private val boundaries by lazy { FamilyBoundaryMonitor(this) }
    private var driving = false
    private var boundaryUntil = 0L
    private var lastBoundaryProbe = -30_000L
    private var boundaryProbe: Job? = null
    private var monitoredRegions: Job? = null
    private var lastWakeMs: Long? = null
    private var platformIdleFixes: Job? = null
    private var platformMotionSensor: Sensor? = null
    private var platformMotionArmed = false
    private val platformMotionListener = object : TriggerEventListener() {
        override fun onTrigger(event: TriggerEvent) {
            handler.post {
                platformMotionArmed = false
                if (sampling && isTrackingEnabled(this@TrackingService)) recordMovement(SystemClock.elapsedRealtime())
            }
        }
    }
    private var locationRequestGeneration = 0
    private var firstFixFallback: Runnable? = null
    private var activityTransitionsRegistered = false
    private var lastBatteryBucket: BatteryBucket? = null
    private var batteryReceiverRegistered = false
    private var activityTransitionPi: PendingIntent? = null
    private var geofencePi: PendingIntent? = null

    private val locationCallback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            result.locations.sortedBy { it.elapsedRealtimeNanos }.forEach(::onLocationReceived)
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

    // Non-GMS devices have no geofence/AR to re-arm updates, and the plain
    // LocationManager registration silently no-ops when GPS/network are both
    // off. Without this, toggling Location off then on left the user's dot
    // frozen until they reopened the app. Re-apply the mode whenever the
    // provider set changes so updates re-register once a provider returns.
    private val providersChangedReceiver = object : BroadcastReceiver() {
        override fun onReceive(ctx: Context?, intent: Intent?) {
            FcmRegistrationWorker.enqueue(this@TrackingService)
            if (sampling) applyMode(currentMode, force = true)
        }
    }

    override fun onCreate() {
        super.onCreate()
        activeService = this
        try {
            // Channel first: foreground promotion depends on it.
            createTrackingChannel(this)
            hasGms = GmsSupport.available(this)
            if (hasGms) {
                fusedClient = LocationServices.getFusedLocationProviderClient(this)
                geofencingClient = LocationServices.getGeofencingClient(this)
            }
            platformLocationManager = getSystemService(LOCATION_SERVICE) as? LocationManager
            if (!hasGms) platformMotionSensor = getSystemService(SensorManager::class.java)?.getDefaultSensor(Sensor.TYPE_SIGNIFICANT_MOTION)
            store = TrackingStore(this)
        } catch (e: Exception) {
            // Play Services / Room can fail on broken devices — shut down
            // cleanly on the next command instead of crashing the app.
            Log.e(TAG, "Service init failed", e)
            initFailed = true
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val enabled = isTrackingEnabled(this)
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

        if (!enabled || !hasPermission || !canShowTrackingNotification(this)) {
            shutdown()
            return START_NOT_STICKY
        }
        isRunning = true
        val wake = FamilyWake.from(intent)
        if (intent?.action == ACTION_FAMILY_WAKE && wake != null) {
            captureFamilyWake(wake)
            return if (started) START_STICKY else START_NOT_STICKY
        }
        if (intent?.action == ACTION_WAKE && !started) {
            captureWakeFix()
            return START_NOT_STICKY
        }
        doStart()
        when (intent?.action) {
            ACTION_MOTION_STARTED -> recordMovement(SystemClock.elapsedRealtime())
            ACTION_DRIVING -> recordDriving(SystemClock.elapsedRealtime())
            ACTION_BOUNDARY -> captureBoundary(null)
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
        unregisterConnectivity()
        if (activeService === this) activeService = null

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
        captureSequence++
        handler.removeCallbacks(idleTimeout)
        wakeFix?.cancel()
        wakeFix = null
        boundaryProbe?.cancel()
        monitoredRegions?.cancel()
        stopLocationUpdates()
        unregisterBatteryReceiver()
        unregisterProvidersChangedReceiver()
        unregisterConnectivity()
        sampling = false
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
        val prefs = PreferencesManager(this)
        val consent = prefs.trackingConsent() ?: return
        started = true
        policy = prefs.trackingPolicy()
        FcmRegistrationWorker.enqueue(this)
        lastBatteryBucket = getBatteryBucket()
        registerBatteryReceiver()
        registerProvidersChangedReceiver()
        registerConnectivity()
        TrackingRestartWorker.enqueuePeriodic(this)
        WatchdogAlarmReceiver.cancel(this)
        TrackingHeartbeatReceiver.cancel(this)

        serviceScope.launch {
            if (connected()) {
                refreshServerPolicy(this@TrackingService, consent.sessionCookie)
            }
            if (!started || prefs.trackingConsent()?.scope != consent.scope) return@launch
            policy = prefs.trackingPolicy()
            if (!policy.canCollect) {
                shutdown()
                return@launch
            }
            sampling = true
            requireFirstFix = true
            applyMode(currentMode, force = true)
            registerActivityTransitions()
            checkPendingWake(consent)
            if (connected()) boundaries.refresh(consent) { lastLocation }
        }
        monitoredRegions = serviceScope.launch {
            while (isActive && started && prefs.trackingConsent()?.scope == consent.scope) {
                delay(60_000)
                if (sampling && connected()) boundaries.refresh(consent) { lastLocation }
            }
        }
        serviceScope.launch {
            for (signal in uploadSignals) {
                delay(2000)
                if (!connected()) continue
                val activeConsent = prefs.trackingConsent() ?: continue
                when (LocationUploader.send(this@TrackingService, activeConsent)) {
                    UploadResult.RETRY, UploadResult.SENT -> LocationUploadWorker.enqueue(this@TrackingService)
                    else -> Unit
                }
            }
        }
    }

    private fun policyChanged(current: TrackingPolicy) {
        policy = current
        if (!current.canCollect && started) {
            PreferencesManager(this).trackingConsent()?.let { TrackingRestartWorker.discard(this, it.scope) }
            unregisterActivityTransitions()
            removeGeofence()
            boundaries.invalidate()
            shutdown()
        } else if (sampling) {
            applyMode(currentMode, force = true)
        }
    }

    private fun connected(): Boolean {
        val network = getSystemService(ConnectivityManager::class.java) ?: return false
        val capabilities = network.getNetworkCapabilities(network.activeNetwork) ?: return false
        return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    private fun registerConnectivity() {
        val manager = getSystemService(ConnectivityManager::class.java) ?: return
        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                handler.post {
                    if (!sampling || networkRecovery?.isActive == true) return@post
                    val consent = PreferencesManager(this@TrackingService).trackingConsent() ?: return@post
                    networkRecovery = serviceScope.launch {
                        refreshServerPolicy(this@TrackingService, consent.sessionCookie)
                        if (!started || !PreferencesManager(this@TrackingService).trackingPolicy().canCollect) return@launch
                        checkPendingWake(consent)
                        val last = lastLocation
                        if (last == null || TrackingFix.ageMs(last) > intervalFor(currentMode)) {
                            requireFirstFix = true
                            applyMode(currentMode, force = true)
                        } else {
                            uploadSignals.trySend(Unit)
                        }
                        LocationUploadWorker.enqueue(this@TrackingService)
                    }
                }
            }
        }
        runCatching { manager.registerDefaultNetworkCallback(callback) }.onSuccess { networkCallback = callback }
    }

    private fun unregisterConnectivity() {
        networkCallback?.let { callback ->
            runCatching { getSystemService(ConnectivityManager::class.java)?.unregisterNetworkCallback(callback) }
        }
        networkCallback = null
    }

    private fun doStop() {
        disableTracking(this)
        shutdown()
    }

    private suspend fun checkPendingWake(consent: PreferencesManager.TrackingConsent) {
        if (!canCapture(consent) || !connected()) return
        val reply = TrackingTransport(this).pendingWake(consent.sessionCookie, PreferencesManager(this).deviceUuid)
        if (reply?.success == true && canCapture(consent)) FamilyWake.pending(this, consent, reply.data)
    }

    private fun recordDriving(now: Long) {
        val newTrip = !driving
        driving = true
        if (newTrip && currentMode == Mode.MOVING) applyMode(Mode.MOVING, force = true)
        recordMovement(now)
    }

    private fun recordMovement(now: Long) {
        lastMovementMs = now
        if (currentMode != Mode.MOVING) requireFirstFix = true
        applyMode(Mode.MOVING)
        handler.removeCallbacks(idleTimeout)
        handler.postDelayed(idleTimeout, idleDelayMs())
    }

    private fun idleDelayMs(): Long = maxOf(TrackingMotion.IDLE_AFTER_MS, 2 * intervalFor(Mode.MOVING))

    private fun switchToIdle() {
        handler.removeCallbacks(idleTimeout)
        driving = false
        applyMode(Mode.IDLE)
    }

    private fun captureBoundary(hint: Location?) {
        val consent = PreferencesManager(this).trackingConsent() ?: return
        if (!canCapture(consent)) return
        val now = SystemClock.elapsedRealtime()
        val wasActive = now < boundaryUntil
        boundaryUntil = now + 40_000
        if (!sampling || now - lastBoundaryProbe < 25_000 || boundaryProbe?.isActive == true) return
        lastBoundaryProbe = now
        if (hint != null && TrackingFix.isFresh(hint) && TrackingFix.ageMs(hint) < 10_000 &&
            hint.hasAccuracy() && hint.accuracy <= preciseTarget()) {
            enqueue(hint, now, urgent = true)
        }
        if (!wasActive) applyMode(currentMode, force = true)
        boundaryProbe = serviceScope.launch {
            val fix = captureFreshFix(now, now + 20_000)
            if (fix != null && canCapture(consent)) enqueue(fix, SystemClock.elapsedRealtime(), urgent = true)
        }
    }

    private fun captureWakeFix() {
        if (wakeFix?.isActive == true) return
        val now = SystemClock.elapsedRealtime()
        if (lastWakeMs?.let { now - it < WAKE_COOLDOWN_MS } == true) return
        lastWakeMs = now
        val consent = PreferencesManager(this).trackingConsent() ?: return
        val sequence = ++captureSequence
        wakeFix = serviceScope.launch {
            try {
                if (connected()) refreshServerPolicy(this@TrackingService, consent.sessionCookie)
                if (!canCapture(consent)) return@launch
                val fix = captureFreshFix(now, now + 25_000)
                if (fix != null && canCapture(consent)) {
                    persistWakeFix(fix, consent, recordedMs = TrackingClock.capture(fix))
                }
            } finally {
                restoreSamplingAfterWake(sequence)
            }
        }
    }

    private fun captureFamilyWake(wake: FamilyWake) {
        val prefs = PreferencesManager(this)
        val consent = prefs.trackingConsent()?.takeIf { it.scope == wake.scope }
        if (consent == null || lastFamilyWakeId == wake.id) {
            if (!started && wakeFix?.isActive != true) shutdown()
            return
        }
        lastFamilyWakeId = wake.id
        val sequence = ++captureSequence
        wakeFix?.cancel()
        lastWakeMs = SystemClock.elapsedRealtime()
        wakeFix = serviceScope.launch {
            try {
                if (!canCapture(consent)) {
                    val capability = wakeCapability(this@TrackingService)
                    FamilyWake.acknowledge(this@TrackingService, consent.sessionCookie, wake.id,
                        if (capability == "silent_ready") "paused" else capability)
                    return@launch
                }
                var acknowledgement = FamilyWake.acknowledge(this@TrackingService, consent.sessionCookie, wake.id, "received")
                if ((acknowledgement == null || acknowledgement.code >= 500) && canCapture(consent)) {
                    delay(500)
                    acknowledgement = FamilyWake.acknowledge(this@TrackingService, consent.sessionCookie, wake.id, "received")
                }
                val data = acknowledgement?.data
                if (data == null) {
                    if (acknowledgement == null || acknowledgement.code >= 500) lastFamilyWakeId = null
                    return@launch
                }
                if (data.flag("acquire") != true || !canCapture(consent)) return@launch
                val remaining = data.number("remaining_ms") ?: return@launch
                val serverNow = data.number("server_time_ms") ?: (wake.expiresAtMs - remaining)
                val issued = data.number("issued_at_ms") ?: return@launch
                val receivedElapsed = SystemClock.elapsedRealtime()
                TrackingClock.sync(serverNow, receivedElapsed)
                val deadline = FamilyWake.deadline(remaining)
                val issueElapsed = receivedElapsed - (serverNow - issued).coerceIn(0, 60_000)
                val ready = lastLocation?.takeIf { TrackingFix.captureElapsedMs(it) >= issueElapsed &&
                    TrackingFix.isFresh(it) && it.hasAccuracy() && it.accuracy <= preciseTarget() }
                val fix = ready ?: captureFreshFix(issueElapsed, minOf(deadline, receivedElapsed + 25_000))
                if (fix == null || !canCapture(consent)) {
                    FamilyWake.acknowledge(this@TrackingService, consent.sessionCookie, wake.id, if (fix == null) "no_fix" else "paused")
                    return@launch
                }
                val captureMs = serverNow + TrackingFix.captureElapsedMs(fix) - receivedElapsed
                val timeLeft = deadline - SystemClock.elapsedRealtime()
                if (timeLeft > 0) {
                    withTimeoutOrNull(timeLeft) {
                        persistWakeFix(fix, consent, captureMs, wake.id, serverNow + (deadline - receivedElapsed))
                    }
                }
            } finally {
                restoreSamplingAfterWake(sequence)
            }
        }
    }

    private fun canCapture(consent: PreferencesManager.TrackingConsent): Boolean {
        val prefs = PreferencesManager(this)
        return prefs.trackingConsent()?.scope == consent.scope && prefs.trackingPolicy().canCollect &&
            hasLocationPermission(this) && canShowTrackingNotification(this) && providersEnabled(this)
    }

    private suspend fun captureFreshFix(since: Long, deadline: Long): Location? {
        val remaining = deadline - SystemClock.elapsedRealtime()
        if (remaining <= 0) return null
        val authorizeGps = getBatteryBucket() != BatteryBucket.CRITICAL && hasPreciseLocation(this)
        val precise = if (authorizeGps) TrackingFix.current(
            this, highAccuracy = true, timeoutMs = (remaining * 3 / 4).coerceAtLeast(1),
            captureAfterMs = since, targetAccuracyM = preciseTarget(),
        ) else null
        if (precise?.let { it.hasAccuracy() && it.accuracy <= preciseTarget() } == true) return precise
        val timeLeft = deadline - SystemClock.elapsedRealtime()
        if (timeLeft <= 0) return precise
        val balanced = TrackingFix.current(
            this, highAccuracy = false, timeoutMs = timeLeft, captureAfterMs = since, allowGps = authorizeGps,
        )
        return listOfNotNull(precise, balanced).minByOrNull { if (it.hasAccuracy()) it.accuracy else Float.MAX_VALUE }
    }

    private fun preciseTarget(): Float = minOf(40, policy.targetAccuracyM).toFloat()

    private suspend fun persistWakeFix(
        fix: Location, consent: PreferencesManager.TrackingConsent, recordedMs: Long,
        wakeId: String? = null, expiresMs: Long? = null,
    ) {
        val entity = TrackingFix.asQueueEntry(fix, currentMode == Mode.MOVING, getBatteryLevel()).copy(
            timestamp = recordedMs, priority = 1, wakeRequestId = wakeId, wakeDeadlineMs = expiresMs,
        )
        if (store.enqueueLocation(entity, consent.scope)) {
            LocationUploadWorker.enqueue(this, 10)
            val result = LocationUploader.send(this, consent, entity.clientEventId)
            Log.d(TAG, "Refresh upload result=$result")
        }
    }

    private fun restoreSamplingAfterWake(sequence: Int) {
        if (sequence != captureSequence) return
        wakeFix = null
        if (sampling) applyMode(currentMode, force = true)
        else if (!started) shutdown()
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
        if (!sampling || (currentMode == mode && !force)) return
        val modeChanged = currentMode != mode
        if (modeChanged) Log.d(TAG, "Mode=$mode interval_ms=${intervalFor(mode)}")
        currentMode = mode
        stopLocationUpdates()
        val generation = locationRequestGeneration

        try {
            if (hasGms && ::fusedClient.isInitialized) {
                fusedClient.requestLocationUpdates(buildLocationRequest(mode), locationCallback, mainLooper)
                    .addOnFailureListener { error ->
                        if (sampling && generation == locationRequestGeneration) {
                            Log.w(TAG, "Fused location unavailable; using platform location", error)
                            stopLocationUpdates()
                            requestPlatformLocationUpdates(mode)
                        }
                    }
                if (requireFirstFix) {
                    firstFixFallback = Runnable {
                        if (sampling && requireFirstFix && generation == locationRequestGeneration) {
                            Log.d(TAG, "Fused provider gave no initial fix; trying platform location")
                            stopLocationUpdates()
                            requestPlatformLocationUpdates(mode)
                        }
                    }.also { handler.postDelayed(it, 12_000) }
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
    }

    @SuppressLint("MissingPermission")
    private fun requestPlatformLocationUpdates(mode: Mode) {
        val manager = platformLocationManager ?: return
        val network = manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER)
        val gps = manager.isProviderEnabled(LocationManager.GPS_PROVIDER)
        if (mode == Mode.IDLE && !platformMotionArmed) {
            platformMotionSensor?.let { sensor ->
                platformMotionArmed = runCatching {
                    getSystemService(SensorManager::class.java)?.requestTriggerSensor(platformMotionListener, sensor) == true
                }.getOrDefault(false)
            }
        }
        if (!network && !gps) return

        // A bounded request avoids keeping GPS running between samples on a GPS-only phone.
        val interval = intervalFor(mode)
        platformIdleFixes = serviceScope.launch {
            while (isActive && sampling && isTrackingEnabled(this@TrackingService)) {
                val startedAt = SystemClock.elapsedRealtime()
                val balanced = TrackingFix.current(
                    this@TrackingService, highAccuracy = false, platformOnly = true, timeoutMs = 10_000,
                    allowGps = getBatteryBucket() == BatteryBucket.NORMAL || !network,
                )
                val useGps = gps && network && shouldSamplePrecisely() &&
                    (balanced == null || !balanced.hasAccuracy() || balanced.accuracy > preciseTarget())
                val precise = if (useGps) TrackingFix.current(
                    this@TrackingService, highAccuracy = true, platformOnly = true, timeoutMs = 8_000,
                ) else null
                (precise ?: balanced)?.let(::onLocationReceived)
                delay(maxOf(1000, interval - (SystemClock.elapsedRealtime() - startedAt)))
            }
        }
    }

    private fun intervalFor(mode: Mode): Long {
        val battery = getBatteryBucket()
        if (battery != BatteryBucket.CRITICAL) {
            if (SystemClock.elapsedRealtime() < boundaryUntil) return 4_000
            if (mode == Mode.MOVING && driving && policy.drivingAccuracy) {
                return policy.drivingIntervalMs(battery == BatteryBucket.LOW)
            }
        }
        return policy.intervalMs(
            moving = mode == Mode.MOVING,
            motionAvailable = activityTransitionsRegistered || platformMotionArmed,
            lowPower = battery == BatteryBucket.LOW,
            critical = battery == BatteryBucket.CRITICAL,
        )
    }

    private fun buildLocationRequest(mode: Mode): LocationRequest {
        val interval = intervalFor(mode)
        val priority = when {
            shouldSamplePrecisely() -> Priority.PRIORITY_HIGH_ACCURACY
            getBatteryBucket() != BatteryBucket.NORMAL || (mode == Mode.IDLE && activityTransitionsRegistered) ->
                Priority.PRIORITY_LOW_POWER
            else -> Priority.PRIORITY_BALANCED_POWER_ACCURACY
        }

        return LocationRequest.Builder(priority, interval)
            .setMinUpdateIntervalMillis(interval)
            .setMaxUpdateAgeMillis(if (requireFirstFix) 5_000L else 0)
            .setMaxUpdateDelayMillis(interval)
            .setWaitForAccurateLocation(priority == Priority.PRIORITY_HIGH_ACCURACY)
            .build()
    }

    private fun shouldSamplePrecisely(): Boolean = hasPreciseLocation(this) &&
        (getBatteryBucket() != BatteryBucket.CRITICAL && SystemClock.elapsedRealtime() < boundaryUntil ||
            getBatteryBucket() == BatteryBucket.NORMAL && currentMode == Mode.MOVING && driving && policy.drivingAccuracy)

    private fun stopLocationUpdates() {
        locationRequestGeneration++
        firstFixFallback?.let(handler::removeCallbacks)
        firstFixFallback = null
        platformIdleFixes?.cancel()
        platformIdleFixes = null
        if (::fusedClient.isInitialized) fusedClient.removeLocationUpdates(locationCallback)
        if (platformMotionArmed) {
            platformMotionSensor?.let { sensor ->
                runCatching { getSystemService(SensorManager::class.java)?.cancelTriggerSensor(platformMotionListener, sensor) }
            }
            platformMotionArmed = false
        }
    }

    private fun acceptsFix(location: Location): Boolean {
        if (!sampling) return false
        if (!isTrackingEnabled(this) || !canShowTrackingNotification(this) || !hasLocationPermission(this)) {
            shutdown()
            return false
        }
        return TrackingFix.isFresh(location)
    }

    private fun onLocationReceived(location: Location) {
        if (!acceptsFix(location) || location.elapsedRealtimeNanos <= lastFixNanos) return
        lastFixNanos = location.elapsedRealtimeNanos
        firstFixFallback?.let(handler::removeCallbacks)
        firstFixFallback = null
        val now = SystemClock.elapsedRealtime()
        if (currentMode == Mode.MOVING && now - lastMovementMs >= idleDelayMs()) {
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
        val vehicle = TrackingMotion.isDriving(location.takeIf(Location::hasSpeed)?.speed,
            location.takeIf(Location::hasSpeedAccuracy)?.speedAccuracyMetersPerSecond, accuracy)
        if (accuracy != null && accuracy <= 100f && (anchor == null || moved)) {
            movementAnchor = location
        }
        lastLocation = location
        if (vehicle) recordDriving(now)
        else if (moved) recordMovement(now)
        if (currentMode == Mode.IDLE) addIdleGeofence(location)
        if (moved && getBatteryBucket() != BatteryBucket.CRITICAL && boundaries.nearBoundary(location)) {
            captureBoundary(location)
        } else if (boundaryUntil != 0L && now >= boundaryUntil) {
            boundaryUntil = 0
            applyMode(currentMode, force = true)
        }

        val lastQueued = lastQueuedMs
        val interval = if (currentMode == Mode.MOVING || now < boundaryUntil) intervalFor(currentMode) else policy.idleSeconds * 1000L
        if (requireFirstFix || lastQueued == null || now - lastQueued >= interval - 1000L) {
            if (requireFirstFix) Log.d(TAG, "First fix age_ms=${TrackingFix.ageMs(location)}")
            val urgent = requireFirstFix || lastQueued == null
            requireFirstFix = false
            enqueue(location, now, urgent)
        }
    }

    private fun enqueue(location: Location, now: Long, urgent: Boolean = false) {
        val consent = PreferencesManager(this).trackingConsent() ?: return
        if (location.elapsedRealtimeNanos <= lastQueuedFixNanos) {
            if (urgent) uploadSignals.trySend(Unit)
            return
        }
        lastQueuedFixNanos = location.elapsedRealtimeNanos
        lastQueuedMs = now
        val entity = TrackingFix.asQueueEntry(location, currentMode == Mode.MOVING, getBatteryLevel()).copy(
            priority = if (urgent) 1 else 0,
        )
        serviceScope.launch {
            if (store.enqueueLocation(entity, consent.scope)) {
                if (urgent && connected()) {
                    LocationUploadWorker.enqueue(this@TrackingService, 10)
                    LocationUploader.send(this@TrackingService, consent, entity.clientEventId)
                } else if (connected()) {
                    uploadSignals.trySend(Unit)
                } else {
                    LocationUploadWorker.enqueue(this@TrackingService)
                }
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
        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(R.string.app_name))
            .setContentText(getString(R.string.tracking_notif_idle))
            .setSmallIcon(R.drawable.ic_notification)
            .setOngoing(true)
            .setSilent(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .addAction(0, getString(R.string.tracking_notif_stop), stopPending)
            .build()
    }

}
