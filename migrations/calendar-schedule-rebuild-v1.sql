-- ============================================
-- CALENDAR & SCHEDULE REBUILD - MIGRATION V1
-- Date: 2026-01-03
-- ============================================
-- This migration:
-- 1. Adds missing columns to `events` table
-- 2. Creates `event_reminders` table
-- 3. Creates `schedule_templates` table
-- 4. Creates `event_history` table
-- 5. Migrates data from `schedule_events` to `events`
-- ============================================

-- SAFETY: Start transaction
START TRANSACTION;

-- ============================================
-- STEP 1: ALTER `events` TABLE
-- Add missing columns for unified schema
-- ============================================

-- Add description column (alias for notes, but more explicit)
ALTER TABLE events
ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER title;

-- Add location
ALTER TABLE events
ADD COLUMN IF NOT EXISTS location VARCHAR(255) NULL AFTER description;

-- Add timezone support
ALTER TABLE events
ADD COLUMN IF NOT EXISTS timezone VARCHAR(50) DEFAULT 'Africa/Johannesburg' AFTER ends_at;

-- Add created_by (who created, vs user_id which is the owner)
ALTER TABLE events
ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER user_id;

-- Add assigned_to for task assignment
ALTER TABLE events
ADD COLUMN IF NOT EXISTS assigned_to INT NULL AFTER created_by;

-- Add recurrence support
ALTER TABLE events
ADD COLUMN IF NOT EXISTS recurrence_rule VARCHAR(50) NULL AFTER status;

ALTER TABLE events
ADD COLUMN IF NOT EXISTS recurrence_parent_id INT NULL AFTER recurrence_rule;

-- Add focus mode fields
ALTER TABLE events
ADD COLUMN IF NOT EXISTS focus_mode TINYINT(1) DEFAULT 0 AFTER recurrence_parent_id;

ALTER TABLE events
ADD COLUMN IF NOT EXISTS actual_start DATETIME NULL AFTER focus_mode;

ALTER TABLE events
ADD COLUMN IF NOT EXISTS actual_end DATETIME NULL AFTER actual_start;

ALTER TABLE events
ADD COLUMN IF NOT EXISTS productivity_rating TINYINT NULL AFTER actual_end;

ALTER TABLE events
ADD COLUMN IF NOT EXISTS pomodoro_count INT DEFAULT 0 AFTER productivity_rating;

-- Update kind enum to include all types
ALTER TABLE events
MODIFY COLUMN kind VARCHAR(20) DEFAULT 'event';

-- Update status enum
ALTER TABLE events
MODIFY COLUMN status VARCHAR(20) DEFAULT 'pending';

-- Add index for recurrence parent
ALTER TABLE events
ADD INDEX IF NOT EXISTS idx_recurrence_parent (recurrence_parent_id);

-- Add index for assigned_to
ALTER TABLE events
ADD INDEX IF NOT EXISTS idx_assigned_to (assigned_to);

-- Set created_by = user_id for existing records where NULL
UPDATE events SET created_by = user_id WHERE created_by IS NULL;

-- ============================================
-- STEP 2: CREATE `event_reminders` TABLE
-- Proper reminders with multiple per event
-- ============================================

