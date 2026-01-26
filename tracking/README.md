# Family Tracking V2

Real-time family location tracking with geofences, alerts, and events.

## Features

- **Live Location Tracking** - Real-time family member locations
- **Two Tracking Modes**
  - Mode 1: Family Live Session (battery-efficient, triggered by dashboard open)
  - Mode 2: Motion-based (only tracks when moving)
- **Geofences** - Define zones with enter/exit alerts
- **Places** - Mark important locations (home, work, school)
- **Events Feed** - Timeline of all location events
- **Directions** - Person-to-person and person-to-place routing

## Directory Structure

```
/tracking/
  /app/          - Web UI (Mapbox fullscreen)
  /api/          - REST endpoints
  /core/         - Bootstrap, repos, services
  /jobs/         - Scheduled maintenance tasks
  /migrations/   - Database schema
```

## Documentation

- `STACK_INTEGRATION.md` - Auth/session/DB integration patterns
- `DESIGN.md` - Architecture and design decisions
- `MEMCACHE.md` - Cache keys and TTLs

## Requirements

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Memcached (recommended)
- Mapbox API key

## API Endpoints

### Sessions
- `POST /api/keepalive` - Keep live session active
- `GET /api/session_status` - Check live session state

### Location
- `POST /api/location` - Submit single location
- `POST /api/batch` - Submit multiple locations
- `GET /api/current` - Get family current locations
- `GET /api/history` - Get location history

### Settings
- `GET /api/settings_get` - Get family tracking settings
- `POST /api/settings_save` - Save settings

### Places
- `GET /api/places_list` - List family places
- `POST /api/places_add` - Add place
- `DELETE /api/places_delete` - Remove place

### Geofences
- `GET /api/geofences_list` - List family geofences
- `POST /api/geofences_add` - Create geofence
- `PUT /api/geofences_update` - Update geofence
- `DELETE /api/geofences_delete` - Delete geofence

### Events & Alerts
- `GET /api/events_list` - Get events feed
- `GET /api/alerts_rules_get` - Get alert rules
- `POST /api/alerts_rules_save` - Save alert rules

### Navigation
- `GET /api/directions` - Get route between points

## Privacy

- Respects `users.location_sharing` setting
- Family isolation enforced on all queries
- Session-based auth (no custom tokens)

---

## Native App Contract

This section documents the requirements for native iOS/Android apps to integrate with the tracking API.

### Authentication

Native apps use the same session-based authentication as the web app:

1. User logs in via web or app (creates PHP session)
2. Session cookie (`RELATIVES_SESSION`) is stored and sent with all requests
3. No separate API tokens for tracking

### Location Payload

```json
{
    "lat": -26.2041,
    "lng": 28.0473,
    "accuracy_m": 10.5,
    "speed_mps": 5.2,
    "bearing_deg": 180.0,
    "altitude_m": 1500.0,
    "recorded_at": "2024-01-15T14:30:00Z",
    "device_id": "abc123-device-uuid",
    "platform": "ios",
    "app_version": "2.1.0"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `lat` | float | Yes | Latitude (-90 to 90) |
| `lng` | float | Yes | Longitude (-180 to 180) |
| `accuracy_m` | float | No | Horizontal accuracy in meters |
| `speed_mps` | float | No | Speed in meters per second |
| `bearing_deg` | float | No | Bearing/heading (0-360) |
| `altitude_m` | float | No | Altitude in meters |
| `recorded_at` | string | No | ISO 8601 timestamp (defaults to now) |
| `device_id` | string | No | Unique device identifier |
| `platform` | string | No | "ios" or "android" |
| `app_version` | string | No | App version string |

### Mode 1: Live Session Behavior

When the family is configured for Mode 1 (Live Session):

1. **Before starting high-power tracking**, call:
   ```
   GET /tracking/api/session_status.php
   ```

   Response:
   ```json
   {
       "success": true,
       "data": {
           "mode": 1,
           "session": {
               "active": true,
               "expires_in_seconds": 280
           },
           "should_track": true
       }
   }
   ```

2. **If `should_track` is `false`**:
   - Stop high-power GPS tracking
   - Optionally send coarse heartbeat every 5-10 minutes
   - Check `session_status` periodically (every 1-2 minutes)

3. **If `should_track` is `true`**:
   - Start tracking at configured interval (`moving_interval_seconds`)
   - Upload locations to `POST /tracking/api/location.php`

4. **Handle `session_off` error**:
   If location upload returns:
   ```json
   {
       "success": false,
       "error": "session_off",
       "message": "No active tracking session"
   }
   ```
   Stop tracking and wait for session to become active.

### Mode 2: Motion-Based Behavior

When configured for Mode 2:

1. **Always** monitor device motion (accelerometer/activity recognition)

2. **When moving**:
   - Upload locations at `moving_interval_seconds` (e.g., 30s)
   - Server determines motion state from speed/distance

3. **When idle**:
   - Upload heartbeat at `idle_interval_seconds` (e.g., 5 min)
   - Server will not store in history, only update current

4. **Motion detection**:
   - Use iOS: `CMMotionActivityManager`
   - Use Android: `ActivityRecognitionClient`
   - Fallback: significant location change APIs

### Batch Uploads

For offline/buffered locations:

```
POST /tracking/api/batch.php

{
    "locations": [
        { "lat": ..., "lng": ..., "recorded_at": "..." },
        { "lat": ..., "lng": ..., "recorded_at": "..." }
    ]
}
```

- Maximum 100 locations per batch
- Locations should be sorted by `recorded_at` (oldest first)
- Server processes each and returns per-location results

### Error Handling

| HTTP Code | Error | Action |
|-----------|-------|--------|
| 401 | `not_authenticated` | Redirect to login |
| 402 | `subscription_locked` | Show subscription prompt |
| 403 | `location_sharing_disabled` | Show privacy settings prompt |
| 409 | `session_off` | Stop tracking, wait for session |
| 422 | `poor_accuracy` | Discard point, try again |
| 429 | `rate_limited` | Wait `retry_after` seconds |

### Recommended Upload Strategy

```
function uploadLocation(location):
    // Check session first (Mode 1)
    if (mode == 1):
        status = GET /session_status
        if not status.should_track:
            return  // Don't upload

    // Upload
    result = POST /location, location

    if result.error == "rate_limited":
        sleep(result.retry_after)
        return uploadLocation(location)

    if result.error == "session_off":
        stopTracking()
        scheduleSessionCheck()
        return

    if result.success:
        // Success! Check for geofence events
        if result.geofence_events.length > 0:
            showLocalNotification(result.geofence_events)
```

### Battery Optimization

- **Mode 1**: Only track when someone is viewing the dashboard
- **Mode 2**: Use significant location change APIs when idle
- **iOS**: Use `allowsBackgroundLocationUpdates` sparingly
- **Android**: Use `FusedLocationProviderClient` with appropriate priority
- **Always**: Respect `min_accuracy_m` setting (don't upload poor fixes)
