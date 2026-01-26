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
