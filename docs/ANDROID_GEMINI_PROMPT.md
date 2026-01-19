# Android Studio Gemini Prompt: Fix Tracking Service

## Context
This Android app uses a foreground service for location tracking. The backend was just updated to:
1. Accept state-only heartbeat updates (no coords required)
2. Use Bearer token auth (not cookies)
3. Return `server_settings` in response with timing thresholds
4. Properly compute status using `last_seen` (heartbeat) vs `last_fix_at` (location)

The Android code needs updates to work properly with the new backend.

---

## TASK 1: Fetch Session Token After WebView Login

**File: `MainActivity.kt`**

In `onPageFinished()`, after the loader hide code, add session token fetch:

```kotlin
// In onPageFinished(), after loader hide code:
url?.let { pageUrl ->
    // Fetch session token after login pages
    if (pageUrl.contains("/home") || pageUrl.contains("/tracking") || pageUrl.contains("/dashboard")) {
        fetchSessionToken()
    }
}
```

Add this function to MainActivity:

```kotlin
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
                        val prefs = com.relatives.app.tracking.PreferencesManager(this)
                        prefs.sessionToken = token
                        android.util.Log.d("MainActivity", "Session token saved successfully")
                    }
                }
            }
            connection.disconnect()
        } catch (e: Exception) {
            android.util.Log.e("MainActivity", "Failed to fetch session token", e)
        }
    }.start()
}
```

---

## TASK 2: Add Device State Fields to PreferencesManager

**File: `PreferencesManager.kt`**

Add these keys and properties:

```kotlin
// In companion object, add:
const val KEY_IDLE_HEARTBEAT_SECONDS = "idle_heartbeat_seconds"
const val KEY_OFFLINE_THRESHOLD_SECONDS = "offline_threshold_seconds"
const val KEY_STALE_THRESHOLD_SECONDS = "stale_threshold_seconds"

// Add properties:
var idleHeartbeatSeconds: Int
    get() = prefs.getInt(KEY_IDLE_HEARTBEAT_SECONDS, 600)  // Default 10 min
    set(value) = prefs.edit { putInt(KEY_IDLE_HEARTBEAT_SECONDS, value) }

var offlineThresholdSeconds: Int
    get() = prefs.getInt(KEY_OFFLINE_THRESHOLD_SECONDS, 720)  // Default 12 min
    set(value) = prefs.edit { putInt(KEY_OFFLINE_THRESHOLD_SECONDS, value) }

var staleThresholdSeconds: Int
    get() = prefs.getInt(KEY_STALE_THRESHOLD_SECONDS, 3600)  // Default 60 min
    set(value) = prefs.edit { putInt(KEY_STALE_THRESHOLD_SECONDS, value) }
```

---

## TASK 3: Update LocationUploader with Device State & State-Only Uploads

**File: `LocationUploader.kt`**

### 3a. Update the upload URL:
```kotlin
private const val UPLOAD_URL = "https://www.relaty.co.za/tracking/api/update_location.php"
```

### 3b. Add device state detection methods:

```kotlin
private fun getNetworkStatus(): String {
    return try {
        val connectivityManager = context.getSystemService(Context.CONNECTIVITY_SERVICE) as android.net.ConnectivityManager
        val network = connectivityManager.activeNetwork
        val capabilities = connectivityManager.getNetworkCapabilities(network)
        if (capabilities?.hasCapability(android.net.NetworkCapabilities.NET_CAPABILITY_VALIDATED) == true) {
            "online"
        } else {
            "offline"
        }
    } catch (e: Exception) {
        "online"  // Assume online if check fails
    }
}

private fun getLocationStatus(): String {
    return try {
        val locationManager = context.getSystemService(Context.LOCATION_SERVICE) as android.location.LocationManager
        if (locationManager.isProviderEnabled(android.location.LocationManager.GPS_PROVIDER) ||
            locationManager.isProviderEnabled(android.location.LocationManager.NETWORK_PROVIDER)) {
            "enabled"
        } else {
            "disabled"
        }
    } catch (e: Exception) {
        "enabled"
    }
}

private fun getPermissionStatus(): String {
    return if (ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
               ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED) {
        "granted"
    } else {
        "denied"
    }
}

private fun getAppState(): String {
    // Use ProcessLifecycleOwner in Application class for accurate state
    // For now, use a simple check
    return "background"  // Service runs in background
}
```

### 3c. Add state-only heartbeat upload method:

```kotlin
/**
 * Upload state-only heartbeat (no location coords).
 * Used when location is unavailable but app is alive.
 */
fun uploadHeartbeat(
    onSuccess: (serverSettings: JSONObject?) -> Unit,
    onAuthFailure: () -> Unit,
    onTransientFailure: () -> Unit
) {
    if (!prefs.hasValidAuth()) {
        Log.w(TAG, "No valid auth data, skipping heartbeat")
        onAuthFailure()
        return
    }

    val deviceUuid = prefs.deviceUuid ?: generateDeviceUuid()

    // State-only payload - NO lat/lng
    val body = JSONObject().apply {
        put("device_uuid", deviceUuid)
        put("platform", "android")
        put("device_name", Build.MODEL)
        put("network_status", getNetworkStatus())
        put("location_status", getLocationStatus())
        put("permission_status", getPermissionStatus())
        put("app_state", getAppState())
        put("battery_level", getBatteryLevel() ?: JSONObject.NULL)
        put("source", "native")
        put("session_token", prefs.sessionToken)
    }

    val request = Request.Builder()
        .url(UPLOAD_URL)
        .addHeader("Content-Type", "application/json")
        .addHeader("Authorization", "Bearer ${prefs.sessionToken}")
        .addHeader("User-Agent", "RelativesAndroid/${getAppVersion()}")
        .post(body.toString().toRequestBody(jsonMediaType))
        .build()

    Log.d(TAG, "Uploading heartbeat (state-only)")

    httpClient.newCall(request).enqueue(object : Callback {
        override fun onFailure(call: Call, e: IOException) {
            Log.e(TAG, "Heartbeat failed with network error", e)
            onTransientFailure()
        }

        override fun onResponse(call: Call, response: Response) {
            response.use { resp ->
                when {
                    resp.code in 200..299 -> {
                        Log.d(TAG, "Heartbeat successful")
                        // Parse server_settings from response
                        val serverSettings = try {
                            val responseBody = resp.body?.string()
                            responseBody?.let { JSONObject(it).optJSONObject("server_settings") }
                        } catch (e: Exception) { null }
                        onSuccess(serverSettings)
                    }
                    resp.code in listOf(401, 402, 403) -> onAuthFailure()
                    else -> onTransientFailure()
                }
            }
        }
    })
}
```

