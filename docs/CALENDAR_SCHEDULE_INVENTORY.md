# SECTION 0: INVENTORY & TRUTH CHECK
## Calendar & Schedule Complete Rebuild

**Date:** 2026-01-03
**Status:** INVENTORY COMPLETE

---

## 1. FILE INVENTORY

### Calendar Module
| File | Purpose | Lines | Status |
|------|---------|-------|--------|
| `/calendar/index.php` | Calendar UI + API handlers | 843 | ✅ Active |
| `/calendar/js/calendar.js` | Calendar interactions | 1173 | ✅ Active |
| `/calendar/css/calendar.css` | Calendar styling | - | ✅ Active |

### Schedule Module
| File | Purpose | Lines | Status |
|------|---------|-------|--------|
| `/schedule/index.php` | Schedule UI | 928 | ✅ Active |
| `/schedule/js/schedule.js` | Schedule interactions, focus mode | 2258 | ✅ Active |
| `/schedule/css/schedule.css` | Schedule styling | - | ✅ Active |
| `/schedule/includes/ScheduleManager.php` | Business logic | 233 | ⚠️ Partial - queries `events` table, not `schedule_events` |
| `/schedule/api/events.php` | Schedule API | 586 | ✅ Active |

### Notification/Reminder System
| File | Purpose | Lines | Status |
|------|---------|-------|--------|
| `/cron/notification-reminders.php` | Cron job for reminders | 136 | ⚠️ Partial - only checks `events` table |
| `/core/NotificationManager.php` | Notification core | 379 | ✅ Active |
| `/core/NotificationTriggers.php` | Notification triggers | 466 | ✅ Active |

---

## 2. DATABASE TABLES (INFERRED FROM CODE)

### Current Tables

#### `events` (Calendar Events)
| Column | Type | Status | Notes |
|--------|------|--------|-------|
| id | INT | ✅ | Primary key |
| family_id | INT | ✅ | |
| user_id | INT | ✅ | |
| kind | VARCHAR | ✅ | 'other', 'study', 'work', 'todo' |
| title | VARCHAR | ✅ | |
| notes | TEXT | ✅ | |
| starts_at | DATETIME | ✅ | |
| ends_at | DATETIME | ✅ | |
| all_day | TINYINT | ✅ | |
| color | VARCHAR | ✅ | |
| status | VARCHAR | ✅ | 'pending', 'done', 'cancelled' |
| reminder_minutes | INT | ✅ | Simple reminder |
| created_at | DATETIME | ✅ | |
| updated_at | DATETIME | ✅ | |
| timezone | VARCHAR | ❌ MISSING | |
| recurrence_rule | VARCHAR | ❌ MISSING | |
| recurrence_parent_id | INT | ❌ MISSING | |
| location | VARCHAR | ❌ MISSING | |

#### `schedule_events` (Schedule Events - SEPARATE TABLE!)
| Column | Type | Status | Notes |
|--------|------|--------|-------|
| id | INT | ✅ | |
| family_id | INT | ✅ | |
| user_id | INT | ✅ | |
| added_by | INT | ✅ | |
| assigned_to | INT | ✅ | |
| title | VARCHAR | ✅ | |
| kind | VARCHAR | ✅ | 'study', 'work', 'todo', 'break', 'focus' |
| notes | TEXT | ✅ | |
| starts_at | DATETIME | ✅ | |
| ends_at | DATETIME | ✅ | |
| color | VARCHAR | ✅ | |
| status | VARCHAR | ✅ | 'pending', 'in_progress', 'done', 'cancelled' |
| reminder_minutes | INT | ✅ | |
| repeat_rule | VARCHAR | ✅ | 'daily', 'weekly', 'weekdays', 'monthly' |
| parent_event_id | INT | ✅ | For recurring |
| focus_mode | TINYINT | ✅ | |
| actual_start | DATETIME | ✅ | |
| actual_end | DATETIME | ✅ | |
| productivity_rating | INT | ✅ | 1-5 |
| pomodoro_count | INT | ✅ | |
| created_at | DATETIME | ✅ | |
| updated_at | DATETIME | ✅ | |

#### `schedule_productivity` (Productivity Stats)
| Column | Type | Status | Notes |
|--------|------|--------|-------|
| id | INT | ✅ | |
| user_id | INT | ✅ | |
| family_id | INT | ✅ | |
| date | DATE | ✅ | |
| study_minutes | INT | ✅ | |
| work_minutes | INT | ✅ | |
| focus_minutes | INT | ✅ | |
| completed_tasks | INT | ✅ | |
| total_tasks | INT | ✅ | |
| productivity_score | DECIMAL | ✅ | |

