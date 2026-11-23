package za.co.relatives.app

import android.Manifest
import android.annotation.SuppressLint
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.SystemClock
import android.graphics.Bitmap
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
import androidx.lifecycle.withResumed
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.launch
import za.co.relatives.app.data.TrackingStore
import za.co.relatives.app.network.NetworkClient
import za.co.relatives.app.services.FcmRegistrationWorker
import za.co.relatives.app.tracking.FamilyPoller
import za.co.relatives.app.tracking.PermissionGate
import za.co.relatives.app.tracking.TrackingBridge
import za.co.relatives.app.tracking.TrackingService
import za.co.relatives.app.utils.PreferencesManager
import za.co.relatives.app.ui.SubscriptionActivity

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
        private val TRUSTED_HOSTS = setOf("www.relatives.co.za", "relatives.co.za")
        private val MAP_PATHS = setOf("/tracking/app", "/tracking/app/", "/tracking/app/index.php")
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
    private var navigationPermissionUntil = 0L

    private var fileUploadCallback: ValueCallback<Array<Uri>>? = null
    private var pendingMediaRequest: PermissionRequest? = null

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
        registerNonTrackingLaunchers()

        buildLayout()
        onBackPressedDispatcher.addCallback(this, backCallback)
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

        if (TrackingService.isTrackingEnabled(this) &&
            permissionGate.hasForegroundLocation() &&
            TrackingService.canShowTrackingNotification(this) &&
            !TrackingService.isRunning
        ) {
            TrackingService.revive(this)
        }
    }

    override fun onPause() {
        super.onPause()
        // Persist cookies NOW. Chromium commits cookies to disk lazily; if the
        // process dies while we're paused, a login cookie set since the last
        // flush would be lost and the user silently logged out.
        CookieManager.getInstance().flush()

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

    fun startTrackingWithPermissions(onResult: () -> Unit) {
        syncNativeSession(webView.url)
        val session = prefs.sessionToken
        if (session.isNullOrBlank() || !isTrackingMap(webView.url)) {
            onResult()
            return
        }

        permissionGate.requestTracking { granted ->
            lifecycleScope.launch {
                lifecycle.withResumed {
                    if (granted && prefs.sessionToken == session && isTrackingMap(webView.url)) {
                        TrackingService.start(this@MainActivity)
                        permissionGate.requestMotionRecognition()
                    }
                    onResult()
                }
            }
        }
    }

    fun stopTrackingService() = TrackingService.stop(this)

    fun getTrackingMode(): String = when {
        !permissionGate.hasForegroundLocation() -> "no_permission"
        !TrackingService.canShowTrackingNotification(this) -> "notifications_disabled"
        TrackingService.isTrackingEnabled(this) -> if (TrackingService.isRunning) "enabled" else "paused"
        else -> "disabled"
    }

    fun requestNotificationPermission() = permissionGate.requestNotifications()

    fun wakeAllDevices() = TrackingService.requestWakeFix(this)

    fun prepareWebNavigation(): Boolean {
        if (!isTrackingMap(webView.url) || !lifecycle.currentState.isAtLeast(androidx.lifecycle.Lifecycle.State.RESUMED)) {
            return false
        }
        navigationPermissionUntil = SystemClock.elapsedRealtime() + 5_000L
        return true
    }

    fun onTrackingScreenVisible() {
        familyPoller.setActive(true)
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
            setAcceptThirdPartyCookies(webView, false)
        }

        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            setGeolocationEnabled(true)
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            allowFileAccess = false
            allowContentAccess = true
            mediaPlaybackRequiresUserGesture = false
            javaScriptCanOpenWindowsAutomatically = false
            setSupportMultipleWindows(false)
            useWideViewPort = true
            loadWithOverviewMode = true
            cacheMode = WebSettings.LOAD_DEFAULT
            userAgentString = "$userAgentString RelativesAndroid/1"
        }

        TrackingBridge(this).install(webView)

        webView.webViewClient = object : WebViewClient() {
            override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
                super.onPageStarted(view, url, favicon)
                familyPoller.setActive(false)
                navigationPermissionUntil = 0L
                val uri = url?.let(Uri::parse)
                if (uri != null && !isTrustedUrl(uri)) {
                    view?.stopLoading()
                    return
                }
                if (uri?.path == "/logout.php") clearNativeSession()
            }

            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)
                if (view != webView || !isTrustedUrl(url?.let(Uri::parse))) return
                syncNativeSession(url)
                CookieManager.getInstance().flush()
                if (Uri.parse(url).path !in setOf("/", "/login.php", "/logout.php")) syncFcmToken()
                familyPoller.setActive(isTrackingMap(url))
            }

            override fun shouldOverrideUrlLoading(
                view: WebView?,
                request: WebResourceRequest?,
            ): Boolean {
                val uri = request?.url ?: return true
                if (isTrustedUrl(uri)) {
                    if (uri.path == "/logout.php") clearNativeSession()
                    if (isWebCheckout(uri)) {
                        if (request.isForMainFrame) openSubscriptionScreen()
                        return true
                    }
                    return false
                }
                if (!request.isForMainFrame || !request.hasGesture() ||
                    uri.scheme !in setOf("https", "mailto", "tel")
                ) return true
                runCatching { startActivity(Intent(Intent.ACTION_VIEW, uri)) }
                return true
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
                if (origin == null || !isCurrentTrustedPage(webView, Uri.parse(origin)) ||
                    !isTrackingMap(webView.url) || SystemClock.elapsedRealtime() >= navigationPermissionUntil
                ) {
                    callback?.invoke(origin, false, false)
                    return
                }
                navigationPermissionUntil = 0L
                permissionGate.requestForegroundLocation { granted ->
                    callback?.invoke(origin, granted && isTrackingMap(webView.url), false)
                }
            }

            override fun onPermissionRequest(request: PermissionRequest?) {
                request ?: return
                if (isCurrentTrustedPage(webView, request.origin) &&
                    request.resources.contains(PermissionRequest.RESOURCE_AUDIO_CAPTURE)
                ) {
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
            // Deep links (FCM notifications) load in the WebView like any
            // other page; logged-out users get the server's login redirect.
            loadDeepLink(deepLink)
        } else {
            webView.loadUrl(WEB_URL)
        }
    }

    private fun handleDeepLink(intent: Intent?) {
        val actionUrl = intent?.getStringExtra("action_url") ?: return
        loadDeepLink(actionUrl)
    }

    private fun loadDeepLink(actionUrl: String) {
        val uri = Uri.parse(if (actionUrl.startsWith("/") && !actionUrl.startsWith("//")) {
            "$WEB_URL$actionUrl"
        } else actionUrl)
        if (isTrustedUrl(uri) && isWebCheckout(uri)) {
            openSubscriptionScreen()
            return
        }

        val target = if (isTrustedUrl(uri)) uri.toString() else WEB_URL
        if (pendingRecreateUrl != null) {
            pendingRecreateUrl = target
        } else {
            webView.loadUrl(target)
        }
    }

    private fun isTrustedUrl(uri: Uri?): Boolean =
        uri?.scheme == "https" && uri.host in TRUSTED_HOSTS &&
            uri.encodedUserInfo == null && uri.port in setOf(-1, 443)

    fun isCurrentTrustedPage(view: WebView, origin: Uri): Boolean {
        val document = view.url?.let(Uri::parse)
        return view == webView && isTrustedUrl(document) && isTrustedUrl(origin) &&
            document?.host == origin.host
    }

    fun isTrackingMap(url: String?): Boolean =
        url?.let { Uri.parse(it).let { uri -> isTrustedUrl(uri) && uri.path in MAP_PATHS } } == true

    private fun isWebCheckout(uri: Uri): Boolean =
        uri.path == "/upgrade" || uri.path?.startsWith("/upgrade/") == true

    private fun openSubscriptionScreen() {
        startActivity(Intent(this, SubscriptionActivity::class.java))
    }

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
            pendingMediaRequest?.grant(arrayOf(PermissionRequest.RESOURCE_AUDIO_CAPTURE))
            pendingMediaRequest = null
        } else {
            microphoneLauncher.launch(Manifest.permission.RECORD_AUDIO)
        }
    }

    // ════════════════════════════════════════════════════════════════════
    //  COOKIE SYNC & SESSION TOKEN
    // ════════════════════════════════════════════════════════════════════

    private fun syncNativeSession(url: String?) {
        val uri = url?.let(Uri::parse) ?: return
        if (!isTrustedUrl(uri)) return
        val cookie = CookieManager.getInstance().getCookie("https://${uri.host}")
            ?.split(';')?.map(String::trim)?.firstOrNull { it.startsWith("RELATIVES_SESSION=") }
            ?.substringAfter('=')?.takeIf { it.matches(Regex("[A-Za-z0-9,-]{16,256}")) }
        if (prefs.sessionToken != cookie) {
            clearNativeSession()
            prefs.sessionToken = cookie
        }
        if (cookie != null) {
            NetworkClient.setSessionCookie("www.relatives.co.za", "RELATIVES_SESSION", cookie)
        }
    }

    private fun clearNativeSession() {
        if (prefs.sessionToken != null) TrackingService.stop(this)
        prefs.clearSession()
        FcmRegistrationWorker.cancel(this)
        trackingStore.clearFamilyLocations()
        NetworkClient.clearCookies()
        fcmTokenSynced = false
    }

    // ── FCM token registration ──────────────────────────────────────────

    private var fcmTokenSynced = false

    private fun syncFcmToken() {
        val session = prefs.sessionToken ?: return
        if (fcmTokenSynced) return
        fcmTokenSynced = true
        FirebaseMessaging.getInstance().token
            .addOnSuccessListener { token ->
                if (prefs.sessionToken == session && !token.isNullOrBlank()) {
                    prefs.fcmToken = token
                    FcmRegistrationWorker.enqueue(this)
                } else if (prefs.sessionToken == session) {
                    fcmTokenSynced = false
                }
            }
            .addOnFailureListener {
                if (prefs.sessionToken == session) fcmTokenSynced = false
            }
    }
}
