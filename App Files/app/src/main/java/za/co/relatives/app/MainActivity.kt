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
import android.webkit.CookieManager
import android.webkit.GeolocationPermissions
import android.webkit.PermissionRequest
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

        prefs = (application as RelativesApplication).preferencesManager
        trackingStore = TrackingStore(this)
        permissionGate = PermissionGate(this)
        familyPoller = FamilyPoller(this, trackingStore)

        // Register all permission launchers BEFORE onStart
        permissionGate.registerLaunchers()
        registerNonTrackingLaunchers()

        buildLayout()
        configureWebView()
        onBackPressedDispatcher.addCallback(this, backCallback)
        connectBillingClient()
        enterImmersiveMode()

        // Start family polling immediately (works without location permission)
        familyPoller.start()

        // Restore WebView state when recreated (e.g. after background location
        // permission opens Settings and the system destroys this Activity).
        // Only load the initial URL on a truly fresh start.
        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState)
        } else {
            loadInitialUrl(intent)
        }
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
        familyPoller.setActive(true)
    }

    override fun onPause() {
        super.onPause()
        familyPoller.setActive(false)
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
        billingClient?.endConnection()
        webView.destroy()
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
        TrackingService.motionStarted(this)
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
        val root = FrameLayout(this)

        webView = WebView(this).apply {
            layoutParams = FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.MATCH_PARENT,
            )
        }
        root.addView(webView)

        setContentView(root)
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
                val url = request?.url?.toString() ?: return false

                // Intercept tracking URLs → launch native TrackingActivity
                if (url.contains("/tracking/app")) {
                    syncCookiesToNative()
                    startActivity(Intent(this@MainActivity, TrackingActivity::class.java))
                    return true
                }

                if (url.contains("relatives.co.za")) return false
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
                val allowed = hasSystemPermission && origin?.contains("relatives.co.za") == true
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
            // Intercept tracking deep links → launch native TrackingActivity
            if (deepLink.contains("/tracking/app")) {
                startActivity(Intent(this, TrackingActivity::class.java))
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
            startActivity(Intent(this, TrackingActivity::class.java))
            return
        }
        webView.loadUrl(resolveUrl(actionUrl))
    }

    private fun resolveUrl(path: String): String =
        if (path.startsWith("http")) path else "$WEB_URL$path"

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
        if (trialDialogShown || isFinishing) return
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