### 3d. Update `uploadLocation()` to include device state fields and parse server_settings:

In the `body` JSONObject, add BEFORE the location fields:
```kotlin
put("platform", "android")
put("device_name", Build.MODEL)
put("network_status", getNetworkStatus())
put("location_status", getLocationStatus())
put("permission_status", getPermissionStatus())
put("app_state", getAppState())
```

Update callback signature to pass server_settings:
```kotlin
fun uploadLocation(
    location: Location,
    isMoving: Boolean,
    onSuccess: (serverSettings: JSONObject?) -> Unit,  // Changed signature
    onAuthFailure: () -> Unit,
    onTransientFailure: () -> Unit
)
```

In success handler, parse and return server_settings:
```kotlin
resp.code in 200..299 -> {
    val serverSettings = try {
        val responseBody = resp.body?.string()
        responseBody?.let { JSONObject(it).optJSONObject("server_settings") }
    } catch (e: Exception) { null }
    onSuccess(serverSettings)
}
```

---

## TASK 4: Fix IDLE Mode Heartbeat in TrackingLocationService

**File: `TrackingLocationService.kt`**

### 4a. Remove minDistance from IDLE mode (line ~384-386):
```kotlin
// BEFORE:
TrackingMode.IDLE -> LocationRequest.Builder(Priority.PRIORITY_LOW_POWER, IDLE_INTERVAL_MS)
    .setMinUpdateDistanceMeters(IDLE_MIN_DISTANCE_M)
    .build()

// AFTER:
TrackingMode.IDLE -> LocationRequest.Builder(Priority.PRIORITY_LOW_POWER, IDLE_INTERVAL_MS)
    .build()  // NO minDistance - heartbeat timer handles alive signal
```

### 4b. Update heartbeat to send state-only when no location available:

Replace `requestHeartbeatLocation()` with:
```kotlin
private fun requestHeartbeatLocation() {
    if (!hasLocationPermission()) {
        Log.w(TAG, "No location permission - sending state-only heartbeat")
        sendStateOnlyHeartbeat()
        return
    }

    if (!isLocationServicesEnabled()) {
        Log.w(TAG, "Location services disabled - sending state-only heartbeat")
        sendStateOnlyHeartbeat()
        return
    }

    try {
        fusedLocationClient.getCurrentLocation(Priority.PRIORITY_BALANCED_POWER_ACCURACY, null)
            .addOnSuccessListener { location ->
                if (location != null) {
                    Log.d(TAG, "Heartbeat location received: lat=${location.latitude}")
                    uploadHeartbeat(location)
                } else {
                    Log.w(TAG, "Heartbeat location null - sending state-only")
                    sendStateOnlyHeartbeat()
                }
            }
            .addOnFailureListener { e ->
                Log.e(TAG, "Heartbeat location request failed - sending state-only", e)
                sendStateOnlyHeartbeat()
            }
    } catch (e: SecurityException) {
        Log.e(TAG, "Security exception - sending state-only heartbeat", e)
        sendStateOnlyHeartbeat()
    }
}

private fun sendStateOnlyHeartbeat() {
    if (prefs.isAuthBlocked() || isInBackoff()) {
        Log.d(TAG, "Auth blocked or in backoff, skipping state-only heartbeat")
        return
    }

    uploader.uploadHeartbeat(
        onSuccess = { serverSettings ->
            lastUploadTime = System.currentTimeMillis()
            prefs.resetFailureState()
            applyServerSettings(serverSettings)
            Log.d(TAG, "State-only heartbeat uploaded successfully")
        },
        onAuthFailure = {
            Log.w(TAG, "Heartbeat auth failure")
            prefs.authFailureUntil = System.currentTimeMillis() + 30 * 60 * 1000
            updateNotification(currentMode, "Login required")
        },
        onTransientFailure = {
            prefs.consecutiveFailures++
            prefs.lastFailureTime = System.currentTimeMillis()
        }
    )
}
```

### 4c. Add server settings handler:

```kotlin
private fun applyServerSettings(settings: JSONObject?) {
    settings ?: return

    try {
        settings.optInt("update_interval_seconds", 0).takeIf { it > 0 }?.let {
            prefs.updateIntervalSeconds = it
        }
        settings.optInt("idle_heartbeat_seconds", 0).takeIf { it > 0 }?.let {
            prefs.idleHeartbeatSeconds = it
            // Update heartbeat interval if in IDLE mode
            if (currentMode == TrackingMode.IDLE) {
                stopHeartbeatTimer()
                startHeartbeatTimer()
            }
        }
        settings.optInt("offline_threshold_seconds", 0).takeIf { it > 0 }?.let {
            prefs.offlineThresholdSeconds = it
        }
        settings.optInt("stale_threshold_seconds", 0).takeIf { it > 0 }?.let {
            prefs.staleThresholdSeconds = it
        }
        Log.d(TAG, "Applied server settings: interval=${prefs.updateIntervalSeconds}s, heartbeat=${prefs.idleHeartbeatSeconds}s")
    } catch (e: Exception) {
        Log.w(TAG, "Error applying server settings", e)
    }
}
```

### 4d. Update heartbeat timer to use dynamic interval:

```kotlin
// In startHeartbeatTimer(), change:
// mainHandler.postDelayed(heartbeatRunnable!!, HEARTBEAT_INTERVAL_MS)
// To:
val heartbeatMs = prefs.idleHeartbeatSeconds * 1000L
mainHandler.postDelayed(heartbeatRunnable!!, heartbeatMs)
Log.d(TAG, "Heartbeat timer started (${heartbeatMs}ms interval)")
```

### 4e. Update uploadLocation callbacks to handle server settings:

In `uploadLocation()` and `uploadHeartbeat()` calls, update to:
```kotlin
uploader.uploadLocation(
    location = location,
    isMoving = currentMode != TrackingMode.IDLE,
    onSuccess = { serverSettings ->
        lastUploadTime = System.currentTimeMillis()
        prefs.resetFailureState()
        applyServerSettings(serverSettings)
        Log.d(TAG, "Location uploaded successfully")
    },
    // ... rest unchanged
)
```

---

## TASK 5: Add WorkManager Watchdog

**New file: `TrackingWatchdogWorker.kt`**