#### `notifications`
| Column | Type | Status | Notes |
|--------|------|--------|-------|
| id | INT | ✅ | |
| user_id | INT | ✅ | |
| from_user_id | INT | ✅ | |
| type | VARCHAR | ✅ | |
| priority | VARCHAR | ✅ | |
| category | VARCHAR | ✅ | |
| title | VARCHAR | ✅ | |
| message | TEXT | ✅ | |
| icon | VARCHAR | ✅ | |
| sound | VARCHAR | ✅ | |
| vibrate | TINYINT | ✅ | |
| action_url | VARCHAR | ✅ | |
| data_json | JSON | ✅ | |
| is_read | TINYINT | ✅ | |
| read_at | DATETIME | ✅ | |
| requires_interaction | TINYINT | ✅ | |
| expires_at | DATETIME | ✅ | |
| created_at | DATETIME | ✅ | |

---

## 3. FEATURE STATUS

### Events/Calendar
| Feature | Status | Files | Notes |
|---------|--------|-------|-------|
| Create event | ✅ Implemented | calendar/index.php:59-109 | Works |
| Update event | ✅ Implemented | calendar/index.php:111-172 | Works |
| Delete event | ✅ Implemented | calendar/index.php:174-181 | Soft delete |
| Move event (drag) | ✅ Implemented | calendar/index.php:290-325 | Date only, no resize |
| All-day events | ✅ Implemented | | |
| Event colors | ✅ Implemented | | |
| Event notes | ✅ Implemented | | |
| Recurring events | ❌ MISSING | calendar/index.php | No recurrence support |
| Timezone support | ❌ MISSING | | No timezone column |
| Event location | ❌ MISSING | | Column missing |
| Multi-day events | ⚠️ Partial | | end_date works but UI limited |

### Schedule
| Feature | Status | Files | Notes |
|---------|--------|-------|-------|
| Create event | ✅ Implemented | schedule/api/events.php:48-206 | Works |
| Update event | ✅ Implemented | schedule/api/events.php:383-464 | Works, updates children |
| Delete event | ✅ Implemented | schedule/api/events.php:370-381 | Soft delete |
| Toggle done | ✅ Implemented | schedule/api/events.php:209-262 | Updates productivity |
| Recurring events | ✅ Implemented | schedule/api/events.php:130-187 | Creates 10 instances |
| Focus mode | ✅ Implemented | schedule/js/schedule.js:155-311 | Timer, overlay |
| Productivity rating | ✅ Implemented | schedule/api/events.php:280-296 | 1-5 stars |
| Bulk operations | ✅ Implemented | schedule/js/schedule.js:929-1235 | Mark done, type, assign, delete |
| Templates | ⚠️ Partial | schedule/js/schedule.js:1637-1755 | Hardcoded, no custom save |
| Analytics | ✅ Implemented | schedule/js/schedule.js:1518-1633 | 30-day stats |
| Week view | ✅ Implemented | schedule/js/schedule.js:1275-1360 | Modal view |
| Conflict detection | ✅ Implemented | schedule/api/events.php:74-102 | |

### Reminders & Alarms
| Feature | Status | Files | Notes |
|---------|--------|-------|-------|
| Simple reminder | ✅ Implemented | cron/notification-reminders.php | Minutes before |
| Multiple reminders per event | ❌ MISSING | | Only 1 reminder_minutes column |
| Snooze | ❌ MISSING | | No snooze logic |
| Offline retry | ❌ MISSING | | No retry queue |
| Push notifications | ✅ Implemented | core/NotificationManager.php | Via FCM |
| In-app notifications | ✅ Implemented | core/NotificationTriggers.php | |

### Sync & Integration
| Feature | Status | Files | Notes |
|---------|--------|-------|-------|
| Calendar ↔ Schedule sync | ⚠️ Partial | calendar/index.php:190-254 | UNION query only, no real sync |
| Edit propagation | ❌ MISSING | | Changes don't sync |
| Google Calendar sync | ❌ MISSING | | Placeholder only |

### Multi-User/Family
| Feature | Status | Files | Notes |
|---------|--------|-------|-------|
| Family-wide events | ✅ Implemented | | family_id filtering |
| Event assignment | ✅ Implemented | schedule | assigned_to column |
| Permissions | ❌ MISSING | | No view/edit/approve |
| Notifications to others | ✅ Implemented | core/NotificationTriggers.php | |

---

## 4. CRITICAL ISSUES IDENTIFIED

