-- ============================================
-- MIGRATION: Tracking Module - Clean Rebuild v2
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;

-- Drop old tables if they exist
DROP TABLE IF EXISTS tracking_alerts;
DROP TABLE IF EXISTS tracking_browser_subscriptions;
DROP TABLE IF EXISTS tracking_cache_stats;
DROP TABLE IF EXISTS tracking_checkins;
DROP TABLE IF EXISTS tracking_current;
DROP TABLE IF EXISTS tracking_devices;
DROP TABLE IF EXISTS tracking_events;
DROP TABLE IF EXISTS tracking_geofence_queue;
DROP TABLE IF EXISTS tracking_history;
DROP TABLE IF EXISTS tracking_locations;
DROP TABLE IF EXISTS tracking_members;
DROP TABLE IF EXISTS tracking_places;
DROP TABLE IF EXISTS tracking_settings;
DROP TABLE IF EXISTS tracking_zones;
DROP TABLE IF EXISTS tracking_current_locations;
DROP TABLE IF EXISTS tracking_location_history;
DROP TABLE IF EXISTS tracking_sessions;
DROP TABLE IF EXISTS tracking_geofences;
DROP TABLE IF EXISTS tracking_geofence_states;
DROP TABLE IF EXISTS tracking_alert_rules;
DROP TABLE IF EXISTS tracking_consent;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- tracking_current_locations
-- One row per user, upserted on each update
-- ============================================
CREATE TABLE tracking_current_locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    family_id INT UNSIGNED NOT NULL,
    lat DECIMAL(10, 7) NOT NULL,
    lng DECIMAL(10, 7) NOT NULL,
    accuracy_m FLOAT DEFAULT 0,
    altitude FLOAT DEFAULT NULL,
    speed FLOAT DEFAULT 0,
    heading FLOAT DEFAULT NULL,
    battery INT DEFAULT 0,
    is_moving TINYINT(1) DEFAULT 0,
    source ENUM('gps', 'network', 'fused', 'passive') DEFAULT 'fused',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user (user_id),
    KEY idx_family (family_id),
    KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- tracking_location_history
-- Append-only log of all positions
-- ============================================
CREATE TABLE tracking_location_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    family_id INT UNSIGNED NOT NULL,
    lat DECIMAL(10, 7) NOT NULL,
    lng DECIMAL(10, 7) NOT NULL,
    accuracy_m FLOAT DEFAULT 0,
    altitude FLOAT DEFAULT NULL,
    speed FLOAT DEFAULT 0,
    heading FLOAT DEFAULT NULL,
    battery INT DEFAULT 0,
    is_moving TINYINT(1) DEFAULT 0,
    source ENUM('gps', 'network', 'fused', 'passive') DEFAULT 'fused',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_time (user_id, created_at DESC),
    KEY idx_family_time (family_id, created_at DESC),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- tracking_sessions
-- Live tracking sessions
-- ============================================
CREATE TABLE tracking_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    family_id INT UNSIGNED NOT NULL,
    status ENUM('active', 'stopped', 'expired') DEFAULT 'active',
    mode ENUM('live', 'motion') DEFAULT 'live',
    interval_seconds INT DEFAULT 30,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_keepalive DATETIME DEFAULT NULL,
    stopped_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    KEY idx_user_status (user_id, status),
    KEY idx_family_status (family_id, status),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- tracking_settings
-- Per-family settings
-- ============================================
CREATE TABLE tracking_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    update_interval INT DEFAULT 30,
    history_retention_days INT DEFAULT 30,
    distance_unit ENUM('km', 'mi') DEFAULT 'km',
    map_style ENUM('streets', 'satellite', 'dark', 'light') DEFAULT 'streets',
    show_speed TINYINT(1) DEFAULT 1,
    show_battery TINYINT(1) DEFAULT 1,
    show_accuracy TINYINT(1) DEFAULT 0,
    geofence_notifications TINYINT(1) DEFAULT 1,
    low_battery_alert TINYINT(1) DEFAULT 1,
    low_battery_threshold INT DEFAULT 15,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_family (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- tracking_geofences
-- Geofence definitions (circle or polygon)
-- ============================================
CREATE TABLE tracking_geofences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('circle', 'polygon') DEFAULT 'circle',
    lat DECIMAL(10, 7) DEFAULT NULL,
    lng DECIMAL(10, 7) DEFAULT NULL,
    radius_m INT DEFAULT 200,
    polygon_json TEXT DEFAULT NULL,
    color VARCHAR(7) DEFAULT '#667eea',
    notify_enter TINYINT(1) DEFAULT 1,
    notify_exit TINYINT(1) DEFAULT 1,
    active TINYINT(1) DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_family (family_id),
    KEY idx_active (family_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- tracking_geofence_states
-- Current in/out state per user per geofence
-- ============================================
CREATE TABLE tracking_geofence_states (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    geofence_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    is_inside TINYINT(1) DEFAULT 0,
    entered_at DATETIME DEFAULT NULL,
    exited_at DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_geofence_user (geofence_id, user_id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- tracking_events
-- Event log (enter, exit, arrive, leave, etc.)
-- ============================================
CREATE TABLE tracking_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    event_type ENUM('geofence_enter', 'geofence_exit', 'session_start', 'session_stop', 'low_battery', 'sos', 'speed_alert', 'custom') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    lat DECIMAL(10, 7) DEFAULT NULL,
    lng DECIMAL(10, 7) DEFAULT NULL,
    meta_json TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_family_time (family_id, created_at DESC),
    KEY idx_user_time (user_id, created_at DESC),
    KEY idx_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- tracking_places
-- Saved places (home, work, school, etc.)
-- ============================================
CREATE TABLE tracking_places (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(10) DEFAULT NULL,
    lat DECIMAL(10, 7) NOT NULL,
    lng DECIMAL(10, 7) NOT NULL,
    address VARCHAR(500) DEFAULT NULL,
    radius_m INT DEFAULT 100,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_family (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- tracking_alert_rules
-- Alert configurations
-- ============================================
CREATE TABLE tracking_alert_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    rule_type ENUM('speed', 'battery', 'geofence', 'inactivity', 'custom') NOT NULL,
    target_user_id INT UNSIGNED DEFAULT NULL,
    conditions_json TEXT NOT NULL,
    notify_users_json TEXT DEFAULT NULL,
    active TINYINT(1) DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_family (family_id),
    KEY idx_active (family_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- tracking_devices
-- FCM device tokens for push notifications
-- ============================================
CREATE TABLE tracking_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    fcm_token VARCHAR(500) NOT NULL,
    device_type ENUM('android', 'ios', 'web') DEFAULT 'android',
    device_name VARCHAR(100) DEFAULT NULL,
    active TINYINT(1) DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    UNIQUE KEY uq_token (fcm_token(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- tracking_consent
-- User consent records
-- ============================================
CREATE TABLE tracking_consent (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    family_id INT UNSIGNED NOT NULL,
    location_consent TINYINT(1) DEFAULT 0,
    notification_consent TINYINT(1) DEFAULT 0,
    background_consent TINYINT(1) DEFAULT 0,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    consented_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user (user_id),
    KEY idx_family (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
