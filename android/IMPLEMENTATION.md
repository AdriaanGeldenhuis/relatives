# Android Location Tracking Service Implementation

## SECTION 1: DIAGNOSIS

### Previous Issues (Based on Requirements)

1. **Indefinite WakeLock** - Holding CPU wake lock forever causes severe battery drain
2. **Zombie Service Restarts** - Service restarting after user explicitly stops tracking
3. **Incorrect Staleness Detection** - "Stale" status based on upload frequency, not actual movement
4. **No Viewer Mode** - No high-frequency mode when someone is actively watching the map
5. **No Network Backoff** - Hammering server on failures drains battery
6. **No Auth Failure Handling** - 401/403 responses not handled, causing spam

### Root Causes

| Issue | Cause | Impact |
|-------|-------|--------|
| Battery drain | Indefinite `PARTIAL_WAKE_LOCK` | CPU never sleeps |
| Zombie restarts | `START_STICKY` without preference guard | Service restarts even after stop |
| Stale status | Server defaults + no movement state | UI shows "stale" for stationary users |
| No LIVE mode | Missing viewer detection | Can't show real-time when needed |
| Network spam | No backoff on errors | Constant retries drain battery |

---

## SECTION 2: CHANGES (Per File)

### New Files Created

| File | Purpose |
|------|---------|
| `tracking/PreferencesManager.kt` | Persisted state management with `tracking_enabled` flag |
| `tracking/TrackingLocationService.kt` | Foreground service with LIVE/MOVING/IDLE modes |
| `tracking/LocationUploader.kt` | Network uploads with auth handling and exponential backoff |
| `webview/WebViewBridge.kt` | JavaScript interface for WebView communication |
| `receiver/BootReceiver.kt` | Boot restart with preference guards |
| `MainActivity.kt` | WebView host with permission handling |
| `AndroidManifest.xml` | Service and receiver declarations |

### Key Changes Summary

#### A) Persisted State (`PreferencesManager.kt`)
- ✅ `tracking_enabled` - Master switch for tracking
- ✅ `user_requested_stop` - Prevents restart after explicit stop
- ✅ `auth_failure_until` - Blocks uploads after 401/403
- ✅ `consecutive_failures` - Tracks failures for exponential backoff

#### B) Stop Action (`TrackingLocationService.kt:196-205`)
```kotlin
private fun handleStopTracking() {
    prefs.disableTracking()  // Sets tracking_enabled=false, user_requested_stop=true
    stopSelfCleanly()        // Removes updates, releases wakelock, stops service
}
```

#### C) WakeLock (`TrackingLocationService.kt:431-449`)
- ❌ No indefinite wakelock
- ✅ Time-limited (2 minutes max) only in LIVE mode
- ✅ Auto-release when exiting LIVE mode

```kotlin
private fun acquireTimeLimitedWakeLock() {
    wakeLock = powerManager.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, WAKELOCK_TAG)
        .apply { acquire(WAKELOCK_TIMEOUT_MS) }  // 2 minute timeout
}
```

#### D) Mode Behavior (`TrackingLocationService.kt:222-268`)

| Mode | Interval | Priority | Min Distance | Upload |
|------|----------|----------|--------------|--------|
| LIVE | 10s | HIGH_ACCURACY | 10m | Always |
| MOVING | Settings (default 60s) | BALANCED/HIGH | - | Always |
| IDLE | 10 min | LOW_POWER | 100m | Heartbeat only |

#### E) Viewer Keepalive (`TrackingLocationService.kt:207-220`)
```kotlin
private fun handleViewerVisible() {
    viewerLiveUntil = System.currentTimeMillis() + VIEWER_LIVE_DURATION_MS  // +10 min
    if (currentMode != TrackingMode.LIVE) switchMode(TrackingMode.LIVE)
}
```

Periodic checker (`checkAndUpdateMode`) drops to MOVING/IDLE when `viewerLiveUntil` expires.

#### F) Network Protection (`LocationUploader.kt`)
- **Auth Failure (401/403/402)**: Block uploads for 30 minutes
- **Transient Failure**: Exponential backoff (10s → 30s → 60s → 2m → 5m cap)
- **Too Many Failures**: Drop from LIVE to MOVING to reduce request frequency

---

## SECTION 3: CODE ARCHITECTURE

### State Machine

```
                    ┌──────────────┐
                    │              │
     onTrackingScreen──►  LIVE    │◄── viewerLiveUntil extended
     Visible()      │   (10s)     │    on each call
                    │              │
                    └──────┬───────┘
                           │ viewerLiveUntil expired
                           ▼
┌─────────────┐    ┌──────────────┐
│             │    │              │
│    IDLE     │◄───│   MOVING    │
│  (10 min)   │    │ (settings)  │
│             │    │              │
└──────┬──────┘    └──────┬───────┘
       │                   │
       │ movement          │ no movement
       │ detected          │ for 3 min
       │                   │
       └───────────────────┘
```

### Service Lifecycle

```
START_TRACKING
     │
     ▼
┌─────────────────┐
│ Check tracking_ │──── false ────► Do nothing
│ enabled pref    │
└────────┬────────┘
         │ true
         ▼
┌─────────────────┐
│ startForeground │
│ requestUpdates  │
│ registerActivity│
└─────────────────┘
         │
         ▼ (running)
         │
STOP_TRACKING
     │
     ▼
┌─────────────────┐
│ tracking_enabled│
│ = false         │
│ user_stop = true│
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ removeUpdates   │
│ releaseWakelock │
│ stopForeground  │
│ stopSelf        │
└─────────────────┘
         │
         ▼
     (stopped - NO restart)
```

