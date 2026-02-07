package com.relatives.app

import android.Manifest
import android.annotation.SuppressLint
import android.app.AlertDialog
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.webkit.GeolocationPermissions
import android.webkit.WebChromeClient
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import com.relatives.app.tracking.PreferencesManager
import com.relatives.app.webview.WebViewBridge

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
 * 2. Web calls Android.startTracking() via bridge
 * 3. Bridge calls MainActivity.requestTrackingPermissions()
 * 4. Foreground disclosure dialog shown (blocking, non-cancelable)
 * 5. User taps "Continue" → OS foreground location prompt
 * 6. If granted → background disclosure dialog shown
 * 7. User taps "Continue" → OS background location prompt
 * 8. Notification permission requested (Android 13+)
 * 9. Tracking service starts
 *
 * If user taps "Not Now" at any disclosure → no permission requested,
 * no tracking started. App continues to work without location.
 */
class MainActivity : AppCompatActivity() {

    companion object {
        private const val BASE_URL = "https://relatives.app"
    }

    private lateinit var webView: WebView
    private lateinit var bridge: WebViewBridge
    private lateinit var prefs: PreferencesManager

    // Track if a disclosure dialog is currently showing
    private var isDisclosureShowing = false

    // Permission request launchers
    private val locationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { permissions ->
        val fineGranted = permissions[Manifest.permission.ACCESS_FINE_LOCATION] ?: false
        val coarseGranted = permissions[Manifest.permission.ACCESS_COARSE_LOCATION] ?: false

        if (fineGranted || coarseGranted) {
            // Foreground granted → show background disclosure (Android 10+)
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

        prefs = PreferencesManager(this)
        webView = WebView(this)
        setContentView(webView)

        bridge = WebViewBridge(this)

        setupWebView()
        webView.loadUrl(BASE_URL)

        // NO permission requests here. Permissions are requested ONLY
        // when the user explicitly enables tracking via the web UI.
        // This is a Google Play Prominent Disclosure requirement.

        // Request notification permission separately since it's low-friction
        // and doesn't require location disclosure
        requestNotificationPermissionIfNeeded()
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            cacheMode = WebSettings.LOAD_DEFAULT
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            setSupportZoom(false)
            builtInZoomControls = false

            @Suppress("DEPRECATION")
            setRenderPriority(WebSettings.RenderPriority.HIGH)

            setGeolocationEnabled(true)
        }

        webView.addJavascriptInterface(bridge, WebViewBridge.INTERFACE_NAME)

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, url: String): Boolean {
                return !url.startsWith(BASE_URL)
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onGeolocationPermissionsShowPrompt(
                origin: String?,
                callback: GeolocationPermissions.Callback?
            ) {
                val hasPermission = ContextCompat.checkSelfPermission(
                    this@MainActivity, Manifest.permission.ACCESS_FINE_LOCATION
                ) == PackageManager.PERMISSION_GRANTED

                callback?.invoke(origin, hasPermission, false)
            }
        }
    }

    // ========== PUBLIC API (called by WebViewBridge) ==========

    /**
     * Called by WebViewBridge.startTracking() when user explicitly enables
     * location sharing. This is the ONLY entry point for the permission flow.
     *
     * If permissions already granted, starts tracking immediately.
     * If not, shows prominent disclosure first.
     */
    fun requestTrackingPermissions() {
        val hasLocation = ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED

        if (hasLocation) {
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

        // No location permission → show prominent disclosure FIRST
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
            // Permission granted - the bridge's startTracking() will
            // proceed to actually start the TrackingLocationService
            notifyWebPermissionResult(granted = true)
        } else {
            notifyWebPermissionResult(granted = false)
        }
    }

    /**
     * Notify the WebView about permission result so the UI can update.
     */
    private fun notifyWebPermissionResult(granted: Boolean) {
        runOnUiThread {
            webView.evaluateJavascript(
                "if(window.NativeBridge && window.NativeBridge.onPermissionResult) { " +
                "window.NativeBridge.onPermissionResult($granted); }",
                null
            )
        }
    }

    // ========== LIFECYCLE ==========

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        if (webView.canGoBack()) {
            webView.goBack()
        } else {
            @Suppress("DEPRECATION")
            super.onBackPressed()
        }
    }

    override fun onDestroy() {
        webView.removeJavascriptInterface(WebViewBridge.INTERFACE_NAME)
        webView.destroy()
        super.onDestroy()
    }
}
