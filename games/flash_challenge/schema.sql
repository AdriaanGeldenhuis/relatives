-- ============================================
-- FLASH CHALLENGE - Database Schema v1.0
-- Daily 30-second challenge game
-- ============================================

-- A) flash_daily_challenges
-- Stores the daily challenge question and valid answers
CREATE TABLE IF NOT EXISTS flash_daily_challenges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    challenge_date DATE NOT NULL,
    question TEXT NOT NULL,
    answer_type VARCHAR(32) NOT NULL DEFAULT 'single_word' COMMENT 'single_word|phrase|list|number',
    valid_answers JSON NOT NULL COMMENT 'Array of acceptable answers',
    partial_rules JSON DEFAULT NULL COMMENT 'Rules for partial matching',
    difficulty TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '1-5 scale',
    category VARCHAR(32) NOT NULL DEFAULT 'general',
    format_hint VARCHAR(120) DEFAULT NULL COMMENT 'Hint shown to user about answer format',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY idx_challenge_date (challenge_date),
    KEY idx_category (category),
    KEY idx_difficulty (difficulty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B) flash_attempts
-- Stores every user attempt with anti-cheat data
CREATE TABLE IF NOT EXISTS flash_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    challenge_date DATE NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    family_id INT UNSIGNED DEFAULT NULL,
    device_id VARCHAR(64) DEFAULT NULL,
    answer_text TEXT NOT NULL,
    normalized_answer TEXT DEFAULT NULL,
    verdict ENUM('correct', 'partial', 'incorrect') NOT NULL,
    confidence TINYINT UNSIGNED NOT NULL DEFAULT 100 COMMENT '0-100 confidence score',
    reason VARCHAR(160) DEFAULT NULL COMMENT 'Short reason for verdict',
    base_score INT UNSIGNED NOT NULL DEFAULT 0,
    speed_bonus INT UNSIGNED NOT NULL DEFAULT 0,
    score INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'base_score + speed_bonus',
    started_at DATETIME NOT NULL,
    answered_at DATETIME NOT NULL,
    ended_at DATETIME NOT NULL,
    answered_in_ms INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY idx_unique_attempt (challenge_date, user_id),
    KEY idx_family_leaderboard (challenge_date, family_id, score DESC, answered_in_ms ASC),
    KEY idx_global_leaderboard (challenge_date, score DESC, answered_in_ms ASC),
    KEY idx_user_history (user_id, score DESC),
    KEY idx_family_id (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- C) flash_user_stats
-- Tracks streaks and personal bests
CREATE TABLE IF NOT EXISTS flash_user_stats (
    user_id INT UNSIGNED PRIMARY KEY,
    user_streak INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Consecutive days played',
    last_played_date DATE DEFAULT NULL,
    personal_best_score INT UNSIGNED NOT NULL DEFAULT 0,
    personal_best_date DATE DEFAULT NULL,
    total_games INT UNSIGNED NOT NULL DEFAULT 0,
    total_correct INT UNSIGNED NOT NULL DEFAULT 0,
    total_partial INT UNSIGNED NOT NULL DEFAULT 0,
    total_incorrect INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_streak (user_streak DESC),
    KEY idx_personal_best (personal_best_score DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D) flash_validation_logs
-- Optional: logs AI validation calls for debugging
CREATE TABLE IF NOT EXISTS flash_validation_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT UNSIGNED NOT NULL,
    method_used ENUM('exact', 'fuzzy', 'ai') NOT NULL,
    ai_prompt TEXT DEFAULT NULL,
    ai_response TEXT DEFAULT NULL,
    processing_time_ms INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_attempt_id (attempt_id),
    KEY idx_method (method_used),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- E) flash_family_stats (optional - for quick family queries)
CREATE TABLE IF NOT EXISTS flash_family_stats (
    family_id INT UNSIGNED NOT NULL,
    stat_date DATE NOT NULL,
    members_played INT UNSIGNED NOT NULL DEFAULT 0,
    total_members INT UNSIGNED NOT NULL DEFAULT 0,
    participation_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    family_winner_id INT UNSIGNED DEFAULT NULL,
    family_winner_score INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (family_id, stat_date),
    KEY idx_date (stat_date),
    KEY idx_winner (family_winner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Sample data for testing (optional)
-- ============================================

-- Insert a sample daily challenge for today
-- INSERT INTO flash_daily_challenges (
--     challenge_date,
--     question,
--     answer_type,
--     valid_answers,
--     partial_rules,
--     difficulty,
--     category,
--     format_hint
-- ) VALUES (
--     CURDATE(),
--     'Name the largest planet in our solar system.',
--     'single_word',
--     '["Jupiter", "jupiter"]',
--     '{"allow_synonyms": false, "allow_plural": false, "min_similarity": 0.85}',
--     2,
--     'general',
--     'One word answer'
-- );
