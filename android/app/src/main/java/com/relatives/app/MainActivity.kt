package com.relatives.app

import android.Manifest
import android.annotation.SuppressLint
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.util.Log
import android.webkit.PermissionRequest
import android.webkit.WebChromeClient
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import com.relatives.app.tracking.TrackingLocationService
import com.relatives.app.webview.WebViewBridge

/**
 * Main activity hosting the WebView with JavaScript bridge.
 *
 * PERMISSION FLOW (fixed):
 * - No permissions are requested at launch
 * - Location permissions are requested only when user taps "Start Tracking"
 * - Mic permission is requested only when user taps voice button
 * - After permission grant, the requested action starts immediately (no second tap)
 * - Disclosure dialogs are shown before each OS permission request
 */
class MainActivity : AppCompatActivity() {

    companion object {
        private const val TAG = "MainActivity"
        private const val BASE_URL = "https://relatives.app"
    }

    private lateinit var webView: WebView
    private lateinit var bridge: WebViewBridge

    // ============ PERMISSION LAUNCHERS ============

    /**
     * Foreground location permission result.
     * On grant: either start tracking (foreground-only) or show background disclosure.
     * On deny: show message, do NOT start tracking.
     */
    private val foregroundLocationLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { permissions ->
        val fineGranted = permissions[Manifest.permission.ACCESS_FINE_LOCATION] ?: false
        val coarseGranted = permissions[Manifest.permission.ACCESS_COARSE_LOCATION] ?: false

        Log.d(TAG, "Foreground permission result: fine=$fineGranted, coarse=$coarseGranted")

        if (fineGranted || coarseGranted) {
            // Foreground granted - request background if needed (Android 10+)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                showBackgroundLocationDisclosure()
            } else {
                // Pre-Android 10: foreground is enough, start tracking now
                onAllLocationPermissionsHandled()
            }
        } else {
            Log.d(TAG, "Foreground location denied by user")
            Toast.makeText(this, "Location permission required for tracking", Toast.LENGTH_LONG).show()
            // Do NOT start tracking - user denied
        }
    }

    /**
     * Background location permission result.
     * On grant: start tracking immediately with background capability.
     * On deny: start tracking in foreground-only mode (still works).
     */
    private val backgroundLocationLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        Log.d(TAG, "Background permission result: granted=$granted")

        if (!granted) {
            Log.d(TAG, "Background location denied - tracking will work foreground-only")
            Toast.makeText(this, "Tracking will work when app is open. Enable 'Allow all the time' for background tracking.", Toast.LENGTH_LONG).show()
        }

        // Start tracking regardless - foreground-only is still useful
        onAllLocationPermissionsHandled()
    }

    /**
     * Notification permission result (Android 13+).
     * Requested as part of tracking flow - tracking works without it.
     */
    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        Log.d(TAG, "Notification permission result: granted=$granted")
        // Continue starting the tracking service regardless
        startTrackingService()
    }

    /**
     * Mic (RECORD_AUDIO) permission result.
     * On grant: start voice capture immediately.
     * On deny: show message (no crash).
     */
    private val micPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        Log.d(TAG, "Mic permission result: granted=$granted")

        if (granted) {
            Log.d(TAG, "Mic granted - starting voice capture")
            onMicPermissionGranted()
        } else {
            Log.d(TAG, "Mic denied by user")
            Toast.makeText(this, "Microphone permission is required for voice input", Toast.LENGTH_LONG).show()
        }
    }

    // ============ LIFECYCLE ============

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        Log.d(TAG, "onCreate")

        webView = WebView(this)
        setContentView(webView)

        bridge = WebViewBridge(this)

        setupWebView()

        // NO permissions requested here - only on user action
        Log.d(TAG, "No permission prompts at launch")

        webView.loadUrl(BASE_URL)
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

        // Handle WebView permission requests (e.g., mic for WebRTC/SpeechRecognition)
        webView.webChromeClient = object : WebChromeClient() {
            override fun onPermissionRequest(request: PermissionRequest) {
                Log.d(TAG, "WebChromeClient onPermissionRequest: ${request.resources.joinToString()}")
                // Handle audio permission requests from web content
                val resources = request.resources
                if (resources.contains(PermissionRequest.RESOURCE_AUDIO_CAPTURE)) {
                    // Check if mic permission is already granted
                    if (hasMicPermission()) {
                        Log.d(TAG, "Mic already granted, granting WebView audio")
                        request.grant(resources)
                    } else {
                        Log.d(TAG, "Mic not granted, requesting via native flow")
                        // Store the request and ask for mic permission
                        pendingWebPermissionRequest = request
                        requestMicPermission()
                    }
                } else {
                    request.deny()
                }
            }
        }
    }

    // Store pending WebView permission request for mic
    private var pendingWebPermissionRequest: PermissionRequest? = null

    // ============ TRACKING PERMISSION FLOW ============

    /**
     * Called from WebViewBridge.startTracking().
     * This is the ENTRY POINT for the tracking permission flow.
     *
     * Flow:
     * 1. If location already granted -> skip to step 4
     * 2. Show foreground disclosure dialog
     * 3. On "Continue" -> request OS foreground location permission
     * 4. If foreground granted + Android 10+ -> show background disclosure
     * 5. On "Continue" -> request OS background location permission
     * 6. Request notification permission if needed (Android 13+)
     * 7. Start tracking service
     */
    fun requestTrackingPermissions() {
        Log.d(TAG, "requestTrackingPermissions() entered")

        // Check if foreground location is already granted
        if (hasForegroundLocationPermission()) {
            Log.d(TAG, "Foreground location already granted")

            // Check if background is needed and not granted
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q && !hasBackgroundLocationPermission()) {
                showBackgroundLocationDisclosure()
            } else {
                // All location permissions already granted
                onAllLocationPermissionsHandled()
            }
            return
        }

        // Need foreground permission - show disclosure first
        showForegroundLocationDisclosure()
    }

    /**
     * Foreground location disclosure dialog.
     * Non-cancelable - user must tap Continue or Not Now.
     */
    private fun showForegroundLocationDisclosure() {
        Log.d(TAG, "Showing foreground location disclosure")

        AlertDialog.Builder(this)
            .setTitle("Location Permission Required")
            .setMessage(
                "Relatives needs access to your location to share it with your family members. " +
                "This enables real-time tracking so your family can see where you are."
            )
            .setCancelable(false)
            .setPositiveButton("Continue") { dialog, _ ->
                dialog.dismiss()
                Log.d(TAG, "Foreground disclosure: user tapped Continue")
                // Request OS permission immediately on same tap
                Log.d(TAG, "Requesting foreground OS location permission")
                foregroundLocationLauncher.launch(
                    arrayOf(
                        Manifest.permission.ACCESS_FINE_LOCATION,
                        Manifest.permission.ACCESS_COARSE_LOCATION
                    )
                )
            }
            .setNegativeButton("Not Now") { dialog, _ ->
                dialog.dismiss()
                Log.d(TAG, "Foreground disclosure: user tapped Not Now")
                // Do nothing - no OS prompt, no service start
            }
            .create()
            .apply {
                setCanceledOnTouchOutside(false)
            }
            .show()
    }

    /**
     * Background location disclosure dialog.
     * Shown only after foreground is granted (Android 10+).
     * Non-cancelable - user must tap Continue or Skip.
     */
    private fun showBackgroundLocationDisclosure() {
        Log.d(TAG, "Showing background location disclosure")

        AlertDialog.Builder(this)
            .setTitle("Background Location")
            .setMessage(
                "To keep tracking your location when the app is in the background or your " +
                "phone is locked, please select \"Allow all the time\" on the next screen.\n\n" +
                "You can skip this and tracking will only work while the app is open."
            )
            .setCancelable(false)
            .setPositiveButton("Continue") { dialog, _ ->
                dialog.dismiss()
                Log.d(TAG, "Background disclosure: user tapped Continue")
                // Request OS background permission immediately
                Log.d(TAG, "Requesting background OS location permission")
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                    backgroundLocationLauncher.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
                }
            }
            .setNegativeButton("Skip") { dialog, _ ->
                dialog.dismiss()
                Log.d(TAG, "Background disclosure: user tapped Skip")
                // Continue to start tracking without background permission
                onAllLocationPermissionsHandled()
            }
            .create()
            .apply {
                setCanceledOnTouchOutside(false)
            }
            .show()
    }

    /**
     * Called after all location permission dialogs are handled.
     * Proceeds to notification permission (if needed) then starts tracking.
     */
    private fun onAllLocationPermissionsHandled() {
        Log.d(TAG, "All location permissions handled, checking notification permission")

        // Request notification permission if needed (Android 13+)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
            != PackageManager.PERMISSION_GRANTED
        ) {
            Log.d(TAG, "Requesting notification permission")
            notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
        } else {
            // Start tracking immediately
            startTrackingService()
        }
    }

    /**
     * Actually start the tracking service.
     * Called only after permissions are handled.
     */
    private fun startTrackingService() {
        Log.d(TAG, "Starting tracking service")
        TrackingLocationService.startTracking(this)
        Log.d(TAG, "Tracking service start requested")
    }

    // ============ MIC PERMISSION FLOW ============

    /**
     * Called from WebViewBridge.startVoice().
     * Requests mic permission with disclosure, then starts voice capture.
     */
    fun requestMicPermission() {
        Log.d(TAG, "requestMicPermission() entered")

        if (hasMicPermission()) {
            Log.d(TAG, "Mic already granted")
            onMicPermissionGranted()
            return
        }

        // Show mic disclosure
        Log.d(TAG, "Showing mic permission disclosure")
        AlertDialog.Builder(this)
            .setTitle("Microphone Permission")
            .setMessage(
                "Relatives needs microphone access for voice input. " +
                "Your audio is only used for speech recognition and is not recorded or stored."
            )
            .setCancelable(false)
            .setPositiveButton("Continue") { dialog, _ ->
                dialog.dismiss()
                Log.d(TAG, "Mic disclosure: user tapped Continue")
                Log.d(TAG, "Requesting mic OS permission")
                micPermissionLauncher.launch(Manifest.permission.RECORD_AUDIO)
            }
            .setNegativeButton("Not Now") { dialog, _ ->
                dialog.dismiss()
                Log.d(TAG, "Mic disclosure: user tapped Not Now")
                pendingWebPermissionRequest?.deny()
                pendingWebPermissionRequest = null
            }
            .create()
            .apply {
                setCanceledOnTouchOutside(false)
            }
            .show()
    }

    /**
     * Called when mic permission is confirmed granted.
     * Grants any pending WebView audio permission request, then signals JS.
     */
    private fun onMicPermissionGranted() {
        Log.d(TAG, "onMicPermissionGranted - granting pending WebView request and notifying JS")

        // Grant pending WebView permission request if any
        pendingWebPermissionRequest?.let { request ->
            request.grant(request.resources)
            pendingWebPermissionRequest = null
        }

        // Notify JavaScript that mic is ready
        webView.post {
            webView.evaluateJavascript(
                "if(window.SuziVoice && window.SuziVoice.open) { window.SuziVoice.open(); }",
                null
            )
        }
        Log.d(TAG, "Voice capture started via JS callback")
    }

    // ============ PERMISSION CHECKS ============

    private fun hasForegroundLocationPermission(): Boolean {
        val fine = ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
        val coarse = ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION)
        return fine == PackageManager.PERMISSION_GRANTED || coarse == PackageManager.PERMISSION_GRANTED
    }

    private fun hasBackgroundLocationPermission(): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ContextCompat.checkSelfPermission(
                this, Manifest.permission.ACCESS_BACKGROUND_LOCATION
            ) == PackageManager.PERMISSION_GRANTED
        } else {
            true // Not needed pre-Q
        }
    }

    private fun hasMicPermission(): Boolean {
        return ContextCompat.checkSelfPermission(
            this, Manifest.permission.RECORD_AUDIO
        ) == PackageManager.PERMISSION_GRANTED
    }

    // ============ NAVIGATION ============

    @Suppress("DEPRECATION")
    override fun onBackPressed() {
        if (webView.canGoBack()) {
            webView.goBack()
        } else {
            super.onBackPressed()
        }
    }

    override fun onDestroy() {
        webView.removeJavascriptInterface(WebViewBridge.INTERFACE_NAME)
        webView.destroy()
        super.onDestroy()
    }
}
