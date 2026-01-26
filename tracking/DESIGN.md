# Tracking V2 - Design Document

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                        Web UI                                │
│  ┌─────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐     │
│  │  Map    │  │  Family  │  │Geofences │  │  Events  │     │
│  │ (index) │  │  Panel   │  │   UI     │  │   Feed   │     │
│  └────┬────┘  └────┬─────┘  └────┬─────┘  └────┬─────┘     │
└───────┼────────────┼─────────────┼─────────────┼────────────┘
        │            │             │             │
        ▼            ▼             ▼             ▼
┌─────────────────────────────────────────────────────────────┐
│                      API Layer                               │
│  Sessions │ Location │ Settings │ Places │ Geofences │ etc  │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                    Services Layer                            │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐       │
│  │ Session  │ │  Motion  │ │ Geofence │ │  Alerts  │       │
│  │  Gate    │ │   Gate   │ │  Engine  │ │  Engine  │       │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘       │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐                    │
│  │  Rate    │ │  Dedupe  │ │Directions│                    │
│  │ Limiter  │ │          │ │          │                    │
│  └──────────┘ └──────────┘ └──────────┘                    │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                   Repository Layer                           │
│  Settings │ Location │ Places │ Sessions │ Geofence │ etc   │
└─────────────────────────┬───────────────────────────────────┘
                          │
        ┌─────────────────┼─────────────────┐
        ▼                 ▼                 ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│   Memcache   │  │    MySQL     │  │   Mapbox     │
│   (Cache)    │  │  (DB Truth)  │  │     API      │
└──────────────┘  └──────────────┘  └──────────────┘
```

## Tracking Modes

### Mode 1: Family Live Session

**Concept:** Tracking only active when someone is viewing the dashboard.

**Flow:**
1. User opens tracking page → `keepalive` called
2. Server sets `trk:live:{familyId}` in cache + DB session
3. Native apps check `session_status` before uploading
4. Session expires after TTL (no keepalive)
5. Apps stop high-power tracking, optional coarse heartbeat

**Benefits:**
- Massive battery savings (no tracking when nobody watching)
- Users control when tracking is active
- Fair to all family members

### Mode 2: Motion-Based

**Concept:** Track only when moving.

**Flow:**
1. Device detects motion (accelerometer/activity recognition)
2. If moving → upload location at configured interval
3. If idle → send occasional heartbeat, no history stored
4. Speed/distance thresholds determine state

**Benefits:**
- Good battery life while still tracking movement
- Useful for commute tracking, trips

## Data Flow: Location Update

```
Device → POST /api/location
           │
           ▼
     ┌─────────────┐
     │  Auth Check │ → 401 if not authenticated
     └──────┬──────┘
            │
            ▼
     ┌─────────────┐
     │ Rate Limit  │ → 429 if too frequent
     └──────┬──────┘
            │
            ▼
     ┌─────────────┐
     │Session Gate │ → Mode 1: reject if session off
     └──────┬──────┘
            │
            ▼
     ┌─────────────┐
     │ Motion Gate │ → Mode 2: determine moving/idle
     └──────┬──────┘
            │
            ▼
     ┌─────────────┐
     │   Dedupe    │ → Skip if too similar to last
     └──────┬──────┘
            │
            ▼
     ┌─────────────────────────────────────┐
     │         Location Accepted            │
     │  • Update tracking_current (cache+DB)│
     │  • Insert tracking_locations (if moving) │
     │  • Trigger GeofenceEngine           │
     │  • Log tracking_events              │
     └─────────────────────────────────────┘
```

## Geofence Processing

```
Location Update
      │
      ▼
┌─────────────┐
│ Load zones  │ ← Cache first, fallback DB
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ Point-in-   │ For each zone:
│ polygon/    │ - Calculate if inside
│ circle      │ - Compare to last state
└──────┬──────┘
       │
       ├── State changed? ──┐
       │                    │
       ▼                    ▼
┌─────────────┐     ┌─────────────┐
│ Update      │     │ Insert      │
│ geofence    │     │ event       │
│ state       │     │ (enter/exit)│
└─────────────┘     └──────┬──────┘
                           │
                           ▼
                    ┌─────────────┐
                    │ Alerts      │
                    │ Engine      │
                    └─────────────┘
```

## Cache Strategy

**Principle:** Cache-first, DB is truth.

| Data | Cache TTL | Invalidation |
|------|-----------|--------------|
| Live session | session_ttl | On expire/destroy |
| Current location | 120s+ | On update |
| Family snapshot | 3-10s | On any update |
| Settings | 10 min | On save |
| Geofences | 10 min | On CRUD |
| Directions | 6-24h | Time-based |

**Read Pattern:**
```php
$data = $cache->get($key);
if ($data === null) {
    $data = $repo->getFromDb();
    $cache->set($key, $data, $ttl);
}
return $data;
```

**Write Pattern:**
```php
$repo->saveToDb($data);
$cache->delete($key);  // or set with new value
```

## Events System

**Event Types:**
- `location_update` - Regular location received
- `enter_geofence` - Entered a zone
- `exit_geofence` - Left a zone
- `arrive_place` - Near a saved place
- `leave_place` - Left a saved place
- `session_on` - Live session started
- `session_off` - Live session ended
- `settings_change` - Settings modified
- `alert_triggered` - Alert rule fired

**Event Schema:**
```json
{
  "id": 12345,
  "family_id": 42,
  "user_id": 7,
  "event_type": "enter_geofence",
  "meta_json": {
    "geofence_id": 3,
    "geofence_name": "Home",
    "lat": -26.2041,
    "lng": 28.0473
  },
  "occurred_at": "2024-01-15T14:30:00Z"
}
```

## Alerts System

**Rules:**
- Arrive at place
- Leave place
- Enter geofence
- Exit geofence

**Cooldown:**
- Prevents spam alerts
- Per rule type + user + target
- Configurable (default 15 min)

**Quiet Hours:**
- Optional time window to suppress alerts
- Still logs events, just no notifications

## UI Components

### Main Map (`/app/index.php`)
- Fullscreen Mapbox map
- Family member markers (color-coded)
- Motion state indicators (moving/idle/stale/offline)
- Follow mode (auto-pan to member)
- Directions overlay

### Family Panel
- List of family members
- Current status for each
- Click to center/follow
- Quick actions (directions)

### Geofences UI (`/app/geofences.php`)
- List family geofences
- Add new (tap map, set radius)
- Edit name/radius/active
- Toggle overlay on main map

### Events Feed (`/app/events.php`)
- Timeline of events
- Filter by member, type, date
- Infinite scroll or pagination

## Native App Contract

### Payload Schema
```json
{
  "lat": -26.2041,
  "lng": 28.0473,
  "accuracy_m": 10.5,
  "speed_mps": 5.2,
  "bearing_deg": 180,
  "recorded_at": "2024-01-15T14:30:00Z",
  "device_id": "abc123",
  "platform": "ios",
  "app_version": "2.1.0"
}
```

### Mode 1 Behavior
1. App checks `/api/session_status` periodically
2. If `active: false` → stop GPS, optional coarse heartbeat
3. If `active: true` → track at configured interval

### Mode 2 Behavior
1. Always monitor motion (low-power)
2. Moving → upload at `moving_interval`
3. Idle → upload heartbeat at `idle_interval` (optional)

## Security

- Session-based auth (no custom tokens)
- Family isolation on all queries
- Rate limiting per user
- CSRF for web state changes
- Privacy: respect `location_sharing` setting
