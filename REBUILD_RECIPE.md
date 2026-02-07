# REBUILD RECIPE v3: /tracking/ + /App Files/

## INSTRUCTIONS

Delete ALL code in `/tracking/` and `/App Files/` directories. Rebuild from scratch. Also create a fresh SQL migration at `migrations/tracking-rebuild-v3.sql`.

**CRITICAL: Read all files in Step 0 FIRST before writing any code. Do NOT look at any existing /tracking/ or /App Files/ code.**

---

## STEP 0: READ THESE FILES FIRST (DO NOT SKIP)

```
/core/bootstrap.php            — Session init, env loading, DB + Cache singletons ($db, $cache)
/core/Auth.php                 — Auth class: login(), getCurrentUser() returns user array
/core/Session.php              — RELATIVES_SESSION cookie, validate() returns user data
/core/Cache.php                — Two-tier cache: Memcached + MySQL fallback. Singleton via Cache::init($db)
/core/Response.php             — Response::success($data) and Response::error($msg, $code)
/core/GeoUtils.php             — geo_haversineDistance(), geo_isPointInPolygon()
/core/NotificationManager.php  — FCM push: NotificationManager::getInstance($db)->create([...])
/core/NotificationTriggers.php — onGeofenceEnter(), onGeofenceExit(), onLowBattery(), onSOSAlert()
/shared/components/header.php  — Global header (opens <html>, <head>, CSS, nav, mobile sidebar)
/shared/components/footer.php  — Global footer (mic/tracking/plus buttons, voice modal, closes </body></html>)
/home/index.php                — Working auth pattern example
```

---

## STEP 1: CRITICAL PATTERNS

### 1A. Auth on PHP pages
Login stores `user_id` under default `PHPSESSID`. Bootstrap changes session to `RELATIVES_SESSION`. If bootstrap runs first, it reads an empty session.

```php
session_start();                                    // opens PHPSESSID
if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
require_once __DIR__ . '/../../core/bootstrap.php'; // now $db, $cache available
$auth = new Auth($db);
$user = $auth->getCurrentUser();
if (!$user) { header('Location: /login.php'); exit; }
```

### 1B. Auth on API endpoints
```php
require_once __DIR__ . '/../core/bootstrap_tracking.php';
$ctx = SiteContext::require($db);   // dies 401 if not authenticated
// $ctx->userId, $ctx->familyId, $ctx->role, $ctx->name, $ctx->isAdmin()
```

### 1C. API Response format
```php
Response::success($data);    // → {"success": true, "message": "Success", "data": <payload>}
Response::error('msg', 400); // → {"success": false, "error": "msg"}
```
JS: always `data.success` and `data.data` — NEVER `data.ok` or `data.members`.

### 1D. Cache — TrackingCache takes Cache, NOT PDO
```php
$trackingCache = new TrackingCache($cache);   // $cache from bootstrap.php
$locationRepo = new LocationRepo($db, $trackingCache);
```

### 1E. Page template
```php
$pageTitle = 'Page Title';
$pageCSS = ['/tracking/app/assets/css/tracking.css'];
require_once __DIR__ . '/../../shared/components/header.php';
?>
<!-- content -->
<?php
$pageJS = [];
require_once __DIR__ . '/../../shared/components/footer.php';
?>
```

### 1F. Z-Index hierarchy
```
tracking-app: position:fixed; inset:0; z-index:10000
  #trackingMap: position:absolute; inset:0
  topbar: z-index:20
  family panel: z-index:15
  wake FAB: z-index:25
  directions bar: z-index:30
consent overlay: z-index:100000 (OUTSIDE .tracking-app)
notification prompt: z-index:99999 (OUTSIDE .tracking-app)
global footer: z-index:999 (HIDDEN on tracking page)
global header: z-index:1000 (HIDDEN on tracking page)
```

### 1G. Tracking page hides ALL global chrome
```css
body.tracking-page { overflow:hidden!important; padding-bottom:0!important; }
body.tracking-page .footer-container,
body.tracking-page .bottom-nav,
body.tracking-page .plus-menu-overlay,
body.tracking-page .plus-menu-container,
body.tracking-page .app-loader { display:none!important; }
.tracking-app { position:fixed; inset:0; z-index:10000; }
#trackingMap { position:absolute; inset:0; width:100%; height:100%; }
```

