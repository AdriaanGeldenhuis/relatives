package za.co.relatives.app

import android.Manifest
import android.app.Activity
import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.util.Log
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
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.BackHandler
import za.co.relatives.app.network.ApiClient
import za.co.relatives.app.services.TrackingLocationService
import za.co.relatives.app.ui.SubscriptionActivity
import za.co.relatives.app.ui.TrackingJsInterface
import za.co.relatives.app.ui.VoiceAssistantBridge
import za.co.relatives.app.ui.theme.RelativesTheme
import za.co.relatives.app.utils.PreferencesManager

// Safe extension to get Activity from Context
fun Context.findActivity(): Activity? {
    var context = this
    while (context is android.content.ContextWrapper) {
        if (context is Activity) return context
        context = context.baseContext
    }
    return null
}

class MainActivity : ComponentActivity() {

    private val BASE_URL = "https://www.relatives.co.za"

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
    
    private var showTrialBanner by mutableStateOf(false)
    private var trialEndDate by mutableStateOf("")
    private var isLocked by mutableStateOf(false)
    
    private val webView = mutableStateOf<WebView?>(null)

    private var showBatteryOptimizationDialog by mutableStateOf(false)

    private val permissionsLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { result ->
        if (result.values.all { it }) {
            checkBackgroundPermission()
        } else {
            // Handle permission denial gracefully if needed
            permissionsGranted = true // Allow app to proceed even with some permissions denied
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
        
        hideSystemBars()

        if (hasRequiredPermissions()) {
            permissionsGranted = true
            startTrackingService()
            checkBatteryOptimization()
        } else {
            permissionsLauncher.launch(requiredPermissions)
        }
        
        setContent {
            RelativesTheme {
                MainScreen()
            }
        }
    }

    override fun onResume() {
        super.onResume()
        checkSubscription()
    }
    
    @Composable
    private fun MainScreen() {
        val context = LocalContext.current
        var initialUrl = "https://www.relatives.co.za"
        intent.getStringExtra("open_url")?.let { initialUrl = it }

        if (showBatteryOptimizationDialog) {
            BatteryOptimizationDialog(
                onDismiss = {
                    PreferencesManager.setBatteryDialogDismissed()
                    showBatteryOptimizationDialog = false
                },
                onConfirm = {
                    PreferencesManager.setBatteryDialogDismissed()  // Don't show again after clicking Open Settings
                    showBatteryOptimizationDialog = false
                    requestIgnoreBatteryOptimizations()
                }
            )
        }

        Surface(modifier = Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
            Box(modifier = Modifier.fillMaxSize()) {
                if (permissionsGranted) {
                    Column(modifier = Modifier.fillMaxSize()) {
                        AnimatedVisibility(visible = showTrialBanner) {
                            TrialBanner(endDate = trialEndDate) {
                                startActivity(Intent(context, SubscriptionActivity::class.java))
                            }
                        }
                        WebViewScreen(
                            initialUrl = initialUrl,
                            onWebViewReady = { webView.value = it },
                            onPageTrackingCheck = { url ->
                                if (url.contains("/tracking/")) {
                                    startTrackingService()
                                }
                            },
                            onPageFinishedForToken = ::fetchSessionToken
                        )
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

                if (isLocked) {
                    LockedOverlay {
                        startActivity(Intent(context, SubscriptionActivity::class.java))
                    }
                }
            }
        }
    }

    private fun fetchSessionToken() {
        val cookieManager = android.webkit.CookieManager.getInstance()
        val cookies = cookieManager.getCookie(BASE_URL) ?: return

        Thread {
            try {
                val url = java.net.URL("$BASE_URL/api/session-token.php")
                val connection = url.openConnection() as java.net.HttpURLConnection
                connection.requestMethod = "GET"
                connection.setRequestProperty("Cookie", cookies)
                connection.connectTimeout = 10000
                connection.readTimeout = 10000

                if (connection.responseCode == 200) {
                    val response = connection.inputStream.bufferedReader().readText()
                    val json = org.json.JSONObject(response)
                    if (json.optBoolean("success")) {
                        val token = json.optString("session_token")
                        if (token.isNotBlank()) {
                            PreferencesManager.sessionToken = token
                            Log.d("MainActivity", "Session token saved successfully")
                            // Auto-start tracking now that we have a token
                            runOnUiThread { startTrackingService() }
                            // Sync FCM token to server for push notifications
                            syncFcmTokenToServer(cookies)
                        }
                    }
                }
                connection.disconnect()
            } catch (e: Exception) {
                Log.e("MainActivity", "Failed to fetch session token", e)
            }
        }.start()
    }

    /**
     * Sync FCM token to server for push notifications
     * Called after successful session token fetch
     */
    private fun syncFcmTokenToServer(cookies: String) {
        try {
            val fcmPrefs = getSharedPreferences("fcm_prefs", Context.MODE_PRIVATE)
            val fcmToken = fcmPrefs.getString("fcm_token", null)
            val needsSync = fcmPrefs.getBoolean("token_needs_sync", true)

            if (fcmToken.isNullOrBlank()) {
                Log.d("MainActivity", "No FCM token to sync")
                return
            }

            // Always sync on app start to ensure server has current token
            Log.d("MainActivity", "Syncing FCM token to server...")

            val url = java.net.URL("$BASE_URL/notifications/api/preferences.php")
            val connection = url.openConnection() as java.net.HttpURLConnection
            connection.requestMethod = "POST"
            connection.setRequestProperty("Cookie", cookies)
            connection.setRequestProperty("Content-Type", "application/x-www-form-urlencoded")
            connection.doOutput = true
            connection.connectTimeout = 10000
            connection.readTimeout = 10000

            val postData = "action=register_fcm_token&token=${java.net.URLEncoder.encode(fcmToken, "UTF-8")}&device_type=android"
            connection.outputStream.bufferedWriter().use { it.write(postData) }

            if (connection.responseCode == 200) {
                val response = connection.inputStream.bufferedReader().readText()
                val json = org.json.JSONObject(response)
                if (json.optBoolean("success")) {
                    Log.d("MainActivity", "FCM token synced successfully")
                    fcmPrefs.edit().putBoolean("token_needs_sync", false).apply()
                } else {
                    Log.e("MainActivity", "FCM sync failed: ${json.optString("error")}")
                }
            } else {
                Log.e("MainActivity", "FCM sync HTTP error: ${connection.responseCode}")
            }
            connection.disconnect()
        } catch (e: Exception) {
            Log.e("MainActivity", "Failed to sync FCM token", e)
        }
    }

    private fun checkBatteryOptimization() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            val manufacturer = Build.MANUFACTURER.lowercase()
            val isAggressiveOEM = manufacturer.contains("huawei") ||
                                  manufacturer.contains("honor") ||
                                  manufacturer.contains("xiaomi") ||
                                  manufacturer.contains("redmi") ||
                                  manufacturer.contains("oppo") ||
                                  manufacturer.contains("realme") ||
                                  manufacturer.contains("vivo") ||
                                  manufacturer.contains("oneplus")

            val powerManager = getSystemService(Context.POWER_SERVICE) as android.os.PowerManager
            val isIgnoringBattery = powerManager.isIgnoringBatteryOptimizations(packageName)

            // For aggressive OEMs (Huawei, Xiaomi, etc.), the standard check is not enough
            // They have additional layers (App Launch, Protected Apps) that need manual setup
            // So we show the dialog even if Android says we're ignoring battery optimization
            val shouldShow = if (isAggressiveOEM) {
                // For aggressive OEMs: show on first launch, then respect the 7-day snooze
                PreferencesManager.shouldShowBatteryDialog()
            } else {
                // For other devices: only show if not ignoring battery optimization
                !isIgnoringBattery && PreferencesManager.shouldShowBatteryDialog()
            }

            if (shouldShow) {
                Log.d("MainActivity", "Showing battery dialog (manufacturer: $manufacturer, isIgnoring: $isIgnoringBattery, isAggressiveOEM: $isAggressiveOEM)")
                showBatteryOptimizationDialog = true
            }
        }
    }

    private fun requestIgnoreBatteryOptimizations() {
        val manufacturer = Build.MANUFACTURER.lowercase()
        Log.d("MainActivity", "Device manufacturer: $manufacturer")

        // Build list of intents to try (manufacturer-specific first, then standard)
        val intentsToTry = mutableListOf<Intent>()

        // Manufacturer-specific battery settings
        when {
            manufacturer.contains("samsung") -> {
                intentsToTry.add(Intent().setClassName("com.samsung.android.lool", "com.samsung.android.sm.battery.ui.BatteryActivity"))
                intentsToTry.add(Intent().setClassName("com.samsung.android.sm", "com.samsung.android.sm.battery.ui.BatteryActivity"))
            }
            manufacturer.contains("xiaomi") || manufacturer.contains("redmi") -> {
                intentsToTry.add(Intent().setClassName("com.miui.powerkeeper", "com.miui.powerkeeper.ui.HiddenAppsConfigActivity"))
                intentsToTry.add(Intent().setClassName("com.miui.securitycenter", "com.miui.permcenter.autostart.AutoStartManagementActivity"))
            }
            manufacturer.contains("huawei") || manufacturer.contains("honor") -> {
                // Huawei has MULTIPLE settings that ALL need to be enabled for background apps to work:
                // 1. App Launch Manager (Auto-start) - CRITICAL
                intentsToTry.add(Intent().setClassName("com.huawei.systemmanager", "com.huawei.systemmanager.startupmgr.ui.StartupNormalAppListActivity"))
                intentsToTry.add(Intent().setClassName("com.huawei.systemmanager", "com.huawei.systemmanager.appcontrol.activity.StartupAppControlActivity"))
                // 2. Protected Apps (older EMUI)
                intentsToTry.add(Intent().setClassName("com.huawei.systemmanager", "com.huawei.systemmanager.optimize.process.ProtectActivity"))
                // 3. Battery Optimization / Power-intensive apps
                intentsToTry.add(Intent().setClassName("com.huawei.systemmanager", "com.huawei.systemmanager.power.ui.HwPowerManagerActivity"))
                intentsToTry.add(Intent().setClassName("com.huawei.systemmanager", "com.huawei.systemmanager.power.ui.HwBatteryDetailActivity"))
                // 4. Lock screen cleanup (EMUI 10+)
                intentsToTry.add(Intent().setClassName("com.huawei.systemmanager", "com.huawei.systemmanager.optimize.bootstart.BootStartActivity"))
                // 5. HarmonyOS 2.0+ specific
                intentsToTry.add(Intent().setClassName("com.huawei.systemmanager", "com.huawei.systemmanager.mainscreen.MainScreenActivity"))
                // 6. General app settings as fallback
                intentsToTry.add(Intent().setClassName("com.huawei.systemmanager", "com.huawei.systemmanager.optimize.process.ProtectActivity"))
            }
            manufacturer.contains("oppo") || manufacturer.contains("realme") -> {
                // Oppo and Realme both use ColorOS
                intentsToTry.add(Intent().setClassName("com.coloros.safecenter", "com.coloros.safecenter.permission.startup.StartupAppListActivity"))
                intentsToTry.add(Intent().setClassName("com.coloros.safecenter", "com.coloros.safecenter.startupapp.StartupAppListActivity"))
                intentsToTry.add(Intent().setClassName("com.oppo.safe", "com.oppo.safe.permission.startup.StartupAppListActivity"))
                intentsToTry.add(Intent().setClassName("com.coloros.oppoguardelf", "com.coloros.powermanager.fuelga498.PowerUsageModelActivity"))
            }
            manufacturer.contains("vivo") -> {
                intentsToTry.add(Intent().setClassName("com.vivo.permissionmanager", "com.vivo.permissionmanager.activity.BgStartUpManagerActivity"))
                intentsToTry.add(Intent().setClassName("com.iqoo.secure", "com.iqoo.secure.ui.phoneoptimize.AddWhiteListActivity"))
            }
            manufacturer.contains("oneplus") -> {
                intentsToTry.add(Intent().setClassName("com.oneplus.security", "com.oneplus.security.chainlaunch.view.ChainLaunchAppListActivity"))
            }
        }

        // Standard Android intents (work on most devices)
        intentsToTry.add(Intent(android.provider.Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
            data = android.net.Uri.parse("package:$packageName")
        })
        intentsToTry.add(Intent(android.provider.Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
        intentsToTry.add(Intent(android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
            data = android.net.Uri.parse("package:$packageName")
        })
        intentsToTry.add(Intent(android.provider.Settings.ACTION_SETTINGS))

        // Try each intent until one works
        for (intent in intentsToTry) {
            try {
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                if (intent.resolveActivity(packageManager) != null) {
                    Log.d("MainActivity", "Starting intent: ${intent.component ?: intent.action}")
                    startActivity(intent)
                    return
                }
            } catch (e: Exception) {
                Log.w("MainActivity", "Intent failed: ${intent.component ?: intent.action}", e)
            }
        }

        // If nothing worked, show a helpful message
        Log.e("MainActivity", "No battery settings intent worked")
        android.widget.Toast.makeText(
            this,
            "Please go to Settings > Apps > Relatives > Battery and select 'Unrestricted'",
            android.widget.Toast.LENGTH_LONG
        ).show()
    }


    private fun checkSubscription() {
        val familyId = PreferencesManager.getDeviceUuid()
        if (familyId.isBlank()) return

        ApiClient.getSubscriptionStatus(familyId) { status ->
            runOnUiThread {
                if (status != null) {
                    applySubscriptionStatus(status)
                }
            }
        }
    }
    
    private fun applySubscriptionStatus(status: ApiClient.SubscriptionStatus) {
        when (status.status) {
            "active" -> {
                isLocked = false
                showTrialBanner = false
                injectJsLock(false)
            }
            "trial" -> {
                isLocked = false
                showTrialBanner = true
                trialEndDate = status.trial_ends_at ?: "soon"
                injectJsLock(false)
            }
            "locked", "expired", "cancelled" -> {
                isLocked = true
                showTrialBanner = false
                injectJsLock(true)
            }
        }
    }

    private fun injectJsLock(locked: Boolean) {
        webView.value?.evaluateJavascript("window.RELATIVES_SUBSCRIPTION_LOCKED = $locked;") {}
    }

    private fun hasRequiredPermissions(): Boolean {
        return requiredPermissions.all {
            ContextCompat.checkSelfPermission(this, it) == PackageManager.PERMISSION_GRANTED
        }
    }

    private fun checkBackgroundPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_BACKGROUND_LOCATION) != PackageManager.PERMISSION_GRANTED) {
            showBackgroundRationale = true
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
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                startForegroundService(intent)
            } else {
                startService(intent)
            }
        }
    }

    private fun hideSystemBars() {
        val windowInsetsController = WindowCompat.getInsetsController(window, window.decorView)
        windowInsetsController.systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
        windowInsetsController.hide(WindowInsetsCompat.Type.systemBars())
    }
}