```kotlin
package com.relatives.app.tracking

import android.content.Context
import android.util.Log
import androidx.work.*
import java.util.concurrent.TimeUnit

/**
 * Periodic worker that ensures tracking service is running.
 * Insurance against OEM battery optimization killing the service.
 */
class TrackingWatchdogWorker(
    context: Context,
    params: WorkerParameters
) : Worker(context, params) {

    companion object {
        private const val TAG = "TrackingWatchdog"
        private const val WORK_NAME = "tracking_watchdog"

        fun schedule(context: Context) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            val request = PeriodicWorkRequestBuilder<TrackingWatchdogWorker>(
                15, TimeUnit.MINUTES  // Minimum interval
            )
                .setConstraints(constraints)
                .setBackoffCriteria(
                    BackoffPolicy.LINEAR,
                    WorkRequest.MIN_BACKOFF_MILLIS,
                    TimeUnit.MILLISECONDS
                )
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                request
            )
            Log.d(TAG, "Watchdog scheduled")
        }

        fun cancel(context: Context) {
            WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
            Log.d(TAG, "Watchdog cancelled")
        }
    }

    override fun doWork(): Result {
        val prefs = PreferencesManager(applicationContext)

        if (prefs.isTrackingEnabled && !prefs.userRequestedStop) {
            Log.d(TAG, "Watchdog: Tracking should be running, ensuring service is started")
            TrackingLocationService.startTracking(applicationContext)
        } else {
            Log.d(TAG, "Watchdog: Tracking not enabled or user stopped, skipping")
        }

        return Result.success()
    }
}
```

**Update `TrackingLocationService.kt`:**

In `handleStartTracking()`, after service starts:
```kotlin
// Schedule watchdog to keep service alive
TrackingWatchdogWorker.schedule(this)
```

In `handleStopTracking()`:
```kotlin
// Cancel watchdog when user explicitly stops
TrackingWatchdogWorker.cancel(this)
```

---

## TASK 6: Battery Optimization Prompt

**In `MainActivity.kt`**, add battery optimization check:

```kotlin
private fun checkBatteryOptimization() {
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
        val powerManager = getSystemService(Context.POWER_SERVICE) as android.os.PowerManager
        if (!powerManager.isIgnoringBatteryOptimizations(packageName)) {
            // Show dialog explaining why this is needed
            androidx.appcompat.app.AlertDialog.Builder(this)
                .setTitle("Battery Optimization")
                .setMessage("For reliable location tracking, please disable battery optimization for this app.")
                .setPositiveButton("Open Settings") { _, _ ->
                    try {
                        val intent = Intent(android.provider.Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                            data = android.net.Uri.parse("package:$packageName")
                        }
                        startActivity(intent)
                    } catch (e: Exception) {
                        // Fallback to general battery settings
                        startActivity(Intent(android.provider.Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
                    }
                }
                .setNegativeButton("Later", null)
                .show()
        }
    }
}
```

Call `checkBatteryOptimization()` from `onCreate()` after permissions.

---

## TASK 7: Durable Offline Queue with At-Least-Once Delivery

**Problem:** Current `sendQueuedLocations()` clears the queue first, then uploads. If any upload fails, points are lost forever.

**Solution:** Implement a durable queue that only removes items after successful server ACK.

### 7a. Add Room Database for Location Queue

**New file: `LocationQueueDatabase.kt`**

```kotlin
package com.relatives.app.tracking

import android.content.Context
import androidx.room.*
import java.util.UUID

@Entity(tableName = "location_queue")
data class QueuedLocation(
    @PrimaryKey
    val clientEventId: String = UUID.randomUUID().toString(),

    val latitude: Double,
    val longitude: Double,
    val accuracyM: Int?,
    val speedKmh: Float?,
    val headingDeg: Float?,
    val altitudeM: Float?,
    val isMoving: Boolean,
    val batteryLevel: Int?,
    val timestamp: Long = System.currentTimeMillis(),

    // Device state
    val networkStatus: String,
    val locationStatus: String,
    val permissionStatus: String,
    val appState: String,

    // Retry tracking
    val retryCount: Int = 0,
    val lastAttemptAt: Long? = null
)

@Dao
interface LocationQueueDao {
    @Query("SELECT * FROM location_queue ORDER BY timestamp ASC")
    suspend fun getAll(): List<QueuedLocation>

    @Query("SELECT * FROM location_queue ORDER BY timestamp ASC LIMIT :limit")
    suspend fun getOldest(limit: Int): List<QueuedLocation>

    @Query("SELECT COUNT(*) FROM location_queue")
    suspend fun getCount(): Int

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(location: QueuedLocation)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertAll(locations: List<QueuedLocation>)

    @Query("DELETE FROM location_queue WHERE clientEventId = :clientEventId")
    suspend fun deleteById(clientEventId: String)

    @Query("DELETE FROM location_queue WHERE clientEventId IN (:ids)")
    suspend fun deleteByIds(ids: List<String>)

    @Query("UPDATE location_queue SET retryCount = retryCount + 1, lastAttemptAt = :now WHERE clientEventId = :id")
    suspend fun incrementRetry(id: String, now: Long = System.currentTimeMillis())

    @Query("DELETE FROM location_queue WHERE timestamp < :cutoff")
    suspend fun deleteOlderThan(cutoff: Long)
}

@Database(entities = [QueuedLocation::class], version = 1, exportSchema = false)
abstract class LocationQueueDatabase : RoomDatabase() {
    abstract fun locationQueueDao(): LocationQueueDao

    companion object {
        @Volatile
        private var INSTANCE: LocationQueueDatabase? = null

        fun getInstance(context: Context): LocationQueueDatabase {
            return INSTANCE ?: synchronized(this) {
                val instance = Room.databaseBuilder(
                    context.applicationContext,
                    LocationQueueDatabase::class.java,
                    "location_queue_db"
                ).build()
                INSTANCE = instance
                instance
            }
        }
    }
}
```

### 7b. Add Queue Manager

**New file: `LocationQueueManager.kt`**

