# Tracking V2 - Stack Integration

**This is the single source of truth for auth + session + DB access.**

> All tracking code MUST follow these exact patterns. No custom auth. No custom sessions.

---

## 1. Bootstrap Entry

**File:** `/core/bootstrap.php`

Must be included at the start of every tracking endpoint:
```php
require_once __DIR__ . '/../core/bootstrap.php';
```

**Provides:**
- `$db` - PDO database connection (global)
- `$cache` - Cache instance with Memcached support (global)
- Session already started with security settings

**Session Configuration:**
- Session name: `RELATIVES_SESSION`
- Lifetime: 30 days (2592000 seconds)
- Secure, HttpOnly, SameSite=Lax

---

## 2. Database Connector

**File:** `/core/DB.php`

**Pattern:** Singleton PDO connection

```php
$db = DB::getInstance();
```

**Connection:** MySQL via environment variables
- `DB_HOST` - MySQL host
- `DB_NAME` - Database name
- `DB_USER` - Username
- `DB_PASS` - Password

**PDO Options:**
- Error mode: Exception
- Fetch mode: Associative arrays
- Emulate prepares: false (real prepared statements)

---

## 3. Cache / Memcached Connector

**File:** `/core/Cache.php`

**Pattern:** Dual-layer cache (Memcached primary, MySQL fallback)

**Access via bootstrap:**
```php
$cache = Cache::init($db);
```

**Environment Variables:**
- `MEMCACHED_HOST` (default: 127.0.0.1)
- `MEMCACHED_PORT` (default: 11211)

**Methods:**
```php
$cache->get(string $key): mixed           // Returns value or null
$cache->set(string $key, $value, int $ttl = 3600): bool
$cache->delete(string $key): bool
$cache->available(): bool                 // Check if Memcached is active
```

**For Tracking, use existing Cache class** - No need to create custom cache wrapper.

---

## 4. Session Keys

**Primary Keys (MUST check):**

| Key | Type | Description |
|-----|------|-------------|
| `$_SESSION['user_id']` | int | **PRIMARY** - User ID |
| `$_SESSION['session_token']` | string | 64-char hex token |
| `$_SESSION['csrf_token']` | string | CSRF protection token |

**Cached User Data (5-minute TTL):**

| Key | Type | Description |
|-----|------|-------------|
| `$_SESSION['user_data']` | array | Full user object |
| `$_SESSION['user_data']['id']` | int | User ID |
| `$_SESSION['user_data']['family_id']` | int | Family ID |
| `$_SESSION['user_data']['role']` | string | 'owner'/'admin'/'member' |
| `$_SESSION['user_data']['name']` | string | Full name |
| `$_SESSION['user_data']['email']` | string | Email address |
| `$_SESSION['user_data']['avatar_color']` | string | Hex color code |

---

## 5. Authentication Pattern

**TRACKING MUST FOLLOW THIS EXACT PATTERN:**

```php
<?php
// 1. Start session (if not already started)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Quick session check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// 3. Load bootstrap (provides $db, $cache)
require_once __DIR__ . '/../core/bootstrap.php';

// 4. Get full user with Auth class
$auth = new Auth($db);
$user = $auth->getCurrentUser();

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session invalid']);
    exit;
}

// 5. (Optional) Check subscription status
require_once __DIR__ . '/../core/SubscriptionManager.php';
$subscriptionManager = new SubscriptionManager($db);

if ($subscriptionManager->isFamilyLocked($user['family_id'])) {
    http_response_code(402);
    echo json_encode([
        'success' => false,
        'error' => 'subscription_locked',
        'message' => 'Your subscription has expired.'
    ]);
    exit;
}

// 6. Now use $user data
$userId = $user['id'];
$familyId = $user['family_id'];
$userRole = $user['role'];
```

---

## 6. User Object Structure

When `$auth->getCurrentUser()` returns successfully:

```php
$user = [
    'id' => 123,                      // int - User ID
    'family_id' => 45,                // int - Family ID
    'family_name' => 'Smith Family',  // string - Family name
    'role' => 'member',               // enum: 'owner', 'admin', 'member'
    'name' => 'John Smith',           // string - Full name
    'email' => 'john@example.com',    // string - Email
    'avatar_color' => '#667eea',      // string - Hex color
    'csrf_token' => 'abc123...'       // string - CSRF token
];
```

---

## 7. Family Relationship

**Database Schema:**

