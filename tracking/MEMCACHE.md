# Tracking V2 - Memcache Keys

All tracking cache keys use the prefix `trk:` to namespace from other app caches.

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
  "active": true,
  "started_by": 123,
  "started_at": "2024-01-15T14:00:00Z",
  "last_ping_at": "2024-01-15T14:05:00Z",
  "expires_at": "2024-01-15T14:10:00Z"
}
```

**TTL:** `session_ttl_seconds` from settings (default 300s)

**Set by:** `keepalive.php`

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

**Set by:** `location.php`, `batch.php`

**Read by:** `current.php`, GeofenceEngine

---

### `trk:family_cur:{familyId}` - Family Snapshot

**Purpose:** Quick fetch of all family members' current positions.

**Value:**
```json
{
  "family_id": 42,
  "members": [
    {
      "user_id": 123,
      "name": "John",
      "avatar_color": "#667eea",
      "lat": -26.2041,
      "lng": 28.0473,
      "motion_state": "moving",
      "recorded_at": "2024-01-15T14:30:00Z"
    }
  ],
  "updated_at": "2024-01-15T14:30:05Z"
}
```

**TTL:** 10s (short for freshness)

**Set by:** `current.php` (after DB query)

**Read by:** `current.php` (before DB query)

---

### `trk:settings:{familyId}` - Settings

**Purpose:** Cache family tracking settings.

**Value:** Full `tracking_family_settings` row as JSON.

**TTL:** 600s (10 minutes)

**Set by:** `settings_get.php`, SettingsRepo

**Invalidated by:** `settings_save.php`

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
    "radius_m": 100
  }
]
```

**TTL:** 600s (10 minutes)

**Set by:** GeofenceRepo

**Invalidated by:** Geofence CRUD operations

---

### `trk:geo_state:{userId}` - Geofence State

**Purpose:** Cache user's inside/outside state for each geofence.

**Value:**
```json
{
  "1": true,
  "2": false,
  "3": false
}
```

(Key is geofence_id, value is is_inside)

**TTL:** 600s (10 minutes)

**Set by:** GeofenceEngine

**Invalidated by:** State changes

---

### `trk:places:{familyId}` - Places List

**Purpose:** Cache family's saved places.

**TTL:** 600s (10 minutes)

**Invalidated by:** Place CRUD operations

---

### `trk:dir:{profile}:{hash}` - Directions

**Purpose:** Cache Mapbox directions responses.

**Profile:** `driving`, `walking`, `cycling`

**Hash:** MD5 of `{fromLat},{fromLng}|{toLat},{toLng}`

**TTL:** 21600s (6 hours) - routes don't change often

---

### `trk:alerts:{familyId}` - Alert Rules

**Purpose:** Cache family's alert rules.

**TTL:** 600s (10 minutes)

**Invalidated by:** `alerts_rules_save.php`

---

### `trk:alerts_cd:{familyId}:{rule}:{userId}:{targetId}` - Alert Cooldown

**Purpose:** Prevent spam by tracking when last alert was sent.

**Example key:** `trk:alerts_cd:42:enter_geofence:123:5`

**Value:** Timestamp of last delivery

**TTL:** `cooldown_seconds` from alert rules

---

## Usage Pattern

### Read-through cache:

```php
$key = "trk:settings:{$familyId}";
$settings = $cache->get($key);

if ($settings === null) {
    $settings = $repo->getFromDb($familyId);
    $cache->set($key, $settings, 600);
}

return $settings;
```

### Write-through invalidation:

```php
// Save to DB
$repo->save($familyId, $data);

// Invalidate cache
$cache->delete("trk:settings:{$familyId}");
```

### Cooldown check:

```php
$key = "trk:alerts_cd:{$familyId}:{$rule}:{$userId}:{$targetId}";
$lastSent = $cache->get($key);

if ($lastSent !== null) {
    // Still in cooldown
    return false;
}

// Send alert, set cooldown
$cache->set($key, time(), $cooldownSeconds);
return true;
```
