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
import android.view.View
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.util.Log
import android.webkit.CookieManager
import android.webkit.GeolocationPermissions
import android.webkit.PermissionRequest
import android.webkit.RenderProcessGoneDetail
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import za.co.relatives.app.network.ApiClient
import com.android.billingclient.api.BillingClient
import com.android.billingclient.api.BillingClientStateListener
import com.android.billingclient.api.BillingResult
import com.android.billingclient.api.PendingPurchasesParams
import com.android.billingclient.api.Purchase
import com.android.billingclient.api.QueryPurchasesParams
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.network.NetworkClient
import za.co.relatives.app.tracking.FamilyPoller
import za.co.relatives.app.tracking.PermissionGate
import za.co.relatives.app.tracking.TrackingBridge
import za.co.relatives.app.tracking.TrackingService
import za.co.relatives.app.ui.tracking.TrackingActivity
import za.co.relatives.app.utils.PreferencesManager
import java.net.CookieHandler
import java.net.HttpCookie
import java.net.URI
import java.net.CookieManager as JavaNetCookieManager

/**
 * Single-activity host for the Relatives hybrid WebView app.
 *
 * Wires together:
 *   - TrackingStore (cache, single source of truth)
 *   - PermissionGate (prominent disclosure → permission flow)
 *   - TrackingService (foreground location service, started only on user action)
 *   - FamilyPoller (fetches family locations into cache)
 *   - TrackingBridge (WebView JS interface, reads from cache)
 *   - Mapbox (renders in WebView from cached data, no permission needed)
 */
class MainActivity : ComponentActivity() {

    companion object {
        private const val TAG = "MainActivity"
        private const val WEB_URL = "https://www.relatives.co.za"
        private const val SUBSCRIPTION_ACTIVITY_CLASS =
            "za.co.relatives.app.ui.SubscriptionActivity"
    }

    // ── Core modules ────────────────────────────────────────────────────

    lateinit var trackingStore: TrackingStore
        private set

    private lateinit var permissionGate: PermissionGate
    private lateinit var familyPoller: FamilyPoller
    private lateinit var prefs: PreferencesManager

    // ── UI ───────────────────────────────────────────────────────────────

    private lateinit var root: FrameLayout
    private lateinit var webView: WebView
    private var trialDialogShown = false

    private var fileUploadCallback: ValueCallback<Array<Uri>>? = null
    private var pendingMediaRequest: PermissionRequest? = null

    // ── Billing ─────────────────────────────────────────────────────────

    private var billingClient: BillingClient? = null
    private var hasActiveSubscription = false

    // ── Permission launchers (non-tracking, must be registered before onStart) ──

    private lateinit var microphoneLauncher: ActivityResultLauncher<String>
    private lateinit var fileChooserLauncher: ActivityResultLauncher<Intent>

    // ── Back press ──────────────────────────────────────────────────────

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

    // ════════════════════════════════════════════════════════════════════
    //  LIFECYCLE
    // ════════════════════════════════════════════════════════════════════

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        prefs = (application as? RelativesApplication)?.preferencesManager
            ?: PreferencesManager(this)
        trackingStore = TrackingStore(this)
        permissionGate = PermissionGate(this)
        familyPoller = FamilyPoller(this, trackingStore)

        // Register all permission launchers BEFORE onStart
        permissionGate.registerLaunchers()
        registerNonTrackingLaunchers()

        buildLayout()
        onBackPressedDispatcher.addCallback(this, backCallback)
        connectBillingClient()
        enterImmersiveMode()

