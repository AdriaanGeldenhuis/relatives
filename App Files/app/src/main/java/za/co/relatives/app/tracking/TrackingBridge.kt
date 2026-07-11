package za.co.relatives.app.tracking

import android.webkit.JavascriptInterface
import za.co.relatives.app.MainActivity

/**
 * TrackingBridge — JavaScript interface exposed as `window.TrackingBridge`.
 *
 * UI actions:
 *   startTracking  → PermissionGate → TrackingService.start
 *   stopTracking   → TrackingService.stop
 *   getCachedFamily → TrackingStore dump to JS for Mapbox render
 *   wakeAllDevices → TrackingService.wake (FCM push via server)
 *   getTrackingMode → returns "enabled" / "disabled" / "no_permission"
 *
 * All mutating methods dispatch to the UI thread via [MainActivity.runOnUiThread].
 * Read-only methods return synchronously (lightweight SharedPreferences reads).
 */
class TrackingBridge(private val activity: MainActivity) {

    /**
     * Run [action] on the UI thread, swallowing any exception. Bridge calls
     * originate from the WebView's JavaBridge thread and can land while the
     * activity is finishing (e.g. dialog on a dead window) — nothing a web
     * page triggers may ever crash the app.
     */
    private inline fun safeOnUiThread(crossinline action: () -> Unit) {
        activity.runOnUiThread {
            try {
                action()
            } catch (e: Exception) {
                android.util.Log.w("TrackingBridge", "Bridge call failed", e)
            }
        }
    }

    /**
     * Returns cached family member locations as a JSON string.
     * The map JS uses this to render pins immediately without waiting
     * for the next API poll — this is the cache-to-render pipeline.
     */
    @JavascriptInterface
    fun getCachedFamily(): String {
        return try {
            activity.trackingStore.familyLocationsJson()
        } catch (e: Exception) {
            "[]"
        }
    }

    /**
     * Start tracking. Goes through PermissionGate first —
     * prominent disclosure → foreground permission → background → start service.
     */
    @JavascriptInterface
    fun startTracking() {
        safeOnUiThread {
            activity.startTrackingWithPermissions()
        }
    }

    /**
     * Stop tracking. Immediately stops the foreground service.
     */
    @JavascriptInterface
    fun stopTracking() {
        safeOnUiThread {
            activity.stopTrackingService()
        }
    }

    /**
     * Returns the current tracking state:
     *  - "enabled"       — tracking is on and permissions granted
     *  - "disabled"      — user has turned tracking off
     *  - "no_permission" — location permission not yet granted
     */
    @JavascriptInterface
    fun getTrackingMode(): String {
        return try {
            activity.getTrackingMode()
        } catch (e: Exception) {
            "disabled"
        }
    }

    /**
     * Returns true when tracking is enabled AND permission is granted.
     */
    @JavascriptInterface
    fun isTrackingEnabled(): Boolean {
        return try {
            activity.isTrackingActive()
        } catch (e: Exception) {
            false
        }
    }

    /**
     * Send a wake push to all family devices via the server.
     * Triggers WAKE (burst) mode on receiving devices.
     */
    @JavascriptInterface
    fun wakeAllDevices() {
        safeOnUiThread {
            activity.wakeAllDevices()
        }
    }

    /**
     * Request notification permission (Android 13+).
     * Called when the user visits the notifications page so they can
     * receive push notifications.
     */
    @JavascriptInterface
    fun requestNotificationPermission() {
        safeOnUiThread {
            activity.requestNotificationPermission()
        }
    }

    /**
     * Notify native side that the tracking map screen is now visible.
     * Triggers faster polling and a location boost.
     */
    @JavascriptInterface
    fun onTrackingScreenVisible() {
        safeOnUiThread {
            activity.onTrackingScreenVisible()
        }
    }

    /**
     * Notify native side that the tracking map screen is now hidden.
     * Reverts to slower background polling.
     */
    @JavascriptInterface
    fun onTrackingScreenHidden() {
        safeOnUiThread {
            activity.onTrackingScreenHidden()
        }
    }
}