### 1H. SVG icons
All SVGs: `stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round`

---

## STEP 2: SQL MIGRATION — migrations/tracking-rebuild-v3.sql

Drop ALL old tracking tables first:
```sql
DROP TABLE IF EXISTS tracking_alerts, tracking_browser_subscriptions, tracking_cache_stats,
    tracking_checkins, tracking_current, tracking_devices, tracking_events,
    tracking_geofence_queue, tracking_history, tracking_locations, tracking_members,
    tracking_places, tracking_settings, tracking_zones, tracking_current_locations,
    tracking_location_history, tracking_sessions, tracking_geofences,
    tracking_geofence_states, tracking_alert_rules, tracking_consent;
```

### Table: tracking_current (UPSERT — one row per user)
Columns: `user_id` (PK), `family_id`, `lat` DECIMAL(10,7), `lng` DECIMAL(10,7), `accuracy_m` FLOAT, `speed_mps` FLOAT, `bearing_deg` FLOAT, `altitude_m` FLOAT, `motion_state` ENUM('still','walking','driving','unknown'), `recorded_at` DATETIME, `device_id` VARCHAR(100), `platform` VARCHAR(20), `app_version` VARCHAR(20), `updated_at` DATETIME auto-update.
Keys: idx_family(family_id), idx_updated(updated_at).

### Table: tracking_locations (append-only history)
Columns: `id` BIGINT AUTO PK, `family_id`, `user_id`, `lat`, `lng`, `accuracy_m`, `speed_mps`, `bearing_deg`, `altitude_m`, `motion_state`, `recorded_at`, `created_at`.
Keys: idx_family_user_time(family_id, user_id, recorded_at DESC), idx_created(created_at).

### Table: tracking_sessions
Columns: `id` BIGINT AUTO PK, `family_id`, `started_by`, `status` ENUM('active','stopped','expired'), `keepalive_interval_seconds` INT DEFAULT 30, `started_at`, `last_keepalive`, `stopped_at`, `expires_at`.
Keys: idx_family_status, idx_expires.

### Table: tracking_settings
Columns: `family_id` (PK), `keepalive_interval_seconds` DEFAULT 30, `history_retention_days` DEFAULT 30, `units` ENUM('metric','imperial') DEFAULT 'metric', `map_style` ENUM('streets','satellite','dark','light') DEFAULT 'dark', `show_speed` DEFAULT 1, `show_battery` DEFAULT 1, `show_accuracy` DEFAULT 0, `geofence_notifications` DEFAULT 1, `low_battery_alert` DEFAULT 1, `low_battery_threshold` DEFAULT 15, `updated_at`.

### Table: tracking_geofences
Columns: `id` BIGINT AUTO PK, `family_id`, `created_by`, `name` VARCHAR(100), `type` ENUM('circle','polygon'), `lat`, `lng`, `radius_m` DEFAULT 200, `polygon_json` TEXT, `color` DEFAULT '#667eea', `notify_enter` DEFAULT 1, `notify_exit` DEFAULT 1, `active` DEFAULT 1, `updated_at`, `created_at`.

### Table: tracking_geofence_states
Columns: `id` BIGINT AUTO PK, `geofence_id`, `user_id`, `is_inside` DEFAULT 0, `entered_at`, `exited_at`, `updated_at`.
UNIQUE(geofence_id, user_id).

### Table: tracking_events
Columns: `id` BIGINT AUTO PK, `family_id`, `user_id`, `event_type` ENUM('geofence_enter','geofence_exit','session_start','session_stop','low_battery','sos','speed_alert','custom'), `title`, `description`, `lat`, `lng`, `meta_json`, `created_at`.

### Table: tracking_places
Columns: `id` BIGINT AUTO PK, `family_id`, `created_by`, `name`, `icon`, `lat`, `lng`, `address`, `radius_m` DEFAULT 100, `created_at`.

### Table: tracking_alert_rules
Columns: `id` BIGINT AUTO PK, `family_id`, `created_by`, `name`, `rule_type` ENUM('speed','battery','geofence','inactivity','custom'), `target_user_id`, `conditions_json`, `notify_users_json`, `active` DEFAULT 1, `cooldown_minutes` DEFAULT 30, `updated_at`, `created_at`.