        // Restore WebView state when recreated (e.g. after background location
        // permission opens Settings and the system destroys this Activity).
        // Only load the initial URL on a truly fresh start — but never leave
        // a blank WebView if restoration produced nothing.
        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState)
            if (webView.url.isNullOrBlank()) {
                loadInitialUrl(intent)
            }
        } else {
            loadInitialUrl(intent)
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleDeepLink(intent)
    }

    override fun onResume() {
        super.onResume()
        backCallback.isEnabled = true
        enterImmersiveMode()
        CookieManager.getInstance().flush()

        // Rebuild a WebView whose renderer died while we were backgrounded.
        pendingRecreateUrl?.let { url ->
            pendingRecreateUrl = null
            recreateWebView(url)
        }

        // Wake the WebView back up (timers/JS paused in onPause).
        if (::webView.isInitialized) {
            webView.onResume()
            webView.resumeTimers()
        }

        // Poll only while the app is actually in the foreground.
        familyPoller.start()

        // Resume tracking if the user has it enabled but the service is not
        // running (system kill, reboot on Android 15+ where BootReceiver may
        // not start a location service, permission round-trips, etc.).
        // Starting a foreground service from a resumed activity is always
        // allowed.
        if (prefs.trackingEnabled &&
            permissionGate.hasForegroundLocation() &&
            !TrackingService.isRunning
        ) {
            TrackingService.start(this)
        }
    }

    override fun onPause() {
        super.onPause()
        // Stop polling entirely in the background — the native cache keeps the
        // last known positions for instant render on return, and background
        // polling was pure battery/data drain.
        familyPoller.stop()

        // Freeze the WebView: without this its JS intervals and any page
        // geolocation keep running with the screen off. (TrackingService is a
        // separate process-level service and is unaffected.) Skip in
        // multi-window, where a paused activity can still be visible.
        if (::webView.isInitialized && !isInMultiWindowMode) {
            webView.onPause()
            webView.pauseTimers()
        }
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus) enterImmersiveMode()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        if (::webView.isInitialized) {
            webView.saveState(outState)
        }
    }

    override fun onDestroy() {
        familyPoller.stop()
        if (::permissionGate.isInitialized) {
            permissionGate.dismissDialogs()
        }
        billingClient?.endConnection()
        if (::webView.isInitialized) {
            try {
                webView.destroy()
            } catch (_: Exception) {
            }
        }
        super.onDestroy()
    }

    // ════════════════════════════════════════════════════════════════════
    //  TRACKING CONTROL (called by TrackingBridge)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Start tracking through the PermissionGate.
     * Permission only requested when user explicitly taps "Enable live location".
     */
    fun startTrackingWithPermissions() {
        permissionGate.requestTracking { granted ->
            if (granted) {
                prefs.trackingEnabled = true
                TrackingService.start(this)
            }
        }
    }

    fun stopTrackingService() {
        prefs.trackingEnabled = false
        TrackingService.stop(this)
    }

    fun isTrackingActive(): Boolean =
        prefs.trackingEnabled && permissionGate.hasForegroundLocation()

    fun getTrackingMode(): String = when {
        !permissionGate.hasForegroundLocation() -> "no_permission"
        prefs.trackingEnabled -> "enabled"
        else -> "disabled"
    }

    fun requestNotificationPermission() {
        permissionGate.requestNotifications()
    }

    fun wakeAllDevices() {
        // The web page POSTs /tracking/api/wake_devices.php itself to wake the
        // rest of the family via FCM; here we only boost our own device, and
        // only when tracking is actually on.
        if (prefs.trackingEnabled && permissionGate.hasForegroundLocation()) {
            TrackingService.motionStarted(this)
        }
    }

    fun onTrackingScreenVisible() {
        familyPoller.setActive(true)
        familyPoller.pollNow()
    }

    fun onTrackingScreenHidden() {
        familyPoller.setActive(false)
    }

    // ════════════════════════════════════════════════════════════════════
    //  LAYOUT
    // ════════════════════════════════════════════════════════════════════

    private fun buildLayout() {
        root = FrameLayout(this)
        attachNewWebView()
        setContentView(root)
    }

    private fun attachNewWebView() {
        webView = WebView(this).apply {
            layoutParams = FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.MATCH_PARENT,
            )
        }
        root.addView(webView)
        configureWebView()
    }

    private var lastRendererCrashAt = 0L
    private var rendererCrashCount = 0
    private var pendingRecreateUrl: String? = null

    /**
     * Replace a WebView whose renderer process died. Without this the system
     * kills the entire app — the map page (Mapbox GL) is GPU/memory heavy and
     * renderer crashes there must not take the app down.
     *
     * If the same page keeps killing the renderer, reloading it would just
     * crash-loop (GPU OOM), so after repeated crashes in quick succession we
     * escape to the home page instead.
     */
    private fun recreateWebView(lastUrl: String?) {
        val now = System.currentTimeMillis()
        rendererCrashCount = if (now - lastRendererCrashAt < 60_000L) rendererCrashCount + 1 else 1
        lastRendererCrashAt = now

        try {
            root.removeView(webView)
            webView.destroy()
        } catch (_: Exception) {
        }
        attachNewWebView()
        webView.loadUrl(if (rendererCrashCount >= 3) WEB_URL else lastUrl ?: WEB_URL)
    }

    // ════════════════════════════════════════════════════════════════════
    //  WEBVIEW
    // ════════════════════════════════════════════════════════════════════

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
            setGeolocationEnabled(true)
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

        // Register the TrackingBridge (new clean bridge)
        webView.addJavascriptInterface(TrackingBridge(this), "TrackingBridge")

        webView.webViewClient = object : WebViewClient() {
            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)
                extractSessionToken()
                syncCookiesToNative()
                syncFcmToken()

                // Drive the poll rate from the real page URL. The page's own
                // beforeunload/visibility signals are unreliable in a WebView,
                // which used to leave the poller stuck in fast mode after
                // navigating away from the tracking map.
                familyPoller.setActive(url?.contains("/tracking") == true)

                // Prompt for notification permission when the user visits the
                // notifications page — all logic stays inside the APK.
                if (url?.contains("/notifications") == true) {
                    requestNotificationPermission()
                }
            }

            override fun shouldOverrideUrlLoading(
                view: WebView?,
                request: WebResourceRequest?,
            ): Boolean {
                val uri = request?.url ?: return false
                val internal = isRelativesHost(uri.host)

                // Intercept tracking URLs → launch native TrackingActivity.
                // Main-frame + real host + path prefix only: a foreign URL
                // merely containing the substring must not trigger it.
                if (request.isForMainFrame && internal &&
                    uri.path?.startsWith("/tracking/app") == true
                ) {
                    syncCookiesToNative()
                    startActivity(
                        Intent(this@MainActivity, TrackingActivity::class.java).apply {
                            putExtra(
                                TrackingActivity.EXTRA_START_SCREEN,
                                TrackingActivity.screenForPath(uri.path),
                            )
                        },
                    )
                    return true
                }

                // Host check, not substring: "evil.com/relatives.co.za" must
                // NOT stay inside this WebView — TrackingBridge is exposed to
                // every page loaded here.
                if (internal) return false
                if (!request.isForMainFrame) return true // cancel foreign subframe navigations
                return try {
                    startActivity(Intent(Intent.ACTION_VIEW, uri))
                    true
                } catch (e: Exception) {
                    Log.w(TAG, "No handler for external url: $uri", e)
                    true
                }
            }

            override fun onRenderProcessGone(
                view: WebView?,
                detail: RenderProcessGoneDetail?,
            ): Boolean {
                Log.e(TAG, "WebView renderer gone (didCrash=${detail?.didCrash()}); rebuilding WebView")
                val lastUrl = view?.url
                runOnUiThread {
                    if (lifecycle.currentState.isAtLeast(androidx.lifecycle.Lifecycle.State.RESUMED)) {
                        recreateWebView(lastUrl)
                    } else {
                        // Renderer reclaimed while backgrounded (didCrash=false
                        // is the common case): reloading now would just churn —
                        // the OS may kill it again. Leave the dead view in
                        // place and rebuild on next resume.
                        pendingRecreateUrl = lastUrl ?: WEB_URL
                    }
                }
                return true // handled — do NOT let the system kill the app
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onShowFileChooser(
                webView: WebView?,
                callback: ValueCallback<Array<Uri>>?,
                params: FileChooserParams?,
            ): Boolean {
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

            override fun onGeolocationPermissionsShowPrompt(
                origin: String?,
                callback: GeolocationPermissions.Callback?,
            ) {
                // Only grant WebView geolocation if system permission is already granted
                val hasSystemPermission = ContextCompat.checkSelfPermission(
                    this@MainActivity, Manifest.permission.ACCESS_FINE_LOCATION,
                ) == PackageManager.PERMISSION_GRANTED
                val originHost = origin?.let { runCatching { Uri.parse(it).host }.getOrNull() }
                val allowed = hasSystemPermission && isRelativesHost(originHost)
                callback?.invoke(origin, allowed, false)
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

    // ── URL loading ─────────────────────────────────────────────────────

    private fun loadInitialUrl(intent: Intent?) {
        val deepLink = intent?.getStringExtra("action_url")
        if (!deepLink.isNullOrBlank()) {
            // Intercept tracking deep links → launch native TrackingActivity,
            // but still give the WebView a page so backing out of the native
            // screen doesn't land on a blank activity.
            if (deepLink.contains("/tracking/app")) {
                webView.loadUrl(WEB_URL)
                launchTrackingActivity(deepLink)
                return
            }
            webView.loadUrl(resolveUrl(deepLink))
        } else {
            webView.loadUrl(WEB_URL)
        }
    }

    private fun handleDeepLink(intent: Intent?) {
        val actionUrl = intent?.getStringExtra("action_url") ?: return
        // Intercept tracking deep links → launch native TrackingActivity
        if (actionUrl.contains("/tracking/app")) {
            launchTrackingActivity(actionUrl)
            return
        }
        webView.loadUrl(resolveUrl(actionUrl))
    }

    private fun launchTrackingActivity(path: String?) {
        startActivity(
            Intent(this, TrackingActivity::class.java).apply {
                putExtra(
                    TrackingActivity.EXTRA_START_SCREEN,
                    TrackingActivity.screenForPath(path),
                )
            },
        )
    }

    private fun resolveUrl(path: String): String =
        if (path.startsWith("http")) path else "$WEB_URL$path"

    /**
     * True only for relatives.co.za and its subdomains. A plain substring
     * check would also match attacker URLs like evil.com/relatives.co.za and
     * keep them inside the TrackingBridge-armed WebView.
     */
    private fun isRelativesHost(host: String?): Boolean =
        host == "relatives.co.za" || host?.endsWith(".relatives.co.za") == true

    // ════════════════════════════════════════════════════════════════════
    //  IMMERSIVE MODE
    // ════════════════════════════════════════════════════════════════════

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

    // ════════════════════════════════════════════════════════════════════
    //  NON-TRACKING PERMISSION LAUNCHERS
    // ════════════════════════════════════════════════════════════════════

    private fun registerNonTrackingLaunchers() {
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
                WebChromeClient.FileChooserParams.parseResult(result.resultCode, result.data!!)
            } else {
                null
            }
            fileUploadCallback?.onReceiveValue(uris)
            fileUploadCallback = null
        }
    }

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

    // ════════════════════════════════════════════════════════════════════
    //  COOKIE SYNC & SESSION TOKEN
    // ════════════════════════════════════════════════════════════════════

    private fun syncCookiesToNative() {
        val raw = CookieManager.getInstance().getCookie(WEB_URL) ?: return
        val javaManager = (CookieHandler.getDefault() as? JavaNetCookieManager)
            ?: JavaNetCookieManager().also { CookieHandler.setDefault(it) }
        val uri = URI.create(WEB_URL)
        val domain = uri.host ?: "www.relatives.co.za"
        raw.split(";").forEach { segment ->
            val trimmed = segment.trim()
            if (trimmed.isNotEmpty()) {
                runCatching {
                    HttpCookie.parse("Set-Cookie: $trimmed").forEach { cookie ->
                        javaManager.cookieStore.add(uri, cookie)
                    }
                }
                // Also push to OkHttp so native services (FamilyPoller,
                // LocationUploadWorker) can authenticate independently of
                // whatever page the WebView is currently showing.
                val parts = trimmed.split("=", limit = 2)
                if (parts.size == 2) {
                    NetworkClient.setSessionCookie(domain, parts[0].trim(), parts[1].trim())
                }
            }
        }
    }

    // ── FCM token registration ──────────────────────────────────────────

    private var fcmTokenSynced = false

    /**
     * Register this device's FCM token with the backend once a page has
     * loaded (i.e. once session cookies exist). FirebaseMessagingService's
     * onNewToken usually fires before the user has logged in, so its 401'd
     * registration was never retried — leaving the device unreachable for
     * pushes and tracking wakes.
     */
    private fun syncFcmToken() {
        if (fcmTokenSynced) return
        fcmTokenSynced = true
        try {
            FirebaseMessaging.getInstance().token
                .addOnSuccessListener { token ->
                    if (token.isNullOrBlank()) {
                        fcmTokenSynced = false
                        return@addOnSuccessListener
                    }
                    prefs.fcmToken = token
                    lifecycleScope.launch(Dispatchers.IO) {
                        try {
                            ApiClient(applicationContext).registerFcmToken(token)
                            Log.d(TAG, "FCM token registered with backend")
                        } catch (e: Exception) {
                            // Not logged in yet or offline — retry on a later page load.
                            fcmTokenSynced = false
                            Log.w(TAG, "FCM token registration failed (will retry)", e)
                        }
                    }
                }
                .addOnFailureListener { e ->
                    // Async token fetch failure (no network, Play Services busy)
                    // must also re-arm the retry.
                    fcmTokenSynced = false
                    Log.w(TAG, "FCM token fetch failed (will retry)", e)
                }
        } catch (e: Exception) {
            fcmTokenSynced = false
            Log.w(TAG, "FCM token fetch failed", e)
        }
    }

    private fun extractSessionToken() {
        val raw = CookieManager.getInstance().getCookie(WEB_URL) ?: return
        val targetNames = setOf("relatives_session", "session_token", "phpsessid", "token")
        raw.split(";").forEach { segment ->
            val parts = segment.trim().split("=", limit = 2)
            if (parts.size == 2 && parts[0].trim().lowercase() in targetNames) {
                val cookieName = parts[0].trim()
                val cookieValue = parts[1].trim()
                prefs.sessionToken = cookieValue
                // Push to OkHttp so background workers authenticate
                // even after the WebView navigates away from /tracking/
                NetworkClient.setSessionCookie("www.relatives.co.za", cookieName, cookieValue)
                return
            }
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  BILLING / SUBSCRIPTION
    // ════════════════════════════════════════════════════════════════════

    private fun connectBillingClient() {
        try {
            billingClient = BillingClient.newBuilder(this)
                .setListener { _, _ -> }
                .enablePendingPurchases(
                    PendingPurchasesParams.newBuilder().enableOneTimeProducts().build(),
                )
                .build()

            billingClient?.startConnection(object : BillingClientStateListener {
                override fun onBillingSetupFinished(result: BillingResult) {
                    if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                        querySubscriptionStatus()
                    }
                }
                override fun onBillingServiceDisconnected() {}
            })
        } catch (e: Exception) {
            android.util.Log.e("MainActivity", "BillingClient init failed", e)
        }
    }

    private fun querySubscriptionStatus() {
        val params = QueryPurchasesParams.newBuilder()
            .setProductType(BillingClient.ProductType.SUBS)
            .build()
        billingClient?.queryPurchasesAsync(params) { result, purchases ->
            if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                hasActiveSubscription = purchases.any { p ->
                    p.purchaseState == Purchase.PurchaseState.PURCHASED
                }
                if (!hasActiveSubscription && !trialDialogShown) {
                    runOnUiThread { showTrialDialog() }
                }
            }
        }
    }

    private fun showTrialDialog() {
        if (trialDialogShown || isFinishing || isDestroyed) return
        trialDialogShown = true

        AlertDialog.Builder(this)
            .setTitle(getString(R.string.trial_dialog_title))
            .setMessage(getString(R.string.trial_dialog_message))
            .setPositiveButton(getString(R.string.trial_dialog_subscribe)) { dialog, _ ->
                dialog.dismiss()
                openSubscriptionScreen()
            }
            .setNegativeButton(getString(R.string.trial_dialog_close)) { dialog, _ ->
                dialog.dismiss()
            }
            .setCancelable(true)
            .show()
    }

    private fun openSubscriptionScreen() {
        startActivity(Intent().apply {
            component = ComponentName(this@MainActivity, SUBSCRIPTION_ACTIVITY_CLASS)
        })
    }
}
