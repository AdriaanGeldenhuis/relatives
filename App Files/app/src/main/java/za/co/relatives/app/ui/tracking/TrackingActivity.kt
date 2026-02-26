package za.co.relatives.app.ui.tracking

import android.os.Build
import android.os.Bundle
import android.view.View
import android.view.WindowInsets
import android.view.WindowInsetsController
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModelProvider
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.tracking.FamilyPoller
import za.co.relatives.app.tracking.PermissionGate
import za.co.relatives.app.ui.theme.RelativesTheme

/**
 * TrackingActivity — standalone native activity for the tracking feature.
 *
 * Hosts all tracking screens (Map, Events, Geofences, Settings)
 * using Jetpack Compose navigation. Completely replaces the WebView
 * for all /tracking/app/ pages.
 *
 * The existing TrackingService, FamilyPoller, TrackingStore, and
 * LocationUploadWorker continue to work exactly as before — this
 * activity just provides a native UI on top of them.
 */
class TrackingActivity : ComponentActivity() {

    private lateinit var viewModel: TrackingViewModel
    private lateinit var permissionGate: PermissionGate
    private lateinit var familyPoller: FamilyPoller
    private var permissionCallback by mutableStateOf<(() -> Unit)?>(null)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        viewModel = ViewModelProvider(this)[TrackingViewModel::class.java]
        val trackingStore = TrackingStore(this)
        permissionGate = PermissionGate(this)
        familyPoller = FamilyPoller(this, trackingStore)

        // Register permission launchers before onStart
        permissionGate.registerLaunchers()

        // Start family polling (works without location permission)
        familyPoller.start()

        enterImmersiveMode()

        setContent {
            RelativesTheme(darkTheme = true) {
                val navController = rememberNavController()

                NavHost(navController = navController, startDestination = "map") {
                    composable("map") {
                        TrackingMapScreen(
                            viewModel = viewModel,
                            onNavigateToEvents = { navController.navigate("events") },
                            onNavigateToGeofences = { navController.navigate("geofences") },
                            onNavigateToSettings = { navController.navigate("settings") },
                            onBack = { finish() },
                            onRequestPermissions = {
                                permissionGate.requestTracking { granted ->
                                    if (granted) {
                                        viewModel.enableTracking()
                                    }
                                }
                            },
                        )
                    }
                    composable("events") {
                        EventsScreen(
                            viewModel = viewModel,
                            onBack = { navController.popBackStack() },
                        )
                    }
                    composable("geofences") {
                        GeofencesScreen(
                            viewModel = viewModel,
                            onBack = { navController.popBackStack() },
                        )
                    }
                    composable("settings") {
                        SettingsScreen(
                            viewModel = viewModel,
                            onBack = { navController.popBackStack() },
                        )
                    }
                }
            }
        }
    }

    override fun onResume() {
        super.onResume()
        enterImmersiveMode()
        familyPoller.setActive(true)
        familyPoller.pollNow()
        viewModel.pollNow()
    }

    override fun onPause() {
        super.onPause()
        familyPoller.setActive(false)
    }

    override fun onDestroy() {
        familyPoller.stop()
        super.onDestroy()
    }

    private fun enterImmersiveMode() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            window.setDecorFitsSystemWindows(false)
            window.insetsController?.apply {
                hide(WindowInsets.Type.systemBars())
                systemBarsBehavior =
                    WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            }
        } else {
            @Suppress("DEPRECATION")
            window.decorView.systemUiVisibility = (
                View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                    or View.SYSTEM_UI_FLAG_FULLSCREEN
                    or View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                    or View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                    or View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                    or View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                )
        }
    }
}
