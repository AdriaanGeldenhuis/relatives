package za.co.relatives.app

import android.Manifest
import android.app.Activity
import android.annotation.SuppressLint
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.net.Uri
import android.net.http.SslError
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
import androidx.compose.ui.graphics.Color
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

class MainActivity : ComponentActivity() {

    private val requiredPermissions = mutableListOf(
        Manifest.permission.ACCESS_FINE_LOCATION,
        Manifest.permission.ACCESS_COARSE_LOCATION,
        Manifest.permission.RECORD_AUDIO
    ).apply {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            add(Manifest.permission.POST_NOTIFICATIONS)
        }
    }.toTypedArray()

    private var permissionsGranted by mutableStateOf(false)
    private var showBackgroundRationale by mutableStateOf(false)

    // State for Subscription Banner
    private var showTrialBanner by mutableStateOf(false)
    private var trialEndDate by mutableStateOf("")

    // WebView reference for navigation from notifications
    private var webViewRef: WebView? = null
    private var pendingUrl by mutableStateOf<String?>(null)

    private val permissionsLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { result ->
        val allGranted = result.values.all { it }
        if (allGranted) {
            checkBackgroundPermission()
        } else {
            permissionsGranted = true 
        }
    }

    private val backgroundPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) {
        permissionsGranted = true
        startTrackingService()
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

        if (hasRequiredPermissions()) {
            PreferencesManager.setTrackingEnabled(true) // FORCE ENABLE
            checkBackgroundPermission()
        } else {
            permissionsLauncher.launch(requiredPermissions)
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
                        if (permissionsGranted) {
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
                                            if (url.contains("/tracking/")) {
                                                startTrackingService()
                                            }
                                        }
                                    )
                                }
                            }
                        } else if (showBackgroundRationale) {
                            BackgroundPermissionRationale {
                                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                                    backgroundPermissionLauncher.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
                                } else {
                                    permissionsGranted = true
                                    startTrackingService()
                                }
                                showBackgroundRationale = false
                            }
                        } else {
                            Box(contentAlignment = Alignment.Center, modifier = Modifier.fillMaxSize()) {
                                CircularProgressIndicator()
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

    private fun hasRequiredPermissions(): Boolean {
        return requiredPermissions.all {
            ContextCompat.checkSelfPermission(this, it) == PackageManager.PERMISSION_GRANTED
        }
    }

    private fun checkBackgroundPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            val hasBackground = ContextCompat.checkSelfPermission(
                this, Manifest.permission.ACCESS_BACKGROUND_LOCATION
            ) == PackageManager.PERMISSION_GRANTED
            
            if (hasBackground) {
                permissionsGranted = true
                startTrackingService()
            } else {
                showBackgroundRationale = true
            }
        } else {
            permissionsGranted = true
            startTrackingService()
        }
    }

    private fun startTrackingService() {
        if (PreferencesManager.isTrackingEnabled()) {
            val intent = Intent(this, TrackingLocationService::class.java).apply {
                action = TrackingLocationService.ACTION_START_TRACKING
            }
            startForegroundService(intent)
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

@Composable
fun BackgroundPermissionRationale(onContinue: () -> Unit) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Text(
            text = "Always-On Location Needed",
            style = MaterialTheme.typography.headlineSmall
        )
        Spacer(modifier = Modifier.height(16.dp))
        Text(
            text = "To keep your family updated even when the app is closed, please select 'Allow all the time' in the next screen."
        )
        Spacer(modifier = Modifier.height(24.dp))
        Button(onClick = onContinue) {
            Text("Understand")
        }
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
