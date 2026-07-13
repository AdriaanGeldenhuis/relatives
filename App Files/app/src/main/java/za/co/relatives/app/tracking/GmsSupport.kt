package za.co.relatives.app.tracking

import android.content.Context
import android.util.Log
import com.google.android.gms.common.ConnectionResult
import com.google.android.gms.common.GoogleApiAvailability

/**
 * GmsSupport — one cached answer to "can this device use Google Play services?"
 *
 * Recent Huawei devices (Mate 30 and later) ship without Play services, and
 * everything this app's tracking stack leaned on — FusedLocationProvider,
 * Geofencing, ActivityRecognition, FCM — is a Play services API. On those
 * devices every location call failed silently and the user simply never
 * appeared on the family map. Callers use this to switch to the plain
 * android.location.LocationManager fallback instead.
 */
object GmsSupport {

    private const val TAG = "GmsSupport"

    @Volatile
    private var cached: Boolean? = null

    fun available(context: Context): Boolean {
        cached?.let { return it }
        val ok = try {
            GoogleApiAvailability.getInstance()
                .isGooglePlayServicesAvailable(context.applicationContext) == ConnectionResult.SUCCESS
        } catch (t: Throwable) {
            // Play services classes can be entirely absent on HMS-only builds.
            Log.w(TAG, "Play services check failed; assuming unavailable", t)
            false
        }
        if (!ok) Log.i(TAG, "Google Play services unavailable — using LocationManager fallback")
        cached = ok
        return ok
    }
}
