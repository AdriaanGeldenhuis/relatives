-- =====================================================
-- SUZI VOICE ASSISTANT v6.0 - Database Migration
-- Run this if voice_command_log table doesn't exist
-- =====================================================

CREATE TABLE IF NOT EXISTS `voice_command_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `transcript` VARCHAR(500) NOT NULL,
    `intent` VARCHAR(50) NOT NULL,
    `slots` JSON DEFAULT NULL,
    `response_text` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_intent` (`intent`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: Add response_text column if table exists but column doesn't
-- ALTER TABLE `voice_command_log` ADD COLUMN `response_text` VARCHAR(500) DEFAULT NULL AFTER `slots`;
