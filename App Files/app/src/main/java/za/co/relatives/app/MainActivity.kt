package za.co.relatives.app

import android.Manifest
import android.annotation.SuppressLint
import android.app.AlertDialog
import android.content.ComponentName
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.view.Gravity
import android.view.View
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.webkit.CookieManager
import android.webkit.PermissionRequest
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import android.widget.TextView
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import com.android.billingclient.api.BillingClient
import com.android.billingclient.api.BillingClientStateListener
import com.android.billingclient.api.BillingResult
import com.android.billingclient.api.PendingPurchasesParams
import com.android.billingclient.api.Purchase
import com.android.billingclient.api.QueryPurchasesParams
import za.co.relatives.app.ui.TrackingJsInterface
import za.co.relatives.app.utils.PreferencesManager
import java.net.CookieHandler
import java.net.HttpCookie
import java.net.URI
import java.net.CookieManager as JavaNetCookieManager

/**
 * Single-activity host for the Relatives hybrid WebView app.
 *
 * Responsibilities:
 * - Loads the web app in a full-screen WebView
 * - Manages runtime permission flows (location, notifications, microphone)
 * - Registers the [TrackingJsInterface] as `window.TrackingBridge`
 * - Handles deep-link navigation via `action_url` intent extras
 * - Syncs cookies between the WebView and native HTTP stacks
 * - Checks subscription status and displays a trial upgrade banner
 */
class MainActivity : ComponentActivity() {

    // ── Constants ──────────────────────────────────────────────────────────

    companion object {
        private const val WEB_URL = "https://www.relatives.co.za"

        // Fully-qualified class names for components created in other files.
        // Using strings avoids a compile-time dependency so these 5 files are
        // self-contained; intents resolve at runtime once the classes exist.
        private const val TRACKING_SERVICE_CLASS =
            "za.co.relatives.app.services.TrackingLocationService"
        private const val SUBSCRIPTION_ACTIVITY_CLASS =
            "za.co.relatives.app.ui.SubscriptionActivity"

        // Intent actions understood by TrackingLocationService
        const val ACTION_START_TRACKING = "za.co.relatives.app.ACTION_START_TRACKING"
        const val ACTION_STOP_TRACKING = "za.co.relatives.app.ACTION_STOP_TRACKING"
        const val ACTION_BOOST = "za.co.relatives.app.ACTION_BOOST"
        const val ACTION_REVERT_BOOST = "za.co.relatives.app.ACTION_REVERT_BOOST"
        const val ACTION_WAKE_ALL = "za.co.relatives.app.ACTION_WAKE_ALL"
        const val EXTRA_BOOST_SECONDS = "boost_seconds"
    }

    // ── Fields ─────────────────────────────────────────────────────────────

    private lateinit var webView: WebView
    private lateinit var trialBanner: TextView
    private lateinit var prefs: PreferencesManager

    private var fileUploadCallback: ValueCallback<Array<Uri>>? = null
    private var pendingMediaRequest: PermissionRequest? = null

    private var billingClient: BillingClient? = null
    private var hasActiveSubscription = false

    // Activity-result launchers (must be registered before onStart)
    private lateinit var fineLocationLauncher: ActivityResultLauncher<String>
    private lateinit var backgroundLocationLauncher: ActivityResultLauncher<String>
    private lateinit var notificationLauncher: ActivityResultLauncher<String>
    private lateinit var microphoneLauncher: ActivityResultLauncher<String>
    private lateinit var fileChooserLauncher: ActivityResultLauncher<Intent>

    // Back-press handling via the modern OnBackPressedDispatcher API
    private val backCallback = object : OnBackPressedCallback(true) {
        override fun handleOnBackPressed() {
            if (::webView.isInitialized && webView.canGoBack()) {
                webView.goBack()
            } else {
                isEnabled = false
                onBackPressedDispatcher.onBackPressed()
            }
        }
    }

    // ── Lifecycle ──────────────────────────────────────────────────────────

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = (application as RelativesApplication).preferencesManager

        registerPermissionLaunchers()
        buildLayout()
        configureWebView()
        onBackPressedDispatcher.addCallback(this, backCallback)
        connectBillingClient()
        enterImmersiveMode()

        // Load the deep-link URL if present, otherwise the default home page.
        loadInitialUrl(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handleDeepLink(intent)
    }