```sql
-- families table
CREATE TABLE families (
    id BIGINT UNSIGNED PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    owner_user_id BIGINT UNSIGNED,
    subscription_status ENUM('trial','active','locked','expired','cancelled'),
    invite_code CHAR(8) NOT NULL,
    timezone VARCHAR(50) DEFAULT 'Africa/Johannesburg',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- users table (relevant fields)
CREATE TABLE users (
    id BIGINT UNSIGNED PRIMARY KEY,
    family_id BIGINT UNSIGNED NOT NULL,  -- FK to families.id
    role ENUM('owner','admin','member') DEFAULT 'member',
    email VARCHAR(190) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    avatar_color CHAR(7) DEFAULT '#667eea',
    has_avatar TINYINT(1) DEFAULT 0,
    location_sharing TINYINT(1) DEFAULT 1,  -- Privacy setting
    status ENUM('active','pending','disabled') DEFAULT 'active',
    -- ... other fields
);
```

**Relationship:**
- `users.family_id` -> `families.id` (Many-to-One)
- Each user belongs to ONE family
- All queries MUST filter by `family_id` for family isolation

**Fetching Family Members:**
```php
$stmt = $db->prepare("
    SELECT id, full_name, avatar_color, has_avatar, location_sharing, role
    FROM users
    WHERE family_id = ? AND status = 'active'
");
$stmt->execute([$user['family_id']]);
$members = $stmt->fetchAll();
```

---

## 8. Permissions / Roles

**Role Hierarchy:**

| Role | Level | Capabilities |
|------|-------|--------------|
| `owner` | Highest | All permissions, can change roles, delete family |
| `admin` | Mid | Manage family settings, members |
| `member` | Base | View/edit own data, view family data |

**Permission Checks:**

```php
// Owner only
if ($user['role'] !== 'owner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Owner only']);
    exit;
}

// Admin or Owner
if (!in_array($user['role'], ['owner', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

// Any family member (implicit - just use family_id filter)
$stmt = $db->prepare("SELECT * FROM items WHERE family_id = ?");
$stmt->execute([$user['family_id']]);
```

---

## 9. Privacy Setting

**Important:** Users can disable location sharing via `users.location_sharing`

```php
// Check if user allows location sharing
$stmt = $db->prepare("SELECT location_sharing FROM users WHERE id = ?");
$stmt->execute([$userId]);
$priv = $stmt->fetchColumn();

if (!$priv) {
    // User has disabled location sharing - respect this
}
```

**When fetching family members for tracking, filter by this:**
```php
$stmt = $db->prepare("
    SELECT id, full_name, avatar_color
    FROM users
    WHERE family_id = ? AND status = 'active' AND location_sharing = 1
");
```

---

## 10. API Response Pattern

**Success Response:**
```php
Response::json([
    'success' => true,
    'data' => $result
]);
```

**Error Response:**
```php
http_response_code(400); // or 401, 403, 404, 500
Response::json([
    'success' => false,
    'error' => 'error_code',
    'message' => 'Human readable message'
]);
```

**Using existing Response class:**
```php
require_once __DIR__ . '/../core/Response.php';
Response::json($data);  // Sets Content-Type and exits
```

---

## 11. CSRF Protection

For state-changing requests (POST, PUT, DELETE), validate CSRF:

```php
// Get token from header or body
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN']
    ?? $_POST['csrf_token']
    ?? null;

if (!$csrfToken || $csrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}
```

**Note:** For tracking location updates from mobile apps, CSRF may be skipped if session token is validated.

---

## 12. Summary: Integration Checklist

| Component | Source | Usage |
|-----------|--------|-------|
| Bootstrap | `/core/bootstrap.php` | `require_once` first |
| Database | Global `$db` | PDO prepared statements |
| Cache | Global `$cache` | `$cache->get()`, `$cache->set()` |
| Auth | `/core/Auth.php` | `new Auth($db)->getCurrentUser()` |
| User ID | `$user['id']` | From getCurrentUser() |
| Family ID | `$user['family_id']` | **ALWAYS filter queries by this** |
| Role | `$user['role']` | 'owner', 'admin', 'member' |
| Response | `/core/Response.php` | `Response::json()` |

---

## 13. DO NOT

- Create custom authentication
- Create custom session handling
- Create custom database connections
- Bypass family_id filtering
- Ignore location_sharing privacy setting
- Create new $_SESSION keys for tracking

**USE WHAT EXISTS. INTEGRATE. DON'T REINVENT.**
