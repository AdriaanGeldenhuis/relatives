# Family Tracking V2

Real-time family location tracking with geofences, alerts, and events.

## Features

- **Live Location Tracking** - Real-time family member locations
- **Android tracking policy** with Off, Normal and Active. Normal and Active use movement-based tracking with low-power stationary check-ins.
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
- `GET /api/session_status.php` - Read the Android tracking policy and legacy session state
- `POST /api/wake_devices.php` - Request one fresh fix from eligible family installations
- `POST /api/wake_ack.php` - Authenticate and acknowledge the receiving installation
- `GET /api/wake_status.php?request_id=…` - Read the requester’s per-device progress

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

### Android schedule

Fetch `data.tracking_policy` from `session_status.php` on activation and reconnect. Upload acknowledgements include the same policy as `data.server_settings`. The policy provides `allowed`, `mode`, `moving_interval`, `idle_interval`, `min_accuracy_m`, and server time in milliseconds. Mode 0 suspends collection. Normal and Active use a visible location foreground service, movement and idle detection, and reduced sampling in Battery Saver.

### Location and Wake acknowledgements

Send up to 100 fixes in `POST /tracking/api/batch.php`. Assign an installation `device_id` and a stable `client_event_id` to every queued fix. Capture time can be sent as UTC `recorded_at_ms` or in the supported `recorded_at` format. Read each result by its index and event ID. `stored` and `duplicate` acknowledge a persisted fix. `skipped` and `error` are permanent point outcomes with a reason. Retain points whose acknowledgement is missing or whose transport failed. A 401 requires sign-in. A 403 with `location_sharing_disabled` requires renewed consent. A `tracking_disabled` result suspends collection.

The signed-in family viewer sends `client_request_id` and the page’s CSRF token to request Wake. The 60-second request has one target per eligible installation. After Firebase delivery, the installation calls `wake_ack.php` with `request_id`, `device_id`, and its state. A positive `acquire` and remaining time permit one fresh fix. Attach `wake_request_id` to that upload. Only a committed qualifying fix moves the request state to `refreshed`.

### Verification

Run `node --test tracking/tests/wake-ui.test.cjs` for map request status and cooldown handling. Run `php tracking/tests/reliability.php` with `RELATIVES_TRACKING_TEST_DSN` pointed at an empty, disposable MariaDB/MySQL database named `relatives_tracking_test`. Set `RELATIVES_TRACKING_TEST_USER` and `RELATIVES_TRACKING_TEST_PASSWORD` for that fixture database if needed. See the Android release checks for rollout switches, migration order, and physical-device acceptance.
