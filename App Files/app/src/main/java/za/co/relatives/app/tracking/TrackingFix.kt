package za.co.relatives.app.tracking

import android.annotation.SuppressLint
import android.content.Context
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Bundle
import android.os.CancellationSignal
import android.os.Looper
import android.os.SystemClock
import androidx.core.content.ContextCompat
import androidx.core.location.LocationManagerCompat
import com.google.android.gms.location.CurrentLocationRequest
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import kotlin.coroutines.resume
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeoutOrNull
import za.co.relatives.app.data.QueuedLocationEntity

internal object TrackingFix {
    fun captureElapsedMs(location: Location) = location.elapsedRealtimeNanos / 1_000_000
    fun ageMs(location: Location) = SystemClock.elapsedRealtime() - captureElapsedMs(location)

    fun isFresh(location: Location): Boolean =
        ageMs(location) in 0L..TrackingMotion.STALE_FIX_MS && location.time > 0 &&
            location.latitude in -90.0..90.0 && location.longitude in -180.0..180.0 &&
            (!location.hasAccuracy() || location.accuracy in 0f..TrackingMotion.MAX_UPLOAD_ACCURACY_M)

    @SuppressLint("MissingPermission")
    suspend fun current(
        context: Context, highAccuracy: Boolean, platformOnly: Boolean = false,
        timeoutMs: Long = 25_000L, captureAfterMs: Long? = null, allowGps: Boolean = true,
    ): Location? {
        val deadline = SystemClock.elapsedRealtime() + timeoutMs
        fun qualifying(location: Location?) = location?.takeIf {
            isFresh(it) && (captureAfterMs == null || captureElapsedMs(it) >= captureAfterMs)
        }
        return withTimeoutOrNull(timeoutMs) {
            try {
                if (!platformOnly && GmsSupport.available(context)) {
                    val fused = withTimeoutOrNull(if (timeoutMs > 5000) timeoutMs - 2000 else timeoutMs) {
                        suspendCancellableCoroutine<Location?> { continuation ->
                            val source = CancellationTokenSource()
                            continuation.invokeOnCancellation { source.cancel() }
                            val request = CurrentLocationRequest.Builder()
                                .setPriority(if (highAccuracy) Priority.PRIORITY_HIGH_ACCURACY else Priority.PRIORITY_BALANCED_POWER_ACCURACY)
                                .setMaxUpdateAgeMillis(if (captureAfterMs == null) 3000 else 0)
                                .setDurationMillis((deadline - SystemClock.elapsedRealtime()).coerceAtLeast(1))
                                .build()
                            LocationServices.getFusedLocationProviderClient(context)
                                .getCurrentLocation(request, source.token)
                                .addOnCompleteListener { task ->
                                    continuation.resume(if (task.isSuccessful) task.result else null)
                                }
                        }
                    }
                    qualifying(fused)?.let { return@withTimeoutOrNull it }
                }
                val manager = context.getSystemService(LocationManager::class.java) ?: return@withTimeoutOrNull null
                val precise = ContextCompat.checkSelfPermission(context, android.Manifest.permission.ACCESS_FINE_LOCATION) ==
                    android.content.pm.PackageManager.PERMISSION_GRANTED
                val enabled = if (highAccuracy && precise && allowGps) {
                    listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
                } else {
                    listOf(LocationManager.NETWORK_PROVIDER) + if (precise && allowGps) listOf(LocationManager.GPS_PROVIDER) else emptyList()
                }.filter { manager.isProviderEnabled(it) }
                for ((index, provider) in enabled.withIndex()) {
                    val remaining = deadline - SystemClock.elapsedRealtime()
                    if (remaining <= 0) break
                    val limit = if (index + 1 < enabled.size) remaining * 2 / 3 else remaining
                    val location = withTimeoutOrNull(limit) {
                        suspendCancellableCoroutine<Location?> { continuation ->
                            val signal = CancellationSignal()
                            continuation.invokeOnCancellation { signal.cancel() }
                            if (captureAfterMs == null) {
                                LocationManagerCompat.getCurrentLocation(
                                    manager, provider, signal, ContextCompat.getMainExecutor(context),
                                ) { continuation.resume(it) }
                            } else {
                                val listener = object : LocationListener {
                                    override fun onLocationChanged(location: Location) {
                                        if (qualifying(location) != null && continuation.isActive) {
                                            manager.removeUpdates(this)
                                            continuation.resume(location)
                                        }
                                    }
                                    override fun onProviderEnabled(provider: String) = Unit
                                    override fun onProviderDisabled(provider: String) = Unit
                                    @Deprecated("Location providers no longer send status callbacks.")
                                    override fun onStatusChanged(provider: String, status: Int, extras: Bundle?) = Unit
                                }
                                manager.requestLocationUpdates(provider, 0L, 0f, listener, Looper.getMainLooper())
                                continuation.invokeOnCancellation { manager.removeUpdates(listener) }
                            }
                        }
                    }
                    qualifying(location)?.let { return@withTimeoutOrNull it }
                }
                null
            } catch (_: SecurityException) {
                null
            } catch (_: IllegalArgumentException) {
                null
            }
        }
    }

    fun asQueueEntry(location: Location, moving: Boolean, batteryLevel: Int? = null) =
        QueuedLocationEntity(
            lat = location.latitude,
            lng = location.longitude,
            accuracy = location.takeIf(Location::hasAccuracy)?.accuracy,
            altitude = location.takeIf(Location::hasAltitude)?.altitude,
            bearing = location.takeIf(Location::hasBearing)?.bearing,
            speed = location.takeIf(Location::hasSpeed)?.speed,
            speedKmh = location.takeIf(Location::hasSpeed)?.speed?.times(3.6f),
            isMoving = moving,
            batteryLevel = batteryLevel,
            timestamp = location.time,
        )
}
