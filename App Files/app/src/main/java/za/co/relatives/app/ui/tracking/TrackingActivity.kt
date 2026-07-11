package za.co.relatives.app.ui.tracking

import android.os.Build
import android.os.Bundle
import android.util.Log
import android.view.View
import android.view.WindowInsets
import android.view.WindowInsetsController
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.lifecycleScope
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import com.mapbox.common.MapboxOptions
import kotlinx.coroutines.launch
import za.co.relatives.app.RelativesApplication
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.network.TrackingApiClient
import za.co.relatives.app.tracking.FamilyPoller
import za.co.relatives.app.tracking.PermissionGate
import za.co.relatives.app.ui.theme.RelativesTheme
import za.co.relatives.app.utils.PreferencesManager

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

    companion object {
        private const val TAG = "TrackingActivity"
    }

    private lateinit var viewModel: TrackingViewModel
    private lateinit var permissionGate: PermissionGate
    private lateinit var familyPoller: FamilyPoller

    /**
     * Mapbox refuses to create a MapView without an access token. The token
     * is the site's public token, fetched once from the server and cached;
     * the map area shows a loading state until it is available.
     */
    private var mapboxTokenReady by mutableStateOf(false)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        try {
            viewModel = ViewModelProvider(this)[TrackingViewModel::class.java]
        } catch (e: Exception) {
            Log.e(TAG, "ViewModel init failed", e)
            finish()
            return
        }
        val trackingStore = TrackingStore(this)
        permissionGate = PermissionGate(this)
        familyPoller = FamilyPoller(this, trackingStore)

        // Register permission launchers before onStart
        permissionGate.registerLaunchers()

        bootstrapMapboxToken()

        enterImmersiveMode()

        setContent {
            RelativesTheme(darkTheme = true) {
                val navController = rememberNavController()

                NavHost(navController = navController, startDestination = "map") {
                    composable("map") {
                        TrackingMapScreen(
                            viewModel = viewModel,
                            mapboxTokenReady = mapboxTokenReady,
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
        // Poll only while this activity is in the foreground. start() is
        // idempotent; setActive(true) switches to the fast interval.
        familyPoller.start()
        familyPoller.setActive(true)
        familyPoller.pollNow()
        viewModel.pollNow()
    }

    override fun onPause() {
        super.onPause()
        // Fully stop — leaving it running (even slowly) drains battery when
        // the user leaves via Home instead of Back.
        familyPoller.stop()
    }

    override fun onDestroy() {
        if (::familyPoller.isInitialized) {
            familyPoller.stop()
        }
        super.onDestroy()
    }

    /**
     * Ensure MapboxOptions.accessToken is set: use the cached copy instantly,
     * then refresh from the server (the same public token the web map uses,
     * exposed to authenticated users via /tracking/api/mapbox_token.php).
     */
    private fun bootstrapMapboxToken() {
        val prefs = (application as? RelativesApplication)?.preferencesManager
            ?: PreferencesManager(this)

        val cached = prefs.mapboxToken
        if (!cached.isNullOrBlank()) {
            MapboxOptions.accessToken = cached
            mapboxTokenReady = true
        }

        lifecycleScope.launch {
            try {
                val result = TrackingApiClient(applicationContext).getMapboxToken()
                val token = result.getAsJsonObject("data")?.get("token")
                    ?.takeIf { it.isJsonPrimitive }?.asString
                if (!token.isNullOrBlank()) {
                    prefs.mapboxToken = token
                    MapboxOptions.accessToken = token
                    mapboxTokenReady = true
                }
            } catch (e: Exception) {
                Log.w(TAG, "Mapbox token fetch failed (map disabled until next open)", e)
            }
        }
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
