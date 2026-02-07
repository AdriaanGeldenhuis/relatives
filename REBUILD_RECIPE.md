# COMPLETE REBUILD RECIPE: /tracking/ and /App Files/

## TASK
Delete ALL code in `/tracking/` and `/App Files/` directories. Rebuild both from scratch with clean, production-ready code. Everything must integrate with the existing app shell (global header, footer, auth, caching).

---

## STEP 0: READ THESE FILES FIRST (DO NOT SKIP)

Before writing ANY code, read and understand these files. They define the patterns your code MUST follow:

```
# Auth & Session (CRITICAL - every bug in the last build was auth/session related)
/core/bootstrap.php          # Sets session_name('RELATIVES_SESSION') - this is why session must start BEFORE this file
/core/Auth.php               # Auth class - login(), getCurrentUser(), etc.
/core/Session.php            # Session management

# Global shell (your pages MUST integrate with these)
/shared/components/header.php   # Global header - outputs <!DOCTYPE html> through <header>
/shared/components/footer.php   # Global footer - outputs <footer> through </html>

# Existing working pages (copy their auth pattern EXACTLY)
/home/index.php              # Example of correct auth + header/footer pattern
/messages/index.php          # Another working example

# Infrastructure your code must use
/core/Cache.php              # Two-tier cache (Memcached + MySQL fallback) - your TrackingCache wraps this
/core/Response.php           # API response helper - Response::success($data), Response::error($msg, $code)
/core/GeoUtils.php           # Haversine distance, point-in-polygon, etc.
/core/NotificationManager.php # FCM push notifications
/core/NotificationTriggers.php # Notification event triggers

# Database schema
/migrations/                 # Check for existing tracking tables
```

---

## STEP 1: CRITICAL PATTERNS (VIOLATIONS = BROKEN CODE)

### Pattern 1: Auth on PHP pages (MOST IMPORTANT)
The login flow stores session under default `PHPSESSID` cookie. `bootstrap.php` changes session name to `RELATIVES_SESSION`. If bootstrap runs first, it reads from wrong (empty) session → redirect loop.

```php
<?php
declare(strict_types=1);

// STEP 1: Start session BEFORE bootstrap (uses PHPSESSID where login data lives)
session_start();

// STEP 2: Check session directly
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

// STEP 3: Load bootstrap (DB, Cache, etc.)
require_once __DIR__ . '/../core/bootstrap.php';

// STEP 4: Verify with Auth class
$auth = new Auth($db);
$user = $auth->getCurrentUser();
if (!$user) {
    header('Location: /login.php');
    exit;
}
```

### Pattern 2: Auth on API endpoints
API endpoints use a tracking-specific bootstrap that handles session:

```php
<?php
declare(strict_types=1);
// bootstrap_tracking.php already calls session_start() before bootstrap.php
require_once __DIR__ . '/../core/bootstrap_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('method_not_allowed', 405);
}

// SiteContext validates session and returns user/family context
$ctx = SiteContext::require($db);
```

### Pattern 3: Page template with global header/footer
```php
$pageTitle = 'Page Title';
$pageCSS = ['/path/to/styles.css'];
require_once __DIR__ . '/../../shared/components/header.php';
?>

<!-- YOUR PAGE CONTENT HERE -->

<?php
$pageJS = ['/path/to/script.js'];
require_once __DIR__ . '/../../shared/components/footer.php';
?>
```

### Pattern 4: API Response format
`Response::success($data)` outputs: `{"success": true, "message": "Success", "data": <your data>}`
`Response::error($msg, $code)` outputs: `{"success": false, "message": "<msg>", "data": null}`

In JavaScript, parse as:
```javascript
fetch(url).then(r => r.json()).then(data => {
    if (data.success && data.data) {
        // data.data contains the payload
    }
});
```

### Pattern 5: Cache usage
The `$cache` variable (from bootstrap.php) is a `Cache` instance (Memcached + MySQL fallback).
Your TrackingCache class MUST take `Cache $cache` in constructor, NOT `PDO $db`:
```php
class TrackingCache {
    private Cache $cache;
    public function __construct(Cache $cache) { $this->cache = $cache; }
}

// Usage in pages/endpoints:
$trackingCache = new TrackingCache($cache);  // $cache from bootstrap, NOT $db
```

### Pattern 6: Global header/footer z-index hierarchy
```
z-index: 99999  - App loader (hidden after page load)
z-index: 9999   - Voice modal (footer.php)
z-index: 1999   - Mobile sidebar (header.php)
z-index: 1998   - Mobile overlay (header.php)
z-index: 1000   - Global header bar (header.php, position: sticky)
z-index: 999    - Global footer (footer.php, position: fixed, pointer-events: none)
z-index: 998    - Menu backdrop (footer.php)
```

