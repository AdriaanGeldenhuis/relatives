-- ============================================
-- MIGRATION 001: Tracking V2 Tables
-- ============================================
-- Full schema for family location tracking
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 4.1 CORE TABLES
-- ============================================

-- Family tracking settings
CREATE TABLE IF NOT EXISTS tracking_family_settings (
    family_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,

    -- Tracking mode: 1 = Live Session, 2 = Motion-based
    mode TINYINT UNSIGNED NOT NULL DEFAULT 1,

    -- Mode 1: Live session settings
    session_ttl_seconds INT UNSIGNED NOT NULL DEFAULT 300,       -- 5 min default
    keepalive_interval_seconds INT UNSIGNED NOT NULL DEFAULT 30, -- How often UI pings

    -- Mode 2: Motion-based settings
    moving_interval_seconds INT UNSIGNED NOT NULL DEFAULT 30,    -- Upload when moving
    idle_interval_seconds INT UNSIGNED NOT NULL DEFAULT 300,     -- Heartbeat when idle
    speed_threshold_mps DECIMAL(5,2) NOT NULL DEFAULT 1.0,       -- m/s to consider moving
    distance_threshold_m INT UNSIGNED NOT NULL DEFAULT 50,       -- meters to consider moved

    -- Quality settings
    min_accuracy_m INT UNSIGNED NOT NULL DEFAULT 100,            -- Reject worse accuracy
    dedupe_radius_m INT UNSIGNED NOT NULL DEFAULT 10,            -- Skip similar points
    dedupe_time_seconds INT UNSIGNED NOT NULL DEFAULT 60,        -- Within this time

    -- Rate limiting
    rate_limit_seconds INT UNSIGNED NOT NULL DEFAULT 5,          -- Min between uploads

    -- Display settings
    units ENUM('metric', 'imperial') NOT NULL DEFAULT 'metric',
    map_style VARCHAR(50) NOT NULL DEFAULT 'streets',

    -- Data retention
    history_retention_days INT UNSIGNED NOT NULL DEFAULT 30,
    events_retention_days INT UNSIGNED NOT NULL DEFAULT 90,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_settings_family
        FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Family live sessions (Mode 1)
CREATE TABLE IF NOT EXISTS tracking_family_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    family_id BIGINT UNSIGNED NOT NULL,

    -- Session state
    active TINYINT(1) NOT NULL DEFAULT 1,

    -- Who started it (optional tracking)
    started_by_user_id BIGINT UNSIGNED NULL,

    -- Timing
    last_ping_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_sessions_family (family_id),
    INDEX idx_sessions_active (family_id, active),
    INDEX idx_sessions_expires (expires_at),

    CONSTRAINT fk_sessions_family
        FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Current location per user (always latest)
CREATE TABLE IF NOT EXISTS tracking_current (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    family_id BIGINT UNSIGNED NOT NULL,

    -- Position
    lat DECIMAL(10, 7) NOT NULL,
    lng DECIMAL(10, 7) NOT NULL,

    -- Quality metrics
    accuracy_m DECIMAL(8, 2) NULL,
    speed_mps DECIMAL(8, 2) NULL,
    bearing_deg DECIMAL(6, 2) NULL,
    altitude_m DECIMAL(10, 2) NULL,

    -- Motion state
    motion_state ENUM('moving', 'idle', 'unknown') NOT NULL DEFAULT 'unknown',

    -- When location was recorded on device
    recorded_at TIMESTAMP NOT NULL,

    -- When we received/updated it
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Device info (optional)
    device_id VARCHAR(64) NULL,
    platform VARCHAR(20) NULL,
    app_version VARCHAR(20) NULL,

    INDEX idx_current_family (family_id),
    INDEX idx_current_updated (updated_at),

    CONSTRAINT fk_current_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_current_family
        FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Location history (time-series data)
CREATE TABLE IF NOT EXISTS tracking_locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    family_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,

    -- Position
    lat DECIMAL(10, 7) NOT NULL,
    lng DECIMAL(10, 7) NOT NULL,

    -- Quality metrics
    accuracy_m DECIMAL(8, 2) NULL,
    speed_mps DECIMAL(8, 2) NULL,
    bearing_deg DECIMAL(6, 2) NULL,
    altitude_m DECIMAL(10, 2) NULL,

    -- Motion state at time of recording
    motion_state ENUM('moving', 'idle', 'unknown') NOT NULL DEFAULT 'unknown',

    -- When recorded on device
    recorded_at TIMESTAMP NOT NULL,

    -- When inserted to DB
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_locations_family_time (family_id, recorded_at),
    INDEX idx_locations_user_time (user_id, recorded_at),
    INDEX idx_locations_prune (created_at),

    CONSTRAINT fk_locations_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_locations_family
        FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 4.2 PLACES
-- ============================================

CREATE TABLE IF NOT EXISTS tracking_places (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    family_id BIGINT UNSIGNED NOT NULL,

    -- Place info
    label VARCHAR(100) NOT NULL,
    category ENUM('home', 'work', 'school', 'other') NOT NULL DEFAULT 'other',

    -- Location
    lat DECIMAL(10, 7) NOT NULL,
    lng DECIMAL(10, 7) NOT NULL,
    radius_m INT UNSIGNED NOT NULL DEFAULT 100,

    -- Address (optional, for display)
    address VARCHAR(255) NULL,

    -- Who created it
    created_by_user_id BIGINT UNSIGNED NULL,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_places_family (family_id),

    CONSTRAINT fk_places_family
        FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    CONSTRAINT fk_places_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 4.3 GEOFENCES (Zones)
-- ============================================

CREATE TABLE IF NOT EXISTS tracking_geofences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    family_id BIGINT UNSIGNED NOT NULL,

    -- Geofence info
    name VARCHAR(100) NOT NULL,

    -- Type and geometry
    type ENUM('circle', 'polygon') NOT NULL DEFAULT 'circle',

    -- For circle type
    center_lat DECIMAL(10, 7) NULL,
    center_lng DECIMAL(10, 7) NULL,
    radius_m INT UNSIGNED NULL,

    -- For polygon type (future)
    polygon_json JSON NULL,

    -- State
    active TINYINT(1) NOT NULL DEFAULT 1,

    -- Who created it
    created_by_user_id BIGINT UNSIGNED NULL,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_geofences_family (family_id),
    INDEX idx_geofences_active (family_id, active),

    CONSTRAINT fk_geofences_family
        FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    CONSTRAINT fk_geofences_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 4.4 GEOFENCE STATE (per user)
-- ============================================

CREATE TABLE IF NOT EXISTS tracking_geofence_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    family_id BIGINT UNSIGNED NOT NULL,
    geofence_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,

    -- Current state
    is_inside TINYINT(1) NOT NULL DEFAULT 0,

    -- Transition times
    last_entered_at TIMESTAMP NULL,
    last_exited_at TIMESTAMP NULL,

    -- Last check
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_geofence_user (geofence_id, user_id),
    INDEX idx_geostate_family (family_id),
    INDEX idx_geostate_user (user_id),

    CONSTRAINT fk_geostate_family
        FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    CONSTRAINT fk_geostate_geofence
        FOREIGN KEY (geofence_id) REFERENCES tracking_geofences(id) ON DELETE CASCADE,
    CONSTRAINT fk_geostate_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 4.5 EVENTS LOG
-- ============================================

CREATE TABLE IF NOT EXISTS tracking_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    family_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,

    -- Event type
    event_type ENUM(
        'location_update',
        'enter_geofence',
        'exit_geofence',
        'arrive_place',
        'leave_place',
        'session_on',
        'session_off',
        'settings_change',
        'alert_triggered'
    ) NOT NULL,

    -- Event payload
    meta_json JSON NULL,

    -- When the event occurred
    occurred_at TIMESTAMP NOT NULL,

    -- When we logged it
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_events_family_time (family_id, occurred_at),
    INDEX idx_events_user_time (user_id, occurred_at),
    INDEX idx_events_type (family_id, event_type, occurred_at),
    INDEX idx_events_prune (created_at),

    CONSTRAINT fk_events_family
        FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 4.6 ALERT RULES
-- ============================================

CREATE TABLE IF NOT EXISTS tracking_alert_rules (
    family_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,

    -- Master enable
    enabled TINYINT(1) NOT NULL DEFAULT 1,

    -- Rule toggles
    arrive_place_enabled TINYINT(1) NOT NULL DEFAULT 1,
    leave_place_enabled TINYINT(1) NOT NULL DEFAULT 1,
    enter_geofence_enabled TINYINT(1) NOT NULL DEFAULT 1,
    exit_geofence_enabled TINYINT(1) NOT NULL DEFAULT 1,

    -- Anti-spam
    cooldown_seconds INT UNSIGNED NOT NULL DEFAULT 900,  -- 15 min default

    -- Quiet hours (optional)
    quiet_hours_start TIME NULL,  -- e.g., 22:00
    quiet_hours_end TIME NULL,    -- e.g., 07:00

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_alertrules_family
        FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- 4.7 ALERT DELIVERIES (audit trail)
-- ============================================

CREATE TABLE IF NOT EXISTS tracking_alert_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    family_id BIGINT UNSIGNED NOT NULL,

    -- What triggered
    rule_type ENUM(
        'arrive_place',
        'leave_place',
        'enter_geofence',
        'exit_geofence'
    ) NOT NULL,

    -- Who triggered
    user_id BIGINT UNSIGNED NOT NULL,

    -- What target (place_id or geofence_id)
    target_id BIGINT UNSIGNED NOT NULL,

    -- When delivered
    delivered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- How delivered
    channel ENUM('inapp', 'push', 'sms', 'email') NOT NULL DEFAULT 'inapp',

    INDEX idx_deliveries_family (family_id),
    INDEX idx_deliveries_cooldown (family_id, rule_type, user_id, target_id, delivered_at),
    INDEX idx_deliveries_prune (delivered_at),

    CONSTRAINT fk_deliveries_family
        FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    CONSTRAINT fk_deliveries_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- VERIFICATION
-- ============================================
-- Run this to confirm tables created:
-- SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
-- WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'tracking_%';
