package za.co.relatives.app.ui.tracking

import android.app.AlertDialog
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
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.lifecycleScope
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import com.mapbox.common.MapboxOptions
import com.mapbox.maps.CameraOptions
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

    /**
     * Mapbox refuses to create a MapView without an access token. The token
     * is the site's public token, fetched once from the server and cached;
     * the map area shows a loading state until it is available.
     */
    private var mapboxTokenReady by mutableStateOf(false)
    private var tokenFetchInFlight = false
    private var abortDialogShowing = false
    private var abortDialog: AlertDialog? = null

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

        val startScreen = intent.getStringExtra(EXTRA_START_SCREEN)
            ?.takeIf { it in SCREENS } ?: "map"

        setContent {
            RelativesTheme(darkTheme = true) {
                val navController = rememberNavController()

                NavHost(navController = navController, startDestination = startScreen) {
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
                            onMapInitFailed = { abortMapUnavailable() },
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
        // Retry the token fetch if the first attempt failed (offline start).
        if (!mapboxTokenReady) {
            bootstrapMapboxToken()
        }
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
        abortDialog?.dismiss()
        abortDialog = null
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
        if (!cached.isNullOrBlank() && !applyMapboxToken(cached)) {
            abortMapUnavailable()
            return
        }

        if (tokenFetchInFlight) return
        tokenFetchInFlight = true
        lifecycleScope.launch {
            try {
                val result = TrackingApiClient(applicationContext).getMapboxToken()
                val token = result.getAsJsonObject("data")?.get("token")
                    ?.takeIf { it.isJsonPrimitive }?.asString
                if (!token.isNullOrBlank()) {
                    prefs.mapboxToken = token
                    if (!applyMapboxToken(token)) abortMapUnavailable()
                }
            } catch (e: ApiException) {
                if (e.httpCode == 401) {
                    if (mapboxTokenReady) {
                        // The map is already up on the cached token — don't
                        // kill a working screen over a background refresh.
                        Log.w(TAG, "Token refresh got 401 with map already up; keeping screen", e)
                    } else {
                        // Session expired/logged out: the native screen has no
                        // login UI, so a stuck "Loading map…" is a dead end.
                        // Drop the dead session cookie before leaving —
                        // MainActivity's intercept only checks that the cookie
                        // EXISTS, so leaving it behind would relaunch this
                        // screen (and 401 again) on every tracking press
                        // instead of letting the server redirect the WebView
                        // to /login.php.
                        expireDeadSession()
                        Toast.makeText(
                            this@TrackingActivity,
                            "Your session has expired. Please log in again.",
                            Toast.LENGTH_LONG,
                        ).show()
                        finish()
                    }
                } else {
                    Log.w(TAG, "Mapbox token fetch failed (retried on next resume)", e)
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                Log.w(TAG, "Mapbox token fetch failed (retried on next resume)", e)
            } finally {
                tokenFetchInFlight = false
            }
        }
    }

    /**
     * Set the Mapbox access token, tolerating native-loader failures
     * (UnsatisfiedLinkError and friends are Errors, not Exceptions — an
     * incompatible Mapbox native build must degrade gracefully, not kill
     * the process the moment the user opens tracking).
     */
    private fun applyMapboxToken(token: String): Boolean =
        try {
            MapboxOptions.accessToken = token
            // MapboxOptions lives in com.mapbox.common and only loads
            // libmapbox-common.so. The renderer's libmapbox-maps.so loads
            // lazily on the first touch of a com.mapbox.maps class — which
            // is otherwise MapView(context) inside the AndroidView factory,
            // where an ExceptionInInitializerError escapes every guard and
            // kills the process. Touch a cheap maps class here so BOTH
            // native libraries load (or fail) under this catch.
            CameraOptions.Builder().build()
            mapboxTokenReady = true
            true
        } catch (t: Throwable) {
            if (t is CancellationException) throw t
            Log.e(TAG, "Mapbox native init failed", t)
            false
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

    /**
     * Leave the tracking screen instead of showing a permanently dead map.
     * A dialog, not a toast: this exit is otherwise indistinguishable from
     * a crash for the user, and the reason must be readable.
     */
    private fun abortMapUnavailable() {
        if (abortDialogShowing || isFinishing || isDestroyed) return
        abortDialogShowing = true
        abortDialog = AlertDialog.Builder(this)
            .setMessage("The map could not start on this device. Please update the app.")
            .setPositiveButton(android.R.string.ok) { _, _ -> finish() }
            .setOnCancelListener { finish() }
            .show()
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