Your tracking page z-indexes MUST fit within this hierarchy. Recommended:
```
z-index: 500    - .tracking-app container (below footer/header)
                  Inside tracking-app (local stacking context):
z-index: 200    - Consent overlay
z-index: 55     - Notification prompt
z-index: 50     - Tracking topbar
z-index: 45     - Wake FAB
z-index: 42     - Directions bar
z-index: 40     - Family panel
z-index: 1      - Map (#trackingMap)
```

For the consent overlay to appear ABOVE the global footer, place it OUTSIDE .tracking-app with z-index: 5000.

---

## STEP 2: TRACKING PAGE LAYOUT (index.php)

The tracking main page is a fullscreen map. Key layout rules:

1. **Hide global header** on tracking page (tracking has its own topbar with navigation)
2. **Keep global footer visible** (mic, tracking btn, plus menu float over map bottom)
3. **Add hamburger menu** to tracking topbar so users can open the mobile sidebar for navigation
4. **.tracking-app** uses `position: fixed; inset: 0; z-index: 500` (below footer 999, below header 1000)
5. **#trackingMap** uses `position: absolute; inset: 0; z-index: 1` (lowest layer inside tracking-app)
6. **All UI overlays** (topbar, panel, FAB) use `position: absolute` with z-index > 1 inside tracking-app
7. **Consent overlay** placed OUTSIDE .tracking-app with `position: fixed; z-index: 5000`

```html
<style>
body.tracking-page { overflow: hidden !important; padding-bottom: 0 !important; margin: 0 !important; }
body.tracking-page .global-header { display: none !important; }
body.tracking-page .app-loader { display: none !important; }
.tracking-app { position: fixed; inset: 0; z-index: 500; }
#trackingMap { position: absolute; inset: 0; width: 100%; height: 100%; z-index: 1; }
</style>
<script>document.body.classList.add('tracking-page');</script>
```

The tracking topbar MUST include a hamburger button:
```html
<div class="tracking-topbar">
    <div style="display:flex;align-items:center;gap:12px;">
        <button class="tracking-topbar-btn" id="trackingMenuBtn" title="Menu">
            <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
        </button>
        <span class="tracking-topbar-title">Family Tracking</span>
    </div>
    <div class="tracking-topbar-actions">
        <!-- Events, Geofences, Settings links -->
    </div>
</div>
```

Wire it up to the global sidebar:
```javascript
document.getElementById('trackingMenuBtn').addEventListener('click', function() {
    var sidebar = document.getElementById('mobileSidebar');
    var overlay = document.getElementById('mobileMenuOverlay');
    if (sidebar) sidebar.classList.add('active');
    if (overlay) overlay.classList.add('active');
});
```

The tracking CSS MUST style SVG icons:
```css
.tracking-topbar-btn svg {
    width: 20px;
    height: 20px;
    stroke: currentColor;
    fill: none;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}
```

---

## STEP 3: WHAT TO BUILD - /tracking/ DIRECTORY

### Directory structure:
```
/tracking/
├── index.php                    # Redirect to app/
├── core/
│   ├── bootstrap_tracking.php   # Loads core bootstrap + tracking classes
│   ├── SiteContext.php          # Auth context for API endpoints
│   ├── Time.php                 # Time formatting utilities
│   ├── Validator.php            # Input validation
│   ├── services/
│   │   ├── TrackingCache.php    # Cache wrapper (constructor takes Cache, NOT PDO)
│   │   ├── RateLimiter.php      # Rate limiting via cache
│   │   ├── Dedupe.php           # Dedup duplicate location updates
│   │   ├── SessionGate.php      # Live session management
│   │   ├── MotionGate.php       # Motion detection gate
│   │   ├── GeofenceEngine.php   # Geofence enter/exit detection
│   │   ├── AlertsEngine.php     # Alert rule processing
│   │   └── MapboxDirections.php # Mapbox directions API wrapper
│   └── repos/
│       ├── SettingsRepo.php     # Family tracking settings
│       ├── SessionsRepo.php     # Live tracking sessions
│       ├── LocationRepo.php     # Current + history locations
│       ├── EventsRepo.php       # Tracking events log
│       ├── GeofenceRepo.php     # Geofence CRUD
│       ├── PlacesRepo.php       # Saved places
│       └── AlertsRepo.php       # Alert rules
├── api/
│   ├── update.php               # POST - receive location update (main pipeline)
│   ├── current.php              # GET - get family current locations
│   ├── history.php              # GET - location history for a user
│   ├── settings.php             # GET/POST - family tracking settings
│   ├── geofences.php            # GET/POST - CRUD geofences
│   ├── geofence_delete.php      # POST - delete geofence
│   ├── events.php               # GET - tracking events
│   ├── places.php               # GET/POST - saved places
│   ├── place_delete.php         # POST - delete place
│   ├── alerts.php               # GET/POST - alert rules
│   ├── alert_delete.php         # POST - delete alert
│   ├── directions.php           # GET - Mapbox directions
│   ├── session_start.php        # POST - start live tracking session
│   ├── session_stop.php         # POST - stop live tracking session
│   ├── session_status.php       # GET - check session status
│   ├── wake_devices.php         # POST - send FCM wake push to family
│   ├── register_device.php      # POST - register FCM token
│   └── consent.php              # POST - save tracking consent
├── app/
│   ├── index.php                # Main tracking dashboard (fullscreen map)
│   ├── events.php               # Events timeline page
│   ├── geofences.php            # Geofence management page
│   ├── settings.php             # Tracking settings page
│   ├── manifest.json            # PWA manifest
│   ├── sw.js                    # Service worker
│   └── assets/
│       ├── css/
│       │   └── tracking.css     # All tracking styles
│       └── js/
│           └── state.js         # Global state manager (Tracking.setState/getState/onStateChange)
└── cron/
    ├── cleanup_sessions.php     # Clean expired sessions
    ├── prune_history.php        # Prune old location history
    └── recompute_geofence_states.php  # Recompute geofence states
```

