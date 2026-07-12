package za.co.relatives.app.ui.tracking

import android.os.Build
import android.os.Bundle
import android.util.Log
import android.view.View
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.webkit.CookieManager
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.lifecycleScope
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import za.co.relatives.app.RelativesApplication
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.network.ApiException
import za.co.relatives.app.network.NetworkClient
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
 * The map is osmdroid (OpenStreetMap): pure Java, no bundled native
 * libraries and no access token, so there is no native-loader failure
 * mode — the screen renders unconditionally.
 *
 * The existing TrackingService, FamilyPoller, TrackingStore, and
 * LocationUploadWorker continue to work exactly as before — this
 * activity just provides a native UI on top of them.
 */
class TrackingActivity : ComponentActivity() {

    companion object {
        private const val TAG = "TrackingActivity"
        private const val WEB_URL = "https://www.relatives.co.za"

        /** Intent extra naming the start destination: map/events/geofences/settings. */
        const val EXTRA_START_SCREEN = "start_screen"

        private val SCREENS = setOf("map", "events", "geofences", "settings")

        /** Map a /tracking/app/... path (or deep link) to a start destination. */
        fun screenForPath(path: String?): String = when {
            path == null -> "map"
            path.contains("events") -> "events"
            path.contains("geofences") -> "geofences"
            path.contains("settings") -> "settings"
            else -> "map"
        }
    }

    private lateinit var viewModel: TrackingViewModel
    private lateinit var permissionGate: PermissionGate
    private lateinit var familyPoller: FamilyPoller

    private var sessionCheckInFlight = false

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

        verifySession()

        enterImmersiveMode()

        val startScreen = intent.getStringExtra(EXTRA_START_SCREEN)
            ?.takeIf { it in SCREENS } ?: "map"

        setContent {
            RelativesTheme(darkTheme = true) {
                val navController = rememberNavController()

                NavHost(navController = navController, startDestination = startScreen) {
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
        // Poll only while this activity is in the foreground. start() is
        // idempotent; setActive(true) switches to the fast interval.
        familyPoller.start()
        familyPoller.setActive(true)
        familyPoller.pollNow()
        viewModel.pollNow()
        // Re-check the session when coming back to the foreground (covers
        // entering offline and the session dying while backgrounded).
        verifySession()
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
        if (::permissionGate.isInitialized) {
            permissionGate.dismissDialogs()
        }
        super.onDestroy()
    }

    /**
     * One-shot cheap authenticated ping at entry. The map itself needs no
     * token or network, but with a dead server session every data call 401s
     * and the screen would just sit empty forever — and because
     * MainActivity's intercept only checks that a session cookie EXISTS,
     * the user would bounce into this dead screen on every tracking press
     * with no path to the login page. Detect it once here and route the
     * user to login instead. Network failures do NOT block entry: cached
     * family data still renders offline.
     */
    private fun verifySession() {
        if (sessionCheckInFlight) return
        sessionCheckInFlight = true
        lifecycleScope.launch {
            try {
                TrackingApiClient(applicationContext).getSessionStatus()
            } catch (e: ApiException) {
                if (e.httpCode == 401) {
                    // Drop the dead session cookie before leaving so the
                    // next tracking press falls through to the WebView and
                    // the server redirects it to /login.php.
                    expireDeadSession()
                    Toast.makeText(
                        this@TrackingActivity,
                        "Your session has expired. Please log in again.",
                        Toast.LENGTH_LONG,
                    ).show()
                    finish()
                } else {
                    Log.w(TAG, "Session check failed (http ${e.httpCode}), continuing", e)
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                // Offline start etc. — the screen still works from cache.
                Log.w(TAG, "Session check skipped", e)
            } finally {
                sessionCheckInFlight = false
            }
        }
    }

    /**
     * The server said this session is dead. Drop every client-side copy of
     * it (WebView cookie store, OkHttp jar, persisted prefs) so the WebView
     * falls through to the login page instead of bouncing back into this
     * screen, and so background workers stop replaying a dead cookie.
     */
    private fun expireDeadSession() {
        try {
            CookieManager.getInstance().apply {
                // The server sets a host-only cookie; also clear a
                // domain-wide variant defensively.
                setCookie(WEB_URL, "RELATIVES_SESSION=; Max-Age=0; path=/")
                setCookie(WEB_URL, "RELATIVES_SESSION=; Max-Age=0; path=/; Domain=.relatives.co.za")
                flush()
            }
        } catch (e: Exception) {
            Log.w(TAG, "Failed to expire WebView session cookie", e)
        }
        NetworkClient.clearCookies()
        val prefs = (application as? RelativesApplication)?.preferencesManager
            ?: PreferencesManager(this)
        prefs.sessionToken = null
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
