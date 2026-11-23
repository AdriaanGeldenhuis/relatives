package za.co.relatives.app.tracking

import android.annotation.SuppressLint
import android.content.Context
import android.location.Location
import android.location.LocationManager
import android.os.CancellationSignal
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
    fun isFresh(location: Location): Boolean {
        val ageMs = (SystemClock.elapsedRealtimeNanos() - location.elapsedRealtimeNanos) / 1_000_000
        return ageMs in 0L..TrackingMotion.STALE_FIX_MS && location.time > 0 &&
            location.latitude in -90.0..90.0 && location.longitude in -180.0..180.0 &&
            (!location.hasAccuracy() || location.accuracy in 0f..TrackingMotion.MAX_UPLOAD_ACCURACY_M)
    }

    @SuppressLint("MissingPermission")
    suspend fun current(context: Context, highAccuracy: Boolean, platformOnly: Boolean = false): Location? =
        withTimeoutOrNull<Location?>(25_000L) {
            try {
                if (!platformOnly && GmsSupport.available(context)) {
                    suspendCancellableCoroutine<Location?> { continuation ->
                        val source = CancellationTokenSource()
                        continuation.invokeOnCancellation { source.cancel() }
                        val priority = if (highAccuracy) Priority.PRIORITY_HIGH_ACCURACY
                            else Priority.PRIORITY_BALANCED_POWER_ACCURACY
                        val request = CurrentLocationRequest.Builder()
                            .setPriority(priority)
                            .setMaxUpdateAgeMillis(3_000L)
                            .setDurationMillis(20_000L)
                            .build()
                        LocationServices.getFusedLocationProviderClient(context)
                            .getCurrentLocation(request, source.token)
                            .addOnCompleteListener { task ->
                                continuation.resume(if (task.isSuccessful) task.result else null)
                            }
                    }
                } else {
                    val manager = context.getSystemService(LocationManager::class.java)
                        ?: return@withTimeoutOrNull null
                    val providers = if (highAccuracy) {
                        listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
                    } else {
                        listOf(LocationManager.NETWORK_PROVIDER, LocationManager.GPS_PROVIDER)
                    }
                    val provider = providers.firstOrNull { manager.isProviderEnabled(it) }
                        ?: return@withTimeoutOrNull null
                    suspendCancellableCoroutine<Location?> { continuation ->
                        val signal = CancellationSignal()
                        continuation.invokeOnCancellation { signal.cancel() }
                        LocationManagerCompat.getCurrentLocation(
                            manager, provider, signal, ContextCompat.getMainExecutor(context),
                        ) { location -> continuation.resume(location) }
                    }
                }
            } catch (_: SecurityException) {
                null // The permission may have been revoked during dispatch.
            } catch (_: IllegalArgumentException) {
                null // The selected provider may have been disabled during dispatch.
            }
        }?.takeIf(::isFresh)

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