CREATE TABLE IF NOT EXISTS event_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,

    -- Trigger settings
    trigger_offset INT NOT NULL COMMENT 'Minutes before event start',
    trigger_type ENUM('push', 'email', 'sms', 'sound', 'silent') DEFAULT 'push',

    -- Snooze support
    snooze_count INT DEFAULT 0,
    snooze_until DATETIME NULL,

    -- Tracking
    is_sent TINYINT(1) DEFAULT 0,
    sent_at DATETIME NULL,
    last_triggered_at DATETIME NULL,
    next_trigger_at DATETIME NULL,

    -- Retry for offline
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_event (event_id),
    INDEX idx_next_trigger (next_trigger_at),
    INDEX idx_pending (is_sent, next_trigger_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- STEP 3: CREATE `schedule_templates` TABLE
-- Reusable schedule patterns
-- ============================================

CREATE TABLE IF NOT EXISTS schedule_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Ownership
    user_id INT NULL COMMENT 'NULL = system template',
    family_id INT NULL,

    -- Template info
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    icon VARCHAR(10) DEFAULT '📋',
    color VARCHAR(20) DEFAULT '#667eea',

    -- Pattern definition
    pattern_json JSON NOT NULL COMMENT 'Array of event definitions',

    -- Settings
    is_public TINYINT(1) DEFAULT 0,
    is_system TINYINT(1) DEFAULT 0,

    -- Stats
    use_count INT DEFAULT 0,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_user (user_id),
    INDEX idx_family (family_id),
    INDEX idx_public (is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default system templates
INSERT INTO schedule_templates (name, description, icon, color, pattern_json, is_public, is_system) VALUES
('Productive Morning', 'Study session + work blocks', '🌅', '#667eea', '[
    {"title": "Morning Study Session", "start_offset": "08:00", "end_offset": "10:00", "kind": "study"},
    {"title": "Coffee Break", "start_offset": "10:00", "end_offset": "10:15", "kind": "break"},
    {"title": "Work Block 1", "start_offset": "10:15", "end_offset": "12:00", "kind": "work"},
    {"title": "Lunch Break", "start_offset": "12:00", "end_offset": "13:00", "kind": "break"}
]', 1, 1),
('Deep Work Day', 'Focus sessions with breaks', '🎯', '#4facfe', '[
    {"title": "Deep Work Session 1", "start_offset": "09:00", "end_offset": "11:00", "kind": "focus", "focus_mode": 1},
    {"title": "Break", "start_offset": "11:00", "end_offset": "11:15", "kind": "break"},
    {"title": "Deep Work Session 2", "start_offset": "11:15", "end_offset": "13:00", "kind": "focus", "focus_mode": 1},
    {"title": "Lunch", "start_offset": "13:00", "end_offset": "14:00", "kind": "break"},
    {"title": "Deep Work Session 3", "start_offset": "14:00", "end_offset": "16:00", "kind": "focus", "focus_mode": 1}
]', 1, 1),
('Study Intensive', 'Multiple study blocks', '📚', '#9b59b6', '[
    {"title": "Study Block 1", "start_offset": "08:00", "end_offset": "10:00", "kind": "study"},
    {"title": "Break", "start_offset": "10:00", "end_offset": "10:15", "kind": "break"},
    {"title": "Study Block 2", "start_offset": "10:15", "end_offset": "12:15", "kind": "study"},
    {"title": "Lunch", "start_offset": "12:15", "end_offset": "13:00", "kind": "break"},
    {"title": "Study Block 3", "start_offset": "13:00", "end_offset": "15:00", "kind": "study"},
    {"title": "Break", "start_offset": "15:00", "end_offset": "15:15", "kind": "break"},
    {"title": "Study Block 4", "start_offset": "15:15", "end_offset": "17:00", "kind": "study"}
]', 1, 1),
('Balanced Day', 'Work, study, and breaks', '⚖️', '#43e97b', '[
    {"title": "Morning Work", "start_offset": "09:00", "end_offset": "11:00", "kind": "work"},
    {"title": "Break", "start_offset": "11:00", "end_offset": "11:15", "kind": "break"},
    {"title": "Study Session", "start_offset": "11:15", "end_offset": "13:00", "kind": "study"},
    {"title": "Lunch", "start_offset": "13:00", "end_offset": "14:00", "kind": "break"},
    {"title": "Afternoon Work", "start_offset": "14:00", "end_offset": "16:00", "kind": "work"},
    {"title": "Break", "start_offset": "16:00", "end_offset": "16:15", "kind": "break"},
    {"title": "Personal Time", "start_offset": "16:15", "end_offset": "17:00", "kind": "todo"}
]', 1, 1)
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- ============================================
-- STEP 4: CREATE `event_history` TABLE
-- Audit trail for undo support
-- ============================================

CREATE TABLE IF NOT EXISTS event_history (
    id INT AUTO_INCREMENT PRIMARY KEY,

    event_id INT NOT NULL,
    action ENUM('create', 'update', 'delete', 'restore', 'complete', 'uncomplete') NOT NULL,

    changed_by INT NOT NULL,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Store the changes
    old_values JSON NULL,
    new_values JSON NULL,

    -- For undo
    is_undone TINYINT(1) DEFAULT 0,
    undone_at DATETIME NULL,
    undone_by INT NULL,

    INDEX idx_event (event_id),
    INDEX idx_changed_by (changed_by),
    INDEX idx_changed_at (changed_at),
    INDEX idx_undone (is_undone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- STEP 5: MIGRATE DATA FROM schedule_events
-- Copy schedule_events to events table
-- ============================================

-- Check if schedule_events exists and has data
-- This migration preserves the original table

INSERT INTO events (
    family_id, user_id, created_by, assigned_to,
    title, notes, starts_at, ends_at,
    kind, color, status, reminder_minutes,
    recurrence_rule, recurrence_parent_id,
    focus_mode, actual_start, actual_end,
    productivity_rating, pomodoro_count,
    created_at, updated_at
)
SELECT
    se.family_id,
    se.user_id,
    se.added_by,
    se.assigned_to,
    se.title,
    se.notes,
    se.starts_at,
    se.ends_at,
    se.kind,
    se.color,
    se.status,
    se.reminder_minutes,
    se.repeat_rule,
    se.parent_event_id,
    se.focus_mode,
    se.actual_start,
    se.actual_end,
    se.productivity_rating,
    se.pomodoro_count,
    se.created_at,
    se.updated_at
FROM schedule_events se
WHERE NOT EXISTS (
    SELECT 1 FROM events e
    WHERE e.title = se.title
    AND e.starts_at = se.starts_at
    AND e.family_id = se.family_id
);

-- ============================================
-- STEP 6: CREATE MIGRATION FOR EXISTING REMINDERS
-- Convert reminder_minutes to event_reminders rows
-- ============================================

INSERT INTO event_reminders (event_id, trigger_offset, trigger_type, next_trigger_at)
SELECT
    id,
    reminder_minutes,
    'push',
    DATE_SUB(starts_at, INTERVAL reminder_minutes MINUTE)
FROM events
WHERE reminder_minutes IS NOT NULL
AND reminder_minutes > 0
AND status = 'pending'
AND starts_at > NOW()
AND NOT EXISTS (
    SELECT 1 FROM event_reminders er WHERE er.event_id = events.id
);

-- ============================================
-- STEP 7: ADD FOREIGN KEY CONSTRAINTS
-- (After data migration to avoid conflicts)
-- ============================================

-- Note: Only add if columns exist and data is clean
-- These may fail if data integrity issues exist

-- ALTER TABLE event_reminders
-- ADD CONSTRAINT fk_reminder_event
-- FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;

-- ALTER TABLE events
-- ADD CONSTRAINT fk_recurrence_parent
-- FOREIGN KEY (recurrence_parent_id) REFERENCES events(id) ON DELETE SET NULL;

-- ============================================
-- COMMIT TRANSACTION
-- ============================================

COMMIT;

-- ============================================
-- VERIFICATION QUERIES
-- Run these to verify migration success
-- ============================================

-- SELECT 'Events with new columns' as check_type, COUNT(*) as count FROM events WHERE timezone IS NOT NULL;
-- SELECT 'Event reminders created' as check_type, COUNT(*) as count FROM event_reminders;
-- SELECT 'Schedule templates' as check_type, COUNT(*) as count FROM schedule_templates;
-- SELECT 'Events migrated from schedule_events' as check_type, COUNT(*) as count FROM events WHERE kind IN ('study', 'work', 'focus', 'break');

-- ============================================
-- ROLLBACK (If needed)
-- ============================================

-- To rollback, run:
-- ALTER TABLE events DROP COLUMN IF EXISTS timezone;
-- ALTER TABLE events DROP COLUMN IF EXISTS location;
-- ALTER TABLE events DROP COLUMN IF EXISTS description;
-- ALTER TABLE events DROP COLUMN IF EXISTS created_by;
-- ALTER TABLE events DROP COLUMN IF EXISTS assigned_to;
-- ALTER TABLE events DROP COLUMN IF EXISTS recurrence_rule;
-- ALTER TABLE events DROP COLUMN IF EXISTS recurrence_parent_id;
-- ALTER TABLE events DROP COLUMN IF EXISTS focus_mode;
-- ALTER TABLE events DROP COLUMN IF EXISTS actual_start;
-- ALTER TABLE events DROP COLUMN IF EXISTS actual_end;
-- ALTER TABLE events DROP COLUMN IF EXISTS productivity_rating;
-- ALTER TABLE events DROP COLUMN IF EXISTS pomodoro_count;
-- DROP TABLE IF EXISTS event_reminders;
-- DROP TABLE IF EXISTS schedule_templates;
-- DROP TABLE IF EXISTS event_history;
