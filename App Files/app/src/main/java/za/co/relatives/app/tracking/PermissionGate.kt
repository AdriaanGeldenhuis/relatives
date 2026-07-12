package za.co.relatives.app.tracking

import android.Manifest
import android.app.AlertDialog
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.activity.ComponentActivity
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import za.co.relatives.app.R

/**
 * PermissionGate — Google Play policy-compliant permission flow.
 *
 * Flow:
 *   1. Prominent disclosure dialog (explains why location is needed)
 *   2. System foreground location prompt (FINE + COARSE together — Android 12+
 *      silently ignores a FINE-only request: no dialog, auto-denied)
 *   3. Background location dialog + system prompt (Android 10+)
 *   4. Notification permission prompt (Android 13+)
 *
 * The map loads without any permission. Permission is only requested
 * when the user explicitly taps "Enable live location".
 */
class PermissionGate(private val activity: ComponentActivity) {

    private var onResult: ((Boolean) -> Unit)? = null

    /** When true, stop after the foreground prompt (no background Settings trip). */
    private var foregroundOnly = false

    private lateinit var locationLauncher: ActivityResultLauncher<Array<String>>
    private lateinit var backgroundLocationLauncher: ActivityResultLauncher<String>
    private lateinit var activityRecognitionLauncher: ActivityResultLauncher<String>
    private lateinit var notificationLauncher: ActivityResultLauncher<String>

    /** Dialog currently showing, so the host activity can dismiss on destroy. */
    private var visibleDialog: AlertDialog? = null

    fun registerLaunchers() {
        locationLauncher = activity.registerForActivityResult(
            ActivityResultContracts.RequestMultiplePermissions(),
        ) { grants ->
            val granted = grants[Manifest.permission.ACCESS_FINE_LOCATION] == true ||
                grants[Manifest.permission.ACCESS_COARSE_LOCATION] == true
            when {
                granted && foregroundOnly -> {
                    foregroundOnly = false
                    onResult?.invoke(true)
                    onResult = null
                    // Still offer notifications, but skip the background-location
                    // Settings round-trip — the WebView geolocation path only
                    // needs foreground, and that round-trip recreates the
                    // activity and would drop this callback.
                    requestNotifications()
                }
                granted -> requestBackgroundLocation()
                else -> {
                    foregroundOnly = false
                    onResult?.invoke(false)
                    onResult = null
                    offerAppSettingsIfPermanentlyDenied()
                }
            }
        }

        backgroundLocationLauncher = activity.registerForActivityResult(
            ActivityResultContracts.RequestPermission(),
        ) { _ ->
            onResult?.invoke(hasForegroundLocation())
            onResult = null
            requestActivityRecognition()
        }

        activityRecognitionLauncher = activity.registerForActivityResult(
            ActivityResultContracts.RequestPermission(),
        ) { _ -> requestNotifications() }

        notificationLauncher = activity.registerForActivityResult(
            ActivityResultContracts.RequestPermission(),
        ) { _ -> }
    }

    fun requestTracking(callback: (Boolean) -> Unit) {
        if (hasForegroundLocation()) {
            callback(true)
            requestNotifications()
            return
        }
        if (!canShowDialog()) {
            callback(false)
            return
        }
        foregroundOnly = false
        onResult = callback
        showProminentDisclosure()
    }

    /**
     * Foreground-location-only request, used by the WebView geolocation
     * prompt. Shows the disclosure + system prompt and reports the result,
     * WITHOUT the background-location Settings round-trip that recreates the
     * activity (which would drop the callback). Background tracking is driven
     * separately by the "Enable live location" button via [requestTracking].
     */
    fun requestForegroundLocation(callback: (Boolean) -> Unit) {
        if (hasForegroundLocation()) {
            callback(true)
            return
        }
        if (!canShowDialog()) {
            callback(false)
            return
        }
        foregroundOnly = true
        onResult = callback
        showProminentDisclosure()
    }

