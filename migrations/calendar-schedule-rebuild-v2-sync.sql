-- ============================================
-- CALENDAR & SCHEDULE REBUILD - MIGRATION V2
-- SYNC TABLES
-- Date: 2026-01-03
-- ============================================

START TRANSACTION;

-- ============================================
-- SYNC QUEUE TABLE
-- For tracking pending syncs
-- ============================================

CREATE TABLE IF NOT EXISTS event_sync_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,

    event_id INT NOT NULL,
    action ENUM('create', 'update', 'delete', 'complete') NOT NULL,

    changes_json JSON NULL,

    status ENUM('pending', 'synced', 'failed') DEFAULT 'pending',
    retry_count INT DEFAULT 0,
    error_message TEXT NULL,

    synced_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_event_pending (event_id, status),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CONFLICT TABLE
-- For storing unresolved conflicts
-- ============================================

CREATE TABLE IF NOT EXISTS event_conflicts (
    id INT AUTO_INCREMENT PRIMARY KEY,

    event_id INT NOT NULL,

    local_changes JSON NOT NULL,
    remote_changes JSON NOT NULL,

    status ENUM('pending', 'resolved', 'dismissed') DEFAULT 'pending',
    resolved_at DATETIME NULL,
    resolved_with ENUM('local', 'remote', 'merged') NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_event (event_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SYNC LOG TABLE
-- For debugging and monitoring
-- ============================================

CREATE TABLE IF NOT EXISTS event_sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,

    event_id INT NULL,
    family_id INT NULL,
    user_id INT NULL,

    action VARCHAR(50) NOT NULL,
    source VARCHAR(50) DEFAULT 'internal',
    target VARCHAR(50) DEFAULT 'internal',

    status ENUM('success', 'failed', 'skipped') NOT NULL,
    details JSON NULL,
    error_message TEXT NULL,

    duration_ms INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_event (event_id),
    INDEX idx_family (family_id),
    INDEX idx_created (created_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- USER SYNC STATE TABLE
-- Track last sync time per user/device
-- ============================================

CREATE TABLE IF NOT EXISTS user_sync_state (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,
    device_id VARCHAR(100) NULL,

    last_sync_at DATETIME NOT NULL,
    last_sync_version INT DEFAULT 1,

    pending_push INT DEFAULT 0,
    pending_pull INT DEFAULT 0,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_user_device (user_id, device_id),
    INDEX idx_user (user_id),
    INDEX idx_last_sync (last_sync_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
