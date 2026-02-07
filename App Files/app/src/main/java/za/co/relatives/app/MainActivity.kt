package za.co.relatives.app

import android.Manifest
import android.app.Activity
import android.app.AlertDialog
import android.annotation.SuppressLint
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.view.ViewGroup
import android.webkit.*
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowForward
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.activity.compose.rememberLauncherForActivityResult
import za.co.relatives.app.services.TrackingLocationService
import za.co.relatives.app.ui.TrackingJsInterface
import za.co.relatives.app.ui.SubscriptionActivity
import za.co.relatives.app.network.ApiClient
import za.co.relatives.app.ui.theme.RelativesTheme
import za.co.relatives.app.utils.PreferencesManager

/**
 * Main activity hosting the WebView with JavaScript bridge.
 *
 * GOOGLE PLAY PROMINENT DISCLOSURE COMPLIANCE:
 *
 * Permissions are NEVER requested automatically on app launch.
 * The disclosure + permission flow is triggered ONLY when the user
 * explicitly enables tracking (via the web UI toggle or native bridge).
 *
 * Flow:
 * 1. User taps "Enable Location Sharing" in web UI
 * 2. Web calls TrackingBridge.startTracking() via bridge
 * 3. Bridge calls MainActivity.requestTrackingPermissions()
 * 4. Foreground disclosure dialog shown (blocking, non-cancelable)
 * 5. User taps "Continue" -> OS foreground location prompt
 * 6. If granted -> background disclosure dialog shown
 * 7. User taps "Continue" -> OS background location prompt
 * 8. Notification permission requested (Android 13+)
 * 9. Tracking service starts
 *
 * If user taps "Not Now" at any disclosure -> no permission requested,
 * no tracking started. App continues to work without location.
 */
class MainActivity : ComponentActivity() {

    // State for Subscription Banner
    private var showTrialBanner by mutableStateOf(false)
    private var trialEndDate by mutableStateOf("")

    // WebView reference for navigation from notifications
    private var webViewRef: WebView? = null
    private var pendingUrl by mutableStateOf<String?>(null)

    // Track if a disclosure dialog is currently showing
    private var isDisclosureShowing = false