### Table: tracking_devices
Columns: `id` BIGINT AUTO PK, `user_id`, `fcm_token` VARCHAR(500), `device_type` ENUM('android','ios','web'), `device_name`, `active` DEFAULT 1, `updated_at`, `created_at`.
UNIQUE(fcm_token(191)).

### Table: tracking_consent
Columns: `user_id` (PK), `family_id`, `location_consent`, `notification_consent`, `background_consent`, `ip_address`, `user_agent`, `consented_at`, `updated_at`.

---

## STEP 3: /tracking/ DIRECTORY STRUCTURE

```
tracking/
  index.php                           # redirect to app/
  core/
    bootstrap_tracking.php            # session_start() before bootstrap, explicit require for each class
    SiteContext.php                    # fromSession(), require(), requireLocationSharing(), isAdmin()
    Time.php                          # TrackingTime utilities
    Validator.php                     # TrackingValidator
  core/services/
    TrackingCache.php                 # Named methods per cache domain (see Step 4)
    RateLimiter.php                   # allow($userId, $max) via cache
    Dedupe.php                        # isDuplicate via cache + haversine
    SessionGate.php                   # isActive, start, stop, keepalive
    MotionGate.php                    # shouldStore($speed, $accuracy)
    GeofenceEngine.php                # process($userId, $familyId, $lat, $lng)
    AlertsEngine.php                  # process($userId, $familyId, $location)
    MapboxDirections.php              # getRoute(from, to, profile)
  core/repos/
    SettingsRepo.php                  # get($familyId), save() — cached
    SessionsRepo.php                  # start(), stop(), getActive(), cleanup()
    LocationRepo.php                  # upsertCurrent(), insertHistory(), getFamilyCurrentLocations(), getHistory()
    EventsRepo.php                    # create(), getForFamily()
    GeofenceRepo.php                  # CRUD + state mgmt
    PlacesRepo.php                    # getForFamily(), create(), delete()
    AlertsRepo.php                    # get(), create(), update(), delete()
  api/
    update.php                        # POST — location pipeline
    current.php                       # GET — family current locations
    history.php                       # GET — user history
    settings.php                      # GET/POST
    geofences.php                     # GET/POST
    geofence_delete.php               # POST
    events.php                        # GET
    places.php                        # GET/POST
    place_delete.php                  # POST
    alerts.php                        # GET/POST
    alert_delete.php                  # POST
    directions.php                    # GET — Mapbox proxy
    session_start.php                 # POST
    session_stop.php                  # POST
    session_status.php                # GET
    wake_devices.php                  # POST — FCM wake
    register_device.php               # POST — register FCM token
    consent.php                       # POST
  app/
    index.php                         # Fullscreen map dashboard
    events.php                        # Events timeline
    geofences.php                     # Geofence management
    settings.php                      # Settings form
    assets/css/tracking.css           # Complete stylesheet
    assets/js/state.js                # Observable state store
    manifest.json                     # PWA manifest
    sw.js                             # Service worker
  cron/
    cleanup_sessions.php
    prune_history.php
    recompute_geofence_states.php
```

---

## STEP 4: TrackingCache — Named Methods (CRITICAL)

Constructor: `__construct(Cache $cache)` — NOT PDO.

Cache key domains and TTLs:
```
trk:live:{familyId}                        — 300s   getSession/setSession/deleteSession
trk:cur:{userId}                           — 120s   getCurrent/setCurrent
trk:family_cur:{familyId}                  — 10s    getFamilySnapshot/setFamilySnapshot/deleteFamilySnapshot
trk:settings:{familyId}                    — 600s   getSettings/setSettings/deleteSettings
trk:rl:{userId}                            — 300s   getRateLimit/setRateLimit
trk:dd:{userId}                            — var    getDedupePoint/setDedupePoint
trk:geo:{familyId}                         — 600s   getGeofences/setGeofences/deleteGeofences
trk:geo_state:{userId}                     — 600s   getGeofenceState/setGeofenceState/deleteGeofenceState
trk:places:{familyId}                      — 600s   getPlaces/setPlaces/deletePlaces
trk:dir:{profile}:{hash}                   — 21600s getDirections/setDirections
trk:alerts:{familyId}                      — 600s   getAlertRules/setAlertRules/deleteAlertRules
trk:alerts_cd:{fam}:{rule}:{uid}:{tid}     — var    getAlertCooldown/setAlertCooldown
```

