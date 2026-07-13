-- ============================================
-- NOTIFICATIONS FIX v2
-- Run against the live database.
-- ============================================

-- 1) Drop UNIQUE KEY user_device (user_id, device_type) on fcm_tokens.
--
-- This key limits every user to ONE token per device type. When a device's
-- FCM token rotates, the INSERT of the new token collided with the row
-- holding the old token and registration returned HTTP 500 forever — the
-- server kept pushing to a dead token and the native app never received
-- notifications. It also prevents a user from having two Android devices.
-- (api/fcm/register.php now upserts so it works with or without this key,
-- but dropping it is still required to support multiple devices per user.)
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'fcm_tokens'
               AND INDEX_NAME = 'user_device');
SET @query := IF(@exist > 0,
    'ALTER TABLE fcm_tokens DROP INDEX user_device',
    'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Keep a plain (non-unique) index for lookups by user+device.
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'fcm_tokens'
               AND INDEX_NAME = 'idx_user_device_type');
SET @query := IF(@exist = 0,
    'ALTER TABLE fcm_tokens ADD INDEX idx_user_device_type (user_id, device_type)',
    'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Event reminder sent-state. Deduping reminders against the
-- notifications table meant a deleted notification re-triggered the reminder
-- push on every cron run; the cron now records delivery here instead.
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'events'
               AND COLUMN_NAME = 'reminder_sent_at');
SET @query := IF(@exist = 0,
    'ALTER TABLE events ADD COLUMN reminder_sent_at DATETIME NULL AFTER reminder_minutes',
    'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) Align notification_delivery_log with the code (fresh installs from
-- notifications-fcm-weather-v1.sql lack created_at, which the cleanup cron
-- prefers).
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'notification_delivery_log'
               AND COLUMN_NAME = 'created_at');
SET @query := IF(@exist = 0,
    'ALTER TABLE notification_delivery_log ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
    'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