@Composable
fun BatteryOptimizationDialog(onConfirm: () -> Unit, onDismiss: () -> Unit) {
    val manufacturer = Build.MANUFACTURER.lowercase()
    val isHuawei = manufacturer.contains("huawei") || manufacturer.contains("honor")
    val isXiaomi = manufacturer.contains("xiaomi") || manufacturer.contains("redmi")
    val isSamsung = manufacturer.contains("samsung")
    val isOppo = manufacturer.contains("oppo") || manufacturer.contains("realme")
    val isVivo = manufacturer.contains("vivo")
    val isOnePlus = manufacturer.contains("oneplus")

    val title = when {
        isHuawei -> "Huawei Battery Settings Required"
        isXiaomi -> "Xiaomi Battery Settings Required"
        isOnePlus -> "OnePlus Battery Settings Required"
        else -> "Battery Optimization"
    }

    val instructions = when {
        isHuawei -> """
            |Huawei devices have aggressive battery management. For reliable tracking:
            |
            |1. Tap 'Open Settings' below
            |2. Find 'Relatives' and set to 'Manage manually'
            |3. Enable ALL three toggles:
            |   • Auto-launch
            |   • Secondary launch
            |   • Run in background
            |
            |Also recommended:
            |• Settings > Battery > App launch > Relatives > Manage manually
            |• Lock app in recent apps (swipe down on app card)
        """.trimMargin()
        isXiaomi -> """
            |Xiaomi devices need special permissions for background tracking:
            |
            |1. Tap 'Open Settings' below
            |2. Enable 'Autostart' for Relatives
            |3. Go to Settings > Battery > Relatives
            |4. Set 'No restrictions'
            |
            |Also: Lock app in recent apps (long press > lock)
        """.trimMargin()
        isSamsung -> """
            |For reliable tracking on Samsung:
            |
            |1. Tap 'Open Settings' below
            |2. Set battery usage to 'Unrestricted'
            |3. Also check: Settings > Device care > Battery > Background usage limits
        """.trimMargin()
        isOppo || isVivo -> """
            |For reliable tracking:
            |
            |1. Tap 'Open Settings' below
            |2. Enable 'Auto-start' for Relatives
            |3. Set battery to 'No restrictions'
            |4. Lock app in recent apps
        """.trimMargin()
        isOnePlus -> """
            |For reliable tracking on OnePlus:
            |
            |1. Tap 'Open Settings' below
            |2. Enable 'Auto-launch' for Relatives
            |3. Go to Settings > Battery > Battery optimization
            |4. Set Relatives to 'Don't optimize'
            |
            |Also: Lock app in recent apps (long press > lock)
        """.trimMargin()
        else -> "For reliable location tracking, please disable battery optimization for this app."
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Text(
                text = instructions,
                style = androidx.compose.ui.text.TextStyle(
                    fontSize = if (isHuawei || isXiaomi || isOnePlus) 14.sp else 16.sp
                )
            )
        },
        confirmButton = {
            Button(onClick = onConfirm) {
                Text("Open Settings")
            }
        },
        dismissButton = {
            Button(onClick = onDismiss) {
                Text("Later")
            }
        }
    )
}

