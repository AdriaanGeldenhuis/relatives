# Snake Classic Game Module

A Nokia 3310 style snake game with offline-first architecture and multi-scope leaderboards (Solo, Family, Global).

## Folder Structure

```
games/snake/
├── index.php              # Main game page (auth-protected)
├── manifest.json          # PWA manifest
├── sw.js                  # Service worker for offline support
├── schema.sql             # Database schema
├── README.md              # This file
└── assets/
    ├── css/
    │   └── snake.css      # Game styles (mobile-first)
    └── js/
        ├── snake.js       # Game engine (canvas, controls, game loop)
        ├── storage.js     # Local storage management
        └── api.js         # Server communication layer

api/
├── me.php                 # Returns current user info
└── games/snake/
    ├── submit_score.php   # Score submission with anti-cheat
    └── leaderboard.php    # Leaderboard data retrieval
```

## Integration Instructions

### 1. Database Setup

Run the SQL schema to create the required tables:

```bash
mysql -u your_user -p your_database < games/snake/schema.sql
```

Or execute the contents of `games/snake/schema.sql` in your MySQL client.

### 2. Database Configuration

Ensure you have a database config file at `/config/database.php`:

```php
<?php
return [
    'host' => 'localhost',
    'database' => 'your_database',
    'username' => 'your_user',
    'password' => 'your_password'
];
```

### 3. Session Requirements

The game expects these session variables to be set after user login:

```php
$_SESSION['user_id'] = 123;           // Required: User's ID
$_SESSION['display_name'] = 'John';   // Optional: Display name
$_SESSION['family_id'] = 55;          // Optional: Family group ID
```

### 4. Navigation Integration

Add a link to the snake game in your app navigation:

```html
<a href="/games/snake/">Snake Classic</a>
```

For Android WebView, load the URL:
```java
webView.loadUrl("https://yoursite.com/games/snake/");
```

### 5. PWA Icons (Optional)

For full PWA support, add icons at:
- `/games/snake/assets/icon-192.png` (192x192)
- `/games/snake/assets/icon-512.png` (512x512)

## Testing Checklist

### Mobile Testing
- [ ] Game loads correctly on mobile browser
- [ ] Game loads correctly in Android WebView
- [ ] Canvas is responsive and fits screen
- [ ] D-pad buttons are large enough to tap
- [ ] Swipe controls work reliably
- [ ] No layout issues in portrait orientation
- [ ] No layout issues in landscape orientation

### Gameplay Testing
- [ ] Snake moves continuously after start
- [ ] D-pad controls change direction correctly
- [ ] Swipe controls change direction correctly
- [ ] Cannot reverse direction instantly (180-degree turn blocked)
- [ ] Eating food increases score by 10
- [ ] Snake grows when eating food
- [ ] Speed increases after eating multiple foods
- [ ] Wall collision ends game
- [ ] Self collision ends game
- [ ] Pause/Resume works correctly
- [ ] Restart works correctly

### Offline Testing
1. Load the game while online
2. Turn off network (airplane mode)
3. Verify:
   - [ ] Game still loads from cache
   - [ ] Gameplay works completely offline
   - [ ] Score saves locally with "Saved locally" message
   - [ ] Sync indicator shows offline status
4. Turn network back on
5. Verify:
   - [ ] Queued scores sync automatically
   - [ ] Sync indicator updates to synced status

### Scoring & Leaderboards
- [ ] Personal best updates after high score
- [ ] Today's best resets at midnight
- [ ] Score submits to server when online
- [ ] Leaderboards load and display correctly
- [ ] Family leaderboard shows family members only
- [ ] Global leaderboard shows all users
- [ ] Tab switching (Today/Week) works
- [ ] Current user highlighted in leaderboards

### Anti-Cheat Validation
- [ ] Impossible scores are flagged (check database)
- [ ] Scores with unrealistic duration are flagged
- [ ] Future timestamps are rejected
- [ ] Very old scores are flagged

### Performance Testing
- [ ] Game runs smoothly at 60fps on mid-range device
- [ ] No memory leaks during extended play
- [ ] No lag when snake grows long
- [ ] Responsive UI updates

## API Reference

### GET /api/me.php

Returns current user information.

**Response (200):**
```json
{
  "user_id": 123,
  "display_name": "Adriaan",
  "family_id": 55
}
```

**Response (401):**
```json
{
  "error": "Not authenticated"
}
```

### POST /api/games/snake/submit_score.php

Submit a score.

**Request:**
```json
{
  "score": 150,
  "mode": "classic",
  "run_started_at": "2026-01-22T10:30:00.000Z",
  "run_ended_at": "2026-01-22T10:32:45.000Z",
  "device_id": "abc123...",
  "seed": "2026-W04"
}
```

**Response (200):**
```json
{
  "ok": true,
  "synced": true,
  "score_id": 456,
  "flagged": false
}
```

### GET /api/games/snake/leaderboard.php

Get leaderboard data.

**Query Parameters:**
- `range`: `today` or `week` (default: `today`)

**Response (200):**
```json
{
  "range": "today",
  "generated_at": "2026-01-22T12:00:00+00:00",
  "solo_personal_best": 350,
  "solo_today_best": 150,
  "family_today_top": [
    {"user_id": 123, "display_name": "Adriaan", "score": 150}
  ],
  "family_week_top": [...],
  "global_today_top": [...],
  "global_week_top": [...]
}
```

## Customization

### Adjusting Game Speed

Edit `CONFIG` in `snake.js`:

```javascript
const CONFIG = {
    INITIAL_SPEED: 150,      // Starting speed (ms per move)
    MIN_SPEED: 60,           // Fastest speed
    SPEED_INCREASE: 5,       // How much faster per speedup
    FOODS_PER_SPEEDUP: 3     // Foods needed for speedup
};
```

### Changing Grid Size

```javascript
const CONFIG = {
    GRID_SIZE: 20  // 20x20 grid (default)
};
```

### Styling

All colors are defined in `snake.js`:

```javascript
const COLORS = {
    BACKGROUND: '#0a0a12',
    GRID: '#151520',
    SNAKE_HEAD: '#4ecca3',
    SNAKE_BODY: '#3db892',
    FOOD: '#e74c3c',
    FOOD_GLOW: 'rgba(231, 76, 60, 0.3)'
};
```

CSS variables can be modified in `snake.css` for UI theming.