Private `decode($value)` helper: handles both string (MySQL json) and array (Memcached) returns.

---

## STEP 5: LocationRepo Pattern (CRITICAL)

Constructor: `__construct(PDO $db, TrackingCache $cache)`

### upsertCurrent(int $userId, int $familyId, array $loc, string $motionState)
INSERT INTO `tracking_current` ON DUPLICATE KEY UPDATE. After DB write:
- `$cache->setCurrent($userId, [lat, lng, accuracy_m, speed_mps, bearing_deg, altitude_m, motion_state, recorded_at])`
- `$cache->deleteFamilySnapshot($familyId)` — invalidate family cache

### insertHistory(int $familyId, int $userId, array $loc, string $motionState)
INSERT INTO `tracking_locations`.

### getFamilyCurrentLocations(int $familyId): array
1. Check `$cache->getFamilySnapshot($familyId)` — return if hit
2. On miss: `SELECT tc.*, u.full_name AS name, u.avatar_color, u.location_sharing FROM tracking_current tc JOIN users u ON tc.user_id = u.id WHERE tc.family_id = ? AND u.status = 'active' AND u.location_sharing = 1`
3. Cache result with `setFamilySnapshot($familyId, $rows)`

### getHistory(int $familyId, int $userId, string $from, string $to, int $limit): array
SELECT from `tracking_locations` with date range, ORDER BY recorded_at ASC.

### getCurrent(int $userId): ?array
Check cache first, then DB.

### pruneHistory(int $retentionDays): int
DELETE old rows from `tracking_locations`.

The `$loc` array keys: `lat`, `lng`, `accuracy_m`, `speed_mps`, `bearing_deg`, `altitude_m`, `recorded_at`, `device_id`, `platform`, `app_version`

---

## STEP 6: SiteContext

```php
class SiteContext {
    public int $userId;
    public int $familyId;
    public string $role;
    public string $name;
    public bool $locationSharing;
    public PDO $db;

    public static function fromSession(PDO $db): ?self   // direct DB query, NOT Auth class
    public static function require(PDO $db): self         // dies 401 if no auth
    public function requireLocationSharing(): void        // dies 403 if disabled
    public function isAdmin(): bool                       // owner or admin
}
```

The `fromSession()` method queries users table directly: `SELECT u.id, u.family_id, u.role, u.full_name AS name, u.location_sharing, u.status FROM users u WHERE u.id = ? AND u.status = 'active'`

---

## STEP 7: Location Update Pipeline (api/update.php)

```
POST body: { lat, lng, accuracy_m, speed_mps, bearing_deg, altitude_m, recorded_at, device_id, platform, app_version, motion_state }

1. Validate JSON
2. SiteContext::require($db)
3. TrackingValidator::locationUpdate($input) — sanitize
4. Reject if accuracy_m > 100
5. RateLimiter: max 4/min
6. Dedupe: skip if < 10m from last (haversine)
7. Always upsertCurrent (even dupes — timestamps matter)
8. If NOT dupe:
   a. insertHistory
   b. GeofenceEngine::process() → enter/exit events
   c. Fire NotificationTriggers for geofence events
   d. AlertsEngine::process() → speed/battery rules
9. Response::success(['stored' => !$isDupe])
```

---

## STEP 8: Tracking Map Page (app/index.php)

### Features:
- Fullscreen Mapbox GL JS v3.4.0 (streets, satellite, dark, light)
- Family panel (left sidebar desktop, bottom sheet mobile)
- Avatar markers — user initial + avatar_color circle
- Directions to a member via Mapbox Directions API
- Wake FAB — FCM push to all family devices
- Consent overlay OUTSIDE .tracking-app
- Notification permission prompt
- Polling /api/current.php every N seconds

### Config from PHP to JS:
```js
window.TrackingConfig = {
    mapboxToken: "...",
    userId: 123,
    familyId: 456,
    userName: "...",
    userRole: "owner",
    avatarColor: "#667eea",
    settings: {...},         // from SettingsRepo
    alertRules: [...],       // from AlertsRepo
    apiBase: "/tracking/api",
    isAdmin: true/false
};
```