```kotlin
package com.relatives.app.tracking

import android.content.Context
import android.location.Location
import android.util.Log
import kotlinx.coroutines.*
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Manages durable offline queue with at-least-once delivery.
 *
 * Rules:
 * - Queue stored in Room DB (survives app restart)
 * - Process in FIFO order
 * - Only remove item AFTER server ACK success
 * - On failure: stop processing, schedule retry with backoff
 * - Supports batch uploads for efficiency
 */
class LocationQueueManager(
    private val context: Context,
    private val uploader: LocationUploader,
    private val prefs: PreferencesManager
) {
    companion object {
        private const val TAG = "LocationQueueManager"
        private const val MAX_BATCH_SIZE = 20
        private const val MAX_QUEUE_AGE_MS = 24 * 60 * 60 * 1000L // 24 hours
        private const val MAX_RETRIES = 10

        // Exponential backoff delays (in ms)
        private val BACKOFF_DELAYS = listOf(
            2_000L,    // 2 seconds
            4_000L,    // 4 seconds
            8_000L,    // 8 seconds
            16_000L,   // 16 seconds
            32_000L,   // 32 seconds
            60_000L,   // 1 minute
            120_000L,  // 2 minutes
            300_000L,  // 5 minutes
            600_000L,  // 10 minutes
            900_000L   // 15 minutes (max)
        )
    }

    private val database = LocationQueueDatabase.getInstance(context)
    private val dao = database.locationQueueDao()
    private val scope = CoroutineScope(Dispatchers.IO + SupervisorJob())
    private val isProcessing = AtomicBoolean(false)

    /**
     * Add location to durable queue.
     * Called from TrackingLocationService when a new location is received.
     */
    suspend fun enqueue(location: Location, isMoving: Boolean) {
        val queuedLocation = QueuedLocation(
            latitude = location.latitude,
            longitude = location.longitude,
            accuracyM = if (location.hasAccuracy()) location.accuracy.toInt() else null,
            speedKmh = if (location.hasSpeed()) location.speed * 3.6f else null,
            headingDeg = if (location.hasBearing()) location.bearing else null,
            altitudeM = if (location.hasAltitude()) location.altitude.toFloat() else null,
            isMoving = isMoving,
            batteryLevel = getBatteryLevel(),
            networkStatus = getNetworkStatus(),
            locationStatus = getLocationStatus(),
            permissionStatus = getPermissionStatus(),
            appState = "background"
        )

        dao.insert(queuedLocation)
        Log.d(TAG, "Enqueued location: ${queuedLocation.clientEventId}")

        // Trigger flush if online
        if (isNetworkAvailable()) {
            flushQueue()
        }
    }

    /**
     * Flush queue with at-least-once delivery guarantee.
     * Only removes items after successful server ACK.
     */
    fun flushQueue() {
        if (!isProcessing.compareAndSet(false, true)) {
            Log.d(TAG, "Queue processing already in progress")
            return
        }

        scope.launch {
            try {
                processQueue()
            } finally {
                isProcessing.set(false)
            }
        }
    }

    private suspend fun processQueue() {
        // Clean up stale entries first
        dao.deleteOlderThan(System.currentTimeMillis() - MAX_QUEUE_AGE_MS)

        val queueSize = dao.getCount()
        if (queueSize == 0) {
            Log.d(TAG, "Queue empty, nothing to process")
            return
        }

        Log.d(TAG, "Processing queue with $queueSize items")

        // Check auth
        if (!prefs.hasValidAuth()) {
            Log.w(TAG, "No valid auth, skipping queue flush")
            return
        }

        // Check backoff
        if (isInBackoff()) {
            Log.d(TAG, "In backoff period, skipping queue flush")
            return
        }

        // Try batch upload first (more efficient)
        val batch = dao.getOldest(MAX_BATCH_SIZE)
        if (batch.size > 1) {
            val success = uploadBatch(batch)
            if (success) {
                // Batch succeeded - remove all items
                dao.deleteByIds(batch.map { it.clientEventId })
                prefs.resetFailureState()
                Log.d(TAG, "Batch upload successful, removed ${batch.size} items")

                // Continue processing if more items exist
                if (dao.getCount() > 0) {
                    processQueue()
                }
                return
            }
            // Batch failed - fall through to individual uploads
            Log.w(TAG, "Batch upload failed, falling back to individual uploads")
        }

        // Process individually in FIFO order
        val queue = dao.getAll()
        for (item in queue) {
            // Check if item has exceeded max retries
            if (item.retryCount >= MAX_RETRIES) {
                Log.w(TAG, "Item ${item.clientEventId} exceeded max retries, discarding")
                dao.deleteById(item.clientEventId)
                continue
            }

            val result = uploadSingle(item)

            when (result) {
                UploadResult.SUCCESS -> {
                    // ONLY remove on success
                    dao.deleteById(item.clientEventId)
                    prefs.resetFailureState()
                    Log.d(TAG, "Uploaded and removed: ${item.clientEventId}")
                }

                UploadResult.ALREADY_EXISTS -> {
                    // Server says duplicate - safe to remove
                    dao.deleteById(item.clientEventId)
                    Log.d(TAG, "Duplicate, removed: ${item.clientEventId}")
                }

                UploadResult.AUTH_FAILURE -> {
                    // Auth failed - stop processing, don't burn battery
                    Log.w(TAG, "Auth failure, stopping queue processing")
                    prefs.authFailureUntil = System.currentTimeMillis() + 30 * 60 * 1000
                    return // STOP
                }

                UploadResult.TRANSIENT_FAILURE -> {
                    // Network/server error - stop and retry later
                    dao.incrementRetry(item.clientEventId)
                    prefs.consecutiveFailures++
                    prefs.lastFailureTime = System.currentTimeMillis()
                    Log.w(TAG, "Transient failure on ${item.clientEventId}, stopping. Will retry with backoff.")
                    scheduleRetryWithBackoff()
                    return // STOP - don't hammer the network
                }
            }
        }

        Log.d(TAG, "Queue processing complete")
    }

    private suspend fun uploadSingle(item: QueuedLocation): UploadResult {
        return suspendCancellableCoroutine { continuation ->
            uploader.uploadLocationWithClientId(
                clientEventId = item.clientEventId,
                latitude = item.latitude,
                longitude = item.longitude,
                accuracyM = item.accuracyM,
                speedKmh = item.speedKmh,
                headingDeg = item.headingDeg,
                altitudeM = item.altitudeM,
                isMoving = item.isMoving,
                batteryLevel = item.batteryLevel,
                timestamp = item.timestamp,
                onSuccess = { _, alreadyExists ->
                    continuation.resume(
                        if (alreadyExists) UploadResult.ALREADY_EXISTS else UploadResult.SUCCESS,
                        null
                    )
                },
                onAuthFailure = {
                    continuation.resume(UploadResult.AUTH_FAILURE, null)
                },
                onTransientFailure = {
                    continuation.resume(UploadResult.TRANSIENT_FAILURE, null)
                }
            )
        }
    }

    private suspend fun uploadBatch(items: List<QueuedLocation>): Boolean {
        return suspendCancellableCoroutine { continuation ->
            uploader.uploadBatch(
                locations = items,
                onSuccess = { continuation.resume(true, null) },
                onFailure = { continuation.resume(false, null) }
            )
        }
    }

    private fun scheduleRetryWithBackoff() {
        val failures = prefs.consecutiveFailures.coerceIn(0, BACKOFF_DELAYS.size - 1)
        val delayMs = BACKOFF_DELAYS[failures]

        Log.d(TAG, "Scheduling retry in ${delayMs}ms (failure #${failures + 1})")

        scope.launch {
            delay(delayMs)
            if (isNetworkAvailable() && !isInBackoff()) {
                flushQueue()
            }
        }
    }

    private fun isInBackoff(): Boolean {
        val lastFailure = prefs.lastFailureTime
        if (lastFailure == 0L) return false

        val failures = prefs.consecutiveFailures.coerceIn(0, BACKOFF_DELAYS.size - 1)
        val backoffMs = BACKOFF_DELAYS[failures]

        return System.currentTimeMillis() - lastFailure < backoffMs
    }

    private fun isNetworkAvailable(): Boolean {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as android.net.ConnectivityManager
        val network = cm.activeNetwork ?: return false
        val capabilities = cm.getNetworkCapabilities(network) ?: return false
        return capabilities.hasCapability(android.net.NetworkCapabilities.NET_CAPABILITY_VALIDATED)
    }

    // Helper methods for device state (similar to LocationUploader)
    private fun getBatteryLevel(): Int? { /* ... same as LocationUploader ... */ }
    private fun getNetworkStatus(): String { /* ... same as LocationUploader ... */ }
    private fun getLocationStatus(): String { /* ... same as LocationUploader ... */ }
    private fun getPermissionStatus(): String { /* ... same as LocationUploader ... */ }

    enum class UploadResult {
        SUCCESS,
        ALREADY_EXISTS,
        AUTH_FAILURE,
        TRANSIENT_FAILURE
    }
}
```

