-- =============================================
-- BLOCKFORGE Database Schema
-- Run this migration to set up the BlockForge tables
-- =============================================

-- Scores table
CREATE TABLE IF NOT EXISTS blockforge_scores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    family_id INT UNSIGNED DEFAULT NULL,
    mode ENUM('solo', 'daily', 'family') NOT NULL DEFAULT 'solo',
    score INT UNSIGNED NOT NULL DEFAULT 0,
    lines_cleared INT UNSIGNED NOT NULL DEFAULT 0,
    level_reached INT UNSIGNED NOT NULL DEFAULT 1,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    seed VARCHAR(32) DEFAULT '',
    device_id VARCHAR(64) DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mode_created (mode, created_at),
    INDEX idx_family_mode_created_score (family_id, mode, created_at, score),
    INDEX idx_user_mode_score (user_id, mode, score),
    INDEX idx_mode_score (mode, score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily challenge seeds
CREATE TABLE IF NOT EXISTS blockforge_daily (
    date DATE NOT NULL,
    seed VARCHAR(32) NOT NULL,
    rules JSON DEFAULT NULL,
    UNIQUE KEY unique_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Family boards (shared board state per day per family)
CREATE TABLE IF NOT EXISTS blockforge_family_boards (
    date DATE NOT NULL,
    family_id INT UNSIGNED NOT NULL,
    board JSON DEFAULT NULL,
    meta JSON DEFAULT NULL,
    UNIQUE KEY unique_date_family (date, family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Family turns (one turn per user per day per family)
CREATE TABLE IF NOT EXISTS blockforge_family_turns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    family_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    actions JSON DEFAULT NULL,
    score_delta INT DEFAULT 0,
    lines_cleared INT DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_date_family_user (date, family_id, user_id),
    INDEX idx_family_date (family_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
