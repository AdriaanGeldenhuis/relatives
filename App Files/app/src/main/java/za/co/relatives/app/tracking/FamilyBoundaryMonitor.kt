package za.co.relatives.app.tracking

import android.annotation.SuppressLint
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.location.Location
import android.os.SystemClock
import android.util.Log
import com.google.android.gms.location.Geofence
import com.google.android.gms.location.GeofencingRequest
import com.google.android.gms.location.LocationServices
import com.google.gson.JsonObject
import za.co.relatives.app.utils.PreferencesManager
import kotlin.math.abs
import kotlin.math.cos
import kotlin.math.hypot

/** OS transitions request a better fix. The server confirms the crossing. */
internal class FamilyBoundaryMonitor(private val context: Context) {
    companion object {
        const val ACTION = "za.co.relatives.app.tracking.FAMILY_BOUNDARY"
        private const val REQUEST = 4
        private const val PREFIX = "boundary:"
        private const val MAX_NATIVE_REGIONS = 90
        private const val REGISTRATION_MS = 15 * 60_000L
        private const val CONFIG_MS = 3 * 60_000L

        private fun pendingIntent(context: Context, flags: Int): PendingIntent? =
            PendingIntent.getBroadcast(context, REQUEST, Intent(context, GeofenceReceiver::class.java).setAction(ACTION),
                flags or PendingIntent.FLAG_MUTABLE)

        fun owns(scope: String, id: String): Boolean = id.startsWith("$PREFIX$scope:")

        fun eligible(context: Context): Boolean = GmsSupport.available(context) &&
            TrackingService.hasPreciseLocation(context) && TrackingService.hasBackgroundLocation(context)

        fun removeRegistrations(context: Context) {
            val pending = pendingIntent(context, PendingIntent.FLAG_NO_CREATE) ?: return
            runCatching { LocationServices.getGeofencingClient(context).removeGeofences(pending) }
            pending.cancel()
        }

        private fun relativeLongitude(value: Double, reference: Double): Double =
            (value - reference + 540.0) % 360.0 - 180.0

        fun distance(lat1: Double, lng1: Double, lat2: Double, lng2: Double): Float {
            val result = FloatArray(1)
            Location.distanceBetween(lat1, lng1, lat2, lng2, result)
            return result[0]
        }
    }

    private data class Region(
        val id: String, val lat: Double, val lng: Double, val radius: Float,
        val vertices: List<Pair<Double, Double>> = emptyList(),
    ) {
        fun centerDistance(location: Location): Float = distance(location.latitude, location.longitude, lat, lng)

        fun boundaryDistance(location: Location): Float {
            if (vertices.isEmpty()) return abs(centerDistance(location) - radius)
            val scale = cos(Math.toRadians(location.latitude)) * 111_195
            val reference = vertices.first().second
            val from = relativeLongitude(location.longitude, reference)
            val points = vertices.map { (lat, lng) ->
                (relativeLongitude(lng, reference) - from) * scale to (lat - location.latitude) * 111_195
            }
            var minimum = Double.POSITIVE_INFINITY
            var (ax, ay) = points.last()
            for ((bx, by) in points) {
                val dx = bx - ax
                val dy = by - ay
                val length = dx * dx + dy * dy
                val fraction = if (length > 0) (-(ax * dx + ay * dy) / length).coerceIn(0.0, 1.0) else 0.0
                minimum = minOf(minimum, hypot(ax + fraction * dx, ay + fraction * dy))
                ax = bx
                ay = by
            }
            return minimum.toFloat()
        }
    }

    private var regions: List<Region> = emptyList()
    private var nextSync = 0L
    private var nextRegistration = 0L
    private var registered: List<Region> = emptyList()
    private var generation = 0
    private var registering = false

    fun nearBoundary(location: Location): Boolean = regions.any {
        it.boundaryDistance(location) <= maxOf(45f, minOf(120f, location.accuracy * 1.5f))
    }

    fun invalidate() {
        generation++
        nextSync = 0
        nextRegistration = 0
        registering = false
        registered = emptyList()
        regions = emptyList()
        removeRegistrations(context)
    }

    suspend fun refresh(consent: PreferencesManager.TrackingConsent, getLocation: () -> Location?) {
        val now = SystemClock.elapsedRealtime()
        if (now >= nextSync) {
            nextSync = now + 30_000L
            val reply = TrackingTransport(context).monitorRegions(consent.sessionCookie) ?: return
            if (PreferencesManager(context).trackingConsent()?.scope != consent.scope) return
            if (reply.code == 401 || reply.code == 403) {
                invalidate()
                return
            }
            if (!reply.success) return
            val data = reply.data?.get("regions")?.takeIf { it.isJsonArray }?.asJsonArray ?: return
            regions = data.mapNotNull { item ->
                if (item.isJsonObject) parseRegion(item.asJsonObject) else null
            }
            nextSync = now + CONFIG_MS
        }
        register(consent.scope, getLocation(), now)
    }