### Location Update Pipeline (api/update.php):
```
Receive POST → Validate input → Check accuracy (reject > 100m) → Rate limit (cache)
→ Deduplicate (reject if <10m from last) → Motion gate (skip if stationary)
→ Upsert current location → Store history → Process geofences → Process alerts
→ Return success
```

### Tracking Modes:
- **Mode 1 (Live Session)**: Battery-efficient, uses cache keepalive, 15-30s intervals
- **Mode 2 (Motion-Based)**: GPS only when device detects movement, stores when significant distance change

### Database tables needed (create migration):
```sql
-- tracking_current_locations: one row per user, upserted on each update
-- tracking_location_history: append-only log of all positions
-- tracking_sessions: live tracking sessions
-- tracking_settings: per-family settings (intervals, units, map style, etc.)
-- tracking_geofences: geofence definitions (circle/polygon)
-- tracking_geofence_states: current in/out state per user per geofence
-- tracking_events: event log (enter, exit, arrive, leave, etc.)
-- tracking_places: saved places (home, work, school, etc.)
-- tracking_alert_rules: alert configurations
-- tracking_devices: FCM device tokens for push notifications
-- tracking_consent: user consent records
```

---

## STEP 4: WHAT TO BUILD - /App Files/ DIRECTORY

### Android Native App (Kotlin)
WebView-based app that loads the web app, with native location tracking and Firebase Cloud Messaging.

### Key files:
```
/App Files/app/src/main/java/za/co/relatives/app/
├── MainActivity.kt              # WebView + permission handling + JS interface
├── RelativesApplication.kt      # App initialization + notification channels
├── tracking/
│   ├── TrackingJsInterface.kt   # @JavascriptInterface bridge between web and native
│   ├── LocationTrackingService.kt # Foreground service for GPS tracking
│   ├── TrackingManager.kt       # 3-mode state machine: IDLE → MOVING → BURST
│   ├── LocationUploader.kt      # Batch upload locations to API
│   └── MotionDetector.kt        # Activity recognition for motion detection
├── notifications/
│   ├── FCMService.kt            # FirebaseMessagingService
│   ├── NotificationHelper.kt    # Create/manage notification channels
│   └── NotificationActionReceiver.kt  # Handle notification button actions
├── data/
│   ├── PreferencesManager.kt    # SharedPreferences wrapper
│   ├── LocationDatabase.kt      # Room database for offline queue
│   └── LocationEntity.kt        # Room entity for queued locations
└── utils/
    ├── BatteryUtils.kt          # Battery level/state checks
    ├── NetworkUtils.kt          # Connectivity checks
    └── PermissionUtils.kt       # Runtime permission helpers
```

### Tracking State Machine (3 modes):
```
IDLE (no GPS, uses activity recognition only)
  ↓ motion detected
MOVING (GPS every 15-30s, distance gating: only upload if moved >10m)
  ↓ high speed detected
BURST (GPS every 5-10s for accurate tracking during driving)
  ↓ stationary for 2 min
IDLE
```

### Required Android permissions:
```xml
<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />
<uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION" />
<uses-permission android:name="android.permission.ACCESS_BACKGROUND_LOCATION" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE_LOCATION" />
<uses-permission android:name="android.permission.INTERNET" />
<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
<uses-permission android:name="com.google.android.gms.permission.ACTIVITY_RECOGNITION" />
<uses-permission android:name="android.permission.ACTIVITY_RECOGNITION" />
<uses-permission android:name="android.permission.RECEIVE_BOOT_COMPLETED" />
<uses-permission android:name="android.permission.WAKE_LOCK" />
```