@Composable
fun LockedOverlay(onManageSubscription: () -> Unit) {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Color.Black.copy(alpha = 0.8f))
            .clickable(enabled = false, onClick = {}), // Block clicks
        contentAlignment = Alignment.Center
    ) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier.padding(32.dp)
        ) {
            Icon(
                imageVector = Icons.Default.Lock,
                contentDescription = "Locked",
                tint = Color.White,
                modifier = Modifier.size(64.dp)
            )
            Spacer(modifier = Modifier.height(16.dp))
            Text(
                text = "Your trial has ended",
                style = MaterialTheme.typography.headlineMedium,
                color = Color.White
            )
            Text(
                text = "You are in view-only mode.",
                style = MaterialTheme.typography.bodyLarge,
                color = Color.White.copy(alpha = 0.8f),
                textAlign = TextAlign.Center
            )
            Spacer(modifier = Modifier.height(24.dp))
            Button(onClick = onManageSubscription) {
                Text("Manage Subscription")
            }
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
            Text("Free Trial Active", style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.onTertiaryContainer)
            Text("Ends: $endDate. Tap to upgrade.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onTertiaryContainer)
        }
        Icon(Icons.Default.ArrowForward, "Upgrade", tint = MaterialTheme.colorScheme.onTertiaryContainer)
    }
}

