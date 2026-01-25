-- ============================================
-- CALENDAR & SCHEDULE REBUILD - MIGRATION V3
-- PERMISSIONS & SHARING TABLES
-- Date: 2026-01-03
-- ============================================

START TRANSACTION;

-- ============================================
-- CALENDAR PERMISSIONS TABLE
-- For sharing calendars between users
-- ============================================

CREATE TABLE IF NOT EXISTS calendar_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,

    calendar_owner_id INT NOT NULL,
    granted_to_user_id INT NOT NULL,

    permission_level TINYINT NOT NULL DEFAULT 1, -- 1=view, 2=edit, 3=manage, 4=admin

    granted_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_share (calendar_owner_id, granted_to_user_id),
    INDEX idx_owner (calendar_owner_id),
    INDEX idx_granted_to (granted_to_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- EVENT APPROVALS TABLE
-- For approval workflow
-- ============================================

CREATE TABLE IF NOT EXISTS event_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,

    event_id INT NOT NULL,
    submitted_by INT NOT NULL,

    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',

    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    rejection_reason TEXT NULL,

    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_event (event_id),
    INDEX idx_status (status),
    INDEX idx_submitted_by (submitted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SHARED CALENDARS VIEW
-- Combines permissions for easy querying
-- ============================================

CREATE OR REPLACE VIEW shared_calendars_view AS
SELECT
    cp.id as share_id,
    cp.calendar_owner_id,
    owner.full_name as owner_name,
    owner.avatar_color as owner_color,
    cp.granted_to_user_id,
    grantee.full_name as grantee_name,
    grantee.avatar_color as grantee_color,
    cp.permission_level,
    CASE cp.permission_level
        WHEN 1 THEN 'view'
        WHEN 2 THEN 'edit'
        WHEN 3 THEN 'manage'
        WHEN 4 THEN 'admin'
        ELSE 'none'
    END as permission_name,
    cp.created_at as shared_at
FROM calendar_permissions cp
JOIN users owner ON cp.calendar_owner_id = owner.id
JOIN users grantee ON cp.granted_to_user_id = grantee.id;

COMMIT;