### 7c. Update LocationUploader with client_event_id and batch support

**File: `LocationUploader.kt`**

Add these new methods:

```kotlin
private const val BATCH_UPLOAD_URL = "https://www.relaty.co.za/tracking/api/update_location_batch.php"

/**
 * Upload single location with client_event_id for idempotency.
 */
fun uploadLocationWithClientId(
    clientEventId: String,
    latitude: Double,
    longitude: Double,
    accuracyM: Int?,
    speedKmh: Float?,
    headingDeg: Float?,
    altitudeM: Float?,
    isMoving: Boolean,
    batteryLevel: Int?,
    timestamp: Long,
    onSuccess: (serverSettings: JSONObject?, alreadyExists: Boolean) -> Unit,
    onAuthFailure: () -> Unit,
    onTransientFailure: () -> Unit
) {
    val deviceUuid = prefs.deviceUuid ?: generateDeviceUuid()

    val body = JSONObject().apply {
        put("client_event_id", clientEventId)  // For idempotency
        put("device_uuid", deviceUuid)
        put("latitude", latitude)
        put("longitude", longitude)
        put("accuracy_m", accuracyM ?: JSONObject.NULL)
        put("speed_kmh", speedKmh ?: JSONObject.NULL)
        put("heading_deg", headingDeg ?: JSONObject.NULL)
        put("altitude_m", altitudeM ?: JSONObject.NULL)
        put("is_moving", isMoving)
        put("battery_level", batteryLevel ?: JSONObject.NULL)
        put("client_timestamp", timestamp)
        put("platform", "android")
        put("device_name", Build.MODEL)
        put("network_status", getNetworkStatus())
        put("location_status", getLocationStatus())
        put("permission_status", getPermissionStatus())
        put("app_state", getAppState())
        put("source", "native")
    }

    val request = Request.Builder()
        .url(UPLOAD_URL)
        .addHeader("Content-Type", "application/json")
        .addHeader("Authorization", "Bearer ${prefs.sessionToken}")
        .addHeader("User-Agent", "RelativesAndroid/${getAppVersion()}")
        .post(body.toString().toRequestBody(jsonMediaType))
        .build()

    httpClient.newCall(request).enqueue(object : Callback {
        override fun onFailure(call: Call, e: IOException) {
            Log.e(TAG, "Upload failed with network error", e)
            onTransientFailure()
        }

        override fun onResponse(call: Call, response: Response) {
            response.use { resp ->
                when {
                    resp.code in 200..299 -> {
                        val responseBody = resp.body?.string()
                        val json = responseBody?.let { JSONObject(it) }
                        val alreadyExists = json?.optBoolean("already_exists", false) ?: false
                        val serverSettings = json?.optJSONObject("server_settings")
                        onSuccess(serverSettings, alreadyExists)
                    }
                    resp.code in listOf(401, 403) -> onAuthFailure()
                    else -> onTransientFailure()
                }
            }
        }
    })
}

/**
 * Upload batch of locations for efficiency.
 * Server processes all and returns results per item.
 */
fun uploadBatch(
    locations: List<QueuedLocation>,
    onSuccess: (successIds: List<String>) -> Unit,
    onFailure: () -> Unit
) {
    val deviceUuid = prefs.deviceUuid ?: generateDeviceUuid()

    val locationsArray = JSONArray()
    for (loc in locations) {
        locationsArray.put(JSONObject().apply {
            put("client_event_id", loc.clientEventId)
            put("latitude", loc.latitude)
            put("longitude", loc.longitude)
            put("accuracy_m", loc.accuracyM ?: JSONObject.NULL)
            put("speed_kmh", loc.speedKmh ?: JSONObject.NULL)
            put("heading_deg", loc.headingDeg ?: JSONObject.NULL)
            put("altitude_m", loc.altitudeM ?: JSONObject.NULL)
            put("is_moving", loc.isMoving)
            put("battery_level", loc.batteryLevel ?: JSONObject.NULL)
            put("client_timestamp", loc.timestamp)
            put("network_status", loc.networkStatus)
            put("location_status", loc.locationStatus)
            put("permission_status", loc.permissionStatus)
            put("app_state", loc.appState)
        })
    }

    val body = JSONObject().apply {
        put("device_uuid", deviceUuid)
        put("platform", "android")
        put("device_name", Build.MODEL)
        put("locations", locationsArray)
        put("source", "native")
    }

    val request = Request.Builder()
        .url(BATCH_UPLOAD_URL)
        .addHeader("Content-Type", "application/json")
        .addHeader("Authorization", "Bearer ${prefs.sessionToken}")
        .addHeader("User-Agent", "RelativesAndroid/${getAppVersion()}")
        .post(body.toString().toRequestBody(jsonMediaType))
        .build()

    Log.d(TAG, "Uploading batch of ${locations.size} locations")

    httpClient.newCall(request).enqueue(object : Callback {
        override fun onFailure(call: Call, e: IOException) {
            Log.e(TAG, "Batch upload failed with network error", e)
            onFailure()
        }

        override fun onResponse(call: Call, response: Response) {
            response.use { resp ->
                if (resp.code in 200..299) {
                    val responseBody = resp.body?.string()
                    val json = responseBody?.let { JSONObject(it) }
                    val results = json?.optJSONArray("results")

                    // Collect successful IDs
                    val successIds = mutableListOf<String>()
                    results?.let {
                        for (i in 0 until it.length()) {
                            val item = it.getJSONObject(i)
                            if (item.optBoolean("success", false) || item.optBoolean("already_exists", false)) {
                                successIds.add(item.getString("client_event_id"))
                            }
                        }
                    }

                    Log.d(TAG, "Batch upload: ${successIds.size}/${locations.size} succeeded")
                    onSuccess(successIds)
                } else {
                    Log.e(TAG, "Batch upload failed with code ${resp.code}")
                    onFailure()
                }
            }
        }
    })
}
```