    /**
     * After a denial, if the permission is permanently denied ("Don't allow"
     * twice / "Don't ask again"), the system prompt no longer appears —
     * leaving the user with no prompt and no way forward. Offer a jump
     * straight to the app's settings page so they can flip Location on.
     */
    private fun offerAppSettingsIfPermanentlyDenied() {
        if (!canShowDialog()) return
        val permanentlyDenied = !ActivityCompat.shouldShowRequestPermissionRationale(
            activity, Manifest.permission.ACCESS_FINE_LOCATION,
        )
        if (!permanentlyDenied) return
        visibleDialog = builder()
            .setTitle(R.string.location_dialog_title)
            .setMessage(
                "Location is turned off for Relatives. Open Settings → " +
                    "Permissions → Location and choose Allow, so your family " +
                    "can see you on the map.",
            )
            .setPositiveButton("Open Settings") { _, _ ->
                try {
                    activity.startActivity(
                        Intent(
                            Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                            Uri.fromParts("package", activity.packageName, null),
                        ),
                    )
                } catch (_: Exception) {
                }
            }
            .setNegativeButton(R.string.dialog_not_now, null)
            .setCancelable(true)
            .show()
    }

    /** Dialogs on a finishing/destroyed activity throw BadTokenException. */
    private fun canShowDialog(): Boolean =
        !activity.isFinishing && !activity.isDestroyed

    /** Dismiss any visible dialog — call from the host activity's onDestroy. */
    fun dismissDialogs() {
        try {
            visibleDialog?.takeIf { it.isShowing }?.dismiss()
        } catch (_: Exception) {
        }
        visibleDialog = null
        onResult = null
    }

    private fun builder(): AlertDialog.Builder =
        AlertDialog.Builder(activity, android.R.style.Theme_DeviceDefault_Dialog_Alert)

    private fun showProminentDisclosure() {
        visibleDialog = builder()
            .setTitle(R.string.location_dialog_title)
            .setMessage(R.string.location_dialog_message)
            .setPositiveButton(R.string.location_dialog_enable) { _, _ ->
                locationLauncher.launch(
                    arrayOf(
                        Manifest.permission.ACCESS_FINE_LOCATION,
                        Manifest.permission.ACCESS_COARSE_LOCATION,
                    ),
                )
            }
            .setNegativeButton(R.string.dialog_not_now) { _, _ ->
                foregroundOnly = false
                onResult?.invoke(false)
                onResult = null
            }
            .setCancelable(false)
            .show()
    }

    private fun requestBackgroundLocation() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q && !hasBackgroundLocation() && canShowDialog()) {
            visibleDialog = builder()
                .setTitle(R.string.background_dialog_title)
                .setMessage(R.string.background_dialog_message)
                .setPositiveButton(R.string.dialog_continue) { _, _ ->
                    backgroundLocationLauncher.launch(
                        Manifest.permission.ACCESS_BACKGROUND_LOCATION,
                    )
                }
                .setNegativeButton(R.string.dialog_skip) { _, _ ->
                    onResult?.invoke(true)
                    onResult = null
                    requestActivityRecognition()
                }
                .setCancelable(false)
                .show()
        } else {
            onResult?.invoke(true)
            onResult = null
            requestActivityRecognition()
        }
    }

    private fun requestActivityRecognition() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q && !hasActivityRecognition()) {
            activityRecognitionLauncher.launch(Manifest.permission.ACTIVITY_RECOGNITION)
        } else {
            requestNotifications()
        }
    }

    fun requestNotifications() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU && !hasNotificationPermission() && canShowDialog()) {
            visibleDialog = builder()
                .setTitle(R.string.notification_dialog_title)
                .setMessage(R.string.notification_dialog_message)
                .setPositiveButton(R.string.dialog_enable) { _, _ ->
                    notificationLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
                }
                .setNegativeButton(R.string.dialog_not_now, null)
                .setCancelable(true)
                .show()
        }
    }

    fun hasForegroundLocation(): Boolean =
        ContextCompat.checkSelfPermission(
            activity, Manifest.permission.ACCESS_FINE_LOCATION,
        ) == PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(
                activity, Manifest.permission.ACCESS_COARSE_LOCATION,
            ) == PackageManager.PERMISSION_GRANTED

    private fun hasBackgroundLocation(): Boolean =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ContextCompat.checkSelfPermission(
                activity, Manifest.permission.ACCESS_BACKGROUND_LOCATION,
            ) == PackageManager.PERMISSION_GRANTED
        } else true

    private fun hasActivityRecognition(): Boolean =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ContextCompat.checkSelfPermission(
                activity, Manifest.permission.ACTIVITY_RECOGNITION,
            ) == PackageManager.PERMISSION_GRANTED
        } else true

    private fun hasNotificationPermission(): Boolean =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            ContextCompat.checkSelfPermission(
                activity, Manifest.permission.POST_NOTIFICATIONS,
            ) == PackageManager.PERMISSION_GRANTED
        } else true
}