### state.js — Observable state store (separate file):
```js
window.Tracking = window.Tracking || {};
Tracking.setState(key, value);
Tracking.getState(key);
Tracking.onStateChange(key, callback); // returns unsubscribe fn
```

### Inline JS in index.php handles:
- Map init with style from settings
- fetchMembers() → GET /api/current.php → renderMembers + updateMarkers
- Markers: custom div elements with avatar circle
- flyToMember(userId)
- getDirections(userId) → GET /api/directions.php → draw route
- Panel toggle (desktop collapse / mobile expand)
- Wake FAB click handler
- Consent accept/decline
- Notification permission prompt
- Service worker registration
- Poll interval from settings.keepalive_interval_seconds

### CSS tracking.css must include:
- .tracking-app: fixed fullscreen
- .tracking-topbar: glass bar with actions
- .family-panel: responsive sidebar/bottom sheet
- .map-marker / .map-marker-inner: 36px avatar circles
- .member-item: clickable row with avatar, name, time, speed
- .member-status-dot: green (<5m), yellow (<30m), red (>30m)
- .wake-fab: floating action button
- .directions-bar: slide-up info bar
- .consent-overlay: centered card with toggles
- .notification-prompt: slide-up banner
- Dark glass aesthetic matching app theme

---

## STEP 9: /App Files/ — Android APK

### GOOGLE PLAY POLICY COMPLIANCE (MUST FIX)

#### Issue 1: Prominent Disclosure (CRITICAL)
Google requires a standalone disclosure screen BEFORE any permission request:
- Create `LocationDisclosureActivity.kt`
- Shows BEFORE MainActivity on first launch
- Explains: what data (location), why (family safety), how (shared with family)
- Has "I Agree" and "No Thanks" buttons
- Only after "I Agree" does the app proceed to request permissions
- This fixes the "Prominent Disclosure and Consent Requirement" violation

The 4 Google-required screenshot flow:
1. Prominent disclosure screen (your custom screen)
2. "Always-On Location" explanation (your custom dialog)
3. Android system "While using" permission dialog
4. Background location permission dialog

#### Issue 2: Google Play Billing Library v7.0.0+
Required by policy even for free apps:
```kotlin
implementation("com.android.billingclient:billing-ktx:7.1.1")
```

#### Issue 3: Notification Bar Styling
Black status bar + navigation bar, white text:
```xml
<item name="android:statusBarColor">@android:color/black</item>
<item name="android:navigationBarColor">@android:color/black</item>
<item name="android:windowLightStatusBar">false</item>
<item name="android:windowLightNavigationBar">false</item>
```

### Gradle — libs.versions.toml (EXACT versions)
```toml
[versions]
agp = "8.13.1"
kotlin = "2.0.21"
ksp = "2.0.21-1.0.28"
room = "2.6.1"
googleServices = "4.4.2"
firebaseBom = "33.7.0"
coreKtx = "1.17.0"
lifecycleRuntimeKtx = "2.10.0"
activityCompose = "1.12.0"
composeBom = "2024.09.00"
```

### CRITICAL BUILD RULES:
- Use **KSP** for Room (NOT kapt — kapt is deprecated)
- Use **Kotlin 2.0.21** with **kotlin-compose** plugin
- **NO** `play-services-activity-recognition` — it's included in `play-services-location`
- `play-services-location:21.3.0` includes Activity Recognition API
- `@Dao` annotation MUST be on the Room DAO interface
- `dependencyResolutionManagement` in settings.gradle.kts (NOT `dependencyResolution`)

### settings.gradle.kts
```kotlin
pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}
rootProject.name = "Relatives"
include(":app")
```

### App Structure:
```
App Files/app/src/main/java/za/co/relatives/app/
  MainActivity.kt                      # WebView + JS bridge + permissions
  RelativesApplication.kt              # Notification channels
  LocationDisclosureActivity.kt        # NEW: Google Play prominent disclosure
  tracking/
    TrackingJsInterface.kt             # @JavascriptInterface bridge
    LocationTrackingService.kt         # Foreground service
    TrackingManager.kt                 # 3-mode: IDLE → MOVING → BURST
    LocationUploader.kt                # HTTP POST to /tracking/api/update.php
    MotionDetector.kt                  # Activity Recognition (from play-services-location)
  notifications/
    FCMService.kt                      # Firebase messaging
    NotificationHelper.kt             # Show alerts
    NotificationActionReceiver.kt     # Handle actions
    BootReceiver.kt                   # Resume on reboot
  data/
    PreferencesManager.kt             # SharedPreferences
    LocationDatabase.kt               # Room DB + @Dao interface
    LocationEntity.kt                 # Room entity
  utils/
    BatteryUtils.kt
    NetworkUtils.kt
    PermissionUtils.kt
```

