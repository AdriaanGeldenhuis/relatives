-- ============================================
-- NOTIFICATIONS MIGRATION V1
-- FCM Tokens & Weather Notification Schedule
-- ============================================
-- Run this migration to add/update required tables
-- ============================================

-- FCM Tokens table for native app push notifications
CREATE TABLE IF NOT EXISTS fcm_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(512) NOT NULL,
    device_type ENUM('android', 'ios', 'web', 'unknown') DEFAULT 'unknown',
    device_info JSON NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_user_id (user_id),
    UNIQUE INDEX idx_token (token(255)),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weather notification schedule
CREATE TABLE IF NOT EXISTS weather_notification_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    enabled TINYINT(1) DEFAULT 1,
    notification_time TIME DEFAULT '07:00:00',
    voice_enabled TINYINT(1) DEFAULT 1,
    include_forecast TINYINT(1) DEFAULT 1,
    last_sent DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_user_id (user_id),
    INDEX idx_enabled_time (enabled, notification_time),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification preferences (if not exists)
CREATE TABLE IF NOT EXISTS notification_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category VARCHAR(50) NOT NULL,
    enabled TINYINT(1) DEFAULT 1,
    push_enabled TINYINT(1) DEFAULT 1,
    sound_enabled TINYINT(1) DEFAULT 1,
    vibrate_enabled TINYINT(1) DEFAULT 1,
    quiet_hours_enabled TINYINT(1) DEFAULT 0,
    quiet_hours_start TIME DEFAULT '22:00:00',
    quiet_hours_end TIME DEFAULT '07:00:00',
    min_priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'low',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_user_category (user_id, category),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification delivery log (for debugging push issues)
CREATE TABLE IF NOT EXISTS notification_delivery_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notification_id INT NOT NULL,
    user_id INT NOT NULL,
    delivery_method ENUM('push', 'email', 'sms', 'in_app') DEFAULT 'push',
    status ENUM('pending', 'sent', 'failed', 'bounced') DEFAULT 'pending',
    error_message TEXT NULL,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_notification_id (notification_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ADD MISSING COLUMNS (if tables already exist)
-- ============================================

-- Add device_info to fcm_tokens if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'fcm_tokens'
               AND COLUMN_NAME = 'device_info');
SET @query := IF(@exist = 0,
    'ALTER TABLE fcm_tokens ADD COLUMN device_info JSON NULL AFTER device_type',
    'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add voice_enabled to weather_notification_schedule if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'weather_notification_schedule'
               AND COLUMN_NAME = 'voice_enabled');
SET @query := IF(@exist = 0,
    'ALTER TABLE weather_notification_schedule ADD COLUMN voice_enabled TINYINT(1) DEFAULT 1 AFTER notification_time',
    'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add include_forecast to weather_notification_schedule if missing
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'weather_notification_schedule'
               AND COLUMN_NAME = 'include_forecast');
SET @query := IF(@exist = 0,
    'ALTER TABLE weather_notification_schedule ADD COLUMN include_forecast TINYINT(1) DEFAULT 1 AFTER voice_enabled',
    'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- ENSURE INDEXES EXIST
-- ============================================

-- Index for faster weather notification lookups
CREATE INDEX IF NOT EXISTS idx_weather_enabled_time
ON weather_notification_schedule(enabled, notification_time);

-- Index for faster FCM token lookups
CREATE INDEX IF NOT EXISTS idx_fcm_user_updated
ON fcm_tokens(user_id, updated_at);

-- ============================================
-- VERIFICATION QUERY
-- ============================================
-- Run this to verify tables are set up correctly:
-- SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE()
-- AND TABLE_NAME IN ('fcm_tokens', 'weather_notification_schedule', 'notification_preferences', 'notification_delivery_log')
-- ORDER BY TABLE_NAME, ORDINAL_POSITION;
