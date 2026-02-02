# Tracking V2 - Memcache Keys

All tracking cache keys use the prefix `trk:` to namespace from other app caches.

## Architecture

The tracking module uses a **two-tier caching architecture**:

1. **Memcached** (primary) - Fast, distributed in-memory cache
2. **MySQL** (fallback) - Database-backed cache table for when Memcached is unavailable

The `TrackingCache` class wraps the core `Cache` class and provides typed methods for each cache entity.

### Key Features

- **Silent failure** - Cache misses are normal, never throw errors
- **Consistent JSON storage** - All array data stored as JSON for compatibility
- **Proper TTL management** - Each entity type has appropriate TTL
- **No double encoding** - Data is encoded once at the tracking layer

---

## Key Reference

| Key Pattern | TTL | Description | Invalidation |
|-------------|-----|-------------|--------------|
| `trk:live:{familyId}` | session_ttl | Live session state blob | On expire/destroy |
| `trk:cur:{userId}` | 120s | Current location for user | On location update |
| `trk:family_cur:{familyId}` | 10s | Family snapshot (all members) | On any member update |
| `trk:settings:{familyId}` | 600s (10 min) | Family settings blob | On settings save |
| `trk:rl:{userId}` | 300s (5 min) | Rate limiter state | Time-based |
| `trk:dd:{userId}` | dedupe_time * 2 | Dedupe last point | Time-based |
| `trk:geo:{familyId}` | 600s (10 min) | Geofence list | On geofence CRUD |
| `trk:geo_state:{userId}` | 600s (10 min) | User's geofence states | On state change |
| `trk:places:{familyId}` | 600s (10 min) | Places list | On place CRUD |
| `trk:dir:{profile}:{hash}` | 21600s (6h) | Directions cache | Time-based |
| `trk:alerts:{familyId}` | 600s (10 min) | Alert rules | On rules save |
| `trk:alerts_cd:{familyId}:{rule}:{userId}:{targetId}` | cooldown_seconds | Alert cooldown | Time-based |

---

## Key Details

### `trk:live:{familyId}` - Live Session

**Purpose:** Track if a family has an active tracking session (Mode 1).

**Value:**
```json
{
  "id": 1,
  "family_id": 42,
  "active": true,
  "started_by": 123,
  "started_at": "2024-01-15T14:00:00Z",
  "last_ping_at": "2024-01-15T14:05:00Z",
  "expires_at": "2024-01-15T14:10:00Z"
}
```

**TTL:** `session_ttl_seconds` from settings (default 300s)

**Set by:** `keepalive.php` via SessionsRepo

**Read by:** `session_status.php`, `location.php` (SessionGate)

---

### `trk:cur:{userId}` - Current Location

**Purpose:** Fast lookup of user's current position.

**Value:**
```json
{
  "user_id": 123,
  "family_id": 42,
  "lat": -26.2041,
  "lng": 28.0473,
  "accuracy_m": 10.5,
  "speed_mps": 5.2,
  "bearing_deg": 180,
  "motion_state": "moving",
  "recorded_at": "2024-01-15T14:30:00Z",
  "updated_at": "2024-01-15T14:30:05Z"
}
```

**TTL:** 120s (refreshed on each update)

**Set by:** LocationRepo.upsertCurrent()

**Read by:** LocationRepo.getCurrent()

---

### `trk:family_cur:{familyId}` - Family Snapshot

**Purpose:** Quick fetch of all family members' current positions.

**Value:**
```json
[
  {
    "user_id": 123,
    "name": "John",
    "avatar_color": "#667eea",
    "has_avatar": true,
    "lat": -26.2041,
    "lng": 28.0473,
    "motion_state": "moving",
    "recorded_at": "2024-01-15T14:30:00Z",
    "updated_at": "2024-01-15T14:30:05Z"
  }
]
```

**TTL:** 10s (short for freshness)

**Set by:** LocationRepo.getFamilyCurrent()

**Invalidated by:** LocationRepo.upsertCurrent()

---

### `trk:settings:{familyId}` - Settings

**Purpose:** Cache family tracking settings.

**Value:** Full `tracking_family_settings` row as JSON.

**TTL:** 600s (10 minutes)

**Set by:** SettingsRepo.get()

**Invalidated by:** SettingsRepo.save()

---

### `trk:rl:{userId}` - Rate Limiter

**Purpose:** Track last accepted location timestamp for rate limiting.

**Value:**
```json
{
  "last_accepted_at": 1705329000,
  "count_in_window": 5
}
```

**TTL:** 300s (5 minutes)

**Set by:** RateLimiter service

**Read by:** `location.php`, `batch.php`

---

### `trk:dd:{userId}` - Dedupe

**Purpose:** Store last accepted point for deduplication.

**Value:**
```json
{
  "lat": -26.2041,
  "lng": 28.0473,
  "recorded_at": 1705329000
}
```

**TTL:** `dedupe_time_seconds * 2` from settings

**Set by:** Dedupe service

**Read by:** `location.php`, `batch.php`

---

### `trk:geo:{familyId}` - Geofences List

**Purpose:** Cache active geofences for quick lookup.

**Value:**
```json
[
  {
    "id": 1,
    "name": "Home",
    "type": "circle",
    "center_lat": -26.2041,
    "center_lng": 28.0473,
    "radius_m": 100,
    "active": true
  }
]
```