### 7d. Update TrackingLocationService to use Queue Manager

**File: `TrackingLocationService.kt`**

```kotlin
// Add property
private lateinit var queueManager: LocationQueueManager

// In onCreate() or service initialization:
queueManager = LocationQueueManager(this, uploader, prefs)

// Replace direct upload calls with queue enqueue:
// OLD:
// uploader.uploadLocation(location, isMoving, onSuccess, onAuthFailure, onTransientFailure)

// NEW:
scope.launch {
    queueManager.enqueue(location, isMoving)
}

// On network connectivity change (register BroadcastReceiver):
private val connectivityReceiver = object : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (isNetworkAvailable()) {
            Log.d(TAG, "Network available, flushing queue")
            queueManager.flushQueue()
        }
    }
}
```

### 7e. Add PreferencesManager properties for failure tracking

**File: `PreferencesManager.kt`**

```kotlin
// Add keys
const val KEY_CONSECUTIVE_FAILURES = "consecutive_failures"
const val KEY_LAST_FAILURE_TIME = "last_failure_time"
const val KEY_AUTH_FAILURE_UNTIL = "auth_failure_until"

// Add properties
var consecutiveFailures: Int
    get() = prefs.getInt(KEY_CONSECUTIVE_FAILURES, 0)
    set(value) = prefs.edit { putInt(KEY_CONSECUTIVE_FAILURES, value) }

var lastFailureTime: Long
    get() = prefs.getLong(KEY_LAST_FAILURE_TIME, 0L)
    set(value) = prefs.edit { putLong(KEY_LAST_FAILURE_TIME, value) }

var authFailureUntil: Long
    get() = prefs.getLong(KEY_AUTH_FAILURE_UNTIL, 0L)
    set(value) = prefs.edit { putLong(KEY_AUTH_FAILURE_UNTIL, value) }

fun resetFailureState() {
    consecutiveFailures = 0
    lastFailureTime = 0L
}

fun isAuthBlocked(): Boolean {
    return System.currentTimeMillis() < authFailureUntil
}
```

---

## TASK 8: Dynamic Timer Configuration from Server Settings

**Problem:** Android receives `idle_heartbeat_seconds` from server but ignores it - heartbeat timer is hardcoded to 10 minutes. Dashboard settings are lying to users.

**Solution:** Create a single source of truth `TrackingConfig` that all timers derive from, updated live from server.

### 8a. Create TrackingConfig Model

**New file: `TrackingConfig.kt`**

```kotlin
package com.relatives.app.tracking

/**
 * Single source of truth for all tracking timing configuration.
 * Updated from server settings, persisted locally.
 *
 * IMPORTANT DISTINCTION:
 * - heartbeatSec: "I'm alive" signal (state-only, no GPS needed)
 * - idleCheckSec: "Check if I moved" polling (requires GPS)
 * - movingIntervalSec: Location updates while moving
 */
data class TrackingConfig(
    val movingIntervalSec: Int,      // Location updates while moving (default 60)
    val idleCheckSec: Int,           // Check for movement while idle (default 300 = 5 min)
    val heartbeatSec: Int,           // "I'm alive" signal interval (default 600 = 10 min)
    val offlineThresholdSec: Int,    // When to consider device offline (default 720 = 12 min)
    val staleThresholdSec: Int       // When location is too old to trust (default 3600 = 1 hr)
) {
    companion object {
        // Sane defaults
        val DEFAULT = TrackingConfig(
            movingIntervalSec = 60,
            idleCheckSec = 300,
            heartbeatSec = 600,
            offlineThresholdSec = 720,
            staleThresholdSec = 3600
        )

        // Validation ranges (reject insane values from server)
        private val MOVING_INTERVAL_RANGE = 10..300      // 10 sec to 5 min
        private val IDLE_CHECK_RANGE = 60..1800          // 1 min to 30 min
        private val HEARTBEAT_RANGE = 60..1800           // 1 min to 30 min
        private val OFFLINE_THRESHOLD_RANGE = 120..3600  // 2 min to 1 hour
        private val STALE_THRESHOLD_RANGE = 300..86400   // 5 min to 24 hours

        /**
         * Create config from server response, with validation.
         * Invalid values fall back to defaults.
         */
        fun fromServerSettings(
            updateIntervalSeconds: Int?,
            idleHeartbeatSeconds: Int?,
            offlineThresholdSeconds: Int?,
            staleThresholdSeconds: Int?,
            current: TrackingConfig = DEFAULT
        ): TrackingConfig {
            return TrackingConfig(
                movingIntervalSec = updateIntervalSeconds
                    ?.takeIf { it in MOVING_INTERVAL_RANGE }
                    ?: current.movingIntervalSec,

                // Server sends "idle_heartbeat_seconds" which is the heartbeat interval
                heartbeatSec = idleHeartbeatSeconds
                    ?.takeIf { it in HEARTBEAT_RANGE }
                    ?: current.heartbeatSec,

                // Idle check is typically 50% of heartbeat (check more often than we report)
                idleCheckSec = idleHeartbeatSeconds
                    ?.let { (it * 0.5).toInt() }
                    ?.takeIf { it in IDLE_CHECK_RANGE }
                    ?: current.idleCheckSec,

                offlineThresholdSec = offlineThresholdSeconds
                    ?.takeIf { it in OFFLINE_THRESHOLD_RANGE }
                    ?: current.offlineThresholdSec,

                staleThresholdSec = staleThresholdSeconds
                    ?.takeIf { it in STALE_THRESHOLD_RANGE }
                    ?: current.staleThresholdSec
            )
        }
    }

    // Convenience methods for milliseconds (what Android APIs need)
    val movingIntervalMs: Long get() = movingIntervalSec * 1000L
    val idleCheckMs: Long get() = idleCheckSec * 1000L
    val heartbeatMs: Long get() = heartbeatSec * 1000L
}
```

