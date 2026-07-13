package za.co.relatives.app.tracking

import android.annotation.SuppressLint
import android.content.Context
import android.location.Location
import android.location.LocationManager
import android.os.Build
import android.util.Log
import androidx.core.location.LocationManagerCompat
import androidx.work.Constraints
import androidx.work.Data
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.Worker
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.Tasks
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.function.Consumer
import za.co.relatives.app.data.QueuedLocationEntity
import za.co.relatives.app.data.TrackingStore

/**
 * TrackingRestartWorker — the tracking watchdog.
 *
 * Three schedules share this worker:
 *  - a one-time run 15s after task swipe-away ([enqueue], from
 *    TrackingService.onTaskRemoved) and after FCM keepalives,
 *  - a 15-minute periodic run ([enqueuePeriodic], armed whenever tracking
 *    starts) that survives process death — this is what brings tracking
 *    back after Doze/OEM battery killers silently kill the service, and
 *  - an immediate force-fix run ([enqueueWakeFix], from a family member's
 *    Wake push) that captures ONE fresh high-accuracy fix even when the
 *    foreground service cannot legally be restarted from the background.
 *
 * Each run: if tracking should be on but the service is dead, restart it
 * (the FGS-from-background refusal on some devices is swallowed inside
 * TrackingService.safeStart). Independently, if no fix has been captured
 * for [STALE_FIX_MS] (or always, for a wake), capture one directly here and
 * queue it for upload — so the family still sees a recent dot even when the
 * service cannot be restarted from the background.
 */
