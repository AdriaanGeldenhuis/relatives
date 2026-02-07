package za.co.relatives.app.tracking

import android.content.Intent
import android.webkit.JavascriptInterface
import za.co.relatives.app.MainActivity
import za.co.relatives.app.data.PreferencesManager
import com.google.firebase.messaging.FirebaseMessaging

class TrackingJsInterface(private val activity: MainActivity) {

    private val prefs = PreferencesManager(activity)

    @JavascriptInterface
    fun startTracking() {
        val intent = Intent(activity, LocationTrackingService::class.java).apply {
            action = LocationTrackingService.ACTION_START
        }
        activity.startForegroundService(intent)
    }

    @JavascriptInterface
    fun stopTracking() {
        val intent = Intent(activity, LocationTrackingService::class.java).apply {
            action = LocationTrackingService.ACTION_STOP
        }
        activity.startService(intent)
    }

    @JavascriptInterface
    fun getTrackingState(): String {
        return prefs.getTrackingState()
    }

    @JavascriptInterface
    fun isNativeApp(): Boolean = true

    @JavascriptInterface
    fun getFCMToken(): String {
        return prefs.getFCMToken() ?: ""
    }

    @JavascriptInterface
    fun requestPermissions() {
        activity.runOnUiThread {
            activity.requestLocationPermissions()
        }
    }

    @JavascriptInterface
    fun requestBackgroundPermission() {
        activity.runOnUiThread {
            activity.requestBackgroundLocationPermission()
        }
    }

    @JavascriptInterface
    fun hasLocationPermission(): Boolean {
        return activity.hasLocationPermission()
    }

    @JavascriptInterface
    fun setUpdateInterval(seconds: Int) {
        prefs.setUpdateInterval(seconds)
    }

    @JavascriptInterface
    fun getUpdateInterval(): Int {
        return prefs.getUpdateInterval()
    }

    @JavascriptInterface
    fun getBatteryLevel(): Int {
        return za.co.relatives.app.utils.BatteryUtils.getBatteryLevel(activity)
    }

    fun onPermissionsGranted() {
        activity.webView.evaluateJavascript(
            "if(typeof onNativePermissionsGranted==='function')onNativePermissionsGranted();",
            null
        )
    }

    fun onNotificationPermissionGranted() {
        // Register FCM token
        FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
            prefs.setFCMToken(token)
            activity.webView.evaluateJavascript(
                "if(typeof onFCMTokenReceived==='function')onFCMTokenReceived('$token');",
                null
            )
        }
    }
}