### TrackingManager 3-Mode State Machine:
```
IDLE: No GPS. Listen for Activity Recognition only.
  → motion detected → MOVING

MOVING: GPS every 15-30s. Upload if moved >10m.
  → speed < 0.5 m/s for 2 min → IDLE
  → speed > 10 m/s → BURST

BURST: GPS every 5-10s. Upload every point.
  → speed < 5 m/s → MOVING
```
This saves battery by never requesting GPS when stationary.

### Permission Flow (Google Play Compliant):
1. App starts → check SharedPreferences for disclosure_shown
2. If NOT shown → launch LocationDisclosureActivity
3. Disclosure explains location usage with "I Agree" / "No Thanks"
4. On agree → save pref → finish() → back to MainActivity
5. MainActivity requests ACCESS_FINE_LOCATION
6. After granted, explain background location need
7. Request ACCESS_BACKGROUND_LOCATION
8. Same pattern for RECORD_AUDIO (mic) and POST_NOTIFICATIONS

### JS Interface (@JavascriptInterface):
```kotlin
fun startTracking()
fun stopTracking()
fun getTrackingState(): String        // "IDLE", "MOVING", "BURST"
fun isNativeApp(): Boolean            // true
fun getFCMToken(): String
fun requestLocationPermissions()
fun requestBackgroundLocationPermission()
fun hasLocationPermission(): Boolean
```

---

## STEP 10: VERIFICATION CHECKLIST

Before committing, verify ALL of these:

- [ ] TrackingCache constructor takes `Cache $cache` (NOT PDO)
- [ ] LocationRepo constructor takes `PDO $db` AND `TrackingCache $cache`
- [ ] Table names: `tracking_current`, `tracking_locations` (NOT tracking_current_locations or tracking_location_history)
- [ ] Column names: `speed_mps`, `bearing_deg`, `accuracy_m`, `altitude_m`, `motion_state`, `recorded_at`
- [ ] SiteContext::require($db) for API auth
- [ ] SiteContext::fromSession() does direct DB query (NOT through Auth class)
- [ ] session_start() called BEFORE require bootstrap.php
- [ ] JS uses data.success and data.data (NEVER data.ok or data.members)
- [ ] @Dao annotation on Room DAO interface
- [ ] KSP used for Room (NOT kapt)
- [ ] play-services-location:21.3.0 (NO separate activity-recognition)
- [ ] dependencyResolutionManagement in settings.gradle.kts
- [ ] Google Play Billing Library v7.1.1+ included
- [ ] LocationDisclosureActivity exists and launches before permissions
- [ ] Android status bar: black bg, white text
- [ ] Consent overlay OUTSIDE .tracking-app div
- [ ] Global footer HIDDEN on tracking page
- [ ] All SVGs: stroke=currentColor, fill=none, stroke-width=2
- [ ] Mapbox GL JS v3.4.0
- [ ] Default map style: dark-v11
- [ ] bootstrap_tracking.php uses explicit require_once (NOT glob)

---

## STEP 11: BUILD ORDER

1. SQL migration
2. tracking/core/bootstrap_tracking.php
3. tracking/core/SiteContext.php, Time.php, Validator.php
4. tracking/core/services/TrackingCache.php (FIRST — repos depend on it)
5. Remaining services
6. All repos
7. All API endpoints
8. App pages
9. CSS, JS, manifest, sw.js
10. Cron jobs
11. Android: gradle files → Kotlin sources → resources

---

## ENVIRONMENT

- MAPBOX_TOKEN in .env (loaded by bootstrap into $_ENV)
- FCM_SERVER_KEY in .env
- Base URL: https://relatives.co.za
- Timezone: Africa/Johannesburg
- PHP 8.0+
- Android: minSdk 26, targetSdk 34
