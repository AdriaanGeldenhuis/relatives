package za.co.relatives.app.tracking

import android.Manifest
import android.app.AlertDialog
import android.content.pm.PackageManager
import android.os.Build
import androidx.activity.ComponentActivity
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat

/**
 * PermissionGate — Google Play policy-compliant permission flow.
 *
 * Flow:
 *   1. Prominent disclosure dialog (explains why location is needed)
 *   2. System foreground location prompt (ACCESS_FINE_LOCATION)
 *   3. Background location dialog + system prompt (Android 10+)
 *   4. Notification permission prompt (Android 13+)
 *
 * The map loads without any permission. Permission is only requested
 * when the user explicitly taps "Enable live location".
 *
 * Usage:
 *   val gate = PermissionGate(activity)
 *   gate.registerLaunchers()  // call in onCreate, before onStart
 *   gate.requestTracking { granted -> if (granted) startService() }
 */
class PermissionGate(private val activity: ComponentActivity) {

    private var onResult: ((Boolean) -> Unit)? = null

    private lateinit var fineLocationLauncher: ActivityResultLauncher<String>
    private lateinit var backgroundLocationLauncher: ActivityResultLauncher<String>
    private lateinit var notificationLauncher: ActivityResultLauncher<String>

    // ── Registration (must be called before onStart) ────────────────────

    fun registerLaunchers() {
        fineLocationLauncher = activity.registerForActivityResult(
            ActivityResultContracts.RequestPermission(),
        ) { granted ->
            if (granted) {
                requestBackgroundLocation()
            } else {
                onResult?.invoke(false)
                onResult = null
            }
        }

        backgroundLocationLauncher = activity.registerForActivityResult(
            ActivityResultContracts.RequestPermission(),
        ) { _ ->
            // Even if background is denied, foreground tracking still works.
            onResult?.invoke(hasForegroundLocation())
            onResult = null
            requestNotifications()
        }

        notificationLauncher = activity.registerForActivityResult(
            ActivityResultContracts.RequestPermission(),
        ) { _ -> /* acknowledged, no action needed */ }
    }

    // ── Public entry point ──────────────────────────────────────────────

    /**
     * Request all tracking permissions through the proper disclosure flow.
     * [callback] receives `true` if at least foreground location was granted.
     */
    fun requestTracking(callback: (Boolean) -> Unit) {
        if (hasForegroundLocation()) {
            callback(true)
            requestNotifications()
            return
        }
        onResult = callback
        showProminentDisclosure()
    }

    // ── Prominent disclosure (Google requirement) ───────────────────────

    private fun showProminentDisclosure() {
        AlertDialog.Builder(activity)
            .setTitle("Location Sharing")
            .setMessage(
                "Relatives uses your location to show family members where you " +
                    "are on the map. Your location is shared only with your " +
                    "approved family group.\n\n" +
                    "For continuous tracking, background location access is also " +
                    "needed so your location updates even when the app is not open."
            )
            .setPositiveButton("Enable Location") { _, _ ->
                fineLocationLauncher.launch(Manifest.permission.ACCESS_FINE_LOCATION)
            }
            .setNegativeButton("Not Now") { _, _ ->
                onResult?.invoke(false)
                onResult = null
            }
            .setCancelable(false)
            .show()
    }

    // ── Background location (Android 10+) ───────────────────────────────

    private fun requestBackgroundLocation() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q && !hasBackgroundLocation()) {
            AlertDialog.Builder(activity)
                .setTitle("Background Location")
                .setMessage(
                    "To keep sharing your location with family when the app " +
                        "is in the background, please select \"Allow all the time\" " +
                        "on the next screen."
                )
                .setPositiveButton("Continue") { _, _ ->
                    backgroundLocationLauncher.launch(
                        Manifest.permission.ACCESS_BACKGROUND_LOCATION,
                    )
                }
                .setNegativeButton("Skip") { _, _ ->
                    onResult?.invoke(true) // foreground-only is fine
                    onResult = null
                    requestNotifications()
                }
                .setCancelable(false)
                .show()
        } else {
            onResult?.invoke(true)
            onResult = null
            requestNotifications()
        }
    }

    // ── Notifications (Android 13+) ─────────────────────────────────────

    private fun requestNotifications() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU && !hasNotificationPermission()) {
            AlertDialog.Builder(activity)
                .setTitle("Enable Notifications")
                .setMessage(
                    "Stay connected with your family! Enable notifications " +
                        "to receive alerts and important updates."
                )
                .setPositiveButton("Enable") { _, _ ->
                    notificationLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
                }
                .setNegativeButton("Not Now", null)
                .setCancelable(true)
                .show()
        }
    }

    // ── Permission checks ───────────────────────────────────────────────

    fun hasForegroundLocation(): Boolean =
        ContextCompat.checkSelfPermission(
            activity, Manifest.permission.ACCESS_FINE_LOCATION,
        ) == PackageManager.PERMISSION_GRANTED

    private fun hasBackgroundLocation(): Boolean =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ContextCompat.checkSelfPermission(
                activity, Manifest.permission.ACCESS_BACKGROUND_LOCATION,
            ) == PackageManager.PERMISSION_GRANTED
        } else {
            true // pre-Q doesn't need it
        }

    private fun hasNotificationPermission(): Boolean =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            ContextCompat.checkSelfPermission(
                activity, Manifest.permission.POST_NOTIFICATIONS,
            ) == PackageManager.PERMISSION_GRANTED
        } else {
            true
        }
}