@Composable
fun BackgroundPermissionRationale(onContinue: () -> Unit) {
    Column(
        modifier = Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Text("Always-On Location Needed", style = MaterialTheme.typography.headlineSmall)
        Spacer(Modifier.height(16.dp))
        Text("To keep your family updated even when the app is closed, please select 'Allow all the time' in the next screen.")
        Spacer(Modifier.height(24.dp))
        Button(onClick = onContinue) { Text("Understand") }
    }
}

@SuppressLint("SetJavaScriptEnabled")
@Composable
fun WebViewScreen(
    initialUrl: String,
    onWebViewReady: (WebView) -> Unit,
    onPageTrackingCheck: (String) -> Unit,
    onPageFinishedForToken: () -> Unit
) {
    val context = LocalContext.current
    val activity = context.findActivity() ?: return
    var uploadMessageCallback by remember { mutableStateOf<ValueCallback<Array<Uri>>?>(null) }

    val webView = remember {
        WebView(context).also(onWebViewReady)
    }

    val voiceAssistantBridge = remember(context, webView) {
        VoiceAssistantBridge(activity, webView)
    }

    DisposableEffect(voiceAssistantBridge) {
        onDispose { voiceAssistantBridge.cleanup() }
    }

    // Handle back button - navigate back in WebView instead of exiting app
    BackHandler(enabled = true) {
        if (webView.canGoBack()) {
            webView.goBack()
        } else {
            // If can't go back, let the system handle it (exit app)
            activity.moveTaskToBack(true)
        }
    }

    val fileChooserLauncher = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        val uris = WebChromeClient.FileChooserParams.parseResult(result.resultCode, result.data)
        uploadMessageCallback?.onReceiveValue(uris)
        uploadMessageCallback = null
    }

    AndroidView(factory = {
        webView.apply {
            layoutParams = ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
            CookieManager.getInstance().setAcceptThirdPartyCookies(this, true)
            settings.apply {
                javaScriptEnabled = true
                domStorageEnabled = true
                mixedContentMode = WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE
                userAgentString += " RelativesAndroidApp"
                allowFileAccess = true
                allowContentAccess = true
            }
            
            addJavascriptInterface(TrackingJsInterface(context.applicationContext), "Android")
            addJavascriptInterface(voiceAssistantBridge, "AndroidVoice")

            webViewClient = object : WebViewClient() {
                override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                    val url = request?.url ?: return false
                    if (url.scheme == "relatives" && url.host == "subscription") {
                        context.startActivity(Intent(context, SubscriptionActivity::class.java))
                        return true
                    }
                    return false
                }

                override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
                    super.onPageStarted(view, url, favicon)
                    url?.let(onPageTrackingCheck)
                }

                override fun onPageFinished(view: WebView?, url: String?) {
                    super.onPageFinished(view, url)
                    // Hide loading screen after page loads
                    view?.evaluateJavascript("""
                        (function() {
                            var loader = document.getElementById('appLoader');
                            if (loader && !loader.classList.contains('hidden')) {
                                loader.classList.add('hidden');
                            }
                        })();
                    """.trimIndent(), null)
                    url?.let { pageUrl ->
                        // Fetch session token after login pages
                        if (pageUrl.contains("/home") || pageUrl.contains("/tracking") || pageUrl.contains("/dashboard")) {
                            onPageFinishedForToken()
                        }
                    }
                }
            }
            
            webChromeClient = object : WebChromeClient() {
                override fun onShowFileChooser(wv: WebView?, cb: ValueCallback<Array<Uri>>?, params: FileChooserParams?): Boolean {
                    uploadMessageCallback?.onReceiveValue(null)
                    uploadMessageCallback = cb
                    params?.createIntent()?.let {
                        fileChooserLauncher.launch(it)
                    }
                    return true
                }
            }
            
            loadUrl(initialUrl)
        }
    })
}