class TrackingRestartWorker(
    ctx: Context,
    params: WorkerParameters,
) : Worker(ctx, params) {

    companion object {
        private const val TAG = "TrackingRestartWorker"
        private const val WORK_NAME = "tracking_restart"
        private const val WATCHDOG_NAME = "tracking_watchdog"
        private const val WAKE_FIX_NAME = "tracking_wake_fix"

        private const val KEY_FORCE_FIX = "force_fix"

        /** No fix for this long → the watchdog captures one itself. */
        private const val STALE_FIX_MS = 20 * 60 * 1000L

        fun enqueue(ctx: Context) {
            val req = OneTimeWorkRequestBuilder<TrackingRestartWorker>()
                .setInitialDelay(15, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(ctx).enqueueUniqueWork(
                WORK_NAME,
                ExistingWorkPolicy.REPLACE,
                req
            )
        }

        /**
         * Immediate run that captures a fix regardless of staleness — a
         * family member is looking at the map RIGHT NOW. No initial delay;
         * the triggering high-priority FCM has already woken the process.
         */
        fun enqueueWakeFix(ctx: Context) {
            val req = OneTimeWorkRequestBuilder<TrackingRestartWorker>()
                .setInputData(Data.Builder().putBoolean(KEY_FORCE_FIX, true).build())
                .setConstraints(
                    Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()
                )
                .build()
            WorkManager.getInstance(ctx).enqueueUniqueWork(
                WAKE_FIX_NAME,
                ExistingWorkPolicy.REPLACE,
                req
            )
        }

        /** Arm (or keep) the periodic watchdog. 15 min is WorkManager's floor. */
        fun enqueuePeriodic(ctx: Context) {
            val req = PeriodicWorkRequestBuilder<TrackingRestartWorker>(15, TimeUnit.MINUTES)
                .build()
            WorkManager.getInstance(ctx).enqueueUniquePeriodicWork(
                WATCHDOG_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                req
            )
        }
    }

    override fun doWork(): Result {
        val ctx = applicationContext
        val prefs = ctx.getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)
        val forceFix = inputData.getBoolean(KEY_FORCE_FIX, false)

        if (!prefs.getBoolean("tracking_enabled", false)) {
            // User turned tracking off — disarm the watchdog so it stops
            // waking the device for nothing. Re-armed on next enable.
            Log.d(TAG, "Tracking disabled; disarming watchdog.")
            WorkManager.getInstance(ctx).cancelUniqueWork(WATCHDOG_NAME)
            return Result.success()
        }
        if (!TrackingService.hasLocationPermission(ctx)) {
            return Result.success()
        }
        if (!TrackingService.canReviveFromBackground(ctx)) {
            // Without background location a service started from here would
            // sit fix-less; MainActivity.onResume remains the revival path.
            return Result.success()
        }

        if (!TrackingService.isRunning) {
            Log.i(TAG, "Watchdog: TrackingService not running — reviving.")
            TrackingService.revive(ctx)
        }

        // Freshness backstop: even if the restart was refused (Android 12+
        // background-FGS block on some paths), keep the family map alive.
        // A wake always captures — someone is watching the map right now.
        val lastFix = prefs.getLong("last_fix_time", 0L)
        if (forceFix || System.currentTimeMillis() - lastFix > STALE_FIX_MS) {
            captureFallbackFix(ctx, highAccuracy = forceFix)
        }
        return Result.success()
    }

    @SuppressLint("MissingPermission")
    private fun captureFallbackFix(ctx: Context, highAccuracy: Boolean) {
        try {
            val loc = if (GmsSupport.available(ctx)) {
                val priority = if (highAccuracy) {
                    Priority.PRIORITY_HIGH_ACCURACY
                } else {
                    Priority.PRIORITY_BALANCED_POWER_ACCURACY
                }
                val task = LocationServices.getFusedLocationProviderClient(ctx)
                    .getCurrentLocation(priority, null)
                Tasks.await(task, 50, TimeUnit.SECONDS)
            } else {
                // HMS-only Huawei etc.: fused provider is dead — go straight
                // to the platform LocationManager.
                currentLocationViaLocationManager(ctx, highAccuracy)
            } ?: return

            val now = System.currentTimeMillis()
            // Stamp with the fix's real capture time, not "now": the
            // LocationManager fallback below can return a getLastKnownLocation
            // that is hours old, and stamping it now would upload a stale
            // position as if it were current.
            val captureTime = if (loc.time in 1 until (now + 1)) loc.time else now
            val fixAgeMs = now - captureTime

            TrackingStore(ctx).enqueueLocation(
                QueuedLocationEntity(
                    lat = loc.latitude, lng = loc.longitude,
                    accuracy = if (loc.hasAccuracy()) loc.accuracy else null,
                    altitude = if (loc.hasAltitude()) loc.altitude else null,
                    bearing = if (loc.hasBearing()) loc.bearing else null,
                    speed = if (loc.hasSpeed()) loc.speed else null,
                    speedKmh = if (loc.hasSpeed()) loc.speed * 3.6f else null,
                    isMoving = false,
                    batteryLevel = null,
                    timestamp = captureTime,
                )
            )
            // Only treat the watchdog as "satisfied" for a genuinely fresh
            // fix; a stale last-known point must not stop the watchdog from
            // trying for a real one on the next tick.
            if (fixAgeMs <= STALE_FIX_MS) {
                ctx.getSharedPreferences("relatives_prefs", Context.MODE_PRIVATE)
                    .edit().putLong("last_fix_time", captureTime).apply()
            }

            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()
            // 15s delay matches TrackingService.scheduleUpload: the Room
            // insert above is async, and an instant worker could run before
            // the row commits, uploading nothing.
            val upload = OneTimeWorkRequestBuilder<LocationUploadWorker>()
                .setConstraints(constraints)
                .setInitialDelay(15, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(ctx).enqueueUniqueWork(
                LocationUploadWorker.WORK_NAME,
                ExistingWorkPolicy.KEEP,
                upload
            )
            Log.i(TAG, "Watchdog: captured fallback fix (highAccuracy=$highAccuracy).")
        } catch (t: Throwable) {
            Log.w(TAG, "Watchdog fallback fix failed", t)
        }
    }

    /**
     * Synchronous single fix through android.location.LocationManager for
     * devices without Google Play services. Blocks the worker thread (fine)
     * while the callback arrives on the main looper.
     */
    @SuppressLint("MissingPermission")
    private fun currentLocationViaLocationManager(ctx: Context, highAccuracy: Boolean): Location? {
        val lm = ctx.getSystemService(Context.LOCATION_SERVICE) as? LocationManager ?: return null
        val provider = pickProvider(lm, highAccuracy) ?: return null

        var result: Location? = null
        val latch = CountDownLatch(1)
        val consumer = Consumer<Location?> { loc ->
            result = loc
            latch.countDown()
        }
        // Full listener object, not a lambda: pre-API-30 the OS may invoke the
        // legacy callbacks, which a SAM lambda compiled against a modern SDK
        // does not implement (AbstractMethodError). Kept in scope so it can be
        // removed on timeout — requestSingleUpdate only auto-unregisters after
        // it delivers, so a no-fix timeout otherwise leaks a live GPS request.
        val legacyListener = object : android.location.LocationListener {
            override fun onLocationChanged(location: Location) {
                consumer.accept(location)
            }

            @Deprecated("Deprecated in Java")
            override fun onStatusChanged(p: String?, status: Int, extras: android.os.Bundle?) {}
            override fun onProviderEnabled(p: String) {}
            override fun onProviderDisabled(p: String) {}
        }
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                lm.getCurrentLocation(provider, null, ctx.mainExecutor, consumer)
                latch.await(50, TimeUnit.SECONDS)
            } else {
                @Suppress("DEPRECATION")
                lm.requestSingleUpdate(provider, legacyListener, ctx.mainLooper)
                val delivered = latch.await(50, TimeUnit.SECONDS)
                if (!delivered) {
                    // Never got a fix — cancel the outstanding request.
                    runCatching { lm.removeUpdates(legacyListener) }
                }
            }
        } catch (t: Throwable) {
            Log.w(TAG, "LocationManager fix failed", t)
            if (Build.VERSION.SDK_INT < Build.VERSION_CODES.R) {
                runCatching { lm.removeUpdates(legacyListener) }
            }
        }
        return result ?: lm.getLastKnownLocation(provider)
    }

    private fun pickProvider(lm: LocationManager, highAccuracy: Boolean): String? {
        if (!LocationManagerCompat.isLocationEnabled(lm)) return null
        val preferred = if (highAccuracy) {
            listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
        } else {
            listOf(LocationManager.NETWORK_PROVIDER, LocationManager.GPS_PROVIDER)
        }
        return preferred.firstOrNull { runCatching { lm.isProviderEnabled(it) }.getOrDefault(false) }
    }
}
