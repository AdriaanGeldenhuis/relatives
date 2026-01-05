-- ============================================
-- CALENDAR & SCHEDULE ENHANCEMENTS V4
-- Adds priority levels, subtasks, and more
-- ============================================

-- Add priority column to events table
ALTER TABLE events
ADD COLUMN IF NOT EXISTS priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium' AFTER color;

-- Add index on priority for filtering (ignore if exists)
-- CREATE INDEX idx_events_priority ON events(priority);

-- Create subtasks table for checklist support
-- Note: Using INT UNSIGNED to match events.id if it's unsigned
CREATE TABLE IF NOT EXISTS event_subtasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    is_done TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_subtasks_event (event_id),
    INDEX idx_subtasks_done (is_done)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign key separately (will fail silently if column types don't match)
-- ALTER TABLE event_subtasks ADD CONSTRAINT fk_subtask_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;

-- Add estimated duration column (in minutes)
ALTER TABLE events
ADD COLUMN IF NOT EXISTS estimated_duration INT NULL AFTER ends_at;

-- Add actual duration tracking (calculated on completion)
ALTER TABLE events
ADD COLUMN IF NOT EXISTS actual_duration INT NULL AFTER estimated_duration;

-- Add tags/labels support
CREATE TABLE IF NOT EXISTS event_tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    name VARCHAR(50) NOT NULL,
    color VARCHAR(20) DEFAULT '#667eea',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_family_tag (family_id, name),
    INDEX idx_tags_family (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Junction table for event-tag relationship
CREATE TABLE IF NOT EXISTS event_tag_assignments (
    event_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id, tag_id),
    INDEX idx_eta_event (event_id),
    INDEX idx_eta_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add snooze tracking to existing event_reminders (if not exists)
ALTER TABLE event_reminders
ADD COLUMN IF NOT EXISTS snooze_count INT DEFAULT 0 AFTER trigger_type,
ADD COLUMN IF NOT EXISTS snooze_until DATETIME NULL AFTER snooze_count;

-- Create quick duration presets table
CREATE TABLE IF NOT EXISTS duration_presets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,  -- NULL = system preset
    family_id INT UNSIGNED NULL,
    label VARCHAR(50) NOT NULL,
    duration_minutes INT UNSIGNED NOT NULL,
    icon VARCHAR(10) DEFAULT '⏱️',
    is_default TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default duration presets
INSERT IGNORE INTO duration_presets (label, duration_minutes, icon, is_default, sort_order) VALUES
('Quick (15m)', 15, '⚡', 1, 1),
('Pomodoro (25m)', 25, '🍅', 1, 2),
('Short (30m)', 30, '⏱️', 1, 3),
('Medium (45m)', 45, '⏰', 1, 4),
('Hour', 60, '🕐', 1, 5),
('Long (90m)', 90, '📚', 1, 6),
('Deep Work (2h)', 120, '🎯', 1, 7),
('Half Day (4h)', 240, '🌅', 1, 8);

-- Add conflict detection preference
ALTER TABLE events
ADD COLUMN IF NOT EXISTS allow_conflicts TINYINT(1) DEFAULT 0 AFTER focus_mode;

-- Add natural language source (for parsing history)
ALTER TABLE events
ADD COLUMN IF NOT EXISTS nl_source TEXT NULL AFTER notes;

-- ============================================
-- VIEWS FOR COMMON QUERIES
-- ============================================

-- View: Events with subtask progress
CREATE OR REPLACE VIEW v_events_with_progress AS
SELECT
    e.*,
    COALESCE(s.total_subtasks, 0) as total_subtasks,
    COALESCE(s.completed_subtasks, 0) as completed_subtasks,
    CASE
        WHEN s.total_subtasks > 0
        THEN ROUND((s.completed_subtasks / s.total_subtasks) * 100)
        ELSE NULL
    END as subtask_progress
FROM events e
LEFT JOIN (
    SELECT
        event_id,
        COUNT(*) as total_subtasks,
        SUM(is_done) as completed_subtasks
    FROM event_subtasks
    GROUP BY event_id
) s ON e.id = s.event_id;

-- View: Events with tags
CREATE OR REPLACE VIEW v_events_with_tags AS
SELECT
    e.*,
    GROUP_CONCAT(t.name SEPARATOR ', ') as tag_names,
    GROUP_CONCAT(t.color SEPARATOR ', ') as tag_colors
FROM events e
LEFT JOIN event_tag_assignments eta ON e.id = eta.event_id
LEFT JOIN event_tags t ON eta.tag_id = t.id
GROUP BY e.id;

-- ============================================
-- SAMPLE SYSTEM TAGS
-- ============================================

-- These will be created per-family on first use
-- Just documenting suggested defaults here:
-- Important, Personal, Family, Health, Finance, Career, Learning