### 8b. Add Config Persistence to PreferencesManager

**File: `PreferencesManager.kt`**

```kotlin
// Add keys
const val KEY_MOVING_INTERVAL_SEC = "config_moving_interval_sec"
const val KEY_IDLE_CHECK_SEC = "config_idle_check_sec"
const val KEY_HEARTBEAT_SEC = "config_heartbeat_sec"
const val KEY_OFFLINE_THRESHOLD_SEC = "config_offline_threshold_sec"
const val KEY_STALE_THRESHOLD_SEC = "config_stale_threshold_sec"

// Add config property
var trackingConfig: TrackingConfig
    get() = TrackingConfig(
        movingIntervalSec = prefs.getInt(KEY_MOVING_INTERVAL_SEC, 60),
        idleCheckSec = prefs.getInt(KEY_IDLE_CHECK_SEC, 300),
        heartbeatSec = prefs.getInt(KEY_HEARTBEAT_SEC, 600),
        offlineThresholdSec = prefs.getInt(KEY_OFFLINE_THRESHOLD_SEC, 720),
        staleThresholdSec = prefs.getInt(KEY_STALE_THRESHOLD_SEC, 3600)
    )
    set(value) = prefs.edit {
        putInt(KEY_MOVING_INTERVAL_SEC, value.movingIntervalSec)
        putInt(KEY_IDLE_CHECK_SEC, value.idleCheckSec)
        putInt(KEY_HEARTBEAT_SEC, value.heartbeatSec)
        putInt(KEY_OFFLINE_THRESHOLD_SEC, value.offlineThresholdSec)
        putInt(KEY_STALE_THRESHOLD_SEC, value.staleThresholdSec)
    }

/**
 * Update config from server settings. Returns true if config changed.
 */
fun updateConfigFromServer(serverSettings: JSONObject?): Boolean {
    serverSettings ?: return false

    val oldConfig = trackingConfig
    val newConfig = TrackingConfig.fromServerSettings(
        updateIntervalSeconds = serverSettings.optInt("update_interval_seconds", 0).takeIf { it > 0 },
        idleHeartbeatSeconds = serverSettings.optInt("idle_heartbeat_seconds", 0).takeIf { it > 0 },
        offlineThresholdSeconds = serverSettings.optInt("offline_threshold_seconds", 0).takeIf { it > 0 },
        staleThresholdSeconds = serverSettings.optInt("stale_threshold_seconds", 0).takeIf { it > 0 },
        current = oldConfig
    )

    if (newConfig != oldConfig) {
        trackingConfig = newConfig
        Log.d("PreferencesManager", "Config updated: moving=${newConfig.movingIntervalSec}s, " +
            "idle=${newConfig.idleCheckSec}s, heartbeat=${newConfig.heartbeatSec}s")
        return true
    }
    return false
}
```

### 8c. Update TrackingLocationService to Use Dynamic Config

**File: `TrackingLocationService.kt`**

Replace hardcoded intervals with config-driven values:

```kotlin
// REMOVE these hardcoded constants:
// private const val MOVING_INTERVAL_MS = 60_000L
// private const val IDLE_INTERVAL_MS = 300_000L
// private const val HEARTBEAT_INTERVAL_MS = 600_000L

// ADD config listener interface
interface ConfigChangeListener {
    fun onConfigChanged(newConfig: TrackingConfig)
}

// In TrackingLocationService class:
private var currentConfig: TrackingConfig = TrackingConfig.DEFAULT
private var heartbeatRunnable: Runnable? = null
private var idleCheckRunnable: Runnable? = null

override fun onCreate() {
    super.onCreate()
    // Load persisted config on startup
    currentConfig = prefs.trackingConfig
    Log.d(TAG, "Loaded config: heartbeat=${currentConfig.heartbeatSec}s, idle=${currentConfig.idleCheckSec}s")
}

/**
 * Apply new config and reschedule timers immediately.
 */
fun applyConfig(newConfig: TrackingConfig) {
    val oldConfig = currentConfig
    currentConfig = newConfig

    // Only reschedule if relevant values changed
    if (oldConfig.heartbeatSec != newConfig.heartbeatSec) {
        Log.d(TAG, "Heartbeat interval changed: ${oldConfig.heartbeatSec}s -> ${newConfig.heartbeatSec}s")
        restartHeartbeatTimer()
    }

    if (oldConfig.idleCheckSec != newConfig.idleCheckSec && currentMode == TrackingMode.IDLE) {
        Log.d(TAG, "Idle check interval changed: ${oldConfig.idleCheckSec}s -> ${newConfig.idleCheckSec}s")
        restartIdleCheckTimer()
    }

    if (oldConfig.movingIntervalSec != newConfig.movingIntervalSec && currentMode == TrackingMode.MOVING) {
        Log.d(TAG, "Moving interval changed: ${oldConfig.movingIntervalSec}s -> ${newConfig.movingIntervalSec}s")
        restartLocationUpdates()
    }
}

// HEARTBEAT TIMER (state-only "I'm alive" signal)
private fun startHeartbeatTimer() {
    stopHeartbeatTimer()

    heartbeatRunnable = object : Runnable {
        override fun run() {
            sendHeartbeat()
            // Re-read config in case it changed
            mainHandler.postDelayed(this, currentConfig.heartbeatMs)
        }
    }

    mainHandler.postDelayed(heartbeatRunnable!!, currentConfig.heartbeatMs)
    Log.d(TAG, "Heartbeat timer started: ${currentConfig.heartbeatSec}s interval")
}

private fun stopHeartbeatTimer() {
    heartbeatRunnable?.let { mainHandler.removeCallbacks(it) }
    heartbeatRunnable = null
}

private fun restartHeartbeatTimer() {
    stopHeartbeatTimer()
    startHeartbeatTimer()
}

// IDLE CHECK TIMER (periodic GPS check while stationary)
private fun startIdleCheckTimer() {
    stopIdleCheckTimer()

    idleCheckRunnable = object : Runnable {
        override fun run() {
            checkForMovement()
            mainHandler.postDelayed(this, currentConfig.idleCheckMs)
        }
    }

    mainHandler.postDelayed(idleCheckRunnable!!, currentConfig.idleCheckMs)
    Log.d(TAG, "Idle check timer started: ${currentConfig.idleCheckSec}s interval")
}

private fun stopIdleCheckTimer() {
    idleCheckRunnable?.let { mainHandler.removeCallbacks(it) }
    idleCheckRunnable = null
}

private fun restartIdleCheckTimer() {
    stopIdleCheckTimer()
    startIdleCheckTimer()
}

// LOCATION REQUEST BUILDER (uses config for intervals)
private fun buildLocationRequest(): LocationRequest {
    return when (currentMode) {
        TrackingMode.MOVING -> LocationRequest.Builder(
            Priority.PRIORITY_HIGH_ACCURACY,
            currentConfig.movingIntervalMs
        ).setMinUpdateDistanceMeters(10f).build()

        TrackingMode.IDLE -> LocationRequest.Builder(
            Priority.PRIORITY_BALANCED_POWER_ACCURACY,
            currentConfig.idleCheckMs
        ).build()  // No minDistance for idle - we want periodic checks
    }
}

private fun restartLocationUpdates() {
    stopLocationUpdates()
    startLocationUpdates()
}

// UPDATE: When server settings arrive, apply them immediately
private fun handleServerSettings(serverSettings: JSONObject?) {
    val configChanged = prefs.updateConfigFromServer(serverSettings)
    if (configChanged) {
        applyConfig(prefs.trackingConfig)
    }
}
```