### Network Backoff Strategy

```kotlin
// On auth failure (401/403):
prefs.authFailureUntil = now + 30_MINUTES
// Uploads blocked until this time passes

// On transient failure:
prefs.consecutiveFailures++
backoffDelay = when(failures) {
    1 -> 10s
    2 -> 30s
    3 -> 60s
    4 -> 2min
    else -> 5min  // cap
}
// Skip upload if within backoff window

// On success:
prefs.resetFailureState()  // Clear counters
```

---

## SECTION 4: TEST PLAN

### Functional Tests

#### Test 1: Stop Tracking Actually Stops
**Steps:**
1. Start tracking via WebView (`Android.startTracking()`)
2. Verify notification shows "Moving" or "LIVE"
3. Stop tracking via WebView (`Android.stopTracking()`)
4. Force-kill app from recent apps
5. Wait 30 seconds

**Expected:**
- ✅ Notification disappears
- ✅ Service does NOT restart
- ✅ `adb shell dumpsys activity services | grep TrackingLocation` shows nothing

---

#### Test 2: Viewer Keepalive (LIVE Mode)
**Steps:**
1. Start tracking
2. Call `Android.onTrackingScreenVisible()` from WebView
3. Verify notification shows "LIVE"
4. Wait 11 minutes without calling again

**Expected:**
- ✅ Mode switches to LIVE immediately
- ✅ After 10 min timeout, drops to MOVING or IDLE
- ✅ Battery usage in LIVE: ~2-3% per hour

---

#### Test 3: Stationary Heartbeat
**Steps:**
1. Start tracking
2. Place device stationary for 20 minutes
3. Check server for location uploads

**Expected:**
- ✅ Device switches to IDLE mode after 3 min
- ✅ Heartbeat uploads every 10 minutes
- ✅ Server shows "updated X minutes ago" (not "stale")

---

#### Test 4: Permission Off → Offline
**Steps:**
1. Start tracking
2. Revoke location permission in Settings
3. Check notification

**Expected:**
- ✅ Notification shows appropriate error state
- ✅ No crash or ANR

---

#### Test 5: Auth Failure → No Spam
**Steps:**
1. Start tracking with valid session
2. Invalidate session on server (delete session)
3. Wait for upload attempt

**Expected:**
- ✅ Single 401/403 response
- ✅ No more upload attempts for 30 minutes
- ✅ Notification shows "Login required"

---

#### Test 6: Boot Restart (Enabled)
**Steps:**
1. Start tracking, verify `tracking_enabled=true`
2. Reboot device

**Expected:**
- ✅ Service restarts automatically
- ✅ Notification reappears

---

#### Test 7: Boot Restart (Disabled)
**Steps:**
1. Stop tracking via app
2. Verify `tracking_enabled=false`
3. Reboot device

**Expected:**
- ✅ Service does NOT restart
- ✅ No notification

---

### Performance Validation

#### Android Studio Profiler
1. Open **View > Tool Windows > Profiler**
2. Select running device/emulator
3. Click **CPU** to see wake states
4. Click **Energy** to see battery impact

**Checkpoints:**
- [ ] No sustained CPU wake in IDLE mode
- [ ] Wake events only on location callback
- [ ] Network activity matches expected intervals

#### Battery Historian / dumpsys
```bash
# Reset battery stats
adb shell dumpsys batterystats --reset

# Run app for 1 hour in IDLE mode

# Capture stats
adb shell dumpsys batterystats > batterystats.txt
adb bugreport > bugreport.zip

# Analyze in Battery Historian
# https://bathist.nicholasdille.de/
```

**Checkpoints:**
- [ ] Wake lock held time < 5 min/hour in IDLE
- [ ] Network transfers < 10/hour in IDLE
- [ ] App not in "battery drain" list

#### ADB Commands for Live Testing
```bash
# Check if service is running
adb shell dumpsys activity services com.relatives.app | grep TrackingLocation

# Check wakelock state
adb shell dumpsys power | grep -A5 "Wake Locks"

# Monitor location requests
adb logcat -s TrackingLocationService:D LocationUploader:D

# Force idle mode (Doze)
adb shell dumpsys deviceidle force-idle

# Exit Doze
adb shell dumpsys deviceidle unforce
```

---

### Simulated Movement Test
```bash
# Send mock location via ADB
adb shell am start-foreground-service -a com.relatives.app.START_TRACKING

# Inject locations (requires mock location app or telnet to emulator)
# Emulator: telnet localhost 5554
# geo fix -122.084 37.422  # Mountain View
# geo fix -122.085 37.423  # Move slightly
```

**Expected:**
- Device detects movement → switches to MOVING
- Immediate location upload on movement detection

---

## Summary Checklist

| Requirement | Status | File/Line |
|-------------|--------|-----------|
| No permanent wakelock | ✅ | `TrackingLocationService.kt:431-449` |
| Stop actually stops | ✅ | `TrackingLocationService.kt:196-205` |
| Boot restart guarded | ✅ | `BootReceiver.kt:30-52` |
| 10-min heartbeat in IDLE | ✅ | `TrackingLocationService.kt:41` |
| LIVE mode (10s, viewer) | ✅ | `TrackingLocationService.kt:36-39` |
| Viewer keepalive extend | ✅ | `TrackingLocationService.kt:207-220` |
| Auth failure blocks 30min | ✅ | `LocationUploader.kt:75` |
| Exponential backoff | ✅ | `PreferencesManager.kt:108-118` |
| Notification shows mode | ✅ | `TrackingLocationService.kt:459-492` |
