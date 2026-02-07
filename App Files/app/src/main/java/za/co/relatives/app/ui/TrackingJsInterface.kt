package za.co.relatives.app.ui

import android.webkit.JavascriptInterface
import za.co.relatives.app.MainActivity

/**
 * JavaScript interface exposed to the WebView as `window.TrackingBridge`.
 *
 * All mutating methods dispatch work on the UI thread through [MainActivity]
 * because WebView JS interface calls arrive on a background WebView thread.
 * Read-only methods ([isTrackingEnabled], [getTrackingMode]) return values
 * synchronously -- this is safe because the underlying checks are lightweight
 * reads against [SharedPreferences] and [PackageManager].
 */
class TrackingJsInterface(private val activity: MainActivity) {

    /** Returns `true` when tracking is enabled AND location permission is granted. */
    @JavascriptInterface
    fun isTrackingEnabled(): Boolean {
        return activity.isTrackingActive()
    }

    /**
     * Begin location tracking.
     *
     * If the necessary permissions have not yet been granted the activity will
     * show the prominent disclosure dialog followed by the system permission
     * prompts before starting the foreground service.
     */
    @JavascriptInterface
    fun startTracking() {
        activity.runOnUiThread {
            activity.requestTrackingWithPermissions()
        }
    }

    /** Stop the location tracking foreground service and persist the preference. */
    @JavascriptInterface
    fun stopTracking() {
        activity.runOnUiThread {
            activity.stopTrackingService()
        }
    }

    /**
     * Called by the web page when the live-tracking map becomes visible.
     * Switches the service to a faster (BURST) update interval so the map
     * feels responsive.
     */
    @JavascriptInterface
    fun onTrackingScreenVisible() {
        activity.runOnUiThread {
            activity.boostLocationUpdates()
        }
    }

    /**
     * Called by the web page when the live-tracking map is hidden.
     * Reverts the service to the normal (MOVING / IDLE) update interval.
     */
    @JavascriptInterface
    fun onTrackingScreenHidden() {
        activity.runOnUiThread {
            activity.revertLocationUpdates()
        }
    }

    /**
     * Request a temporary burst of fast location updates.
     *
     * @param seconds Duration in seconds after which the service automatically
     *                reverts to its normal cadence.
     */
    @JavascriptInterface
    fun requestLocationBoost(seconds: Int) {
        activity.runOnUiThread {
            activity.requestLocationBoost(seconds)
        }
    }

    /**
     * Trigger an immediate location fix and upload for all devices in the
     * family group via the server API.
     */
    @JavascriptInterface
    fun wakeAllDevices() {
        activity.runOnUiThread {
            activity.wakeAllDevices()
        }
    }

    /**
     * Returns the current tracking state as a string the web page can act on.
     *
     * Possible values:
     * - `"enabled"`       - tracking is on and permissions are granted
     * - `"disabled"`      - user has opted out of tracking
     * - `"no_permission"` - location permission has not been granted
     */
    @JavascriptInterface
    fun getTrackingMode(): String {
        return activity.getTrackingMode()
    }
}