### 8d. Update Upload Callbacks to Apply Server Settings

**File: `LocationQueueManager.kt`** (and anywhere else uploads happen)

```kotlin
// In uploadSingle success handler:
onSuccess = { serverSettings, alreadyExists ->
    // Apply server config immediately
    if (serverSettings != null) {
        val configChanged = prefs.updateConfigFromServer(serverSettings)
        if (configChanged) {
            // Notify service to reschedule timers
            notifyConfigChanged(prefs.trackingConfig)
        }
    }

    continuation.resume(
        if (alreadyExists) UploadResult.ALREADY_EXISTS else UploadResult.SUCCESS,
        null
    )
}

// Add method to notify service
private fun notifyConfigChanged(newConfig: TrackingConfig) {
    // Option 1: LocalBroadcast
    val intent = Intent(ACTION_CONFIG_CHANGED).apply {
        putExtra(EXTRA_MOVING_INTERVAL, newConfig.movingIntervalSec)
        putExtra(EXTRA_HEARTBEAT_INTERVAL, newConfig.heartbeatSec)
        putExtra(EXTRA_IDLE_CHECK_INTERVAL, newConfig.idleCheckSec)
    }
    LocalBroadcastManager.getInstance(context).sendBroadcast(intent)

    // Option 2: Direct call if you have service reference
    // trackingService?.applyConfig(newConfig)
}

companion object {
    const val ACTION_CONFIG_CHANGED = "com.relatives.app.CONFIG_CHANGED"
    const val EXTRA_MOVING_INTERVAL = "moving_interval"
    const val EXTRA_HEARTBEAT_INTERVAL = "heartbeat_interval"
    const val EXTRA_IDLE_CHECK_INTERVAL = "idle_check_interval"
}
```

### 8e. Register Config Change Receiver in Service

**File: `TrackingLocationService.kt`**

```kotlin
private val configChangeReceiver = object : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == LocationQueueManager.ACTION_CONFIG_CHANGED) {
            Log.d(TAG, "Received config change broadcast")
            applyConfig(prefs.trackingConfig)
        }
    }
}

override fun onCreate() {
    super.onCreate()
    // ... existing code ...

    // Register for config changes
    LocalBroadcastManager.getInstance(this).registerReceiver(
        configChangeReceiver,
        IntentFilter(LocationQueueManager.ACTION_CONFIG_CHANGED)
    )
}

override fun onDestroy() {
    LocalBroadcastManager.getInstance(this).unregisterReceiver(configChangeReceiver)
    // ... existing cleanup ...
    super.onDestroy()
}
```

### 8f. Ensure Config is Applied on Service Start

**File: `TrackingLocationService.kt`**

```kotlin
private fun handleStartTracking() {
    // Load latest config
    currentConfig = prefs.trackingConfig
    Log.d(TAG, "Starting with config: moving=${currentConfig.movingIntervalSec}s, " +
        "heartbeat=${currentConfig.heartbeatSec}s, idle=${currentConfig.idleCheckSec}s")

    // Start timers with config values
    startHeartbeatTimer()  // Uses currentConfig.heartbeatMs
    startLocationUpdates() // Uses currentConfig based on mode

    // ... rest of startup ...
}
```

---

## Summary of Changes

1. **Session Token**: Fetch from `/api/session-token.php` after WebView login
2. **Device State**: Send `network_status`, `location_status`, `permission_status`, `app_state` in every request
3. **State-Only Heartbeats**: Send heartbeat without coords when location unavailable
4. **Server Settings**: Parse `server_settings` from response, apply dynamic intervals
5. **IDLE Mode Fix**: Remove minDistance requirement, rely on heartbeat timer
6. **WorkManager Watchdog**: Periodic worker to restart service if killed by OEM
7. **Battery Optimization**: Prompt user to disable battery optimization
8. **Durable Offline Queue**: Room DB queue with at-least-once delivery, only removes on server ACK
9. **Batch Upload**: Send up to 20 locations in one request for efficiency
10. **Idempotency**: `client_event_id` UUID prevents duplicate inserts on retry
11. **Exponential Backoff**: On failure, wait 2s→4s→8s→...→15min before retry
12. **Dynamic Config**: All timers derive from `TrackingConfig`, updated live from server with validation

## Testing Checklist

- [ ] Login in WebView → session token saved to preferences
- [ ] Start tracking → service starts, watchdog scheduled
- [ ] Disable location services → state-only heartbeats sent
- [ ] Re-enable location → location updates resume
- [ ] Stay stationary for 10+ minutes → heartbeats still sent
- [ ] Force-stop app → watchdog restarts service within 15 min
- [ ] Stop tracking via UI → watchdog cancelled, service stops
- [ ] **Offline queue**: Enable airplane mode, move around, disable airplane mode → all points uploaded
- [ ] **Network failure**: Simulate network error mid-upload → remaining queue preserved
- [ ] **Duplicate prevention**: Force retry of already-uploaded point → server returns already_exists, no duplicate
- [ ] **Batch upload**: Queue 25 points → first 20 sent as batch, remaining 5 in next batch
- [ ] **Dynamic config**: Change `idle_heartbeat_seconds` in dashboard → app picks up new interval on next upload
- [ ] **Config validation**: Set server interval to 1 second → app rejects, keeps previous value