### JS Interface (web ↔ native bridge):
```kotlin
@JavascriptInterface
fun startTracking()      // Start native location tracking
@JavascriptInterface
fun stopTracking()       // Stop tracking
@JavascriptInterface
fun getTrackingState(): String  // Returns "IDLE", "MOVING", or "BURST"
@JavascriptInterface
fun isNativeApp(): Boolean      // Returns true (web checks this)
@JavascriptInterface
fun getFCMToken(): String       // Returns FCM token for push registration
@JavascriptInterface
fun requestPermissions()        // Trigger native permission dialogs
```

### FCM Push Notification Handling:
- Wake push: `{"type": "wake_tracking"}` → Start location tracking service
- Geofence alert: `{"type": "geofence_alert", "message": "...", "user_name": "..."}` → Show notification
- Family alert: `{"type": "family_alert", ...}` → Show notification
- Silent data: `{"type": "location_request"}` → Silently request location update

### build.gradle dependencies:
```groovy
implementation platform('com.google.firebase:firebase-bom:33.0.0')
implementation 'com.google.firebase:firebase-messaging-ktx'
implementation 'com.google.android.gms:play-services-location:21.2.0'
implementation 'androidx.room:room-runtime:2.6.1'
kapt 'androidx.room:room-compiler:2.6.1'
implementation 'androidx.work:work-runtime-ktx:2.9.0'
implementation 'com.google.android.gms:play-services-activity-recognition:18.1.0'
```

---

## STEP 5: ENVIRONMENT & CONFIG

- **Mapbox token**: Available as `$_ENV['MAPBOX_TOKEN']` (from bootstrap.php)
- **Firebase**: Server key in `$_ENV['FCM_SERVER_KEY']`
- **Base URL**: `https://relatives.co.za`
- **Timezone**: `Africa/Johannesburg` (default for families)
- **PHP version**: 8.0+
- **Android**: minSdk 26, targetSdk 34, Kotlin

---

## STEP 6: COMMON PITFALLS (FROM PREVIOUS BUILD)

1. **TrackingCache($db)** → WRONG. Must be **TrackingCache($cache)**
2. **data.ok / data.members** in JS → WRONG. Must be **data.success / data.data**
3. **Session::validate()** directly → Works but inconsistent. Use **Auth::getCurrentUser()** via Auth class
4. **position: fixed with z-index: 10000** on tracking-app → Covers EVERYTHING including footer. Use **z-index: 500**
5. **Hiding footer-container, bottom-nav** with display:none → Kills navigation. Only hide **.global-header** and **.app-loader**
6. **SVG icons without stroke** → Invisible. CSS must set `stroke: currentColor; fill: none; stroke-width: 2`
7. **Consent overlay inside .tracking-app** → Gets trapped in stacking context below footer. Place OUTSIDE with z-index: 5000
8. **bootstrap_tracking.php without session_start()** → API endpoints fail auth. Must call `session_start()` before `require bootstrap.php`
9. **#trackingMap position:absolute inset:0** without z-index → Covers all sibling elements. Must set **z-index: 1** and give siblings higher z-index
10. **PlacesRepo constructor** - if it takes (PDO, TrackingCache), don't pass wrong types
11. **AlertsEngine constructor** - verify constructor signature matches what you pass

---

## STEP 7: TESTING CHECKLIST

After building, verify each of these:
- [ ] `/tracking/app/` loads without redirect to `/login.php` or `/home/`
- [ ] Map renders (not grey/blank)
- [ ] Topbar shows "Family Tracking" with visible hamburger + events/geofences/settings icons
- [ ] Hamburger opens the global mobile sidebar (can navigate to Home, Messages, etc.)
- [ ] Global footer buttons (mic, tracking, plus) are visible at bottom of map
- [ ] Family panel is visible (desktop: left side, mobile: bottom sheet)
- [ ] Wake FAB button visible (pink circle, bottom-right)
- [ ] Console has no JS errors
- [ ] `/tracking/api/current.php` returns `{"success":true,"data":[...]}`
- [ ] `/tracking/app/settings.php` loads with header/footer and settings form
- [ ] `/tracking/app/events.php` loads with header/footer and events timeline
- [ ] `/tracking/app/geofences.php` loads with header/footer and geofence list
- [ ] Consent overlay appears (if not previously accepted) centered above everything
- [ ] Toggle switches in consent dialog look like pill-shaped toggles, NOT grey overlays

---

## STEP 8: GIT

- Branch: create a new branch from main
- Commit message: "Clean rebuild: tracking module + Android app"
- Push when complete