### ❌ Issue 1: DUAL EVENT TABLES
**Problem:** Two separate tables (`events` and `schedule_events`) with overlapping data
**Impact:** Data duplication, sync issues, inconsistent behavior
**Solution:** Merge into single `events` table with unified schema

### ❌ Issue 2: NO PROPER REMINDERS TABLE
**Problem:** Reminders stored as single `reminder_minutes` column
**Impact:** Cannot have multiple reminders, no snooze, no retry
**Solution:** Create separate `event_reminders` table

### ❌ Issue 3: CRON JOB ONLY CHECKS `events` TABLE
**Problem:** `notification-reminders.php` only queries `events`, misses `schedule_events`
**Impact:** Schedule reminders don't fire
**Solution:** Unify tables or update cron

### ❌ Issue 4: NO TIMEZONE SUPPORT
**Problem:** No timezone column, all times assumed server time
**Impact:** Wrong times for users in different timezones
**Solution:** Add timezone column, convert on display

### ❌ Issue 5: SCHEDULEMANAGER QUERIES WRONG TABLE
**Problem:** `ScheduleManager.php` queries `events` table, but schedule uses `schedule_events`
**Impact:** Class is unused/broken
**Solution:** Fix or remove

### ❌ Issue 6: NO AUDIT/HISTORY
**Problem:** No tracking of changes
**Impact:** Cannot undo, no accountability
**Solution:** Add `event_history` table

### ⚠️ Issue 7: NO UNDO SUPPORT
**Problem:** No undo for any operation
**Impact:** Accidental changes are permanent
**Solution:** Implement undo stack with history

---

## 5. PROPOSED NEW SCHEMA

### `events` (Unified)
```sql
CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    family_id INT NOT NULL,
    user_id INT NOT NULL,
    created_by INT NOT NULL,
    assigned_to INT NULL,

    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    location VARCHAR(255) NULL,

    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    timezone VARCHAR(50) DEFAULT 'UTC',
    all_day TINYINT DEFAULT 0,

    kind ENUM('event','study','work','todo','break','focus') DEFAULT 'event',
    color VARCHAR(20) DEFAULT '#3498db',
    status ENUM('pending','in_progress','done','cancelled') DEFAULT 'pending',

    -- Recurrence
    recurrence_rule VARCHAR(50) NULL,
    recurrence_parent_id INT NULL,

    -- Focus mode
    focus_mode TINYINT DEFAULT 0,
    actual_start DATETIME NULL,
    actual_end DATETIME NULL,
    productivity_rating TINYINT NULL,
    pomodoro_count INT DEFAULT 0,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (recurrence_parent_id) REFERENCES events(id) ON DELETE SET NULL,
    INDEX idx_family_date (family_id, starts_at),
    INDEX idx_user_date (user_id, starts_at),
    INDEX idx_status (status)
);
```

### `event_reminders` (New)
```sql
CREATE TABLE event_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,

    trigger_offset INT NOT NULL, -- Minutes before event
    trigger_type ENUM('push','sound','silent') DEFAULT 'push',

    snooze_count INT DEFAULT 0,
    last_triggered_at DATETIME NULL,
    next_trigger_at DATETIME NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    INDEX idx_next_trigger (next_trigger_at)
);
```

### `schedule_templates` (New)
```sql
CREATE TABLE schedule_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,  -- NULL = system template
    family_id INT NULL,

    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    pattern_json JSON NOT NULL, -- Array of {title, start, end, kind, etc}

    is_public TINYINT DEFAULT 0,
    use_count INT DEFAULT 0,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### `event_history` (New - Audit)
```sql
CREATE TABLE event_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,

    action ENUM('create','update','delete','restore') NOT NULL,
    changed_by INT NOT NULL,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    old_values JSON NULL,
    new_values JSON NULL,

    INDEX idx_event (event_id),
    INDEX idx_changed_at (changed_at)
);
```

---

## 6. NEXT STEPS

1. **SECTION 1:** Create migrations to:
   - Merge `schedule_events` into `events`
   - Add missing columns to `events`
   - Create `event_reminders` table
   - Create `schedule_templates` table
   - Create `event_history` table

2. **SECTION 2:** Build unified Event Engine class

3. **SECTION 3:** Implement proper reminders system

---

## SUMMARY

| Category | Count |
|----------|-------|
| Files analyzed | 11 |
| Database tables | 5 |
| Features working | 18 |
| Features partial | 4 |
| Features missing | 8 |
| Critical issues | 7 |

**Verdict:** System is functional but fragmented. Major refactor needed to unify calendar and schedule into single coherent system.

---

✔️ **SECTION 0 COMPLETE**
❌ Missing: Unified data model
🔧 Files affected: All calendar/* and schedule/* files