**TTL:** 600s (10 minutes)

**Set by:** GeofenceRepo.getAll()

**Invalidated by:** GeofenceRepo create/update/delete

---

### `trk:geo_state:{userId}` - Geofence State

**Purpose:** Cache user's inside/outside state for each geofence.

**Value:**
```json
{
  "1": {"is_inside": true, "last_entered_at": "2024-01-15T14:00:00Z", "last_exited_at": null},
  "2": {"is_inside": false, "last_entered_at": null, "last_exited_at": "2024-01-15T13:00:00Z"}
}
```

**TTL:** 600s (10 minutes)

**Set by:** GeofenceRepo.getUserState()

**Invalidated by:** GeofenceRepo.updateState()

---

### `trk:places:{familyId}` - Places List

**Purpose:** Cache family's saved places.

**Value:**
```json
[
  {
    "id": 1,
    "label": "Home",
    "category": "home",
    "lat": -26.2041,
    "lng": 28.0473,
    "radius_m": 100
  }
]
```

**TTL:** 600s (10 minutes)

**Set by:** PlacesRepo.getAll()

**Invalidated by:** PlacesRepo create/update/delete

---

### `trk:dir:{profile}:{hash}` - Directions

**Purpose:** Cache Mapbox directions responses.

**Profile:** `driving`, `walking`, `cycling`

**Hash:** First 16 chars of MD5 of coordinates rounded to 5 decimal places

**Value:**
```json
{
  "profile": "driving",
  "from": {"lat": -26.2041, "lng": 28.0473},
  "to": {"lat": -26.1234, "lng": 28.1234},
  "distance_m": 5000,
  "duration_s": 600,
  "distance_text": "5.0 km",
  "duration_text": "10 min",
  "geometry": {"type": "LineString", "coordinates": [...]},
  "fetched_at": "2024-01-15T14:30:00Z"
}
```

**TTL:** 21600s (6 hours) - routes don't change often

**Set by:** MapboxDirections.getRoute()

---

### `trk:alerts:{familyId}` - Alert Rules

**Purpose:** Cache family's alert rules configuration.

**Value:**
```json
{
  "family_id": 42,
  "enabled": true,
  "arrive_place_enabled": true,
  "leave_place_enabled": true,
  "enter_geofence_enabled": true,
  "exit_geofence_enabled": true,
  "cooldown_seconds": 900,
  "quiet_hours_start": "22:00",
  "quiet_hours_end": "07:00"
}
```

**TTL:** 600s (10 minutes)

**Set by:** AlertsRepo.getRules()

**Invalidated by:** AlertsRepo.saveRules()

---

### `trk:alerts_cd:{familyId}:{rule}:{userId}:{targetId}` - Alert Cooldown

**Purpose:** Prevent spam by tracking when last alert was sent.

**Example key:** `trk:alerts_cd:42:enter_geofence:123:5`

**Value:** Unix timestamp of last delivery (integer)

**TTL:** `cooldown_seconds` from alert rules

**Set by:** AlertsRepo.recordDelivery()

---

## Usage Patterns

### Read-through Cache

```php
// In repository method
$cached = $this->cache->getSettings($familyId);
if ($cached !== null) {
    return $cached;
}

// Cache miss - query database
$settings = $this->queryFromDb($familyId);

// Store in cache
$this->cache->setSettings($familyId, $settings);

return $settings;
```

### Write-through Invalidation

```php
// Save to database
$this->db->update('tracking_family_settings', $data, ['family_id' => $familyId]);

// Invalidate cache - next read will refresh from DB
$this->cache->deleteSettings($familyId);
```

### Cooldown Check

```php
// Check if still in cooldown
$lastSent = $this->cache->getAlertCooldown($familyId, $ruleType, $userId, $targetId);
if ($lastSent !== null) {
    return false; // Still in cooldown
}

// Send alert
$this->sendAlert(...);

// Set cooldown
$this->cache->setAlertCooldown($familyId, $ruleType, $userId, $targetId, $cooldownSeconds);
```

### Bulk Invalidation

```php
// When settings change significantly, invalidate all related caches
$this->cache->invalidateFamily($familyId);

// When user settings change
$this->cache->invalidateUser($userId);
```

---

## TTL Constants

All TTLs are defined as constants in `TrackingCache`:

```php
const TTL_CURRENT = 120;           // 2 minutes
const TTL_FAMILY_SNAPSHOT = 10;    // 10 seconds
const TTL_SETTINGS = 600;          // 10 minutes
const TTL_RATE_LIMIT = 300;        // 5 minutes
const TTL_GEOFENCES = 600;         // 10 minutes
const TTL_GEO_STATE = 600;         // 10 minutes
const TTL_PLACES = 600;            // 10 minutes
const TTL_DIRECTIONS = 21600;      // 6 hours
const TTL_ALERTS = 600;            // 10 minutes
```

---

## Debugging

Check cache status via:

```php
// In API or debug endpoint
$trackingCache = new TrackingCache($cache);
echo "Cache available: " . ($trackingCache->available() ? 'yes' : 'no');
echo "Cache type: " . $trackingCache->getType(); // 'memcached' or 'mysql'
```

Monitor cache efficiency by checking hit/miss ratios in your Memcached stats.