    @SuppressLint("MissingPermission")
    private fun register(scope: String, location: Location?, now: Long) {
        if (!eligible(context) || !TrackingService.providersEnabled(context)) {
            if (registering || registered.isNotEmpty()) invalidate()
            return
        }
        val selected = if (location != null && regions.size > MAX_NATIVE_REGIONS) {
            regions.sortedBy { it.boundaryDistance(location) }.take(MAX_NATIVE_REGIONS).sortedBy { it.id }
        } else {
            regions.take(MAX_NATIVE_REGIONS)
        }
        if (registering || (selected == registered && now < nextRegistration)) return

        nextRegistration = now + REGISTRATION_MS / 2
        val attempt = ++generation
        registering = true
        val pending = pendingIntent(context, PendingIntent.FLAG_UPDATE_CURRENT) ?: return
        val client = LocationServices.getGeofencingClient(context)
        client.removeGeofences(pending).addOnCompleteListener {
            if (generation != attempt) return@addOnCompleteListener
            if (selected.isEmpty() || PreferencesManager(context).trackingConsent()?.scope != scope || !eligible(context)) {
                registering = false
                registered = emptyList()
                return@addOnCompleteListener
            }
            val fences = selected.map { region ->
                Geofence.Builder().setRequestId("$PREFIX$scope:${region.id}")
                    .setCircularRegion(region.lat, region.lng, maxOf(100f, region.radius))
                    .setTransitionTypes(Geofence.GEOFENCE_TRANSITION_ENTER or Geofence.GEOFENCE_TRANSITION_EXIT)
                    .setNotificationResponsiveness(15_000)
                    .setExpirationDuration(REGISTRATION_MS)
                    .build()
            }
            val request = GeofencingRequest.Builder().setInitialTrigger(0).addGeofences(fences).build()
            try {
                client.addGeofences(request, pending).addOnCompleteListener { task ->
                    if (generation == attempt) {
                        registering = false
                        registered = if (task.isSuccessful) selected else emptyList()
                        if (!task.isSuccessful) {
                            nextRegistration = 0L
                            Log.w("FamilyBoundaryMonitor", "Android geofence registration unavailable")
                        }
                    } else if (PreferencesManager(context).trackingConsent()?.scope != scope) {
                        client.removeGeofences(fences.map { it.requestId })
                    }
                }
            } catch (_: SecurityException) {
                registering = false
                nextRegistration = 0L
            }
        }
    }

    private fun parseRegion(row: JsonObject): Region? {
        val id = row.text("id")?.takeIf { it.matches(Regex("[fp][1-9][0-9]{0,19}")) } ?: return null
        val lat = row.text("lat")?.toDoubleOrNull()
        val lng = row.text("lng")?.toDoubleOrNull()
        val radius = row.text("radius_m")?.toFloatOrNull()
        if (lat != null && lng != null && radius != null) {
            return if (lat in -90.0..90.0 && lng in -180.0..180.0 && radius in 1f..50_000f) Region(id, lat, lng, radius) else null
        }
        val ring = row.get("vertices")?.takeIf { it.isJsonArray }?.asJsonArray ?: return null
        val points = ring.mapNotNull { vertex ->
            if (!vertex.isJsonArray || vertex.asJsonArray.size() != 2) return@mapNotNull null
            val point = vertex.asJsonArray
            val x = runCatching { point[0].asDouble }.getOrNull() ?: return@mapNotNull null
            val y = runCatching { point[1].asDouble }.getOrNull() ?: return@mapNotNull null
            if (x !in -90.0..90.0 || y !in -180.0..180.0) return@mapNotNull null
            x to y
        }
        if (points.size != ring.size() || points.size < 3) return null
        val centerLat = points.map { it.first }.average()
        val reference = points.first().second
        val centerLng = relativeLongitude(reference + points.map { relativeLongitude(it.second, reference) }.average(), 0.0)
        val extent = points.maxOf { (x, y) -> distance(centerLat, centerLng, x, y) }
        // The circle is a native wake hint. The full polygon is used for distance checks.
        return Region(id, centerLat, centerLng, extent.coerceAtMost(50_000f), points)
    }

}