    // Permission request launchers
    private val locationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { permissions ->
        val fineGranted = permissions[Manifest.permission.ACCESS_FINE_LOCATION] ?: false
        val coarseGranted = permissions[Manifest.permission.ACCESS_COARSE_LOCATION] ?: false

        if (fineGranted || coarseGranted) {
            // Foreground granted -> show background disclosure (Android 10+)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                showBackgroundLocationDisclosure()
            } else {
                // Pre-Q: no background permission needed, proceed
                onPermissionFlowComplete(locationGranted = true)
            }
        } else {
            // User denied foreground location - do NOT nag
            notifyWebPermissionResult(granted = false)
        }
    }

    private val backgroundLocationLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        // Background granted or denied - either way, proceed
        onPermissionFlowComplete(locationGranted = true)
    }

    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { _ ->
        // Notification result doesn't affect tracking
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Enable Immersive Sticky Mode (Hide System Bars)
        val windowInsetsController = WindowCompat.getInsetsController(window, window.decorView)
        windowInsetsController.systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
        windowInsetsController.hide(WindowInsetsCompat.Type.systemBars())

        var initialUrl = "https://www.relatives.co.za"
        // Check if opened from notification
        intent.getStringExtra("open_url")?.let {
            initialUrl = it
        }

        // NO permission requests here. Permissions are requested ONLY
        // when the user explicitly enables tracking via the web UI.
        // This is a Google Play Prominent Disclosure requirement.

        // If permissions are already granted and tracking was enabled, restart service
        if (hasLocationPermission() && PreferencesManager.isTrackingEnabled()) {
            startTrackingService()
        }

        // Check Subscription Status
        checkSubscription()

        setContent {
            RelativesTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background
                ) {
                    Box(modifier = Modifier.fillMaxSize()) {
                        Column(modifier = Modifier.fillMaxSize()) {
                            // Trial Banner
                            AnimatedVisibility(visible = showTrialBanner) {
                                TrialBanner(endDate = trialEndDate) {
                                    // On Click Banner -> Go to Subscription Page to upgrade early
                                    startActivity(Intent(this@MainActivity, SubscriptionActivity::class.java))
                                }
                            }

                            // Main Web Content
                            Box(modifier = Modifier.weight(1f)) {
                                WebViewScreen(
                                    initialUrl = pendingUrl ?: initialUrl,
                                    onWebViewCreated = { webView ->
                                        webViewRef = webView
                                        // Clear pending URL after WebView is ready
                                        pendingUrl = null
                                    },
                                    onPageTrackingCheck = { url ->
                                        // Only auto-start if permissions already granted
                                        if (url.contains("/tracking/") && hasLocationPermission()) {
                                            startTrackingService()
                                        }
                                    }
                                )
                            }
                        }
                    }
                }
            }
        }
    }

    override fun onResume() {
        super.onResume()
        checkSubscription()
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        // Handle notification click when app is already open
        intent.getStringExtra("open_url")?.let { url ->
            if (webViewRef != null) {
                webViewRef?.loadUrl(url)
            } else {
                pendingUrl = url
            }
        }
    }

    private fun checkSubscription() {
        val familyId = PreferencesManager.getDeviceUuid()

        ApiClient.getSubscriptionStatus(familyId) { status ->
            runOnUiThread {
                if (status != null) {
                    when (status.status) {
                        "locked", "expired", "cancelled" -> {
                            val intent = Intent(this, SubscriptionActivity::class.java)
                            startActivity(intent)
                            finish()
                        }
                        "trial" -> {
                            showTrialBanner = true
                            trialEndDate = status.trial_ends_at ?: "soon"
                        }
                        "active" -> {
                            showTrialBanner = false
                        }
                    }
                }
            }
        }
    }

    // ========== PUBLIC API (called by TrackingJsInterface) ==========

    /**
     * Called by TrackingJsInterface.startTracking() when user explicitly enables
     * location sharing. This is the ONLY entry point for the permission flow.
     *
     * If permissions already granted, starts tracking immediately.
     * If not, shows prominent disclosure first.
     */
    fun requestTrackingPermissions() {
        if (hasLocationPermission()) {
            // Already have foreground - check background
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                val hasBackground = ContextCompat.checkSelfPermission(
                    this, Manifest.permission.ACCESS_BACKGROUND_LOCATION
                ) == PackageManager.PERMISSION_GRANTED

                if (!hasBackground) {
                    showBackgroundLocationDisclosure()
                    return
                }
            }
            // All permissions already granted
            onPermissionFlowComplete(locationGranted = true)
            return
        }

        // No location permission -> show prominent disclosure FIRST
        showForegroundLocationDisclosure()
    }

    /**
     * Check if location permissions are granted.
     */
    fun hasLocationPermission(): Boolean {
        return ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
    }

    // ========== DISCLOSURE DIALOGS (Google Play Policy Compliant) ==========

    /**
     * FOREGROUND LOCATION DISCLOSURE
     *
     * Google Play requirement: shown BEFORE any location permission request.
     * - Non-cancelable (no back button dismiss, no outside tap dismiss)
     * - Clearly states WHAT data, WHY, WHEN (background), and OFF switch
     * - Only appears when user initiates tracking (not auto on launch)
     */
    private fun showForegroundLocationDisclosure() {
        if (isDisclosureShowing) return
        isDisclosureShowing = true

        val dialog = AlertDialog.Builder(this)
            .setTitle("Location Sharing")
            .setMessage(
                "Relatives uses your device location to share your live " +
                "location with trusted family members for safety.\n\n" +
                "Location sharing is optional and can be turned off " +
                "anytime in Tracking Settings."
            )
            .setPositiveButton("Continue") { _, _ ->
                isDisclosureShowing = false
                requestForegroundLocationPermission()
            }
            .setNegativeButton("Not Now") { dialog, _ ->
                isDisclosureShowing = false
                dialog.dismiss()
                notifyWebPermissionResult(granted = false)
            }
            .setCancelable(false)
            .create()

        dialog.setCanceledOnTouchOutside(false)
        dialog.show()
    }

    /**
     * BACKGROUND LOCATION DISCLOSURE
     *
     * Separate disclosure required for "Allow all the time" permission.
     * Shown only after foreground location is granted.
     */
    private fun showBackgroundLocationDisclosure() {
        if (isDisclosureShowing) return
        isDisclosureShowing = true

        val dialog = AlertDialog.Builder(this)
            .setTitle("Background Location")
            .setMessage(
                "If you enable \"Allow all the time\", Relatives can keep " +
                "live family location updated even when the app is closed.\n\n" +
                "This is optional and you can turn it off anytime in " +
                "device Settings or Tracking Settings."
            )
            .setPositiveButton("Continue") { _, _ ->
                isDisclosureShowing = false
                requestBackgroundLocationPermission()
            }
            .setNegativeButton("Skip") { dialog, _ ->
                isDisclosureShowing = false
                dialog.dismiss()
                // Still proceed - tracking works foreground-only
                onPermissionFlowComplete(locationGranted = true)
            }
            .setCancelable(false)
            .create()

        dialog.setCanceledOnTouchOutside(false)
        dialog.show()
    }

    // ========== PERMISSION REQUESTS ==========

    private fun requestForegroundLocationPermission() {
        locationPermissionLauncher.launch(
            arrayOf(
                Manifest.permission.ACCESS_FINE_LOCATION,
                Manifest.permission.ACCESS_COARSE_LOCATION
            )
        )
    }

    private fun requestBackgroundLocationPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            backgroundLocationLauncher.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
        }
    }

    private fun requestNotificationPermissionIfNeeded() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
                != PackageManager.PERMISSION_GRANTED
            ) {
                notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
            }
        }
    }

    // ========== FLOW COMPLETION ==========

    /**
     * Called when the entire permission flow finishes.
     * Notifies the web layer and starts tracking if granted.
     */
    private fun onPermissionFlowComplete(locationGranted: Boolean) {
        if (locationGranted) {
            // Permission granted - start tracking service
            PreferencesManager.setTrackingEnabled(true)
            startTrackingService()
            notifyWebPermissionResult(granted = true)

            // Also request notification permission (low-friction, separate from location)
            requestNotificationPermissionIfNeeded()
        } else {
            notifyWebPermissionResult(granted = false)
        }
    }

    /**
     * Notify the WebView about permission result so the UI can update.
     */
    private fun notifyWebPermissionResult(granted: Boolean) {
        runOnUiThread {
            webViewRef?.evaluateJavascript(
                "if(window.NativeBridge && window.NativeBridge.onPermissionResult) { " +
                "window.NativeBridge.onPermissionResult($granted); }",
                null
            )
        }
    }

    private fun startTrackingService() {
        if (PreferencesManager.isTrackingEnabled() && hasLocationPermission()) {
            TrackingLocationService.startTracking(this)
        }
    }
}