    override fun onResume() {
        super.onResume()
        backCallback.isEnabled = true
        enterImmersiveMode()
        CookieManager.getInstance().flush()
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus) enterImmersiveMode()
    }

    override fun onDestroy() {
        billingClient?.endConnection()
        webView.destroy()
        super.onDestroy()
    }

    // ════════════════════════════════════════════════════════════════════════
    //  LAYOUT
    // ════════════════════════════════════════════════════════════════════════

    private fun buildLayout() {
        val root = FrameLayout(this)

        webView = WebView(this).apply {
            layoutParams = FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.MATCH_PARENT,
            )
        }
        root.addView(webView)

        trialBanner = TextView(this).apply {
            layoutParams = FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.WRAP_CONTENT,
                Gravity.BOTTOM,
            )
            text = "You're on a free trial - Tap to upgrade"
            textSize = 14f
            setTextColor(0xFFFFFFFF.toInt())
            setBackgroundColor(0xFF667eea.toInt())
            setPadding(32, 24, 32, 24)
            gravity = Gravity.CENTER
            visibility = View.GONE
            setOnClickListener { openSubscriptionScreen() }
        }
        root.addView(trialBanner)

        setContentView(root)
    }

    // ════════════════════════════════════════════════════════════════════════
    //  WEBVIEW
    // ════════════════════════════════════════════════════════════════════════

    @SuppressLint("SetJavaScriptEnabled")
    private fun configureWebView() {
        CookieManager.getInstance().apply {
            setAcceptCookie(true)
            setAcceptThirdPartyCookies(webView, true)
        }

        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
            allowFileAccess = true
            allowContentAccess = true
            mediaPlaybackRequiresUserGesture = false
            javaScriptCanOpenWindowsAutomatically = true
            setSupportMultipleWindows(false)
            useWideViewPort = true
            loadWithOverviewMode = true
            cacheMode = WebSettings.LOAD_DEFAULT
        }

        // Register the JavaScript bridge
        webView.addJavascriptInterface(TrackingJsInterface(this), "TrackingBridge")

        webView.webViewClient = object : WebViewClient() {
            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)
                extractSessionToken()
                syncCookiesToNative()
            }

            override fun shouldOverrideUrlLoading(
                view: WebView?,
                request: WebResourceRequest?,
            ): Boolean {
                val url = request?.url?.toString() ?: return false
                // Keep all relatives.co.za pages inside the WebView
                if (url.contains("relatives.co.za")) return false
                // Open external links in the default browser
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                return true
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onShowFileChooser(
                webView: WebView?,
                callback: ValueCallback<Array<Uri>>?,
                params: FileChooserParams?,
            ): Boolean {
                // Cancel any pending callback so the WebView does not hang
                fileUploadCallback?.onReceiveValue(null)
                fileUploadCallback = callback

                val chooserIntent = params?.createIntent()
                    ?: Intent(Intent.ACTION_GET_CONTENT).apply {
                        addCategory(Intent.CATEGORY_OPENABLE)
                        type = "*/*"
                    }
                fileChooserLauncher.launch(chooserIntent)
                return true
            }

            override fun onPermissionRequest(request: PermissionRequest?) {
                request ?: return
                if (request.resources.contains(PermissionRequest.RESOURCE_AUDIO_CAPTURE)) {
                    pendingMediaRequest = request
                    handleMicrophonePermission()
                } else {
                    request.deny()
                }
            }
        }
    }

    // ── URL loading helpers ────────────────────────────────────────────────

    private fun loadInitialUrl(intent: Intent?) {
        val deepLink = intent?.getStringExtra("action_url")
        if (!deepLink.isNullOrBlank()) {
            webView.loadUrl(resolveUrl(deepLink))
        } else {
            webView.loadUrl(WEB_URL)
        }
    }

    private fun handleDeepLink(intent: Intent?) {
        val actionUrl = intent?.getStringExtra("action_url") ?: return
        webView.loadUrl(resolveUrl(actionUrl))
    }

    /** Resolve a relative path or return an absolute URL as-is. */
    private fun resolveUrl(path: String): String =
        if (path.startsWith("http")) path else "$WEB_URL$path"

    // ════════════════════════════════════════════════════════════════════════
    //  IMMERSIVE MODE
    // ════════════════════════════════════════════════════════════════════════

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

    // ════════════════════════════════════════════════════════════════════════
    //  PERMISSION LAUNCHERS
    // ════════════════════════════════════════════════════════════════════════

    private fun registerPermissionLaunchers() {
        fineLocationLauncher = registerForActivityResult(
            ActivityResultContracts.RequestPermission(),
        ) { granted ->
            if (granted) {
                requestBackgroundLocation()
            }
            // If declined the user can retry from the JS bridge later
        }

        backgroundLocationLauncher = registerForActivityResult(
            ActivityResultContracts.RequestPermission(),
        ) { _ ->
            // Foreground-only tracking is still useful if background is denied
            startTrackingService()
        }

        notificationLauncher = registerForActivityResult(
            ActivityResultContracts.RequestPermission(),
        ) { _ ->
            // Result acknowledged; no further action required
        }

        microphoneLauncher = registerForActivityResult(
            ActivityResultContracts.RequestPermission(),
        ) { granted ->
            val request = pendingMediaRequest
            if (granted && request != null) {
                request.grant(request.resources)
            } else {
                request?.deny()
            }
            pendingMediaRequest = null
        }

        fileChooserLauncher = registerForActivityResult(
            ActivityResultContracts.StartActivityForResult(),
        ) { result ->
            val uris = if (result.resultCode == RESULT_OK && result.data != null) {
                WebChromeClient.FileChooserParams.parseResult(
                    result.resultCode,
                    result.data!!,
                )
            } else {
                null
            }
            fileUploadCallback?.onReceiveValue(uris)
            fileUploadCallback = null
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  PERMISSION CONSENT FLOWS
    // ════════════════════════════════════════════════════════════════════════

    // ── Location ───────────────────────────────────────────────────────────

    /**
     * Public entry-point called from [TrackingJsInterface.startTracking].
     * If permission is already granted the service is started immediately;
     * otherwise the prominent disclosure dialog is shown first.
     */
    fun requestTrackingWithPermissions() {
        if (hasLocationPermission()) {
            startTrackingService()
            return
        }
        showLocationDisclosure()
    }

    /**
     * Prominent disclosure dialog (required by Google Play policy).
     * Explains *why* the app needs location before the system prompt appears.
     */
    private fun showLocationDisclosure() {
        AlertDialog.Builder(this)
            .setTitle("Location Sharing")
            .setMessage(
                "Relatives uses your location to show family members where you " +
                    "are on the map. Your location is shared only with your " +
                    "approved family group.\n\n" +
                    "For continuous tracking, background location access is also " +
                    "needed so your location updates even when the app is not open.",
            )
            .setPositiveButton("Enable Location") { _, _ ->
                fineLocationLauncher.launch(Manifest.permission.ACCESS_FINE_LOCATION)
            }
            .setNegativeButton("Not Now", null)
            .setCancelable(true)
            .show()
    }

    /**
     * After foreground location is granted, ask for background location
     * with a separate explanation dialog (required by Play policy on
     * Android 10+).
     */
    private fun requestBackgroundLocation() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q &&
            ContextCompat.checkSelfPermission(
                this,
                Manifest.permission.ACCESS_BACKGROUND_LOCATION,
            ) != PackageManager.PERMISSION_GRANTED
        ) {
            AlertDialog.Builder(this)
                .setTitle("Background Location")
                .setMessage(
                    "To keep sharing your location with family when the app is " +
                        "in the background, please select \"Allow all the time\" " +
                        "on the next screen.",
                )
                .setPositiveButton("Continue") { _, _ ->
                    backgroundLocationLauncher.launch(
                        Manifest.permission.ACCESS_BACKGROUND_LOCATION,
                    )
                }
                .setNegativeButton("Skip") { _, _ ->
                    // Foreground-only tracking still works
                    startTrackingService()
                }
                .setCancelable(false)
                .show()
        } else {
            startTrackingService()
        }
    }

    // ── Notifications ──────────────────────────────────────────────────────

    /**
     * Request POST_NOTIFICATIONS on Android 13+ with an explanation dialog.
     * Called automatically after the tracking service starts so the user
     * sees context for why notifications are needed.
     */
    fun requestNotificationPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(
                this,
                Manifest.permission.POST_NOTIFICATIONS,
            ) != PackageManager.PERMISSION_GRANTED
        ) {
            AlertDialog.Builder(this)
                .setTitle("Enable Notifications")
                .setMessage(
                    "Stay connected with your family! Enable notifications to " +
                        "receive messages, alerts, and important updates from " +
                        "your family group.",
                )
                .setPositiveButton("Enable") { _, _ ->
                    notificationLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
                }
                .setNegativeButton("Not Now", null)
                .setCancelable(true)
                .show()
        }
    }

    // ── Microphone ─────────────────────────────────────────────────────────

    private fun handleMicrophonePermission() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO)
            == PackageManager.PERMISSION_GRANTED
        ) {
            pendingMediaRequest?.grant(pendingMediaRequest?.resources)
            pendingMediaRequest = null
        } else {
            microphoneLauncher.launch(Manifest.permission.RECORD_AUDIO)
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private fun hasLocationPermission(): Boolean =
        ContextCompat.checkSelfPermission(
            this,
            Manifest.permission.ACCESS_FINE_LOCATION,
        ) == PackageManager.PERMISSION_GRANTED

    // ════════════════════════════════════════════════════════════════════════
    //  TRACKING SERVICE CONTROL
    // ════════════════════════════════════════════════════════════════════════

    /** Readable state check consumed by [TrackingJsInterface]. */
    fun isTrackingActive(): Boolean =
        prefs.trackingEnabled && hasLocationPermission()

    /**
     * String representation of the tracking state for the JS bridge.
     * Possible values: `"enabled"`, `"disabled"`, `"no_permission"`.
     */
    fun getTrackingMode(): String = when {
        !hasLocationPermission() -> "no_permission"
        prefs.trackingEnabled -> "enabled"
        else -> "disabled"
    }

    fun startTrackingService() {
        prefs.trackingEnabled = true
        val intent = trackingServiceIntent(ACTION_START_TRACKING)
        ContextCompat.startForegroundService(this, intent)
        // Prompt for notification permission after the service starts so the
        // user understands why it is needed.
        requestNotificationPermission()
    }

    fun stopTrackingService() {
        prefs.trackingEnabled = false
        sendServiceCommand(ACTION_STOP_TRACKING)
    }

    fun boostLocationUpdates() {
        sendServiceCommand(ACTION_BOOST)
    }

    fun revertLocationUpdates() {
        sendServiceCommand(ACTION_REVERT_BOOST)
    }

    fun requestLocationBoost(seconds: Int) {
        val intent = trackingServiceIntent(ACTION_BOOST).apply {
            putExtra(EXTRA_BOOST_SECONDS, seconds)
        }
        trySendServiceIntent(intent)
    }

    fun wakeAllDevices() {
        sendServiceCommand(ACTION_WAKE_ALL)
    }

    // ── Private service helpers ────────────────────────────────────────────

    private fun trackingServiceIntent(action: String): Intent =
        Intent(action).apply {
            component = ComponentName(this@MainActivity, TRACKING_SERVICE_CLASS)
        }

    private fun sendServiceCommand(action: String) {
        trySendServiceIntent(trackingServiceIntent(action))
    }

    private fun trySendServiceIntent(intent: Intent) {
        try {
            startService(intent)
        } catch (_: IllegalStateException) {
            // The service is not running or the app is in a state where
            // starting a service is not allowed; safe to ignore.
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  COOKIE SYNC & SESSION TOKEN
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Copy cookies from the WebView [CookieManager] into
     * [java.net.CookieManager] so that native [java.net.HttpURLConnection]
     * requests include the same session cookies as the WebView.
     */
    private fun syncCookiesToNative() {
        val raw = CookieManager.getInstance().getCookie(WEB_URL) ?: return

        val javaManager = (CookieHandler.getDefault() as? JavaNetCookieManager)
            ?: JavaNetCookieManager().also { CookieHandler.setDefault(it) }

        val uri = URI.create(WEB_URL)
        raw.split(";").forEach { segment ->
            val trimmed = segment.trim()
            if (trimmed.isNotEmpty()) {
                runCatching {
                    HttpCookie.parse("Set-Cookie: $trimmed").forEach { cookie ->
                        javaManager.cookieStore.add(uri, cookie)
                    }
                }
            }
        }
    }

    /**
     * Scan WebView cookies for well-known session token names and persist
     * the value in [PreferencesManager] for use by native API calls.
     */
    private fun extractSessionToken() {
        val raw = CookieManager.getInstance().getCookie(WEB_URL) ?: return
        val targetNames = setOf("session_token", "phpsessid", "token")

        raw.split(";").forEach { segment ->
            val parts = segment.trim().split("=", limit = 2)
            if (parts.size == 2 && parts[0].trim().lowercase() in targetNames) {
                prefs.sessionToken = parts[1].trim()
                return
            }
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  BILLING / SUBSCRIPTION
    // ════════════════════════════════════════════════════════════════════════

    private fun connectBillingClient() {
        billingClient = BillingClient.newBuilder(this)
            .setListener { _, _ -> /* Purchase updates are handled via queryPurchasesAsync */ }
            .enablePendingPurchases(
                PendingPurchasesParams.newBuilder()
                    .enableOneTimeProducts()
                    .build(),
            )
            .build()

        billingClient?.startConnection(object : BillingClientStateListener {
            override fun onBillingSetupFinished(result: BillingResult) {
                if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                    querySubscriptionStatus()
                }
            }

            override fun onBillingServiceDisconnected() {
                // Will reconnect automatically on next app cold-start
            }
        })
    }

    private fun querySubscriptionStatus() {
        val params = QueryPurchasesParams.newBuilder()
            .setProductType(BillingClient.ProductType.SUBS)
            .build()

        billingClient?.queryPurchasesAsync(params) { result, purchases ->
            if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                hasActiveSubscription = purchases.any { purchase ->
                    purchase.purchaseState == Purchase.PurchaseState.PURCHASED
                }
                runOnUiThread {
                    trialBanner.visibility =
                        if (hasActiveSubscription) View.GONE else View.VISIBLE
                }
            }
        }
    }

    private fun openSubscriptionScreen() {
        val intent = Intent().apply {
            component = ComponentName(this@MainActivity, SUBSCRIPTION_ACTIVITY_CLASS)
        }
        startActivity(intent)
    }
}
