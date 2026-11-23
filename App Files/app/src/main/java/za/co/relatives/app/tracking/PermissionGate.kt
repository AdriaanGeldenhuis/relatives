package za.co.relatives.app.tracking

import android.Manifest
import android.app.AlertDialog
import android.content.Intent
import android.os.Build
import android.provider.Settings
import androidx.activity.ComponentActivity
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import android.content.pm.PackageManager
import za.co.relatives.app.R

/** Keep directions permission separate from consent to share location with family. */
class PermissionGate(private val activity: ComponentActivity) {
    private enum class Purpose { DIRECTIONS, TRACKING }

    private var purpose: Purpose? = null
    private var result: ((Boolean) -> Unit)? = null
    private var dialog: AlertDialog? = null

    private val location = activity.registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions(),
    ) {
        onLocationResult()
    }

    private val trackingNotifications = activity.registerForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted ->
        if (purpose == Purpose.TRACKING) {
            if (granted) offerBackgroundLocation() else finish(false)
        }
    }

    private val background = activity.registerForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) {
        if (purpose == Purpose.TRACKING) finishTracking()
    }

    private val motion = activity.registerForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted ->
        if (granted) TrackingService.onMotionPermissionGranted(activity)
    }

    private val notifications = activity.registerForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) {}

    fun requestTracking(callback: (Boolean) -> Unit) {
        if (!begin(Purpose.TRACKING, callback)) return
        dialog = AlertDialog.Builder(activity)
            .setTitle(R.string.location_dialog_title)
            .setMessage(R.string.location_dialog_message)
            .setPositiveButton(R.string.location_dialog_enable) { _, _ -> requestLocation() }
            .setNegativeButton(R.string.dialog_not_now) { _, _ -> finish(false) }
            .setOnCancelListener { finish(false) }
            .show()
    }

    fun requestForegroundLocation(callback: (Boolean) -> Unit) {
        if (purpose != null) {
            callback(false)
            return
        }
        if (hasForegroundLocation()) {
            callback(true)
            return
        }
        if (!begin(Purpose.DIRECTIONS, callback)) return
        requestLocation()
    }

    private fun begin(next: Purpose, callback: (Boolean) -> Unit): Boolean {
        if (activity.isFinishing || activity.isDestroyed || purpose != null) {
            callback(false)
            return false
        }
        purpose = next
        result = callback
        return true
    }

    private fun requestLocation() {
        if (hasForegroundLocation()) {
            onLocationResult()
        } else {
            location.launch(arrayOf(Manifest.permission.ACCESS_COARSE_LOCATION, Manifest.permission.ACCESS_FINE_LOCATION))
        }
    }

    private fun onLocationResult() {
        when (purpose) {
            Purpose.DIRECTIONS -> finish(hasForegroundLocation())
            Purpose.TRACKING -> when {
                !hasForegroundLocation() -> finish(false)
                TrackingService.canShowTrackingNotification(activity) -> offerBackgroundLocation()
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
                    !granted(Manifest.permission.POST_NOTIFICATIONS) ->
                    trackingNotifications.launch(Manifest.permission.POST_NOTIFICATIONS)
                else -> offerNotificationSettings()
            }
            null -> Unit
        }
    }

    private fun offerNotificationSettings() {
        dialog = AlertDialog.Builder(activity)
            .setTitle(R.string.notification_dialog_title)
            .setMessage(R.string.tracking_notification_required)
            .setPositiveButton(R.string.notification_settings) { _, _ ->
                finish(false)
                activity.startActivity(
                    Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                        .putExtra(Settings.EXTRA_APP_PACKAGE, activity.packageName),
                )
            }
            .setNegativeButton(R.string.dialog_not_now) { _, _ -> finish(false) }
            .setOnCancelListener { finish(false) }
            .show()
    }

    private fun offerBackgroundLocation() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q ||
            granted(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
        ) {
            finishTracking()
            return
        }

        dialog = AlertDialog.Builder(activity)
            .setTitle(R.string.background_dialog_title)
            .setMessage(R.string.background_dialog_message)
            .setPositiveButton(R.string.dialog_continue) { _, _ ->
                background.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
            }
            .setNegativeButton(R.string.background_dialog_without) { _, _ -> finishTracking() }
            .setOnCancelListener { finish(false) }
            .show()
    }

    private fun finishTracking() {
        finish(hasForegroundLocation() && TrackingService.canShowTrackingNotification(activity))
    }

    private fun finish(allowed: Boolean) {
        val callback = result
        result = null
        purpose = null
        callback?.invoke(allowed)
    }

    fun requestMotionRecognition() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q &&
            !granted(Manifest.permission.ACTIVITY_RECOGNITION)
        ) {
            motion.launch(Manifest.permission.ACTIVITY_RECOGNITION)
        }
    }

    fun requestNotifications() {
        if (purpose == null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            !NotificationManagerCompat.from(activity).areNotificationsEnabled()
        ) {
            notifications.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    fun hasForegroundLocation(): Boolean =
        granted(Manifest.permission.ACCESS_COARSE_LOCATION) || granted(Manifest.permission.ACCESS_FINE_LOCATION)

    private fun granted(permission: String): Boolean =
        ContextCompat.checkSelfPermission(activity, permission) == PackageManager.PERMISSION_GRANTED

    fun dismissDialogs() {
        dialog?.dismiss()
        result = null
        purpose = null
    }
}
