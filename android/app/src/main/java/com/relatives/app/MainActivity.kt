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
import com.relatives.app.webview.WebViewBridge

/**
 * Main activity hosting the WebView with JavaScript bridge.
 *
 * Permission flow (Google Play Prominent Disclosure compliant):
 * 1. Show prominent disclosure dialog explaining WHY location is needed
 * 2. User accepts → request foreground location permission
 * 3. Foreground granted → show background location disclosure
 * 4. User accepts → request background location permission
 * 5. Request notification permission (Android 13+)
 */
class MainActivity : AppCompatActivity() {

    companion object {
        private const val BASE_URL = "https://relatives.app"
        private const val PREF_DISCLOSURE_SHOWN = "disclosure_shown"
    }

    private lateinit var webView: WebView
    private lateinit var bridge: WebViewBridge

    // Permission request launchers
    private val locationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { permissions ->
        val fineGranted = permissions[Manifest.permission.ACCESS_FINE_LOCATION] ?: false
        val coarseGranted = permissions[Manifest.permission.ACCESS_COARSE_LOCATION] ?: false

        if (fineGranted || coarseGranted) {
            // Foreground granted - now show background location disclosure
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                showBackgroundLocationDisclosure()
            } else {
                requestNotificationPermission()
            }
        } else {
            Toast.makeText(this, "Location permission required for family tracking", Toast.LENGTH_LONG).show()
            requestNotificationPermission()
        }
    }

    private val backgroundLocationLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        if (!granted) {
            Toast.makeText(
                this,
                "Background location enables tracking when the app is closed. You can enable it later in Settings.",
                Toast.LENGTH_LONG
            ).show()
        }
        // Continue to notification permission regardless
        requestNotificationPermission()
    }

    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        // Notification permission result - tracking works without it
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        webView = WebView(this)
        setContentView(webView)

        bridge = WebViewBridge(this)

        setupWebView()
        webView.loadUrl(BASE_URL)

        // Start permission flow AFTER showing the UI
        // Show prominent disclosure first (Google Play requirement)
        checkAndRequestPermissions()
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

            // Performance
            @Suppress("DEPRECATION")
            setRenderPriority(WebSettings.RenderPriority.HIGH)

            // Geolocation
            setGeolocationEnabled(true)
        }

        // Add JavaScript interface
        webView.addJavascriptInterface(bridge, WebViewBridge.INTERFACE_NAME)

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, url: String): Boolean {
                // Keep navigation within the app
                return if (url.startsWith(BASE_URL)) {
                    false // Let WebView handle it
                } else {
                    // Open external links in browser
                    true
                }
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onGeolocationPermissionsShowPrompt(
                origin: String?,
                callback: GeolocationPermissions.Callback?
            ) {
                // Auto-grant geolocation to our own domain if Android permission granted
                val hasPermission = ContextCompat.checkSelfPermission(
                    this@MainActivity, Manifest.permission.ACCESS_FINE_LOCATION
                ) == PackageManager.PERMISSION_GRANTED

                callback?.invoke(origin, hasPermission, false)
            }
        }
    }

    // ========== PERMISSION FLOW (Prominent Disclosure Compliant) ==========

    /**
     * Check permissions and show disclosure if needed.
     * This is the entry point for the Google Play compliant permission flow.
     */
    private fun checkAndRequestPermissions() {
        val hasLocation = ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED

        if (hasLocation) {
            // Already have foreground location - check background
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                val hasBackground = ContextCompat.checkSelfPermission(
                    this, Manifest.permission.ACCESS_BACKGROUND_LOCATION
                ) == PackageManager.PERMISSION_GRANTED

                if (!hasBackground) {
                    showBackgroundLocationDisclosure()
                    return
                }
            }
            // All location permissions granted
            requestNotificationPermission()
            return
        }

        // No location permission yet - show prominent disclosure first
        showLocationDisclosure()
    }

    /**
     * PROMINENT DISCLOSURE (Required by Google Play)
     *
     * Must be shown BEFORE requesting location permission.
     * Must clearly explain:
     * - WHAT data is collected
     * - WHY it's needed
     * - HOW it's used
     */
    private fun showLocationDisclosure() {
        AlertDialog.Builder(this)
            .setTitle("Location Access Required")
            .setMessage(
                "Relatives needs access to your location to:\n\n" +
                "- Share your location with your family group so they can see where you are\n" +
                "- Show nearby family members on the map\n" +
                "- Send alerts when family members arrive at or leave places\n\n" +
                "Your location is only shared with members of your family group. " +
                "You can disable location sharing at any time from the tracking settings.\n\n" +
                "Location data is collected even when the app is closed or not in use, " +
                "to keep your family updated on your whereabouts."
            )
            .setPositiveButton("Continue") { _, _ ->
                // User acknowledged disclosure - now request permission
                requestForegroundLocation()
            }
            .setNegativeButton("Not Now") { dialog, _ ->
                dialog.dismiss()
                Toast.makeText(
                    this,
                    "You can enable location sharing later from tracking settings",
                    Toast.LENGTH_LONG
                ).show()
            }
            .setCancelable(false)
            .show()
    }

    /**
     * BACKGROUND LOCATION DISCLOSURE (Required by Google Play for Android 10+)
     *
     * Separate disclosure explaining why background/always-on location is needed.
     */
    private fun showBackgroundLocationDisclosure() {
        AlertDialog.Builder(this)
            .setTitle("Always-On Location Needed")
            .setMessage(
                "To keep your family updated even when the app is closed, " +
                "Relatives needs the \"Allow all the time\" location permission.\n\n" +
                "This enables:\n" +
                "- Continuous location sharing with your family\n" +
                "- Arrival and departure alerts for saved places\n" +
                "- Location updates when you're driving or on the move\n\n" +
                "Battery impact is minimal - the app only tracks when you're moving " +
                "and uses low-power mode when you're stationary.\n\n" +
                "Please select \"Allow all the time\" on the next screen."
            )
            .setPositiveButton("Continue") { _, _ ->
                requestBackgroundLocation()
            }
            .setNegativeButton("Skip") { dialog, _ ->
                dialog.dismiss()
                Toast.makeText(
                    this,
                    "Tracking will only work while the app is open. You can change this in Settings.",
                    Toast.LENGTH_LONG
                ).show()
                requestNotificationPermission()
            }
            .setCancelable(false)
            .show()
    }

    /**
     * Request foreground location permission (after disclosure shown).
     */
    private fun requestForegroundLocation() {
        locationPermissionLauncher.launch(
            arrayOf(
                Manifest.permission.ACCESS_FINE_LOCATION,
                Manifest.permission.ACCESS_COARSE_LOCATION
            )
        )
    }

    /**
     * Request background location permission (after background disclosure shown).
     */
    private fun requestBackgroundLocation() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_BACKGROUND_LOCATION)
                != PackageManager.PERMISSION_GRANTED
            ) {
                backgroundLocationLauncher.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
            } else {
                requestNotificationPermission()
            }
        }
    }

    /**
     * Request notification permission (Android 13+).
     */
    private fun requestNotificationPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
                != PackageManager.PERMISSION_GRANTED
            ) {
                notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
            }
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