@Composable
fun TrialBanner(endDate: String, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(MaterialTheme.colorScheme.tertiaryContainer)
            .clickable(onClick = onClick)
            .padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.SpaceBetween
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = "Free Trial Active",
                style = MaterialTheme.typography.labelLarge,
                color = MaterialTheme.colorScheme.onTertiaryContainer
            )
            Text(
                text = "Ends: $endDate. Tap to upgrade.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onTertiaryContainer
            )
        }
        Icon(
            imageVector = Icons.Default.ArrowForward,
            contentDescription = "Upgrade",
            tint = MaterialTheme.colorScheme.onTertiaryContainer
        )
    }
}

@SuppressLint("SetJavaScriptEnabled")
@Composable
fun WebViewScreen(
    initialUrl: String,
    onWebViewCreated: (WebView) -> Unit = {},
    onPageTrackingCheck: (String) -> Unit
) {
    val context = LocalContext.current

    // State to hold the callback for file uploads
    var uploadMessageCallback by remember { mutableStateOf<ValueCallback<Array<Uri>>?>(null) }

    // Launcher for the file chooser intent
    val fileChooserLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.StartActivityForResult()
    ) { result ->
        if (result.resultCode == Activity.RESULT_OK) {
            val data = result.data
            // Parse the result using WebChromeClient's utility
            val results = WebChromeClient.FileChooserParams.parseResult(result.resultCode, data)
            uploadMessageCallback?.onReceiveValue(results)
        } else {
            // User cancelled
            uploadMessageCallback?.onReceiveValue(null)
        }
        uploadMessageCallback = null
    }

    AndroidView(factory = {
        WebView(it).apply {
            layoutParams = ViewGroup.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
            )

            val cookieManager = CookieManager.getInstance()
            cookieManager.setAcceptCookie(true)
            cookieManager.setAcceptThirdPartyCookies(this, true)

            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
            settings.userAgentString += " RelativesAndroidApp"
            settings.allowFileAccess = true
            settings.allowContentAccess = true

            // Fix: Use Application Context to prevent memory leaks in JS Interface
            addJavascriptInterface(TrackingJsInterface(context.applicationContext), "TrackingBridge")

            webViewClient = object : WebViewClient() {
                override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                    return false
                }

                override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
                    super.onPageStarted(view, url, favicon)
                    url?.let { onPageTrackingCheck(it) }
                }
            }

            webChromeClient = object : WebChromeClient() {
                // Handle microphone permission requests from web content
                override fun onPermissionRequest(request: PermissionRequest?) {
                    request?.let { req ->
                        val requestedResources = req.resources
                        val grantedResources = mutableListOf<String>()

                        for (resource in requestedResources) {
                            when (resource) {
                                PermissionRequest.RESOURCE_AUDIO_CAPTURE -> {
                                    // Check if app has RECORD_AUDIO permission
                                    if (ContextCompat.checkSelfPermission(
                                            context,
                                            Manifest.permission.RECORD_AUDIO
                                        ) == PackageManager.PERMISSION_GRANTED
                                    ) {
                                        grantedResources.add(resource)
                                    }
                                }
                                PermissionRequest.RESOURCE_VIDEO_CAPTURE -> {
                                    // Future: handle camera if needed
                                }
                            }
                        }

                        if (grantedResources.isNotEmpty()) {
                            req.grant(grantedResources.toTypedArray())
                        } else {
                            req.deny()
                        }
                    }
                }

                // Handle file upload requests (attachments)
                override fun onShowFileChooser(
                    webView: WebView?,
                    filePathCallback: ValueCallback<Array<Uri>>?,
                    fileChooserParams: FileChooserParams?
                ): Boolean {
                    // Cancel any pending callback
                    if (uploadMessageCallback != null) {
                        uploadMessageCallback?.onReceiveValue(null)
                        uploadMessageCallback = null
                    }
                    uploadMessageCallback = filePathCallback

                    val intent = fileChooserParams?.createIntent()
                    if (intent != null) {
                        try {
                            fileChooserLauncher.launch(intent)
                            return true
                        } catch (_: Exception) {
                            uploadMessageCallback = null
                            return false
                        }
                    }
                    return false
                }
            }

            loadUrl(initialUrl)

            // Notify parent that WebView is ready
            onWebViewCreated(this)
        }
    }, update = {})
}
